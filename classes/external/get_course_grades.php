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
 * Course grades for one page of learners, as each learner sees them.
 *
 * gradereport_user_get_grade_items builds the user report for every graded
 * user in one call — about 48 ms each, so a course of a few thousand learners
 * never finishes within the backend's timeout. It also builds the report as the
 * caller, and the service user may see hidden grades.
 *
 * This builds the same report, with the same class, for one page of learners
 * and with viewasuser set: hidden items and grades, including hidden-until,
 * are decided by what the learner may see, exactly as on their own report. The
 * answer has the shape of get_grade_items, so the backend's parser stays, plus
 * total_learners. Item names are formatted in the course context the way the
 * learner reads them; core returns them raw.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\learner_page;
use core_external\external_api;
use core_external\external_format_value;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * External function block_alphabees_get_course_grades.
 */
class get_course_grades extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset into the learners, ordered by user id.', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Learners per page; 0 for all.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The user report of every learner on the page.
     *
     * @param int $courseid
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function execute(int $courseid, int $limitfrom = 0, int $limitnum = 0): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/user/lib.php');

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);

        // Totals marked for regrading would otherwise come out stale. Core's
        // own get_grade_items regrades unconditionally; this only when needed.
        grade_regrade_final_grades_if_required($course);

        $fields = 'u.id, u.idnumber, ' . implode(', ', array_map(
            static fn(string $f): string => 'u.' . $f,
            \core_user\fields::get_name_fields()
        ));
        $users = learner_page::users($context, $params['limitfrom'], $params['limitnum'], $fields);

        // On the course page as a neutral viewer, so item names come out as the
        // learner reads them; the report itself decides visibility by the
        // learner (viewasuser), not by whoever is acting.
        $usergrades = \block_alphabees\local\learner_view::on_course_page(
            $course,
            static fn(): array => self::reports($course, $context, $users)
        );

        return [
            'usergrades' => $usergrades,
            'total_learners' => learner_page::total($context),
            'warnings' => [],
        ];
    }

    /**
     * Build the user report of each learner, as they see it.
     *
     * @param \stdClass $course
     * @param \context_course $context
     * @param \stdClass[] $users
     * @return array
     */
    private static function reports(\stdClass $course, \context_course $context, array $users): array {
        $usergrades = [];
        foreach ($users as $user) {
            $gpr = new \grade_plugin_return([
                'type' => 'report',
                'plugin' => 'user',
                'courseid' => $course->id,
                'userid' => $user->id,
            ]);
            $report = new \gradereport_user\report\user($course->id, $gpr, $context, (int)$user->id, true);
            $report->fill_table();

            $items = [];
            foreach ($report->gradeitemsdata as $item) {
                if (isset($item['itemname']) && $item['itemname'] !== '') {
                    $item['itemname'] = format_string($item['itemname'], true, ['context' => $context, 'escape' => false]);
                }
                $items[] = $item;
            }
            $usergrades[] = [
                'courseid' => (int)$course->id,
                'courseidnumber' => (string)$course->idnumber,
                'userid' => (int)$user->id,
                'userfullname' => fullname($user),
                'useridnumber' => (string)$user->idnumber,
                'maxdepth' => (int)$report->maxdepth,
                'gradeitems' => $items,
            ];
        }
        return $usergrades;
    }

    /**
     * Return definition: get_grade_items' shape plus total_learners.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'usergrades' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'course id'),
                    'courseidnumber' => new external_value(PARAM_TEXT, 'course idnumber'),
                    'userid' => new external_value(PARAM_INT, 'user id'),
                    'userfullname' => new external_value(PARAM_TEXT, 'user fullname'),
                    'useridnumber' => new external_value(\core_user::get_property_type('idnumber'), 'user idnumber'),
                    'maxdepth' => new external_value(PARAM_INT, 'table max depth (needed for printing it)'),
                    'gradeitems' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Grade item id'),
                            'itemname' => new external_value(PARAM_RAW, 'Grade item name, formatted'),
                            'itemtype' => new external_value(PARAM_ALPHA, 'Grade item type'),
                            'itemmodule' => new external_value(PARAM_PLUGIN, 'Grade item module'),
                            'iteminstance' => new external_value(PARAM_INT, 'Grade item instance'),
                            'itemnumber' => new external_value(PARAM_INT, 'Grade item item number'),
                            'idnumber' => new external_value(PARAM_TEXT, 'Grade item idnumber'),
                            'categoryid' => new external_value(PARAM_INT, 'Grade item category id'),
                            'outcomeid' => new external_value(PARAM_INT, 'Outcome id'),
                            'scaleid' => new external_value(PARAM_INT, 'Scale id'),
                            'locked' => new external_value(PARAM_BOOL, 'Grade item for user locked?', VALUE_OPTIONAL),
                            'cmid' => new external_value(PARAM_INT, 'Course module id (if type mod)', VALUE_OPTIONAL),
                            'weightraw' => new external_value(PARAM_FLOAT, 'Weight raw', VALUE_OPTIONAL),
                            'weightformatted' => new external_value(PARAM_NOTAGS, 'Weight', VALUE_OPTIONAL),
                            'status' => new external_value(PARAM_ALPHA, 'Status', VALUE_OPTIONAL),
                            'graderaw' => new external_value(PARAM_FLOAT, 'Grade raw', VALUE_OPTIONAL),
                            'gradedatesubmitted' => new external_value(PARAM_INT, 'Grade submit date', VALUE_OPTIONAL),
                            'gradedategraded' => new external_value(PARAM_INT, 'Grade graded date', VALUE_OPTIONAL),
                            'gradehiddenbydate' => new external_value(PARAM_BOOL, 'Grade hidden by date?', VALUE_OPTIONAL),
                            'gradeneedsupdate' => new external_value(PARAM_BOOL, 'Grade needs update?', VALUE_OPTIONAL),
                            'gradeishidden' => new external_value(PARAM_BOOL, 'Grade is hidden?', VALUE_OPTIONAL),
                            'gradeislocked' => new external_value(PARAM_BOOL, 'Grade is locked?', VALUE_OPTIONAL),
                            'gradeisoverridden' => new external_value(PARAM_BOOL, 'Grade overridden?', VALUE_OPTIONAL),
                            'gradeformatted' => new external_value(PARAM_RAW, 'The grade formatted', VALUE_OPTIONAL),
                            'grademin' => new external_value(PARAM_FLOAT, 'Grade min', VALUE_OPTIONAL),
                            'grademax' => new external_value(PARAM_FLOAT, 'Grade max', VALUE_OPTIONAL),
                            'rangeformatted' => new external_value(PARAM_NOTAGS, 'Range formatted', VALUE_OPTIONAL),
                            'percentageformatted' => new external_value(PARAM_NOTAGS, 'Percentage', VALUE_OPTIONAL),
                            'lettergradeformatted' => new external_value(PARAM_NOTAGS, 'Letter grade', VALUE_OPTIONAL),
                            'rank' => new external_value(PARAM_INT, 'Rank in the course', VALUE_OPTIONAL),
                            'numusers' => new external_value(PARAM_INT, 'Num users in course', VALUE_OPTIONAL),
                            'averageformatted' => new external_value(PARAM_NOTAGS, 'Grade average', VALUE_OPTIONAL),
                            'feedback' => new external_value(PARAM_RAW, 'Grade feedback', VALUE_OPTIONAL),
                            'feedbackformat' => new external_format_value('feedback', VALUE_OPTIONAL),
                        ], 'Grade items')
                    ),
                ])
            ),
            'total_learners' => new external_value(PARAM_INT, 'Learners across all pages.'),
            'warnings' => new external_warnings(),
        ]);
    }
}
