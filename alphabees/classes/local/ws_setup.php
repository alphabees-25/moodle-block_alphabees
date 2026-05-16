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
 * Programmatic auto-setup of the Alphabees web-services integration.
 *
 * Encapsulates every step a Moodle admin would otherwise click through:
 *   1. Enable web services site-wide.
 *   2. Enable the REST protocol.
 *   3. Ensure the `block_alphabees` external service exists (db/services.php
 *      pre-declares it on plugin install; this layer is defensive in case
 *      the row was deleted manually).
 *   4. Create a dedicated `alphabees-service` user, idempotently.
 *   5. Create a system role with the plugin's web-service capability and
 *      assign it to that user.
 *   6. Add the user as authorised user of the service.
 *   7. Generate a long-lived token bound to that user + service.
 *
 * Reverse direction (`disconnect`) revokes the token and removes the
 * service user assignment so the integration can be cleanly torn down.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Web-services auto-setup helper.
 */
class ws_setup {

    /** Shortname of the external service we pre-declare in db/services.php. */
    public const SERVICE_SHORTNAME = 'block_alphabees';

    /** Username of the dedicated service user. */
    public const SERVICE_USERNAME = 'alphabees-service';

    /** Shortname of the auto-created system role granted to the service user. */
    public const SERVICE_ROLE_SHORTNAME = 'alphabees_service';

    /** Capability granted by the role. */
    public const SERVICE_CAPABILITY = 'block/alphabees:usewebservice';

    /**
     * Run the full setup chain.
     *
     * Idempotent: re-running is safe and reuses existing rows where present.
     * Returns the WS token on success.
     *
     * @return string Web-services token bound to the alphabees service user.
     * @throws \moodle_exception When any step fails irrecoverably.
     */
    public static function enable(): string {
        global $CFG;
        require_once($CFG->dirroot . '/lib/externallib.php');
        require_once($CFG->dirroot . '/webservice/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        self::enable_webservices_globally();
        self::enable_rest_protocol();
        $serviceid = self::ensure_service();
        $userid = self::ensure_service_user();
        self::ensure_role_and_assignment($userid);
        self::ensure_authorised_user($serviceid, $userid);
        return self::ensure_token($serviceid, $userid);
    }

    /**
     * Tear down the integration: revoke token and remove service-user authorisation.
     *
     * Leaves the service-user account intact (deleting Moodle users is heavy
     * and reversible flag is honoured) but invalidates every token bound
     * to the alphabees service for that user. The role assignment stays so
     * a future re-enable just regenerates a token.
     *
     * @return void
     */
    public static function disable(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/accesslib.php');

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        $user = $DB->get_record('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);

        // Revoke every WS token for this (service, user) combination first
        // so the service user can no longer authenticate while we're tearing
        // the rest of the assignments down.
        if ($service && $user) {
            $DB->delete_records('external_tokens', [
                'externalserviceid' => $service->id,
                'userid' => $user->id,
            ]);
        }

        // Strip the system-context role assignments so the dormant account
        // does not retain manager privileges between disable/enable cycles.
        // role_unassign is idempotent and safe even if the assignment never
        // existed (e.g., a half-finished enable() that bailed before step 2).
        if ($user) {
            $syscontext = \context_system::instance();
            $managerrole = $DB->get_record('role', ['shortname' => 'manager']);
            if ($managerrole) {
                role_unassign((int)$managerrole->id, (int)$user->id, $syscontext->id);
            }
            $servicerole = $DB->get_record('role', ['shortname' => self::SERVICE_ROLE_SHORTNAME]);
            if ($servicerole) {
                role_unassign((int)$servicerole->id, (int)$user->id, $syscontext->id);
            }
        }
    }

    /**
     * Whether enable() has previously completed successfully on this site.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        global $DB, $CFG;
        if (empty($CFG->enablewebservices)) {
            return false;
        }
        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if (!$service || empty($service->enabled)) {
            return false;
        }
        $user = $DB->get_record('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);
        if (!$user) {
            return false;
        }
        return $DB->record_exists('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);
    }

    /**
     * Return the active token for the alphabees service user, if any.
     *
     * @return string|null
     */
    public static function current_token(): ?string {
        global $DB;
        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if (!$service) {
            return null;
        }
        $user = $DB->get_record('user', ['username' => self::SERVICE_USERNAME, 'deleted' => 0]);
        if (!$user) {
            return null;
        }
        $row = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ], 'token', IGNORE_MULTIPLE);
        return $row ? (string)$row->token : null;
    }

    /**
     * Enable site-wide web-services flag if it isn't already.
     *
     * @return void
     */
    private static function enable_webservices_globally(): void {
        if (empty(get_config(null, 'enablewebservices'))) {
            set_config('enablewebservices', 1);
        }
    }

    /**
     * Enable the REST protocol if not already in the active list.
     *
     * @return void
     */
    private static function enable_rest_protocol(): void {
        $active = (string)get_config(null, 'webserviceprotocols');
        $enabled = array_filter(array_map('trim', explode(',', $active)));
        if (!in_array('rest', $enabled, true)) {
            $enabled[] = 'rest';
            set_config('webserviceprotocols', implode(',', $enabled));
        }
    }

    /**
     * Ensure the external service row exists AND has every function from
     * db/services.php linked.
     *
     * db/services.php is the single source of truth for the function list;
     * Moodle reads it on plugin install/upgrade and syncs into the
     * external_services_functions table. Two failure modes can leave the
     * service under-provisioned at runtime:
     *
     *   1. Admin manually deleted the row → ensure_service inserts a fresh
     *      one but without function links.
     *   2. Older plugin version installed first, function list expanded
     *      later, admin never ran admin/upgrade.php → table reflects the
     *      stale function list.
     *
     * Calling external_update_descriptions() here is cheap, idempotent,
     * and self-heals both modes — the function list is always brought up
     * to date with whatever the currently-installed db/services.php
     * declares, every time the admin re-toggles the WS checkbox.
     *
     * @return int External service id.
     */
    private static function ensure_service(): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/upgradelib.php');
        require_once($CFG->dirroot . '/lib/externallib.php');

        $existing = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if (!$existing) {
            $service = (object)[
                // Must match the array key used in db/services.php. Using
                // "Alphabees AI Tutor" instead of bare "Alphabees" avoids
                // the unique-name collision against legacy manually-created
                // services on existing customer sites.
                'name' => 'Alphabees AI Tutor',
                'shortname' => self::SERVICE_SHORTNAME,
                'enabled' => 1,
                'restrictedusers' => 1,
                'downloadfiles' => 1,
                'uploadfiles' => 1,
                'component' => 'block_alphabees',
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $DB->insert_record('external_services', $service);
        } else if (empty($existing->enabled)) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $existing->id]);
        }

        // Re-sync function list from db/services.php so any functions added
        // in a later plugin version land in external_services_functions
        // without requiring a separate admin/upgrade.php run.
        external_update_descriptions('block_alphabees');

        return (int)$DB->get_field('external_services', 'id', ['shortname' => self::SERVICE_SHORTNAME]);
    }

    /**
     * Ensure the dedicated service user exists, returning its id.
     *
     * Uses a derived email tied to siteidentifier so multi-site setups never
     * collide on the unique-email constraint. Password is randomized; the
     * user only ever authenticates via WS token, never via password.
     *
     * @return int User id.
     */
    private static function ensure_service_user(): int {
        global $CFG, $DB;
        $existing = $DB->get_record('user', [
            'username' => self::SERVICE_USERNAME,
            'mnethostid' => $CFG->mnet_localhost_id,
        ]);
        if ($existing) {
            if (!empty($existing->deleted) || !empty($existing->suspended)) {
                $DB->update_record('user', (object)[
                    'id' => $existing->id,
                    'deleted' => 0,
                    'suspended' => 0,
                ]);
            }
            return (int)$existing->id;
        }

        $user = (object)[
            'username' => self::SERVICE_USERNAME,
            'firstname' => 'Alphabees',
            'lastname' => 'Service',
            'email' => 'noreply+alphabees-' . substr((string)$CFG->siteidentifier, 0, 8) . '@alphalearn.ai',
            'auth' => 'webservice',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => 'en',
            'maildisplay' => 0,
            'description' => 'Auto-created by block_alphabees for the Alphabees web-services integration.',
        ];
        $userid = \user_create_user($user, false, false);
        // Set a random password — the account never logs in via UI; tokens
        // are the only auth surface, but Moodle requires a non-empty hash.
        $rec = (object)[
            'id' => $userid,
            'password' => hash_internal_user_password(bin2hex(random_bytes(32))),
        ];
        $DB->update_record('user', $rec);
        return (int)$userid;
    }

    /**
     * Provision the service user with the role(s) required for the integration.
     *
     * Two role assignments at system context, both idempotent:
     *
     *  1. The auto-created `alphabees_service` role — carries the
     *     `block/alphabees:usewebservice` capability that the external
     *     service uses for its restricted-users access check.
     *
     *  2. Moodle's built-in `manager` role — gives the service user the
     *     site-wide course/grade/user/enrolment access required for the
     *     Alphabees backend to read ALL courses and participants and to
     *     create / update / delete courses regardless of enrolment. The
     *     course-bound web-services functions (core_enrol_get_enrolled_users,
     *     core_course_get_contents, etc.) check moodle/course:view per
     *     course, which a course-context-only role cannot satisfy without
     *     manually enrolling the service user in every course.
     *
     *     Manager intentionally does NOT include moodle/site:config — only
     *     true site admins can change global settings. The integration's
     *     access surface is large but not unbounded.
     *
     * @param int $userid
     * @return void
     */
    private static function ensure_role_and_assignment(int $userid): void {
        global $DB;
        $syscontext = \context_system::instance();

        // 1. The dedicated alphabees_service role (carrier of the WS capability).
        $role = $DB->get_record('role', ['shortname' => self::SERVICE_ROLE_SHORTNAME]);
        if (!$role) {
            $roledesc = 'Auto-created role for the Alphabees web-services integration.'
                . ' Grants the capability needed to authenticate via the alphabees'
                . ' external service. Site-wide course / user / grade access is'
                . ' provided by an additional manager-role assignment on the same user.';
            $roleid = create_role(
                'Alphabees service user',
                self::SERVICE_ROLE_SHORTNAME,
                $roledesc,
                'student'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        } else {
            $roleid = (int)$role->id;
        }
        if (!$DB->record_exists('role_capabilities', [
            'roleid' => $roleid,
            'capability' => self::SERVICE_CAPABILITY,
            'contextid' => $syscontext->id,
        ])) {
            assign_capability(self::SERVICE_CAPABILITY, CAP_ALLOW, $roleid, $syscontext->id, true);
        }
        if (!$DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $userid,
            'contextid' => $syscontext->id,
        ])) {
            role_assign($roleid, $userid, $syscontext->id);
        }

        // 2. Manager role — for site-wide course/grade/user/enrolment access.
        $managerrole = $DB->get_record('role', ['shortname' => 'manager']);
        if ($managerrole) {
            if (!$DB->record_exists('role_assignments', [
                'roleid' => $managerrole->id,
                'userid' => $userid,
                'contextid' => $syscontext->id,
            ])) {
                role_assign((int)$managerrole->id, $userid, $syscontext->id);
            }
        }
    }

    /**
     * Add the service user to the service's authorised-users list, idempotent.
     *
     * @param int $serviceid
     * @param int $userid
     * @return void
     */
    private static function ensure_authorised_user(int $serviceid, int $userid): void {
        global $DB;
        if ($DB->record_exists('external_services_users', [
            'externalserviceid' => $serviceid,
            'userid' => $userid,
        ])) {
            return;
        }
        $DB->insert_record('external_services_users', (object)[
            'externalserviceid' => $serviceid,
            'userid' => $userid,
            'iprestriction' => null,
            'validuntil' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Generate a token if none exists for this (service, user) pair, or return
     * the existing one. Tokens are never rotated automatically — admins use
     * disable() then enable() if they need a fresh token.
     *
     * @param int $serviceid
     * @param int $userid
     * @return string
     */
    private static function ensure_token(int $serviceid, int $userid): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/externallib.php');
        $existing = $DB->get_record('external_tokens', [
            'externalserviceid' => $serviceid,
            'userid' => $userid,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ], 'token', IGNORE_MULTIPLE);
        if ($existing) {
            return (string)$existing->token;
        }
        $syscontext = \context_system::instance();
        return \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            (object)['id' => $serviceid],
            $userid,
            $syscontext
        );
    }
}
