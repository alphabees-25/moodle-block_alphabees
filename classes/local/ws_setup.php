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

    /** Display name of the external service declared in db/services.php. */
    public const SERVICE_NAME = 'Alphabees AI Tutor';

    /** Username of the dedicated service user. */
    public const SERVICE_USERNAME = 'alphabees-service';

    /** Shortname of the auto-created system role granted to the service user. */
    public const SERVICE_ROLE_SHORTNAME = 'alphabees_service';

    /** Capability granted by the role. */
    public const SERVICE_CAPABILITY = 'block/alphabees:usewebservice';

    /** Component name used for config_plugins diagnostics. */
    private const COMPONENT = 'block_alphabees';

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
        require_once($CFG->dirroot . '/lib/accesslib.php');

        self::enable_webservices_globally();
        self::enable_rest_protocol();
        $serviceid = self::ensure_service();
        $userid = self::ensure_service_user();
        self::ensure_role_and_assignment($userid);
        self::ensure_authorised_user($serviceid, $userid);
        $token = self::ensure_token($serviceid, $userid);
        self::self_test_token($token);
        return $token;
    }

    /**
     * Refresh an already-enabled integration during plugin upgrade.
     *
     * This intentionally avoids the REST self-test used by enable(). Moodle
     * upgrades can run while the site is in maintenance mode or behind hosting
     * rules that block loopback HTTP, and a local service-definition refresh
     * must not make the whole plugin upgrade fail.
     *
     * @return string|null Existing or newly-created token, or null if WS integration is disabled.
     */
    public static function refresh_enabled_integration_for_upgrade(): ?string {
        global $CFG;
        if ((string)(get_config(self::COMPONENT, 'ws_enabled') ?: '') !== '1'
            || site_registry::is_portal_disconnected()) {
            return null;
        }

        require_once($CFG->dirroot . '/lib/externallib.php');
        require_once($CFG->dirroot . '/webservice/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/lib/accesslib.php');

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
     * Return persisted web-services diagnostics for the settings status panel.
     *
     * @return array
     */
    public static function diagnostics(): array {
        $missing = get_config(self::COMPONENT, 'ws_selftest_missing_functions');
        $missingfunctions = [];
        if (!empty($missing)) {
            $decoded = json_decode((string)$missing, true);
            $missingfunctions = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        return [
            'selftest_status' => (string)(get_config(self::COMPONENT, 'ws_selftest_status') ?: 'never'),
            'selftest_at' => (int)(get_config(self::COMPONENT, 'ws_selftest_at') ?: 0),
            'selftest_error' => (string)(get_config(self::COMPONENT, 'ws_selftest_error') ?: ''),
            'selftest_function_count' => (int)(get_config(self::COMPONENT, 'ws_selftest_function_count') ?: 0),
            'selftest_missing_functions' => $missingfunctions,
            'post_status' => (string)(get_config(self::COMPONENT, 'ws_token_post_status') ?: 'never'),
            'post_at' => (int)(get_config(self::COMPONENT, 'ws_token_post_at') ?: 0),
            'post_error' => (string)(get_config(self::COMPONENT, 'ws_token_post_error') ?: ''),
            'post_httpcode' => (int)(get_config(self::COMPONENT, 'ws_token_post_httpcode') ?: 0),
        ];
    }

    /**
     * Record the backend token-post state for visible diagnostics.
     *
     * @param string $status
     * @param string|null $error
     * @param int $httpcode
     * @return void
     */
    public static function record_token_post_status(
        string $status,
        ?string $error = null,
        int $httpcode = 0
    ): void {
        set_config('ws_token_post_status', $status, self::COMPONENT);
        set_config('ws_token_post_at', (string)time(), self::COMPONENT);
        set_config('ws_token_post_httpcode', (string)$httpcode, self::COMPONENT);
        if ($error === null || $error === '') {
            unset_config('ws_token_post_error', self::COMPONENT);
        } else {
            set_config('ws_token_post_error', substr($error, 0, 500), self::COMPONENT);
        }
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
     *   2. Admin manually edited the service and removed functions while
     *      leaving the service/token otherwise active.
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
                'name' => self::SERVICE_NAME,
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

        // Re-sync function list from db/services.php and then explicitly
        // repair the service/function links below. This keeps the runtime
        // service aligned with the plugin even if the Moodle UI was used to
        // alter the service manually.
        try {
            external_update_descriptions('block_alphabees');
        } catch (\Throwable $e) {
            debugging(
                '[block_alphabees] external service description sync skipped: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }

        $serviceid = (int)$DB->get_field('external_services', 'id', ['shortname' => self::SERVICE_SHORTNAME]);
        self::ensure_service_functions($serviceid);
        return $serviceid;
    }

    /**
     * Return the function list declared for the Alphabees external service.
     *
     * The display-name key changed from the legacy manual "Alphabees" service
     * to "Alphabees AI Tutor" to avoid name collisions. Match by shortname so
     * UI and setup logic do not drift when that label changes again.
     *
     * @return array
     */
    public static function declared_service_functions(): array {
        global $CFG;
        $services = [];
        require($CFG->dirroot . '/blocks/alphabees/db/services.php');
        foreach ($services as $service) {
            if (($service['shortname'] ?? '') === self::SERVICE_SHORTNAME) {
                return array_values($service['functions'] ?? []);
            }
        }
        return [];
    }

    /**
     * Split declared functions into Moodle-exposed and unavailable functions.
     *
     * Moodle's external function set differs between supported Moodle minors
     * and enabled activity plugins. Missing functions should reduce the granted
     * service surface, not break Connect entirely.
     *
     * @return array Tuple: [available functions, skipped functions].
     */
    private static function partition_declared_service_functions(): array {
        global $DB;
        $available = [];
        $skipped = [];
        foreach (self::declared_service_functions() as $functionname) {
            if ($DB->record_exists('external_functions', ['name' => $functionname])) {
                $available[] = $functionname;
            } else {
                $skipped[] = $functionname;
            }
        }
        return [$available, $skipped];
    }

    /**
     * Verify that the freshly-created token can call Moodle's own site-info WS.
     *
     * This catches the exact failure mode where a token exists and Moodle
     * returns HTTP 200, but the token is bound to the wrong/under-provisioned
     * service or the service user is not actually authorised. We validate the
     * real REST surface because that is what the Alphabees backend calls.
     *
     * @param string $token
     * @return void
     * @throws \moodle_exception
     */
    public static function self_test_token(string $token): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // Only functions exposed by this Moodle are required in the token self-test.
        $servicefunctions = self::partition_declared_service_functions();
        $requiredfunctions = $servicefunctions[0];
        $url = rtrim((string)$CFG->wwwroot, '/') . '/webservice/rest/server.php';
        $params = [
            'wstoken' => $token,
            'wsfunction' => 'core_webservice_get_site_info',
            'moodlewsrestformat' => 'json',
        ];

        $curl = new \curl(['timeout' => 15]);
        $rawresponse = $curl->get($url, $params);
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        $errno = $curl->get_errno();

        if ($errno !== 0 || $httpcode !== 200) {
            $error = $curl->error ?: ('HTTP ' . $httpcode);
            self::record_self_test(false, 'transport_error: ' . $error, [], 0);
            throw new \moodle_exception('ws_selftest_failed', 'block_alphabees', '', $error);
        }

        $decoded = json_decode((string)$rawresponse, true);
        if (!is_array($decoded)) {
            self::record_self_test(false, 'invalid_json_response', [], 0);
            throw new \moodle_exception('ws_selftest_failed', 'block_alphabees', '', 'invalid_json_response');
        }

        if (isset($decoded['exception'])) {
            $errorcode = (string)($decoded['errorcode'] ?? 'unknown');
            $message = (string)($decoded['message'] ?? $decoded['exception']);
            $error = $errorcode . ': ' . $message;
            self::record_self_test(false, $error, [], 0);
            throw new \moodle_exception('ws_selftest_failed', 'block_alphabees', '', $error);
        }

        $available = self::extract_site_info_function_names($decoded);
        if (empty($available)) {
            self::record_self_test(false, 'site_info_missing_functions', [], 0);
            throw new \moodle_exception('ws_selftest_failed', 'block_alphabees', '', 'site_info_missing_functions');
        }

        $missing = array_values(array_diff($requiredfunctions, $available));
        if (!empty($missing)) {
            $error = get_string('ws_selftest_missing_functions', 'block_alphabees', implode(', ', $missing));
            self::record_self_test(false, $error, $missing, count($available));
            throw new \moodle_exception('ws_selftest_failed', 'block_alphabees', '', $error);
        }

        self::record_self_test(true, null, [], count($available));
    }

    /**
     * Extract function names from core_webservice_get_site_info output.
     *
     * @param array $siteinfo
     * @return array
     */
    private static function extract_site_info_function_names(array $siteinfo): array {
        $names = [];
        foreach (($siteinfo['functions'] ?? []) as $function) {
            if (is_array($function) && isset($function['name'])) {
                $names[] = (string)$function['name'];
            } else if (is_object($function) && isset($function->name)) {
                $names[] = (string)$function->name;
            } else if (is_string($function)) {
                $names[] = $function;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Persist self-test diagnostics for the settings status panel.
     *
     * @param bool $ok
     * @param string|null $error
     * @param array $missingfunctions
     * @param int $functioncount
     * @return void
     */
    private static function record_self_test(
        bool $ok,
        ?string $error,
        array $missingfunctions,
        int $functioncount
    ): void {
        set_config('ws_selftest_status', $ok ? 'passed' : 'failed', self::COMPONENT);
        set_config('ws_selftest_at', (string)time(), self::COMPONENT);
        set_config('ws_selftest_function_count', (string)$functioncount, self::COMPONENT);
        set_config('ws_selftest_missing_functions', json_encode(array_values($missingfunctions)), self::COMPONENT);
        if ($error === null || $error === '') {
            unset_config('ws_selftest_error', self::COMPONENT);
        } else {
            set_config('ws_selftest_error', substr($error, 0, 500), self::COMPONENT);
        }
    }

    /**
     * Ensure every available declared function is linked to the external service.
     *
     * external_update_descriptions() should normally do this, but customer
     * sites with manually edited service rows or interrupted setup runs can
     * still end up with an enabled token whose service is missing functions.
     * Repair those links explicitly. Functions not exposed by this Moodle
     * version or plugin set are removed from the service instead of failing the
     * Connect flow.
     *
     * @param int $serviceid
     * @return void
     */
    private static function ensure_service_functions(int $serviceid): void {
        global $DB;
        [$availablefunctions, $skippedfunctions] = self::partition_declared_service_functions();

        if (!empty($skippedfunctions)) {
            [$insql, $params] = $DB->get_in_or_equal($skippedfunctions, SQL_PARAMS_QM);
            $DB->delete_records_select(
                'external_services_functions',
                'externalserviceid = ? AND functionname ' . $insql,
                array_merge([$serviceid], $params)
            );
        }

        foreach ($availablefunctions as $functionname) {
            if ($DB->record_exists('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ])) {
                continue;
            }
            $DB->insert_record('external_services_functions', (object)[
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }
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
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        assign_capability(self::SERVICE_CAPABILITY, CAP_ALLOW, $roleid, $syscontext->id, true);
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
