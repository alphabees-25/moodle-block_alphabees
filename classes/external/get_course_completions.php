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
 * Course completion overview for every tracked user of one course.
 *
 * One call per course instead of one core_completion_* call per user.
 * The percentage is computed with the same core helper the Moodle UI uses
 * (\core_completion\progress::get_course_progress_percentage) so numbers
 * match what learners see in their course overview.
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

/**
 * External function block_alphabees_get_course_completions.
 */
class get_course_completions extends external_api {
    /** Completion tracking is disabled for the course. */
    public const STATE_NOTENABLED = 'notenabled';

    /** No activity completed and course not started. */
    public const STATE_NOTSTARTED = 'notstarted';

    /** At least one activity completed or course started, not yet complete. */
    public const STATE_INPROGRESS = 'inprogress';

    /** Course completion criteria met. */
    public const STATE_COMPLETE = 'complete';

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
        ]);
    }

    /**
     * Return completion state for every user tracked in the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        $course = get_course($params['courseid']);
        $coursecontext = \context_course::instance($course->id);

        require_once($CFG->libdir . '/completionlib.php');
        $completion = new \completion_info($course);
        $enabled = $completion->is_enabled();

        // Same population as Moodle's own completion reports: active
        // enrolments holding moodle/course:isincompletionreports (students).
        $users = get_enrolled_users(
            $coursecontext,
            'moodle/course:isincompletionreports',
            0,
            'u.id',
            'u.id ASC',
            0,
            0,
            true
        );
        if (empty($users)) {
            return [];
        }

        // Bulk-load course_completions rows (started / completed timestamps).
        $records = $enabled
            ? $DB->get_records('course_completions', ['course' => $course->id], '', 'userid, timestarted, timecompleted')
            : [];

        $result = [];
        foreach ($users as $user) {
            $userid = (int)$user->id;
            $percent = null;
            $state = self::STATE_NOTENABLED;
            $timestarted = 0;
            $timecompleted = 0;

            if ($enabled) {
                $raw = \core_completion\progress::get_course_progress_percentage($course, $userid);
                $percent = $raw === null ? null : round((float)$raw, 1);

                $row = $records[$userid] ?? null;
                $timestarted = $row ? (int)$row->timestarted : 0;
                $timecompleted = $row ? (int)$row->timecompleted : 0;

                if ($timecompleted > 0 || $completion->is_course_complete($userid)) {
                    $state = self::STATE_COMPLETE;
                } else if (($percent !== null && $percent > 0) || $timestarted > 0) {
                    $state = self::STATE_INPROGRESS;
                } else {
                    $state = self::STATE_NOTSTARTED;
                }
            }

            $result[] = [
                'userid' => $userid,
                'percent' => $percent,
                'state' => $state,
                'timestarted' => $timestarted,
                'timecompleted' => $timecompleted,
            ];
        }
        return $result;
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User id.'),
                'percent' => new external_value(
                    PARAM_FLOAT,
                    'Progress 0..100 as shown in the course overview; null when completion is disabled, '
                    . 'the user is not tracked, or the course has no activities with completion.',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'state' => new external_value(
                    PARAM_ALPHA,
                    'notenabled | notstarted | inprogress | complete'
                ),
                'timestarted' => new external_value(PARAM_INT, 'Unix timestamp the course was started, 0 if unknown.'),
                'timecompleted' => new external_value(PARAM_INT, 'Unix timestamp the course was completed, 0 if not complete.'),
            ])
        );
    }
}
