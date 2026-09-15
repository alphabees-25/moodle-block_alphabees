<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Creates quiz questions through the question bank.
 *
 * The question bank is the most version-sensitive part of Moodle we touch:
 * question versioning and `question_bank_entries` arrived in 4.0, and the
 * helpers around categories and quiz slots have been moved and renamed
 * repeatedly since. This plugin supports 4.1 through 5.2, so every core entry
 * point used here is feature-detected first. When a release does not offer
 * one, we raise `quiz_unsupported_here` and the module is refused cleanly —
 * far better than leaving half-written questions in a customer's course.
 *
 * Deliberately limited to multichoice and true/false: those two cover what the
 * generator produces, and each additional type is its own set of option fields
 * that would have to be verified against every supported release.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Question creation for quizzes pushed from the portal.
 */
class quiz_writer {
    /** Question types this writer fills in completely. */
    public const SUPPORTED_QTYPES = ['multichoice', 'truefalse'];

    /** Never build a quiz larger than this in one request. */
    private const MAX_QUESTIONS = 100;

    /**
     * Whether this Moodle exposes the pieces we need.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        if (file_exists($CFG->dirroot . '/mod/quiz/locallib.php')) {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        }

        return class_exists('\\question_bank')
            && function_exists('quiz_add_quiz_question')
            && (function_exists('question_get_default_category')
                || function_exists('question_make_default_categories'));
    }

    /**
     * Add questions to a quiz and set its total grade.
     *
     * @param \stdClass $cm Course module row of the quiz.
     * @param array $questions Raw question definitions from the caller.
     * @return array { added: int, skipped: array<int, string> }
     * @throws \moodle_exception When this Moodle cannot be driven safely.
     */
    public static function write_questions(\stdClass $cm, array $questions): array {
        global $CFG, $DB;

        if (!self::is_supported()) {
            throw new \moodle_exception('quiz_unsupported_here', 'block_alphabees');
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        $category = self::default_category($context);

        $added = 0;
        $skipped = [];
        foreach (array_slice($questions, 0, self::MAX_QUESTIONS) as $index => $definition) {
            if (!is_array($definition)) {
                $skipped[$index] = 'not_an_object';
                continue;
            }
            $qtype = (string)($definition['qtype'] ?? '');
            if (!in_array($qtype, self::SUPPORTED_QTYPES, true)) {
                $skipped[$index] = 'unsupported_qtype';
                continue;
            }

            try {
                $questionid = self::save_question($category, $definition, $qtype);
            } catch (\Throwable $e) {
                $skipped[$index] = 'save_failed';
                continue;
            }

            $maxmark = isset($definition['defaultmark']) ? (float)$definition['defaultmark'] : 1.0;
            quiz_add_quiz_question($questionid, $quiz, 0, $maxmark);
            $added++;
        }

        if ($added > 0) {
            self::recalculate_grades($quiz);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * The question category questions are filed into.
     *
     * Uses the quiz's own module context so the questions belong to the
     * activity rather than polluting the course-level bank.
     *
     * @param \context_module $context
     * @return \stdClass
     * @throws \moodle_exception When no category could be obtained.
     */
    private static function default_category(\context_module $context): \stdClass {
        $category = null;

        if (function_exists('question_get_default_category')) {
            $category = question_get_default_category($context->id, true);
            if (!$category) {
                // Older signatures take only the context id.
                $category = question_get_default_category($context->id);
            }
        }
        if (!$category && function_exists('question_make_default_categories')) {
            $category = question_make_default_categories([$context]);
        }
        if (!$category || empty($category->id)) {
            throw new \moodle_exception('quiz_no_question_category', 'block_alphabees');
        }
        return $category;
    }

    /**
     * Build the form-shaped data one question type expects and save it.
     *
     * save_question() takes what the question edit form would have submitted,
     * which is why the structures below mirror those forms rather than the
     * database columns.
     *
     * @param \stdClass $category
     * @param array $definition
     * @param string $qtype
     * @return int The new question id.
     */
    private static function save_question(\stdClass $category, array $definition, string $qtype): int {
        $qtypeobj = \question_bank::get_qtype($qtype);

        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->qtype = $qtype;
        $form->name = self::text($definition['name'] ?? '', 255) ?: get_string('question', 'question');
        $form->questiontext = self::editor_field($definition['questiontext'] ?? '');
        $form->generalfeedback = self::editor_field($definition['generalfeedback'] ?? '');
        $form->defaultmark = isset($definition['defaultmark']) ? (float)$definition['defaultmark'] : 1.0;
        $form->penalty = isset($definition['penalty']) ? (float)$definition['penalty'] : 0.3333333;
        $form->idnumber = '';
        $form->tags = [];

        if ($qtype === 'truefalse') {
            self::apply_truefalse($form, $definition);
        } else {
            self::apply_multichoice($form, $definition);
        }

        $question = new \stdClass();
        $question->category = $category->id;
        $question->qtype = $qtype;
        $question->createdby = self::actor_id();
        $question->modifiedby = self::actor_id();

        $saved = $qtypeobj->save_question($question, $form);
        return (int)$saved->id;
    }

    /**
     * Fill in the true/false specific fields.
     *
     * @param \stdClass $form
     * @param array $definition
     * @return void
     */
    private static function apply_truefalse(\stdClass $form, array $definition): void {
        // Accept a boolean, the strings "true"/"false", or 0/1.
        $raw = $definition['correctanswer'] ?? false;
        if (is_string($raw)) {
            $correct = in_array(strtolower(trim($raw)), ['true', '1', 'yes'], true);
        } else {
            $correct = (bool)$raw;
        }

        $form->correctanswer = $correct ? 1 : 0;
        $form->feedbacktrue = self::editor_field($definition['feedbacktrue'] ?? '');
        $form->feedbackfalse = self::editor_field($definition['feedbackfalse'] ?? '');
    }

    /**
     * Fill in the multiple-choice specific fields.
     *
     * Fractions are taken from the answers themselves when given; otherwise
     * the ones flagged correct share the full mark between them, which is what
     * a multi-answer question needs to add up to 100%.
     *
     * @param \stdClass $form
     * @param array $definition
     * @return void
     */
    private static function apply_multichoice(\stdClass $form, array $definition): void {
        $answers = is_array($definition['answers'] ?? null) ? $definition['answers'] : [];

        $correctcount = 0;
        foreach ($answers as $answer) {
            if (is_array($answer) && !empty($answer['correct'])) {
                $correctcount++;
            }
        }
        $single = array_key_exists('single', $definition)
            ? (bool)$definition['single']
            : $correctcount <= 1;

        $form->single = $single ? 1 : 0;
        $form->shuffleanswers = array_key_exists('shuffleanswers', $definition)
            ? (int)(bool)$definition['shuffleanswers'] : 1;
        $form->answernumbering = (string)($definition['answernumbering'] ?? 'abc');
        $form->showstandardinstruction = 0;
        $form->correctfeedback = self::editor_field($definition['correctfeedback'] ?? '');
        $form->partiallycorrectfeedback = self::editor_field($definition['partiallycorrectfeedback'] ?? '');
        $form->incorrectfeedback = self::editor_field($definition['incorrectfeedback'] ?? '');
        $form->shownumcorrect = 0;

        $form->answer = [];
        $form->fraction = [];
        $form->feedback = [];
        foreach (array_values($answers) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $iscorrect = !empty($answer['correct']);
            if (array_key_exists('fraction', $answer)) {
                $fraction = (float)$answer['fraction'];
            } else if (!$iscorrect) {
                $fraction = 0.0;
            } else if ($single || $correctcount <= 1) {
                $fraction = 1.0;
            } else {
                $fraction = round(1 / $correctcount, 7);
            }

            $form->answer[] = self::editor_field($answer['text'] ?? '');
            $form->fraction[] = $fraction;
            $form->feedback[] = self::editor_field($answer['feedback'] ?? '');
        }
    }

    /**
     * Recompute the quiz total after adding questions.
     *
     * @param \stdClass $quiz
     * @return void
     */
    private static function recalculate_grades(\stdClass $quiz): void {
        global $DB;

        $sumgrades = $DB->get_field_sql(
            'SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            [$quiz->id]
        );
        $quiz->sumgrades = (float)$sumgrades;
        if (empty($quiz->grade)) {
            $quiz->grade = $quiz->sumgrades;
        }
        $DB->set_field('quiz', 'sumgrades', $quiz->sumgrades, ['id' => $quiz->id]);

        if (function_exists('quiz_update_sumgrades')) {
            quiz_update_sumgrades($quiz);
        }
        if (function_exists('quiz_update_grades')) {
            quiz_update_grades($quiz);
        }
    }

    /**
     * Shape a value the way Moodle's editor form elements deliver it.
     *
     * @param mixed $value Either plain text or an array with text/format.
     * @return array
     */
    private static function editor_field($value): array {
        if (is_array($value)) {
            return [
                'text' => (string)($value['text'] ?? ''),
                'format' => isset($value['format']) ? (int)$value['format'] : FORMAT_HTML,
                'itemid' => 0,
            ];
        }
        return ['text' => (string)$value, 'format' => FORMAT_HTML, 'itemid' => 0];
    }

    /**
     * Trim a plain-text field to a safe length.
     *
     * @param mixed $value
     * @param int $maxlength
     * @return string
     */
    private static function text($value, int $maxlength): string {
        $clean = trim(clean_param((string)$value, PARAM_TEXT));
        return \core_text::substr($clean, 0, $maxlength);
    }

    /**
     * Author recorded on the questions.
     *
     * @return int
     */
    private static function actor_id(): int {
        global $USER;
        return !empty($USER->id) ? (int)$USER->id : 0;
    }
}
