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
 * The readable text of one activity, as one learner may read it.
 *
 * Core's web services give the backend a lesson, a book or a quiz as its
 * description only. This reads what the learner has open — a lesson's content
 * pages, a book's chapters, a page's body — decided for that learner, and
 * answers with a fixed code instead of an empty text when there is nothing to
 * read. Never read: quiz questions, answers, model solutions, grades, other
 * people's submissions, forum posts.
 *
 * Must be called with $USER set to the learner, so filters resolve for them
 * and never with the service account's data.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Reads activity text for the inbound action get_activity_text.
 */
class activity_text {
    /** Default and upper bound for the returned text, in characters. */
    public const DEFAULT_CHARS = 12000;

    /** Upper bound a caller may ask for. */
    public const MAX_CHARS = 60000;

    /** lesson_pages.qtype of a content page (LESSON_PAGE_BRANCHTABLE). */
    private const LESSON_CONTENT_PAGE = 20;

    /** Lesson page types that are structure, not questions (end of branch, cluster, end of cluster). */
    private const LESSON_STRUCTURE_PAGES = [21, 30, 31];

    /** Types whose content only exists once the browser has rendered it. */
    private const RENDERED_ONLY = ['h5pactivity', 'hvp', 'scorm', 'lti', 'resource', 'imscp', 'pdfannotator'];

    /** Types read as their description only. Quiz and assignment: never questions or solutions. */
    private const INTRO_ONLY = ['quiz', 'assign', 'label', 'url', 'folder'];

    /**
     * Read the text of a course module for a learner.
     *
     * @param int $cmid
     * @param int $userid The learner; must be the current $USER.
     * @param int $pageid Lesson page, 0 for all readable content pages.
     * @param int $chapterid Book chapter, 0 for all visible chapters.
     * @param int $maxchars
     * @return array Success: cmid, modname, name, part?, content_text, truncated.
     *         Refusal: error, retryable, and cmid/modname where they may be told.
     */
    public static function read(int $cmid, int $userid, int $pageid = 0, int $chapterid = 0, int $maxchars = 0): array {
        $maxchars = $maxchars > 0 ? min($maxchars, self::MAX_CHARS) : self::DEFAULT_CHARS;

        $record = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$record) {
            return self::refuse('module_not_found', ['cmid' => $cmid]);
        }
        $cm = get_fast_modinfo((int)$record->course, $userid)->cms[$cmid] ?? null;
        if (!$cm) {
            return self::refuse('module_not_found', ['cmid' => $cmid]);
        }
        if (!$cm->uservisible) {
            // No name and no type: the learner may not know what is there.
            return self::refuse('not_visible_for_user', ['cmid' => $cmid]);
        }

        $modname = (string)$cm->modname;
        $about = ['cmid' => $cmid, 'modname' => $modname];
        if (in_array($modname, self::RENDERED_ONLY, true)) {
            return self::refuse('rendered_only', $about);
        }

        $context = \context_module::instance($cmid);
        switch ($modname) {
            case 'page':
                $result = self::page($cm, $context);
                break;
            case 'lesson':
                $result = self::lesson($cm, $context, $userid, $pageid);
                break;
            case 'book':
                $result = self::book($cm, $context, $userid, $chapterid);
                break;
            default:
                if (!in_array($modname, self::INTRO_ONLY, true)) {
                    return self::refuse('not_supported', $about);
                }
                if ($modname === 'quiz' && self::quiz_attempt_running((int)$cm->instance, $userid)) {
                    return self::refuse('assessment_running', $about);
                }
                $result = ['part' => ['kind' => 'description'], 'html' => self::intro($cm, $context)];
        }
        if (isset($result['error'])) {
            return self::refuse($result['error'], $about);
        }

        $text = self::to_text($result['html']);
        if ($text === '') {
            return self::refuse('empty', $about);
        }
        $truncated = \core_text::strlen($text) > $maxchars;
        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'name' => clean_param((string)$cm->name, PARAM_TEXT),
            'part' => $result['part'],
            'content_text' => $truncated ? \core_text::substr($text, 0, $maxchars) : $text,
            'truncated' => $truncated,
        ];
    }

    /**
     * A refusal: always a code, never an empty text.
     *
     * @param string $error
     * @param array $about cmid and, where it may be told, modname.
     * @return array
     */
    private static function refuse(string $error, array $about): array {
        return ['error' => $error] + $about + ['retryable' => false];
    }

    /**
     * The activity's description, filtered for the current user.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return string HTML.
     */
    private static function intro(\cm_info $cm, \context_module $context): string {
        global $DB;
        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance], 'id, intro, introformat', IGNORE_MISSING);
        if (!$instance || trim((string)$instance->intro) === '') {
            return '';
        }
        return self::format((string)$instance->intro, (int)$instance->introformat, $context);
    }

    /**
     * Filter a stored text in the module's context.
     *
     * @param string $text
     * @param int $format
     * @param \context_module $context
     * @return string HTML.
     */
    private static function format(string $text, int $format, \context_module $context): string {
        return format_text($text, $format, ['context' => $context, 'noclean' => false, 'para' => false]);
    }

    /**
     * A text page: description and body.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @return array
     */
    private static function page(\cm_info $cm, \context_module $context): array {
        global $DB;
        $page = $DB->get_record('page', ['id' => $cm->instance], 'id, content, contentformat', IGNORE_MISSING);
        $html = self::intro($cm, $context);
        if ($page && trim((string)$page->content) !== '') {
            $html .= self::format((string)$page->content, (int)$page->contentformat, $context);
        }
        return ['part' => ['kind' => 'page'], 'html' => $html];
    }

    /**
     * A lesson: the content page named, or every content page the learner can
     * have reached. Question pages are never read.
     *
     * Reachable means: in the lesson's own order, before the first question
     * page the learner has not answered yet. A lesson without questions is
     * readable in full. People who manage the lesson read everything.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param int $userid
     * @param int $pageid
     * @return array
     */
    private static function lesson(\cm_info $cm, \context_module $context, int $userid, int $pageid): array {
        global $DB;
        $lesson = $DB->get_record('lesson', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if (!$lesson) {
            return ['error' => 'empty'];
        }
        $manages = has_capability('mod/lesson:manage', $context, $userid);
        if (!$manages) {
            $now = time();
            $closed = (!empty($lesson->available) && $now < (int)$lesson->available)
                || (!empty($lesson->deadline) && $now > (int)$lesson->deadline);
            // A password or a prerequisite lesson is a gate we cannot see the
            // learner pass, so it stays shut.
            if ($closed || !empty($lesson->usepassword) || !empty($lesson->dependency)) {
                return ['error' => 'not_visible_for_user'];
            }
            if (!empty($lesson->timelimit)) {
                return ['error' => 'assessment_running'];
            }
        }

        $fields = 'id, prevpageid, nextpageid, qtype, title, contents, contentsformat';
        $pages = $DB->get_records('lesson_pages', ['lessonid' => $lesson->id], '', $fields);
        $ordered = [];
        $next = 0;
        foreach ($pages as $page) {
            if ((int)$page->prevpageid === 0) {
                $next = (int)$page->id;
                break;
            }
        }
        while ($next && isset($pages[$next]) && !isset($ordered[$next])) {
            $ordered[$next] = $pages[$next];
            $next = (int)$pages[$next]->nextpageid;
        }

        $answered = $manages ? [] : $DB->get_records_menu(
            'lesson_attempts',
            ['lessonid' => $lesson->id, 'userid' => $userid],
            '',
            'pageid, id'
        );
        $readable = [];
        foreach ($ordered as $id => $page) {
            $qtype = (int)$page->qtype;
            if ($qtype === self::LESSON_CONTENT_PAGE) {
                $readable[$id] = $page;
            } else if (!$manages && !in_array($qtype, self::LESSON_STRUCTURE_PAGES, true) && !isset($answered[$id])) {
                // An open question: what follows has not been reached.
                break;
            }
        }

        if ($pageid) {
            if (!isset($pages[$pageid])) {
                return ['error' => 'empty'];
            }
            if (!isset($readable[$pageid])) {
                // A question page, or a page behind an open question.
                $iscontent = (int)$pages[$pageid]->qtype === self::LESSON_CONTENT_PAGE;
                return ['error' => $iscontent ? 'not_visible_for_user' : 'not_supported'];
            }
            $page = $readable[$pageid];
            return [
                'part' => ['kind' => 'lesson_page', 'id' => $pageid, 'title' => clean_param((string)$page->title, PARAM_TEXT)],
                'html' => self::format((string)$page->contents, (int)$page->contentsformat, $context),
            ];
        }

        $html = self::intro($cm, $context);
        foreach ($readable as $page) {
            $html .= self::heading((string)$page->title)
                . self::format((string)$page->contents, (int)$page->contentsformat, $context);
        }
        return ['part' => ['kind' => 'lesson', 'pages' => count($readable)], 'html' => $html];
    }

    /**
     * A book: the chapter named, or all chapters the learner sees.
     *
     * @param \cm_info $cm
     * @param \context_module $context
     * @param int $userid
     * @param int $chapterid
     * @return array
     */
    private static function book(\cm_info $cm, \context_module $context, int $userid, int $chapterid): array {
        global $DB;
        $conditions = ['bookid' => $cm->instance];
        if (!has_capability('mod/book:viewhiddenchapters', $context, $userid)) {
            $conditions['hidden'] = 0;
        }
        $chapters = $DB->get_records('book_chapters', $conditions, 'pagenum ASC', 'id, title, content, contentformat');

        if ($chapterid) {
            if (!isset($chapters[$chapterid])) {
                $exists = $DB->record_exists('book_chapters', ['id' => $chapterid, 'bookid' => $cm->instance]);
                return ['error' => $exists ? 'not_visible_for_user' : 'empty'];
            }
            $chapter = $chapters[$chapterid];
            return [
                'part' => [
                    'kind' => 'book_chapter',
                    'id' => $chapterid,
                    'title' => clean_param((string)$chapter->title, PARAM_TEXT),
                ],
                'html' => self::format((string)$chapter->content, (int)$chapter->contentformat, $context),
            ];
        }

        $html = self::intro($cm, $context);
        foreach ($chapters as $chapter) {
            $html .= self::heading((string)$chapter->title)
                . self::format((string)$chapter->content, (int)$chapter->contentformat, $context);
        }
        return ['part' => ['kind' => 'book', 'chapters' => count($chapters)], 'html' => $html];
    }

    /**
     * A page or chapter title as a Markdown heading. Not an <h2>:
     * html_to_text() would print that in capitals.
     *
     * @param string $title
     * @return string HTML.
     */
    private static function heading(string $title): string {
        return '<p>## ' . s(clean_param($title, PARAM_TEXT)) . '</p>';
    }

    /**
     * Whether the learner is in the middle of a quiz attempt.
     *
     * @param int $quizid
     * @param int $userid
     * @return bool
     */
    private static function quiz_attempt_running(int $quizid, int $userid): bool {
        global $DB;
        return $DB->record_exists('quiz_attempts', ['quiz' => $quizid, 'userid' => $userid, 'state' => 'inprogress']);
    }

    /**
     * HTML to plain text, tables as Markdown tables.
     *
     * @param string $html
     * @return string
     */
    public static function to_text(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $tables = [];
        $html = preg_replace_callback('#<table\b.*?</table>#is', function (array $m) use (&$tables): string {
            $key = 'ABTABLE' . count($tables) . 'ELBATBA';
            $tables[$key] = self::table_to_markdown($m[0]);
            return '<p>' . $key . '</p>';
        }, $html);

        $text = html_to_text((string)$html, 0, false);
        $text = strtr($text, $tables);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string)$text);
    }

    /**
     * One HTML table as a Markdown table.
     *
     * @param string $table
     * @return string
     */
    private static function table_to_markdown(string $table): string {
        $lines = [];
        preg_match_all('#<tr\b.*?</tr>#is', $table, $rows);
        foreach ($rows[0] as $index => $row) {
            preg_match_all('#<t[hd]\b[^>]*>(.*?)</t[hd]>#is', $row, $cells);
            $values = array_map(function (string $cell): string {
                $value = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return str_replace('|', '\\|', trim(preg_replace('/\s+/u', ' ', $value)));
            }, $cells[1]);
            if (!$values) {
                continue;
            }
            $lines[] = '| ' . implode(' | ', $values) . ' |';
            if ($index === 0) {
                $lines[] = '|' . str_repeat(' --- |', count($values));
            }
        }
        return "\n" . implode("\n", $lines) . "\n";
    }
}
