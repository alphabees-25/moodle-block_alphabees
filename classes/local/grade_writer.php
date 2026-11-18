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
 * Results of tutor work written into the Moodle gradebook.
 *
 * Alphabees writes only into grade items of its own: manual items whose
 * idnumber is `alphabees:<ref>`, one per exercise the backend runs. Teacher
 * items, quiz and assignment grades are out of reach by construction — they
 * are never looked up here.
 *
 * Two rules protect what people did in the gradebook. A grade a teacher
 * changed by hand is not overwritten unless the caller explicitly says so, and
 * a locked item or grade is never written. Both are reported per learner, so
 * the backend can tell the teacher instead of retrying.
 *
 * write() takes any grade_item, so an Alphabees activity module can later grade
 * through the same rules into its own item; ensure_item() is for the manual
 * items the block owns.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Creates Alphabees grade items and writes learners' grades into them.
 */
class grade_writer {
    /** Prefix of the idnumber of every grade item Alphabees owns. */
    public const IDPREFIX = 'alphabees:';

    /** Source recorded in the grade history. */
    public const SOURCE = 'block_alphabees';

    /** Upper bound for a maximum grade, against typos like 1000000. */
    private const MAX_GRADEMAX = 10000;

    /**
     * The idnumber for a reference, or null when the reference is unusable.
     *
     * @param string $ref The backend's name for the exercise.
     * @return string|null
     */
    public static function idnumber(string $ref): ?string {
        $clean = clean_param($ref, PARAM_ALPHANUMEXT);
        if ($clean === '' || $clean !== $ref || strlen($clean) > 200) {
            return null;
        }
        return self::IDPREFIX . $clean;
    }

    /**
     * An Alphabees grade item in a course, if it exists.
     *
     * @param int $courseid
     * @param string $ref
     * @return \grade_item|null
     */
    public static function find_item(int $courseid, string $ref): ?\grade_item {
        $idnumber = self::idnumber($ref);
        if ($idnumber === null) {
            return null;
        }
        $item = \grade_item::fetch(['courseid' => $courseid, 'idnumber' => $idnumber, 'itemtype' => 'manual']);
        return $item ?: null;
    }

    /**
     * All Alphabees grade items in a course.
     *
     * @param int $courseid
     * @return \grade_item[]
     */
    public static function items(int $courseid): array {
        global $DB;
        $select = 'courseid = :courseid AND itemtype = :itemtype AND ' . $DB->sql_like('idnumber', ':prefix');
        $records = $DB->get_records_select('grade_items', $select, [
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'prefix' => $DB->sql_like_escape(self::IDPREFIX) . '%',
        ], 'sortorder ASC');
        $items = [];
        foreach ($records as $record) {
            $items[] = new \grade_item($record, false);
        }
        return $items;
    }

    /**
     * Find or create the grade item for an exercise, and bring it up to date.
     *
     * @param int $courseid
     * @param string $ref
     * @param array $definition name (required to create), grademax, gradepass,
     *        category (name, found or created), hidden. Absent keys leave the
     *        stored value alone.
     * @return \grade_item
     * @throws \invalid_parameter_exception On an unusable reference or definition.
     */
    public static function ensure_item(int $courseid, string $ref, array $definition): \grade_item {
        $idnumber = self::idnumber($ref);
        if ($idnumber === null) {
            throw new \invalid_parameter_exception('ref must be letters, digits, - and _ only, at most 200');
        }

        $name = isset($definition['name']) ? trim(clean_param((string)$definition['name'], PARAM_TEXT)) : null;
        $grademax = isset($definition['grademax']) ? (float)$definition['grademax'] : null;
        $gradepass = isset($definition['gradepass']) ? (float)$definition['gradepass'] : null;
        if ($grademax !== null && ($grademax <= 0 || $grademax > self::MAX_GRADEMAX)) {
            throw new \invalid_parameter_exception('grademax must be above 0 and at most ' . self::MAX_GRADEMAX);
        }

        $item = self::find_item($courseid, $ref);
        $created = false;
        if (!$item) {
            if ($name === null || $name === '') {
                throw new \invalid_parameter_exception('name is required to create a grade item');
            }
            $item = new \grade_item([
                'courseid' => $courseid,
                'itemtype' => 'manual',
                'itemname' => $name,
                'idnumber' => $idnumber,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademin' => 0,
                'grademax' => $grademax ?? 100,
            ], false);
            $item->insert(self::SOURCE);
            $created = true;
        }

        $max = $grademax ?? (float)$item->grademax;
        if ($gradepass !== null && ($gradepass < 0 || $gradepass > $max)) {
            throw new \invalid_parameter_exception('gradepass must be between 0 and grademax');
        }

        $changed = false;
        if (!$created && $name !== null && $name !== '' && $name !== $item->itemname) {
            $item->itemname = $name;
            $changed = true;
        }
        if (!$created && $grademax !== null && (float)$item->grademax !== $grademax) {
            $item->grademax = $grademax;
            $changed = true;
        }
        if ($gradepass !== null && (float)$item->gradepass !== $gradepass) {
            $item->gradepass = $gradepass;
            $changed = true;
        }
        if ($changed) {
            $item->update(self::SOURCE);
        }

        if (isset($definition['category']) && trim((string)$definition['category']) !== '') {
            $category = self::ensure_category($courseid, (string)$definition['category']);
            if ((int)$item->categoryid !== (int)$category->id) {
                $item->set_parent($category->id);
            }
        }
        if (array_key_exists('hidden', $definition) && (bool)$item->hidden !== (bool)$definition['hidden']) {
            $item->set_hidden(!empty($definition['hidden']) ? 1 : 0);
        }
        return $item;
    }

    /**
     * A grade category by name in the course's top category, created if missing.
     *
     * @param int $courseid
     * @param string $name
     * @return \grade_category
     */
    private static function ensure_category(int $courseid, string $name): \grade_category {
        $name = trim(clean_param($name, PARAM_TEXT));
        $category = \grade_category::fetch(['courseid' => $courseid, 'fullname' => $name]);
        if ($category) {
            return $category;
        }
        $category = new \grade_category(['courseid' => $courseid, 'fullname' => $name], false);
        $category->apply_default_settings();
        $category->apply_forced_settings();
        $category->insert(self::SOURCE);
        return $category;
    }

    /**
     * Write learners' grades into a grade item.
     *
     * @param \grade_item $item
     * @param array $grades Each {userid, grade: float|null (null clears),
     *        feedback?: HTML string}.
     * @param int $actorid The account recorded as having made the change.
     * @param bool $overrideteacher Overwrite a grade a teacher changed by hand.
     * @return array One {userid, ok, error?} per entry, in order.
     */
    public static function write(\grade_item $item, array $grades, int $actorid, bool $overrideteacher = false): array {
        global $DB;

        $context = \context_course::instance((int)$item->courseid);
        $results = [];
        foreach ($grades as $entry) {
            $entry = (array)$entry;
            $userid = (int)($entry['userid'] ?? $entry['userId'] ?? $entry['user_id'] ?? 0);
            $refuse = function (string $error) use ($userid): array {
                return ['userid' => $userid, 'ok' => false, 'error' => $error];
            };

            if ($userid <= 0 || !array_key_exists('grade', $entry)) {
                $results[] = $refuse('missing_userid_or_grade');
                continue;
            }
            $grade = $entry['grade'] === null ? null : (float)$entry['grade'];
            if ($grade !== null && ($grade < (float)$item->grademin || $grade > (float)$item->grademax)) {
                $results[] = $refuse('out_of_range');
                continue;
            }
            if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
                $results[] = $refuse('user_not_found');
                continue;
            }
            if (!is_enrolled($context, $userid, '', true)) {
                $results[] = $refuse('not_enrolled');
                continue;
            }
            if ($item->is_locked($userid)) {
                $results[] = $refuse('locked');
                continue;
            }

            $existing = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if (
                !$overrideteacher && $existing && $existing->finalgrade !== null
                && (int)$existing->usermodified !== $actorid
            ) {
                $results[] = $refuse('teacher_modified');
                continue;
            }

            $feedback = array_key_exists('feedback', $entry) && $entry['feedback'] !== null
                ? clean_text((string)$entry['feedback'], FORMAT_HTML)
                : false;
            // A grade of null clears it; update_final_grade() reads false as "leave".
            $ok = $item->update_final_grade($userid, $grade, self::SOURCE, $feedback, FORMAT_HTML, $actorid);
            $results[] = $ok ? ['userid' => $userid, 'ok' => true] : $refuse('not_written');
        }
        return $results;
    }

    /**
     * Describe an item for the backend.
     *
     * @param \grade_item $item
     * @return array
     */
    public static function describe(\grade_item $item): array {
        global $DB;
        $category = $item->get_parent_category();
        return [
            'id' => (int)$item->id,
            'ref' => substr((string)$item->idnumber, strlen(self::IDPREFIX)),
            'name' => (string)$item->itemname,
            'grademax' => (float)$item->grademax,
            'gradepass' => (float)$item->gradepass,
            'hidden' => (bool)$item->hidden,
            'locked' => (bool)$item->locked,
            'category' => ($category && !$category->is_course_category()) ? (string)$category->fullname : '',
            'graded' => $DB->count_records_select('grade_grades', 'itemid = ? AND finalgrade IS NOT NULL', [$item->id]),
        ];
    }
}
