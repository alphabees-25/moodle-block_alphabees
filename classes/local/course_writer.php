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
 * Helpers that perform the actual Moodle course / section / module
 * mutations behind the inbound dispatcher's push actions.
 *
 * The dispatcher does authn + parameter validation; this class wraps the
 * core Moodle APIs (create_course, update_course, course_update_section,
 * add_moduleinfo, course_delete_module) so the dispatcher stays thin.
 *
 * Every public method runs inside a DB transaction so a partial failure
 * doesn't leave the course in a half-built state.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Course / section / module writer.
 */
class course_writer {
    /**
     * Module types this writer knows how to fill in properly.
     *
     * Anything outside this list is refused rather than handed to
     * add_moduleinfo() with only the generic fields — that used to produce a
     * module row that exists but is missing whatever its own type needs, which
     * is worse than a clean refusal the caller can fall back from.
     *
     * @var string[]
     */
    public const SUPPORTED_MODNAMES = [
        'page',
        'url',
        'folder',
        'resource',
        'label',
        'h5pactivity',
        'assign',
        'book',
        'forum',
        'glossary',
        'quiz',
    ];

    /**
     * Create a course or update an existing one (matched by id or shortname).
     *
     * @param array $params Course fields. Required: fullname, shortname, category.
     * @return \stdClass Persisted course row.
     * @throws \moodle_exception When required fields are missing.
     */
    public static function upsert_course(array $params): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $existing = null;
        if (!empty($params['id'])) {
            $existing = $DB->get_record('course', ['id' => (int)$params['id']]);
        } else if (!empty($params['shortname'])) {
            $existing = $DB->get_record('course', ['shortname' => (string)$params['shortname']]);
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($existing) {
                $update = (object)array_merge((array)$existing, [
                    'fullname' => isset($params['fullname']) ? (string)$params['fullname'] : $existing->fullname,
                    'shortname' => isset($params['shortname']) ? (string)$params['shortname'] : $existing->shortname,
                    'summary' => isset($params['summary']) ? (string)$params['summary'] : $existing->summary,
                    'summaryformat' => isset($params['summaryformat'])
                        ? (int)$params['summaryformat']
                        : $existing->summaryformat,
                    'format' => isset($params['format']) ? (string)$params['format'] : $existing->format,
                    'category' => isset($params['category']) ? (int)$params['category'] : $existing->category,
                    'visible' => isset($params['visible']) ? (int)(bool)$params['visible'] : $existing->visible,
                ]);
                update_course($update);
                $course = $DB->get_record('course', ['id' => $existing->id], '*', MUST_EXIST);
            } else {
                if (empty($params['fullname']) || empty($params['shortname']) || empty($params['category'])) {
                    throw new \moodle_exception(
                        'invalidparameter',
                        'debug',
                        '',
                        null,
                        'fullname, shortname and category are required to create a course'
                    );
                }
                $data = (object)[
                    'fullname' => (string)$params['fullname'],
                    'shortname' => (string)$params['shortname'],
                    'summary' => isset($params['summary']) ? (string)$params['summary'] : '',
                    'summaryformat' => isset($params['summaryformat']) ? (int)$params['summaryformat'] : FORMAT_HTML,
                    'format' => isset($params['format']) ? (string)$params['format'] : 'topics',
                    'category' => (int)$params['category'],
                    'visible' => isset($params['visible']) ? (int)(bool)$params['visible'] : 1,
                    'numsections' => isset($params['numsections']) ? (int)$params['numsections'] : 1,
                ];
                $course = create_course($data);
            }
            $transaction->allow_commit();
            return $course;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Update one section's name and/or summary HTML, creating the section if needed.
     *
     * @param int $courseid
     * @param int $sectionnum 0-based section number.
     * @param array $fields Optional 'name', 'summary', 'summaryformat', 'visible'.
     * @return \stdClass Persisted course_sections row.
     */
    public static function upsert_section(int $courseid, int $sectionnum, array $fields): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $transaction = $DB->start_delegated_transaction();
        try {
            course_create_sections_if_missing($courseid, $sectionnum);
            $section = $DB->get_record(
                'course_sections',
                ['course' => $courseid, 'section' => $sectionnum],
                '*',
                MUST_EXIST
            );
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $update = [];
            if (array_key_exists('name', $fields)) {
                $update['name'] = (string)$fields['name'];
            }
            if (array_key_exists('summary', $fields)) {
                $update['summary'] = (string)$fields['summary'];
            }
            if (array_key_exists('summaryformat', $fields)) {
                $update['summaryformat'] = (int)$fields['summaryformat'];
            }
            if (array_key_exists('visible', $fields)) {
                $update['visible'] = (int)(bool)$fields['visible'];
            }
            if (!empty($update)) {
                course_update_section($course, $section, $update);
                $section = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
            }
            $transaction->allow_commit();
            return $section;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Add or update a single course module instance.
     *
     * Supports the module types listed in SUPPORTED_MODNAMES. Anything else
     * is refused so the caller can fall back, instead of silently creating a
     * module whose type-specific fields were never filled in.
     *
     * Caller passes either { cmid: int } to update an existing module,
     * or { course, section, modname, name, ... } to create a new one.
     *
     * @param array $params
     * @return \stdClass The created/updated cm row joined with the instance.
     * @throws \moodle_exception
     */
    public static function upsert_module(array $params): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $transaction = $DB->start_delegated_transaction();
        try {
            if (!empty($params['cmid'])) {
                $cm = get_coursemodule_from_id('', (int)$params['cmid'], 0, false, MUST_EXIST);
                self::require_supported_modname((string)$cm->modname);
                $module = self::moduleinfo_for_update($cm, $params);
                $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                update_moduleinfo($cm, $module, $course);
                $cm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
            } else {
                if (empty($params['course']) || empty($params['modname'])) {
                    throw new \moodle_exception(
                        'invalidparameter',
                        'debug',
                        '',
                        null,
                        'course and modname are required to create a module'
                    );
                }
                self::require_supported_modname((string)$params['modname']);
                $course = $DB->get_record('course', ['id' => (int)$params['course']], '*', MUST_EXIST);
                $module = self::moduleinfo_for_create($params);
                $module = add_moduleinfo($module, $course);
                $cm = get_coursemodule_from_id($module->modulename, $module->coursemodule, 0, false, MUST_EXIST);
            }
            self::apply_modtype_extras($cm, $params);
            $transaction->allow_commit();
            return $cm;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Delete a list of course modules, returning the IDs that succeeded.
     *
     * @param int[] $cmids
     * @return int[]
     */
    public static function delete_modules(array $cmids): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $deleted = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($cmids as $cmid) {
                $cmid = (int)$cmid;
                $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }
                course_delete_module($cmid);
                $deleted[] = $cmid;
            }
            $transaction->allow_commit();
            return $deleted;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * List courses joined with category info, paginated.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function list_courses_categorized(int $limit, int $offset): array {
        global $DB;
        $sql = "SELECT c.id, c.shortname, c.fullname, c.category, c.format, c.visible,
                       c.startdate, c.enddate, c.summary, c.summaryformat,
                       cc.name AS category_name, cc.idnumber AS category_idnumber,
                       cc.path AS category_path
                  FROM {course} c
                  LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE c.id > 1
              ORDER BY cc.path ASC, c.id ASC";
        $rows = $DB->get_records_sql($sql, [], $offset, $limit);
        $total = (int)$DB->count_records_select('course', 'id > 1');

        $courses = [];
        foreach ($rows as $r) {
            $courses[] = [
                'id' => (int)$r->id,
                'shortname' => (string)$r->shortname,
                'fullname' => (string)$r->fullname,
                'visible' => (bool)$r->visible,
                'format' => (string)$r->format,
                'summary' => (string)$r->summary,
                'summary_format' => (int)$r->summaryformat,
                'startdate' => $r->startdate ? (int)$r->startdate : null,
                'enddate' => $r->enddate ? (int)$r->enddate : null,
                'category' => [
                    'id' => (int)$r->category,
                    'name' => $r->category_name !== null ? (string)$r->category_name : null,
                    'idnumber' => $r->category_idnumber !== null ? (string)$r->category_idnumber : null,
                    'path' => $r->category_path !== null ? (string)$r->category_path : null,
                ],
            ];
        }

        return [
            'courses' => $courses,
            'count' => count($courses),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($courses)) < $total,
        ];
    }

    /**
     * Build the moduleinfo object expected by add_moduleinfo().
     *
     * @param array $params
     * @return \stdClass
     */
    private static function moduleinfo_for_create(array $params): \stdClass {
        global $DB;
        $modname = (string)$params['modname'];
        $modulerow = $DB->get_record('modules', ['name' => $modname], '*', MUST_EXIST);

        $moduleinfo = (object)[
            'modulename' => $modname,
            'module' => (int)$modulerow->id,
            'course' => (int)$params['course'],
            'section' => isset($params['section']) ? (int)$params['section'] : 0,
            'name' => isset($params['name']) ? (string)$params['name'] : '',
            'visible' => isset($params['visible']) ? (int)(bool)$params['visible'] : 1,
            'visibleoncoursepage' => 1,
            'introeditor' => [
                'text' => isset($params['intro']) ? (string)$params['intro'] : '',
                'format' => isset($params['introformat']) ? (int)$params['introformat'] : FORMAT_HTML,
                'itemid' => 0,
            ],
            'showdescription' => isset($params['showdescription']) ? (int)(bool)$params['showdescription'] : 0,
        ];
        self::apply_modtype_defaults($moduleinfo, $params);
        return $moduleinfo;
    }

    /**
     * Build the moduleinfo object for update_moduleinfo().
     *
     * @param \stdClass $cm Existing course_module row.
     * @param array $params
     * @return \stdClass
     */
    private static function moduleinfo_for_update(\stdClass $cm, array $params): \stdClass {
        global $DB;
        $modname = $cm->modname;
        $instance = $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);
        $moduleinfo = (object)[
            'coursemodule' => (int)$cm->id,
            'modulename' => $modname,
            'instance' => (int)$cm->instance,
            'course' => (int)$cm->course,
            'name' => isset($params['name']) ? (string)$params['name'] : (string)($instance->name ?? ''),
            'visible' => isset($params['visible']) ? (int)(bool)$params['visible'] : (int)$cm->visible,
            'visibleoncoursepage' => 1,
            'introeditor' => [
                'text' => isset($params['intro']) ? (string)$params['intro'] : (string)($instance->intro ?? ''),
                'format' => isset($params['introformat'])
                    ? (int)$params['introformat']
                    : (int)($instance->introformat ?? FORMAT_HTML),
                'itemid' => 0,
            ],
        ];
        self::apply_modtype_defaults($moduleinfo, $params);
        return $moduleinfo;
    }

    /**
     * Refuse a module type this writer cannot fill in completely.
     *
     * @param string $modname
     * @return void
     * @throws \moodle_exception
     */
    private static function require_supported_modname(string $modname): void {
        if (!in_array($modname, self::SUPPORTED_MODNAMES, true)) {
            throw new \moodle_exception('unsupported_modname', 'block_alphabees', '', $modname);
        }
    }

    /**
     * Layer in module-type-specific defaults on the moduleinfo struct.
     *
     * Each modtype has its own required fields (mod_page wants `page` HTML,
     * mod_url wants `externalurl`, etc.). We accept those at the top level
     * of $params and remap to the names add_moduleinfo expects.
     *
     * @param \stdClass $moduleinfo
     * @param array $params
     * @return void
     */
    private static function apply_modtype_defaults(\stdClass $moduleinfo, array $params): void {
        self::require_modtype_library($moduleinfo->modulename);

        switch ($moduleinfo->modulename) {
            case 'page':
                $moduleinfo->page = [
                    'text' => isset($params['content']) ? (string)$params['content'] : '',
                    'format' => isset($params['contentformat']) ? (int)$params['contentformat'] : FORMAT_HTML,
                    'itemid' => 0,
                ];
                $moduleinfo->display = isset($params['display']) ? (int)$params['display'] : RESOURCELIB_DISPLAY_AUTO;
                $moduleinfo->printheading = 1;
                $moduleinfo->printintro = 0;
                $moduleinfo->printlastmodified = 1;
                break;
            case 'url':
                $moduleinfo->externalurl = isset($params['externalurl']) ? (string)$params['externalurl'] : '';
                $moduleinfo->display = isset($params['display']) ? (int)$params['display'] : RESOURCELIB_DISPLAY_AUTO;
                $moduleinfo->printintro = 0;
                break;
            case 'folder':
                $moduleinfo->files = isset($params['files_itemid']) ? (int)$params['files_itemid'] : 0;
                $moduleinfo->display = isset($params['display'])
                    ? (int)$params['display']
                    : FOLDER_DISPLAY_PAGE;
                $moduleinfo->showexpanded = isset($params['showexpanded']) ? (int)$params['showexpanded'] : 1;
                break;
            case 'resource':
                $moduleinfo->files = isset($params['files_itemid']) ? (int)$params['files_itemid'] : 0;
                $moduleinfo->display = isset($params['display']) ? (int)$params['display'] : RESOURCELIB_DISPLAY_AUTO;
                $moduleinfo->showsize = 0;
                $moduleinfo->showtype = 0;
                $moduleinfo->showdate = 0;
                $moduleinfo->printintro = 0;
                break;
            case 'h5pactivity':
                // The packagefile is a draft item id from upload_file;
                // h5pactivity_set_mainfile() moves it into the module's own
                // file area and the H5P framework unpacks it from there.
                $moduleinfo->packagefile = isset($params['files_itemid']) ? (int)$params['files_itemid'] : 0;
                $moduleinfo->displayoptions = isset($params['displayoptions']) ? (int)$params['displayoptions'] : 0;
                $moduleinfo->enabletracking = isset($params['enabletracking'])
                    ? (int)(bool)$params['enabletracking'] : 1;
                $moduleinfo->grademethod = isset($params['grademethod']) ? (int)$params['grademethod'] : 1;
                $moduleinfo->reviewmode = isset($params['reviewmode']) ? (int)$params['reviewmode'] : 1;
                $moduleinfo->grade = isset($params['grade']) ? (int)$params['grade'] : 100;
                break;
            case 'assign':
                $datefields = [
                    'duedate' => 0,
                    'allowsubmissionsfromdate' => 0,
                    'cutoffdate' => 0,
                    'gradingduedate' => 0,
                ];
                foreach ($datefields as $field => $default) {
                    $moduleinfo->{$field} = isset($params[$field]) ? (int)$params[$field] : $default;
                }
                $moduleinfo->submissiondrafts = isset($params['submissiondrafts'])
                    ? (int)(bool)$params['submissiondrafts'] : 0;
                $moduleinfo->requiresubmissionstatement = 0;
                $moduleinfo->sendnotifications = 0;
                $moduleinfo->sendlatenotifications = 0;
                $moduleinfo->sendstudentnotifications = 1;
                $moduleinfo->teamsubmission = 0;
                $moduleinfo->blindmarking = 0;
                $moduleinfo->markingworkflow = 0;
                $moduleinfo->attemptreopenmethod = 'none';
                $moduleinfo->maxattempts = -1;
                $moduleinfo->grade = isset($params['grade']) ? (int)$params['grade'] : 100;
                // Submission plugins read their own prefixed settings; they are
                // set explicitly because a missing one silently disables the
                // plugin rather than falling back to the site default.
                $onlinetext = !isset($params['submission_onlinetext'])
                    || (bool)$params['submission_onlinetext'];
                $moduleinfo->assignsubmission_onlinetext_enabled = (int)$onlinetext;
                $moduleinfo->assignsubmission_onlinetext_wordlimitenabled = 0;
                $moduleinfo->assignsubmission_onlinetext_wordlimit = 0;
                $filesubmission = !empty($params['submission_file']);
                $moduleinfo->assignsubmission_file_enabled = (int)$filesubmission;
                $moduleinfo->assignsubmission_file_maxfiles = isset($params['submission_file_maxfiles'])
                    ? (int)$params['submission_file_maxfiles'] : 1;
                $moduleinfo->assignsubmission_file_maxsizebytes = 0;
                $moduleinfo->assignsubmission_file_filetypes = '';
                $moduleinfo->assignfeedback_comments_enabled = 1;
                break;
            case 'book':
                $moduleinfo->numbering = isset($params['numbering']) ? (int)$params['numbering'] : 1;
                $moduleinfo->navstyle = isset($params['navstyle']) ? (int)$params['navstyle'] : 1;
                $moduleinfo->customtitles = isset($params['customtitles'])
                    ? (int)(bool)$params['customtitles'] : 0;
                break;
            case 'forum':
                $type = isset($params['forumtype']) ? (string)$params['forumtype'] : 'general';
                $moduleinfo->type = in_array($type, ['general', 'qanda', 'eachuser', 'single', 'news'], true)
                    ? $type : 'general';
                $moduleinfo->forcesubscribe = isset($params['forcesubscribe'])
                    ? (int)$params['forcesubscribe'] : FORUM_CHOOSESUBSCRIBE;
                $moduleinfo->trackingtype = FORUM_TRACKING_OPTIONAL;
                $moduleinfo->maxbytes = 0;
                $moduleinfo->maxattachments = 1;
                $moduleinfo->blockperiod = 0;
                $moduleinfo->blockafter = 0;
                $moduleinfo->warnafter = 0;
                $moduleinfo->assessed = 0;
                $moduleinfo->scale = 0;
                $moduleinfo->grade_forum = 0;
                break;
            case 'quiz':
                foreach (['timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0] as $field => $default) {
                    $moduleinfo->{$field} = isset($params[$field]) ? (int)$params[$field] : $default;
                }
                $moduleinfo->overduehandling = 'autosubmit';
                $moduleinfo->graceperiod = 0;
                $moduleinfo->preferredbehaviour = (string)($params['preferredbehaviour'] ?? 'deferredfeedback');
                $moduleinfo->canredoquestions = 0;
                $moduleinfo->attempts = isset($params['attempts']) ? (int)$params['attempts'] : 0;
                $moduleinfo->attemptonlast = 0;
                $moduleinfo->grademethod = isset($params['grademethod']) ? (int)$params['grademethod'] : 1;
                $moduleinfo->decimalpoints = 2;
                $moduleinfo->questiondecimalpoints = -1;
                // Review options are bitmasks over four moments (during,
                // immediately after, later while open, after close). Take the
                // site's own defaults so a quiz we create behaves like one an
                // admin would have made by hand.
                $reviewfields = [
                    'reviewattempt',
                    'reviewcorrectness',
                    'reviewmarks',
                    'reviewspecificfeedback',
                    'reviewgeneralfeedback',
                    'reviewrightanswer',
                    'reviewoverallfeedback',
                ];
                foreach ($reviewfields as $field) {
                    $configured = get_config('quiz', $field);
                    $moduleinfo->{$field} = ($configured === false || $configured === null)
                        ? 69904
                        : (int)$configured;
                }
                $moduleinfo->questionsperpage = isset($params['questionsperpage'])
                    ? (int)$params['questionsperpage'] : 1;
                $moduleinfo->navmethod = 'free';
                $moduleinfo->shuffleanswers = isset($params['shuffleanswers'])
                    ? (int)(bool)$params['shuffleanswers'] : 1;
                $moduleinfo->showuserpicture = 0;
                $moduleinfo->showblocks = 0;
                $moduleinfo->password = '';
                $moduleinfo->subnet = '';
                $moduleinfo->browsersecurity = '-';
                $moduleinfo->delay1 = 0;
                $moduleinfo->delay2 = 0;
                $moduleinfo->allowofflineattempts = 0;
                $moduleinfo->completionattemptsexhausted = 0;
                $moduleinfo->completionminattempts = 0;
                // Filled in once the questions exist.
                $moduleinfo->sumgrades = 0;
                $moduleinfo->grade = isset($params['grade']) ? (float)$params['grade'] : 10.0;
                break;
            case 'glossary':
                $moduleinfo->displayformat = isset($params['displayformat'])
                    ? (string)$params['displayformat'] : 'dictionary';
                $moduleinfo->mainglossary = 0;
                $moduleinfo->globalglossary = 0;
                $moduleinfo->entbypage = 10;
                $moduleinfo->allowduplicatedentries = 0;
                $moduleinfo->allowcomments = 0;
                $moduleinfo->usedynalink = 0;
                $moduleinfo->defaultapproval = 1;
                $moduleinfo->approvaldisplayformat = 'default';
                $moduleinfo->showspecial = 1;
                $moduleinfo->showalphabet = 1;
                $moduleinfo->showall = 1;
                $moduleinfo->editalways = 0;
                $moduleinfo->rsstype = 0;
                $moduleinfo->rssarticles = 0;
                $moduleinfo->assessed = 0;
                $moduleinfo->scale = 0;
                break;
        }
    }

    /**
     * Load the libraries whose constants the per-type defaults reference.
     *
     * RESOURCELIB_DISPLAY_AUTO, FOLDER_DISPLAY_PAGE and the FORUM_* family are
     * defined in libraries Moodle does not load on every request. Referencing
     * one that has not been loaded is a fatal "Undefined constant" in the
     * middle of creating a module — the same failure mode that once aborted
     * the plugin upgrade from db/messages.php.
     *
     * @param string $modname
     * @return void
     */
    private static function require_modtype_library(string $modname): void {
        global $CFG;

        require_once($CFG->libdir . '/resourcelib.php');

        // A module we support may still be absent from a given site, so this
        // is a presence check rather than a plain require.
        $modlib = $CFG->dirroot . '/mod/' . $modname . '/lib.php';
        if (file_exists($modlib)) {
            require_once($modlib);
        }
    }

    /**
     * Write the child rows a module type needs beyond its own instance.
     *
     * Runs inside the same transaction as the module itself, so a book whose
     * chapters fail does not survive as an empty book.
     *
     * @param \stdClass $cm Course module row.
     * @param array $params
     * @return void
     */
    private static function apply_modtype_extras(\stdClass $cm, array $params): void {
        switch ($cm->modname) {
            case 'book':
                self::write_book_chapters($cm, $params);
                break;
            case 'glossary':
                self::write_glossary_entries($cm, $params);
                break;
            case 'forum':
                self::write_forum_discussions($cm, $params);
                break;
            case 'quiz':
                self::write_quiz_questions($cm, $params);
                break;
        }
    }

    /**
     * Add the questions a pushed quiz was meant to contain.
     *
     * An empty quiz is a legitimate thing to create, so questions are only
     * required when the caller actually sent some. If they did and this Moodle
     * cannot drive the question bank, the exception rolls the whole module back
     * rather than leaving an empty quiz behind that looks like a success.
     *
     * @param \stdClass $cm
     * @param array $params questions: [{ qtype, name, questiontext, ... }]
     * @return void
     * @throws \moodle_exception
     */
    private static function write_quiz_questions(\stdClass $cm, array $params): void {
        $questions = $params['questions'] ?? null;
        if (!is_array($questions) || empty($questions)) {
            return;
        }
        quiz_writer::write_questions($cm, $questions);
    }

    /**
     * Replace a book's chapters with the ones supplied.
     *
     * @param \stdClass $cm
     * @param array $params chapters: [{ title, content, subchapter? }]
     * @return void
     */
    private static function write_book_chapters(\stdClass $cm, array $params): void {
        global $DB;
        $chapters = $params['chapters'] ?? null;
        if (!is_array($chapters) || empty($chapters)) {
            return;
        }

        $DB->delete_records('book_chapters', ['bookid' => $cm->instance]);
        $order = 0;
        foreach ($chapters as $chapter) {
            if (!is_array($chapter)) {
                continue;
            }
            $order++;
            $DB->insert_record('book_chapters', (object)[
                'bookid' => (int)$cm->instance,
                'pagenum' => $order,
                'subchapter' => !empty($chapter['subchapter']) ? 1 : 0,
                'title' => clean_param((string)($chapter['title'] ?? ''), PARAM_TEXT),
                'content' => (string)($chapter['content'] ?? ''),
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
                'importsrc' => '',
            ]);
        }
    }

    /**
     * Add glossary entries. Existing entries are left alone.
     *
     * @param \stdClass $cm
     * @param array $params entries: [{ concept, definition }]
     * @return void
     */
    private static function write_glossary_entries(\stdClass $cm, array $params): void {
        global $DB, $USER;
        $entries = $params['entries'] ?? null;
        if (!is_array($entries) || empty($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $concept = trim(clean_param((string)($entry['concept'] ?? ''), PARAM_TEXT));
            $definition = (string)($entry['definition'] ?? '');
            if ($concept === '' || $definition === '') {
                continue;
            }
            if (
                $DB->record_exists('glossary_entries', [
                'glossaryid' => (int)$cm->instance,
                'concept' => $concept,
                ])
            ) {
                continue;
            }
            $DB->insert_record('glossary_entries', (object)[
                'glossaryid' => (int)$cm->instance,
                'userid' => (int)$USER->id,
                'concept' => $concept,
                'definition' => $definition,
                'definitionformat' => FORMAT_HTML,
                'definitiontrust' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
                'teacherentry' => 1,
                'sourceglossaryid' => 0,
                'usedynalink' => 0,
                'casesensitive' => 0,
                'fullmatch' => 0,
                'approved' => 1,
            ]);
        }
    }

    /**
     * Start discussions in a forum. Existing discussions are left alone.
     *
     * @param \stdClass $cm
     * @param array $params discussions: [{ subject, message }]
     * @return void
     */
    private static function write_forum_discussions(\stdClass $cm, array $params): void {
        global $CFG, $DB, $USER;
        $discussions = $params['discussions'] ?? null;
        if (!is_array($discussions) || empty($discussions)) {
            return;
        }
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        foreach ($discussions as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $subject = trim(clean_param((string)($entry['subject'] ?? ''), PARAM_TEXT));
            $message = (string)($entry['message'] ?? '');
            if ($subject === '' || $message === '') {
                continue;
            }
            if (
                $DB->record_exists('forum_discussions', [
                'forum' => (int)$forum->id,
                'name' => $subject,
                ])
            ) {
                continue;
            }
            forum_add_discussion((object)[
                'course' => (int)$cm->course,
                'forum' => (int)$forum->id,
                'name' => $subject,
                'message' => $message,
                'messageformat' => FORMAT_HTML,
                'messagetrust' => 0,
                'attachment' => null,
                'groupid' => -1,
                'mailnow' => 0,
                'userid' => (int)$USER->id,
                'timestart' => 0,
                'timeend' => 0,
                'pinned' => FORUM_DISCUSSION_UNPINNED,
            ]);
        }
    }
}
