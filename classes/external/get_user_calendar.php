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
 * Personal calendar view for one user, fetched with a service token.
 *
 * Core's calendar web services return events relative to the REQUESTING
 * user, which for the Alphabees service user (not enrolled anywhere) is
 * useless. This function rebuilds the personal view of a given user
 * server-side: site events, events of the user's courses and groups, the
 * user's own events, and per-user overrides (e.g. extended assignment
 * deadlines) — the pieces a service token cannot reach via core WS.
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
 * External function block_alphabees_get_user_calendar.
 */
class get_user_calendar extends external_api {
    /** Longest allowed query window in seconds (366 days). */
    private const MAX_RANGE_SECONDS = 366 * DAYSECS;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User whose personal calendar view is built.'),
            'timefrom' => new external_value(PARAM_INT, 'Unix timestamp, start of the window (inclusive).'),
            'timeto' => new external_value(PARAM_INT, 'Unix timestamp, end of the window (inclusive).'),
        ]);
    }

    /**
     * Build the personal calendar view of the given user.
     *
     * @param int $userid
     * @param int $timefrom
     * @param int $timeto
     * @return array
     */
    public static function execute(int $userid, int $timefrom, int $timeto): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'timefrom' => $timefrom,
            'timeto' => $timeto,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/alphabees:usewebservice', $context);

        if ($params['timeto'] <= $params['timefrom']) {
            throw new \invalid_parameter_exception('timeto must be after timefrom.');
        }
        if (($params['timeto'] - $params['timefrom']) > self::MAX_RANGE_SECONDS) {
            throw new \invalid_parameter_exception('Requested window exceeds 366 days.');
        }

        \core_user::get_user($params['userid'], '*', MUST_EXIST);

        require_once($CFG->dirroot . '/calendar/lib.php');
        require_once($CFG->libdir . '/grouplib.php');

        // Scope: the user's active courses (plus site-level events), all
        // groups the user belongs to in those courses, and the user's own
        // events — which is exactly what Moodle shows that user.
        $courses = enrol_get_users_courses($params['userid'], true, 'id, fullname');
        $courseids = array_map('intval', array_keys($courses));
        $courseids[] = SITEID;

        $groupids = [];
        foreach (array_keys($courses) as $courseid) {
            $usergroups = groups_get_user_groups((int)$courseid, $params['userid']);
            foreach (($usergroups[0] ?? []) as $groupid) {
                $groupids[(int)$groupid] = (int)$groupid;
            }
        }

        $events = calendar_get_legacy_events(
            $params['timefrom'],
            $params['timeto'],
            [$params['userid']],
            $groupids ? array_values($groupids) : false,
            $courseids,
            true,
            true
        );

        $result = [];
        foreach ($events as $event) {
            $courseid = (int)($event->courseid ?? 0);
            $result[] = [
                'id' => (int)$event->id,
                'name' => format_string($event->name ?? ''),
                // Plain text, links stripped — enough for "what is due",
                // avoids shipping embedded HTML/files.
                'description' => content_to_text((string)($event->description ?? ''), FORMAT_HTML),
                'eventtype' => (string)($event->eventtype ?? ''),
                'courseid' => $courseid,
                'coursename' => isset($courses[$courseid])
                    ? format_string($courses[$courseid]->fullname)
                    : '',
                'groupid' => (int)($event->groupid ?? 0),
                'modulename' => (string)($event->modulename ?? ''),
                'instance' => (int)($event->instance ?? 0),
                'timestart' => (int)$event->timestart,
                'timeduration' => (int)($event->timeduration ?? 0),
                'location' => format_string((string)($event->location ?? '')),
            ];
        }
        usort($result, static function (array $a, array $b): int {
            return $a['timestart'] <=> $b['timestart'];
        });
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
                'id' => new external_value(PARAM_INT, 'Event id.'),
                'name' => new external_value(PARAM_TEXT, 'Event name.'),
                'description' => new external_value(PARAM_RAW, 'Event description as plain text.'),
                'eventtype' => new external_value(PARAM_TEXT, 'site | course | group | user | category or module-specific type.'),
                'courseid' => new external_value(PARAM_INT, 'Course id, 0 for non-course events.'),
                'coursename' => new external_value(PARAM_TEXT, 'Course full name, empty for non-course events.'),
                'groupid' => new external_value(PARAM_INT, 'Group id, 0 unless a group event.'),
                'modulename' => new external_value(
                    PARAM_TEXT,
                    'Activity module type (assign, quiz, ...), empty for manual events.'
                ),
                'instance' => new external_value(PARAM_INT, 'Activity instance id, 0 for manual events.'),
                'timestart' => new external_value(PARAM_INT, 'Unix timestamp of the event start.'),
                'timeduration' => new external_value(PARAM_INT, 'Duration in seconds.'),
                'location' => new external_value(PARAM_TEXT, 'Event location.'),
            ])
        );
    }
}
