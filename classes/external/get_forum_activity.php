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
 * Data-minimised forum activity metadata for one user.
 *
 * Returns ONLY counts and timestamps per forum — never post content,
 * subjects, or author names. Built so the Alphabees backend can answer
 * "was there activity in your course forums this week?" without any
 * personal data of other participants leaving Moodle.
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
 * External function block_alphabees_get_forum_activity.
 */
class get_forum_activity extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User whose visible forums are inspected.'),
            'since' => new external_value(
                PARAM_INT,
                'Unix timestamp; only posts created at or after this time are counted. 0 counts all posts.',
                VALUE_DEFAULT,
                0
            ),
            'onlysubscribed' => new external_value(
                PARAM_BOOL,
                'Return only forums the user is subscribed to (incl. forced subscriptions).',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Collect per-forum activity metadata for the forums the user can see.
     *
     * @param int $userid
     * @param int $since
     * @param bool $onlysubscribed
     * @return array
     */
    public static function execute(int $userid, int $since = 0, bool $onlysubscribed = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'since' => $since,
            'onlysubscribed' => $onlysubscribed,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/alphabees:usewebservice', $context);

        // Ensure the target user exists and is active.
        \core_user::get_user($params['userid'], '*', MUST_EXIST);

        // Forums are scoped to the user's active enrolments, honouring
        // per-user visibility (hidden activities, group restrictions etc.).
        $courses = enrol_get_users_courses($params['userid'], true, 'id, fullname');
        $forummeta = [];
        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id, $params['userid']);
            foreach ($modinfo->get_instances_of('forum') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $forummeta[(int)$cm->instance] = [
                    'forumid' => (int)$cm->instance,
                    'cmid' => (int)$cm->id,
                    'courseid' => (int)$course->id,
                    'coursename' => format_string($course->fullname),
                    'forumname' => $cm->get_formatted_name(),
                ];
            }
        }
        if (empty($forummeta)) {
            return [];
        }

        // Subscription state per forum (forced subscriptions count as subscribed).
        $forums = $DB->get_records_list(
            'forum',
            'id',
            array_keys($forummeta),
            '',
            'id, course, type, forcesubscribe'
        );
        foreach ($forummeta as $forumid => $meta) {
            $subscribed = isset($forums[$forumid])
                && \mod_forum\subscriptions::is_subscribed($params['userid'], $forums[$forumid]);
            $forummeta[$forumid]['subscribed'] = $subscribed;
        }
        if ($params['onlysubscribed']) {
            $forummeta = array_filter($forummeta, static function (array $meta): bool {
                return $meta['subscribed'];
            });
            if (empty($forummeta)) {
                return [];
            }
        }

        // Aggregate counts + timestamps only. Private replies are excluded —
        // they are visible to a limited audience and even their existence
        // should not leak.
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($forummeta), SQL_PARAMS_NAMED, 'f');
        $sql = "SELECT d.forum,
                       COUNT(p.id) AS postssince,
                       COUNT(DISTINCT d.id) AS discussionssince,
                       MAX(p.created) AS lastpostat
                  FROM {forum_discussions} d
                  JOIN {forum_posts} p ON p.discussion = d.id
                 WHERE d.forum $insql
                       AND p.created >= :since
                       AND p.deleted = 0
                       AND p.privatereplyto = 0
              GROUP BY d.forum";
        $activity = $DB->get_records_sql($sql, $inparams + ['since' => $params['since']]);

        $result = [];
        foreach ($forummeta as $forumid => $meta) {
            $row = $activity[$forumid] ?? null;
            $meta['postssince'] = $row ? (int)$row->postssince : 0;
            $meta['discussionssince'] = $row ? (int)$row->discussionssince : 0;
            $meta['lastpostat'] = $row ? (int)$row->lastpostat : 0;
            $result[] = $meta;
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
                'forumid' => new external_value(PARAM_INT, 'Forum instance id.'),
                'cmid' => new external_value(PARAM_INT, 'Course module id.'),
                'courseid' => new external_value(PARAM_INT, 'Course id.'),
                'coursename' => new external_value(PARAM_TEXT, 'Course full name.'),
                'forumname' => new external_value(PARAM_TEXT, 'Forum name.'),
                'subscribed' => new external_value(PARAM_BOOL, 'Whether the user is subscribed to the forum.'),
                'postssince' => new external_value(PARAM_INT, 'Number of posts created at/after "since".'),
                'discussionssince' => new external_value(PARAM_INT, 'Number of discussions with such posts.'),
                'lastpostat' => new external_value(PARAM_INT, 'Unix timestamp of the newest counted post, 0 if none.'),
            ])
        );
    }
}
