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
                    throw new \moodle_exception('invalidparameter', 'debug', '', null,
                        'fullname, shortname and category are required to create a course');
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
            $section = $DB->get_record('course_sections',
                ['course' => $courseid, 'section' => $sectionnum], '*', MUST_EXIST);
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
     * Supports the module types we actually push from the portal:
     * mod_page, mod_url, mod_folder, mod_resource, mod_h5pactivity. Any
     * other modname falls through to a generic add_moduleinfo() call as
     * a best-effort.
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
                $module = self::moduleinfo_for_update($cm, $params);
                $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                update_moduleinfo($cm, $module, $course);
                $cm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
            } else {
                if (empty($params['course']) || empty($params['modname'])) {
                    throw new \moodle_exception('invalidparameter', 'debug', '', null,
                        'course and modname are required to create a module');
                }
                $course = $DB->get_record('course', ['id' => (int)$params['course']], '*', MUST_EXIST);
                $module = self::moduleinfo_for_create($params);
                $module = add_moduleinfo($module, $course);
                $cm = get_coursemodule_from_id($module->modulename, $module->coursemodule, 0, false, MUST_EXIST);
            }
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
        }
    }
}
