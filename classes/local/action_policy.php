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
 * Which caller may invoke which action.
 *
 * One registry, two entrances. Every action names the kinds of caller it
 * accepts, and a user-callable action additionally names the capability its
 * caller must hold and the context that capability is checked in.
 *
 * The default is deny: an action absent from both lists cannot be reached at
 * all, and an action listed only for the site entrance is unreachable from a
 * browser session no matter what the session's user is allowed to do
 * elsewhere.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Authorisation policy for the shared action registry.
 */
final class action_policy {
    /** Capability required to reach the console at all. */
    public const CAP_CONSOLE = 'block/alphabees:useconsole';

    /** Capability required to be offered as a notification recipient. */
    public const CAP_NOTIFY = 'block/alphabees:receivenotifications';

    /** Capability required to receive a notification addressed to you as a learner. */
    public const CAP_LEARNER_NOTIFY = 'block/alphabees:receivelearnernotifications';

    /** Capability required to drive the assistant (phase 2). */
    public const CAP_AGENT = 'block/alphabees:useagent';

    /** Capability checked at system level. */
    public const SCOPE_SYSTEM = 'system';

    /** Capability checked in the context of the course named by the params. */
    public const SCOPE_COURSE = 'course';

    /**
     * Actions reachable through a request signed with the site keypair.
     *
     * These run as the plugin's service user. The backend is trusted to have
     * checked its own side; Moodle applies no per-user capability check here,
     * which is exactly why the list is explicit rather than implied.
     *
     * @var string[]
     */
    private const SITE_ACTIONS = [
        'ping',
        'disconnect_site',
        'revoke_registration',
        'pause_site',
        'resume_site',
        'list_placements',
        'update_placement',
        'delete_placement',
        'create_placement',
        'bulk_create_placements',
        'list_courses',
        'list_courses_categorized',
        'upsert_course',
        'upsert_section',
        'upsert_modules',
        'delete_course_modules',
        'set_activity_completion',
        'upload_file',
        'list_h5p_libraries',
        'notify',
    ];

    /**
     * Actions reachable from a signed-in user's browser session.
     *
     * Deliberately short in phase 1. Opening a write action to this entrance
     * is a decision with consequences, so each one is added by hand together
     * with the capability that guards it.
     *
     * @var array<string, array{capability: string|null, scope: string}>
     */
    private const USER_ACTIONS = [
        // Self-description: tells the caller what they may do. Guarded by
        // nothing on purpose — it discloses only the asker's own permissions,
        // and someone with no access simply gets an empty course list.
        'console_context' => [
            'capability' => null,
            'scope' => self::SCOPE_SYSTEM,
        ],
        'get_learner_overview' => [
            'capability' => self::CAP_CONSOLE,
            'scope' => self::SCOPE_COURSE,
        ],
        'send_user_message' => [
            'capability' => self::CAP_CONSOLE,
            'scope' => self::SCOPE_COURSE,
        ],
    ];

    /**
     * Whether the action exists in the registry at all.
     *
     * @param string $action
     * @return bool
     */
    public static function known(string $action): bool {
        return in_array($action, self::SITE_ACTIONS, true)
            || array_key_exists($action, self::USER_ACTIONS);
    }

    /**
     * Whether this kind of caller may reach the action, before capabilities.
     *
     * @param caller $caller
     * @param string $action
     * @return bool
     */
    public static function accepts(caller $caller, string $action): bool {
        if ($caller->is_site()) {
            return in_array($action, self::SITE_ACTIONS, true);
        }
        return array_key_exists($action, self::USER_ACTIONS);
    }

    /**
     * The actions a browser session may reach, for the console to render from.
     *
     * @return string[]
     */
    public static function user_actions(): array {
        return array_keys(self::USER_ACTIONS);
    }

    /**
     * Enforce the policy for one request, throwing when it does not hold.
     *
     * For a site caller this is a membership test only — the signature already
     * established the authority. For a user caller the declared capability is
     * checked in the declared context, which for course-scoped actions is read
     * from the request parameters.
     *
     * @param caller $caller
     * @param string $action
     * @param array $params Request parameters; supplies courseid where needed.
     * @return void
     * @throws \required_capability_exception When the user lacks the capability.
     * @throws \moodle_exception When the action is unknown or closed to this caller.
     */
    public static function require_permitted(caller $caller, string $action, array $params): void {
        if (!self::accepts($caller, $action)) {
            throw new \moodle_exception('action_not_permitted', 'block_alphabees', '', $action);
        }
        if ($caller->is_site()) {
            return;
        }

        $rule = self::USER_ACTIONS[$action];
        $capability = $rule['capability'];
        if ($capability === null) {
            return;
        }

        $context = $rule['scope'] === self::SCOPE_COURSE
            ? self::course_context_from_params($params)
            : \context_system::instance();

        require_capability($capability, $context, $caller->userid());
    }

    /**
     * Resolve the course context a course-scoped action operates in.
     *
     * @param array $params
     * @return \context_course
     * @throws \moodle_exception When no usable course id was supplied.
     */
    private static function course_context_from_params(array $params): \context_course {
        $courseid = isset($params['courseid']) ? (int)$params['courseid'] : 0;
        if ($courseid <= 0 || $courseid == SITEID) {
            throw new \moodle_exception('action_courseid_required', 'block_alphabees');
        }
        return \context_course::instance($courseid, MUST_EXIST);
    }
}
