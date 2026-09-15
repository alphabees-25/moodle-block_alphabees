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
 * Structured export of every question (incl. answers) of one quiz.
 *
 * Moodle core exposes quiz metadata (mod_quiz_get_quizzes_by_courses) and
 * attempt data, but no web-service function that returns the question-bank
 * content behind a quiz. The Alphabees backend needs exactly that to feed
 * exercises with their correct answers into the course knowledge base, so
 * this function reads the quiz structure via mod_quiz's qbank_helper and
 * question_bank::load_question() and returns plain JSON — no rendered HTML,
 * no attempt required.
 *
 * Answers and feedback are teacher-only data: callers must hold
 * moodle/question:viewall in the quiz context (the manager role assigned to
 * the alphabees-service user carries it).
 *
 * Coverage by question type:
 *  - answers[]     multichoice, truefalse, shortanswer, numerical,
 *                  calculated*, ordering and any other type exposing
 *                  question_answer objects
 *  - pairs[]       match (stem -> correct choice), plus choices[] with all
 *                  distractors
 *  - choices[] +   gapselect, ddwtos, ddimageortext (choice groups and the
 *    places[]      right choice per placeholder)
 *  - subquestions  multianswer (cloze) — one nested entry per {#n} place
 *  - extra         JSON blob with type-specific settings (essay grader
 *                  info / response template, multichoice single/multi, …)
 *                  and a scalar property dump for unknown types
 *
 * Random slots are reported with qtype "random" and the category filter in
 * extra; the concrete question is only chosen at attempt time.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_quiz\question\bank\qbank_helper;

/**
 * External function block_alphabees_get_quiz_questions.
 */
class get_quiz_questions extends external_api {
    /** Rewrite target for @@PLUGINFILE@@ placeholders — token-downloadable by the WS client. */
    private const FILE_SCRIPT = 'webservice/pluginfile.php';

    /** Question types whose "right answer" is the highest-fraction entry of answers[]. */
    private const RIGHTANSWER_FROM_ANSWERS = ['shortanswer', 'numerical', 'calculated', 'calculatedsimple', 'multichoice'];

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(
                PARAM_INT,
                'Quiz instance id (the "id" field returned by mod_quiz_get_quizzes_by_courses).'
            ),
        ]);
    }

    /**
     * Return the quiz header plus one entry per slot with the full question content.
     *
     * @param int $quizid
     * @return array
     */
    public static function execute(int $quizid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['quizid' => $quizid]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $quizcontext = \context_module::instance($cm->id);

        // Answers + feedback are teacher-only: gate on the question-bank capability
        // in the quiz context rather than on the service capability alone.
        require_capability('moodle/question:viewall', $quizcontext);

        $slots = qbank_helper::get_question_structure((int)$quiz->id, $quizcontext);
        usort($slots, function ($a, $b) {
            return (int)$a->slot <=> (int)$b->slot;
        });

        $questions = [];
        foreach ($slots as $slot) {
            $questions[] = self::shape_slot($slot);
        }

        return [
            'quizid' => (int)$quiz->id,
            'cmid' => (int)$cm->id,
            'courseid' => (int)$quiz->course,
            'name' => (string)$quiz->name,
            'intro' => self::rewrite((string)$quiz->intro, $quizcontext->id, 'mod_quiz', 'intro', null),
            'introformat' => (int)$quiz->introformat,
            'grade' => (float)$quiz->grade,
            'sumgrades' => (float)$quiz->sumgrades,
            'questioncount' => count($questions),
            'questions' => $questions,
        ];
    }

    /**
     * Shape one quiz slot (as returned by qbank_helper::get_question_structure).
     *
     * @param \stdClass $slot
     * @return array
     */
    private static function shape_slot(\stdClass $slot): array {
        $base = [
            'slot' => (int)$slot->slot,
            'page' => (int)$slot->page,
            'maxmark' => (float)$slot->maxmark,
            'requireprevious' => !empty($slot->requireprevious),
        ];

        $qtype = (string)($slot->qtype ?? '');

        if ($qtype === 'random') {
            $q = self::blank_question(false);
            $q['qtype'] = 'random';
            $q['random'] = true;
            $q['name'] = (string)($slot->name ?? '');
            $q['extra'] = json_encode([
                'category' => (int)($slot->category ?? 0),
                'includingsubcategories' => !empty($slot->randomrecurse),
                'tags' => array_values((array)($slot->randomtags ?? [])),
            ]);
            return $base + $q;
        }

        $questionid = $slot->questionid ?? null;
        if ($qtype === '' || $qtype === 'missingtype' || !is_numeric($questionid)) {
            $q = self::blank_question(false);
            $q['qtype'] = 'missingtype';
            $q['name'] = (string)($slot->name ?? '');
            return $base + $q;
        }

        try {
            $question = \question_bank::load_question((int)$questionid);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] get_quiz_questions: could not load question ' . $questionid
                . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $q = self::blank_question(false);
            $q['questionid'] = (int)$questionid;
            $q['qtype'] = $qtype;
            $q['name'] = (string)($slot->name ?? '');
            $q['extra'] = json_encode(['error' => 'load_failed']);
            return $base + $q;
        }

        return $base + self::shape_question($question, false);
    }

    /**
     * All question-level keys with neutral defaults.
     *
     * @param bool $nested True for cloze sub-questions (carries `place`, no `subquestions`).
     * @return array
     */
    private static function blank_question(bool $nested): array {
        $q = [
            'questionid' => 0,
            'questionbankentryid' => 0,
            'version' => 0,
            'qtype' => '',
            'name' => '',
            'questiontext' => '',
            'questiontextformat' => FORMAT_HTML,
            'generalfeedback' => '',
            'defaultmark' => 0.0,
            'penalty' => 0.0,
            'random' => false,
            'rightanswer' => null,
            'answers' => [],
            'choices' => [],
            'pairs' => [],
            'places' => [],
            'hints' => [],
            'extra' => null,
        ];
        if ($nested) {
            $q['place'] = 0;
        } else {
            $q['subquestions'] = [];
        }
        return $q;
    }

    /**
     * Shape a loaded question definition.
     *
     * @param \question_definition $q
     * @param bool $nested True when shaping a cloze sub-question.
     * @param int $place Cloze place number (nested only).
     * @return array
     */
    private static function shape_question(\question_definition $q, bool $nested, int $place = 0): array {
        $ctx = (int)$q->contextid;
        $qid = (int)$q->id;
        $type = (string)$q->get_type_name();

        $out = self::blank_question($nested);
        $out['questionid'] = $qid;
        $out['questionbankentryid'] = (int)($q->questionbankentryid ?? 0);
        $out['version'] = (int)($q->version ?? 0);
        $out['qtype'] = $type;
        $out['name'] = (string)$q->name;
        $out['questiontext'] = self::rewrite((string)$q->questiontext, $ctx, 'question', 'questiontext', $qid);
        $out['questiontextformat'] = (int)$q->questiontextformat;
        $out['generalfeedback'] = self::rewrite((string)$q->generalfeedback, $ctx, 'question', 'generalfeedback', $qid);
        $out['defaultmark'] = (float)$q->defaultmark;
        $out['penalty'] = (float)$q->penalty;
        $out['hints'] = self::shape_hints($q, $ctx);
        if ($nested) {
            $out['place'] = $place;
        }

        $extra = [];

        // Generic answers list — covers every type built on question_answer objects.
        if (!empty($q->answers) && is_iterable($q->answers)) {
            $out['answers'] = self::shape_answers($q->answers, $ctx);
        }

        switch ($type) {
            case 'truefalse':
                $trueid = (int)($q->trueanswerid ?? 0);
                $falseid = (int)($q->falseanswerid ?? 0);
                $right = !empty($q->rightanswer);
                $out['answers'] = [
                    [
                        'id' => $trueid,
                        'text' => get_string('true', 'qtype_truefalse'),
                        'textformat' => FORMAT_PLAIN,
                        'fraction' => $right ? 1.0 : 0.0,
                        'feedback' => self::rewrite((string)($q->truefeedback ?? ''), $ctx, 'question', 'answerfeedback', $trueid),
                        'tolerance' => null,
                    ],
                    [
                        'id' => $falseid,
                        'text' => get_string('false', 'qtype_truefalse'),
                        'textformat' => FORMAT_PLAIN,
                        'fraction' => $right ? 0.0 : 1.0,
                        'feedback' => self::rewrite(
                            (string)($q->falsefeedback ?? ''),
                            $ctx,
                            'question',
                            'answerfeedback',
                            $falseid
                        ),
                        'tolerance' => null,
                    ],
                ];
                $out['rightanswer'] = $right ? 'true' : 'false';
                break;

            case 'multichoice':
                $extra['single'] = is_a($q, 'qtype_multichoice_single_question');
                $extra['shuffleanswers'] = !empty($q->shuffleanswers);
                $extra['answernumbering'] = (string)($q->answernumbering ?? '');
                break;

            case 'match':
                $choices = is_array($q->choices ?? null) ? $q->choices : [];
                foreach ($choices as $key => $text) {
                    $out['choices'][] = ['key' => (string)$key, 'text' => (string)$text, 'group' => 0];
                }
                $stems = is_array($q->stems ?? null) ? $q->stems : [];
                foreach ($stems as $stemid => $stem) {
                    $rightkey = $q->right[$stemid] ?? null;
                    $out['pairs'][] = [
                        'stem' => self::rewrite((string)$stem, $ctx, 'qtype_match', 'subquestion', (int)$stemid),
                        'stemformat' => (int)($q->stemformat[$stemid] ?? FORMAT_HTML),
                        'choice' => $rightkey !== null ? (string)($choices[$rightkey] ?? '') : '',
                    ];
                }
                $extra['shufflestems'] = !empty($q->shufflestems);
                break;

            case 'multianswer':
                if (!$nested && is_array($q->subquestions ?? null)) {
                    foreach ($q->subquestions as $placeno => $sub) {
                        if ($sub instanceof \question_definition) {
                            $out['subquestions'][] = self::shape_question($sub, true, (int)$placeno);
                        }
                    }
                }
                break;

            case 'essay':
                $extra['responseformat'] = (string)($q->responseformat ?? '');
                $extra['responserequired'] = !empty($q->responserequired);
                $extra['minwordlimit'] = isset($q->minwordlimit) ? (int)$q->minwordlimit : null;
                $extra['maxwordlimit'] = isset($q->maxwordlimit) ? (int)$q->maxwordlimit : null;
                $extra['attachments'] = (int)($q->attachments ?? 0);
                $extra['graderinfo'] = self::rewrite((string)($q->graderinfo ?? ''), $ctx, 'qtype_essay', 'graderinfo', $qid);
                $extra['responsetemplate'] = (string)($q->responsetemplate ?? '');
                break;

            case 'numerical':
            case 'calculated':
            case 'calculatedsimple':
                $extra['unitdisplay'] = isset($q->unitdisplay) ? (int)$q->unitdisplay : null;
                $extra['unitgradingtype'] = isset($q->unitgradingtype) ? (int)$q->unitgradingtype : null;
                break;

            case 'ordering':
                foreach (['layouttype', 'selecttype', 'selectcount', 'gradingtype'] as $prop) {
                    if (isset($q->{$prop})) {
                        $extra[$prop] = $q->{$prop};
                    }
                }
                break;

            case 'shortanswer':
                $extra['usecase'] = !empty($q->usecase);
                break;

            default:
                // Unknown / third-party type: dump scalar public properties that
                // the fixed schema does not already cover so nothing is lost.
                $extra['properties'] = self::scalar_properties($q);
                break;
        }

        // Placeholder-based types (gapselect, ddwtos, ddimageortext, …): choice
        // groups + right choice per place. Detected structurally so third-party
        // types built on the same base classes are covered too.
        if (empty($out['choices']) && is_array($q->choices ?? null) && is_array($q->places ?? null)) {
            foreach ($q->choices as $group => $groupchoices) {
                if (!is_iterable($groupchoices)) {
                    continue;
                }
                foreach ($groupchoices as $key => $choice) {
                    $out['choices'][] = [
                        'key' => (string)$key,
                        'text' => self::choice_text($choice),
                        'group' => self::group_number($group),
                    ];
                }
            }
            foreach ($q->places as $placeno => $placegroup) {
                $group = self::group_number(is_object($placegroup) ? ($placegroup->group ?? 0) : $placegroup);
                $rightkey = $q->rightchoices[$placeno] ?? null;
                $righttext = '';
                if ($rightkey !== null && isset($q->choices[$group][$rightkey])) {
                    $righttext = self::choice_text($q->choices[$group][$rightkey]);
                }
                $out['places'][] = [
                    'place' => (int)$placeno,
                    'group' => $group,
                    'rightchoice' => $righttext,
                ];
            }
        }

        if (
            $out['rightanswer'] === null
            && in_array($type, self::RIGHTANSWER_FROM_ANSWERS, true)
            && !empty($out['answers'])
        ) {
            $best = null;
            foreach ($out['answers'] as $answer) {
                if ($best === null || $answer['fraction'] > $best['fraction']) {
                    $best = $answer;
                }
            }
            if ($best !== null && $best['fraction'] > 0) {
                $out['rightanswer'] = trim(html_to_text($best['text'], 0, false));
            }
        }

        if (!empty($extra)) {
            $out['extra'] = json_encode($extra);
        }

        return $out;
    }

    /**
     * Shape question_answer objects.
     *
     * @param iterable $answers
     * @param int $ctx Question context id (file rewriting).
     * @return array
     */
    private static function shape_answers(iterable $answers, int $ctx): array {
        $out = [];
        foreach ($answers as $a) {
            if (!is_object($a)) {
                continue;
            }
            $id = (int)($a->id ?? 0);
            $tolerance = null;
            if (property_exists($a, 'tolerance') && $a->tolerance !== null && $a->tolerance !== '') {
                $tolerance = (float)$a->tolerance;
            }
            $out[] = [
                'id' => $id,
                'text' => self::rewrite((string)($a->answer ?? ''), $ctx, 'question', 'answer', $id),
                'textformat' => (int)($a->answerformat ?? FORMAT_PLAIN),
                'fraction' => (float)($a->fraction ?? 0),
                'feedback' => self::rewrite((string)($a->feedback ?? ''), $ctx, 'question', 'answerfeedback', $id),
                'tolerance' => $tolerance,
            ];
        }
        return $out;
    }

    /**
     * Hint texts of a question.
     *
     * @param \question_definition $q
     * @param int $ctx
     * @return array
     */
    private static function shape_hints(\question_definition $q, int $ctx): array {
        $out = [];
        foreach ((array)($q->hints ?? []) as $hint) {
            if (!is_object($hint) || !isset($hint->hint)) {
                continue;
            }
            $out[] = self::rewrite((string)$hint->hint, $ctx, 'question', 'hint', (int)($hint->id ?? 0));
        }
        return $out;
    }

    /**
     * Text of a choice entry regardless of how the qtype stores it.
     *
     * @param mixed $choice
     * @return string
     */
    private static function choice_text($choice): string {
        if (is_object($choice)) {
            return (string)($choice->text ?? $choice->answer ?? '');
        }
        if (is_array($choice)) {
            return (string)($choice['text'] ?? $choice['answer'] ?? '');
        }
        return (string)$choice;
    }

    /**
     * Normalise a group key to int (0 when not numeric).
     *
     * @param mixed $group
     * @return int
     */
    private static function group_number($group): int {
        return is_numeric($group) ? (int)$group : 0;
    }

    /**
     * Scalar public properties of a question not already covered by the fixed schema.
     *
     * @param \question_definition $q
     * @return array
     */
    private static function scalar_properties(\question_definition $q): array {
        $skip = [
            'id', 'category', 'contextid', 'parent', 'qtype', 'name', 'questiontext', 'questiontextformat',
            'generalfeedback', 'generalfeedbackformat', 'defaultmark', 'length', 'penalty', 'stamp', 'idnumber',
            'timecreated', 'timemodified', 'createdby', 'modifiedby', 'hints', 'status', 'versionid', 'version',
            'questionbankentryid', 'customfields', 'answers',
        ];
        $out = [];
        foreach (get_object_vars($q) as $name => $value) {
            if (in_array($name, $skip, true) || !(is_scalar($value) || $value === null)) {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Rewrite @@PLUGINFILE@@ placeholders to token-downloadable URLs.
     *
     * @param string $text
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int|null $itemid
     * @return string
     */
    private static function rewrite(string $text, int $contextid, string $component, string $filearea, ?int $itemid): string {
        if ($text === '' || strpos($text, '@@PLUGINFILE@@') === false) {
            return $text;
        }
        return file_rewrite_pluginfile_urls($text, self::FILE_SCRIPT, $contextid, $component, $filearea, $itemid);
    }

    // Return structures.

    /**
     * Structure of one answers[] entry.
     *
     * @return external_single_structure
     */
    private static function answer_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Answer id.'),
            'text' => new external_value(PARAM_RAW, 'Answer text (HTML or plain, see textformat).'),
            'textformat' => new external_value(PARAM_INT, 'Text format constant.'),
            'fraction' => new external_value(PARAM_FLOAT, 'Grade fraction 0..1; > 0 means (partially) correct.'),
            'feedback' => new external_value(PARAM_RAW, 'Answer-specific feedback (HTML).'),
            'tolerance' => new external_value(
                PARAM_FLOAT,
                'Numerical tolerance, null for non-numeric types.',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * Question-level fields shared by top-level and nested (cloze) entries.
     *
     * @param bool $nested
     * @return array
     */
    private static function question_fields(bool $nested): array {
        $fields = [
            'questionid' => new external_value(PARAM_INT, 'Question id, 0 for random/missing slots.'),
            'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry id.'),
            'version' => new external_value(PARAM_INT, 'Question version number.'),
            'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type (multichoice, truefalse, match, random, …).'),
            'name' => new external_value(PARAM_RAW, 'Question name.'),
            'questiontext' => new external_value(PARAM_RAW, 'Question text (HTML). Cloze: contains {#n} placeholders.'),
            'questiontextformat' => new external_value(PARAM_INT, 'Text format constant.'),
            'generalfeedback' => new external_value(PARAM_RAW, 'General feedback shown after the question (HTML).'),
            'defaultmark' => new external_value(PARAM_FLOAT, 'Default mark.'),
            'penalty' => new external_value(PARAM_FLOAT, 'Penalty per wrong try.'),
            'random' => new external_value(PARAM_BOOL, 'True for random slots (question chosen at attempt time).'),
            'rightanswer' => new external_value(
                PARAM_RAW,
                'Plain-text right answer where a single one exists (truefalse, shortanswer, numerical, multichoice); '
                . 'null otherwise — see answers/pairs/places.',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
            'answers' => new external_multiple_structure(self::answer_structure(), 'Answer options with fractions.'),
            'choices' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_RAW, 'Choice key within its group.'),
                    'text' => new external_value(PARAM_RAW, 'Choice text.'),
                    'group' => new external_value(PARAM_INT, 'Choice group (0 when the type has no groups).'),
                ]),
                'Selectable choices (match distractors, gapselect/ddwtos groups).'
            ),
            'pairs' => new external_multiple_structure(
                new external_single_structure([
                    'stem' => new external_value(PARAM_RAW, 'Matching stem (HTML).'),
                    'stemformat' => new external_value(PARAM_INT, 'Text format constant.'),
                    'choice' => new external_value(PARAM_RAW, 'Correct choice text for this stem.'),
                ]),
                'Matching pairs (qtype match).'
            ),
            'places' => new external_multiple_structure(
                new external_single_structure([
                    'place' => new external_value(PARAM_INT, 'Placeholder number as used in the question text ([[n]]).'),
                    'group' => new external_value(PARAM_INT, 'Choice group the place draws from.'),
                    'rightchoice' => new external_value(PARAM_RAW, 'Text of the correct choice for this place.'),
                ]),
                'Placeholder slots (gapselect, ddwtos, ddimageortext).'
            ),
            'hints' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Hint text (HTML).'),
                'Hints in order.'
            ),
            'extra' => new external_value(
                PARAM_RAW,
                'JSON string with type-specific settings (essay grader info, multichoice single flag, random '
                . 'category filter, scalar property dump for unknown types); null when nothing to add.',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
        ];

        if ($nested) {
            $fields['place'] = new external_value(PARAM_INT, 'Cloze place number ({#n} in the parent question text).');
        }
        return $fields;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $subquestion = new external_single_structure(self::question_fields(true));

        $question = new external_single_structure(array_merge(
            [
                'slot' => new external_value(PARAM_INT, 'Slot number (1-based order in the quiz).'),
                'page' => new external_value(PARAM_INT, 'Quiz page the slot is on.'),
                'maxmark' => new external_value(PARAM_FLOAT, 'Marks this slot contributes.'),
                'requireprevious' => new external_value(PARAM_BOOL, 'Slot requires the previous one to be completed.'),
            ],
            self::question_fields(false),
            [
                'subquestions' => new external_multiple_structure(
                    $subquestion,
                    'Cloze (multianswer) sub-questions, one per {#n} place; empty for other types.'
                ),
            ]
        ));

        return new external_single_structure([
            'quizid' => new external_value(PARAM_INT, 'Quiz instance id.'),
            'cmid' => new external_value(PARAM_INT, 'Course module id.'),
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
            'name' => new external_value(PARAM_RAW, 'Quiz name.'),
            'intro' => new external_value(PARAM_RAW, 'Quiz description (HTML).'),
            'introformat' => new external_value(PARAM_INT, 'Text format constant.'),
            'grade' => new external_value(PARAM_FLOAT, 'Maximum grade of the quiz.'),
            'sumgrades' => new external_value(PARAM_FLOAT, 'Sum of all slot marks.'),
            'questioncount' => new external_value(PARAM_INT, 'Number of slots returned.'),
            'questions' => new external_multiple_structure($question, 'One entry per slot, in slot order.'),
        ]);
    }
}
