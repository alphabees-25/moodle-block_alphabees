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
 * Verifies and dispatches inbound signed requests from the Alphabees backend.
 *
 * Pure-functions style: no direct echo / header() calls — those happen in
 * api.php which calls into here. This makes the dispatcher unit-testable.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Inbound dispatcher.
 */
class inbound_dispatcher {
    /**
     * Verify and dispatch an inbound signed request.
     *
     * Result envelope shape: { httpStatus: int, body: array }.
     *
     * @param string $method HTTP method.
     * @param string $path Request path.
     * @param array $headers Request headers (case-insensitive lookup).
     * @param string $rawbody Raw request body.
     * @return array
     */
    public static function handle(string $method, string $path, array $headers, string $rawbody): array {
        // Method/path basic checks. Wrong method → 405 with no diagnostic info.
        if (strtoupper($method) !== 'POST') {
            return self::deny(405);
        }

        $verification = self::verify_signature($method, $path, $headers, $rawbody);
        if (!$verification['ok']) {
            // Log internally, return uniform 401 so timing/error doesn't leak which step failed.
            debugging('[block_alphabees] inbound verify failed: ' . $verification['reason'], DEBUG_DEVELOPER);
            return self::deny(401);
        }

        $body = json_decode($rawbody, true);
        if (!is_array($body)) {
            return self::deny(400);
        }

        $action = self::normalise_action($body);
        if ($action === '') {
            return self::deny(400);
        }
        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];

        return self::execute(caller::site(), $action, $params);
    }

    /**
     * Run one action on behalf of an already-authenticated caller.
     *
     * Shared by both entrances into the registry: api.php reaches this with a
     * site caller once the Ed25519 signature holds, and the console gateway
     * reaches it with a user caller once Moodle has established the session.
     * Whether that caller may run this action at all is decided in one place,
     * {@see action_policy::require_permitted()}, before the switch.
     *
     * @param caller $caller Who is asking.
     * @param string $action Registry action name.
     * @param array $params Action parameters.
     * @return array Envelope { httpStatus: int, body: array }.
     */
    public static function execute(caller $caller, string $action, array $params): array {
        if (!action_policy::known($action)) {
            return self::deny(404);
        }

        try {
            action_policy::require_permitted($caller, $action, $params);
        } catch (\required_capability_exception $e) {
            return self::deny(403, ['code' => 'capability_required']);
        } catch (\moodle_exception $e) {
            // Unknown to this caller, or the parameters did not name a course
            // we could check against. Both are refusals, not server errors.
            return self::deny(403, ['code' => 'action_not_permitted']);
        }

        switch ($action) {
            case 'ping':
                return self::ok(self::action_ping());
            case 'disconnect_site':
            case 'revoke_registration':
                return self::action_disconnect_site($params);
            case 'pause_site':
                return self::ok(self::action_pause_site($params));
            case 'resume_site':
                return self::action_resume_site($params);
            case 'list_placements':
                return self::ok(self::action_list_placements());
            case 'update_placement':
                return self::action_update_placement($params);
            case 'delete_placement':
                return self::action_delete_placement($params);
            case 'create_placement':
                return self::action_create_placement($params);
            case 'bulk_create_placements':
                return self::action_bulk_create_placements($params);
            case 'list_courses':
                return self::ok(self::action_list_courses($params));
            case 'list_courses_categorized':
                return self::ok(self::action_list_courses_categorized($params));
            case 'upsert_course':
                return self::action_upsert_course($params);
            case 'upsert_section':
                return self::action_upsert_section($params);
            case 'upsert_modules':
                return self::action_upsert_modules($params);
            case 'delete_course_modules':
                return self::action_delete_course_modules($params);
            case 'set_activity_completion':
                return self::action_set_activity_completion($params);
            case 'upload_file':
                return self::action_upload_file($params);
            case 'list_h5p_libraries':
                return self::action_list_h5p_libraries($params);
            case 'notify':
                return self::action_notify($params);
            case 'console_context':
            case 'get_learner_overview':
            case 'send_user_message':
                return self::run_console_action($caller, $action, $params);
            default:
                return self::deny(404);
        }
    }

    /**
     * Verify the request signature, timestamp, nonce, key id, site identifier.
     *
     * Returns { ok: bool, reason?: string }.
     */
    private static function verify_signature(string $method, string $path, array $headers, string $body): array {
        $algo = self::header($headers, 'X-Alphabees-Algo');
        $site = self::header($headers, 'X-Alphabees-Site');
        $keyid = self::header($headers, 'X-Alphabees-KeyId');
        $ts = self::header($headers, 'X-Alphabees-Timestamp');
        $nonce = self::header($headers, 'X-Alphabees-Nonce');
        $sig = self::header($headers, 'X-Alphabees-Signature');

        if ($algo !== 'ed25519') {
            return ['ok' => false, 'reason' => 'algo'];
        }
        if ($site !== site_registry::site_identifier()) {
            return ['ok' => false, 'reason' => 'site'];
        }
        if ((int)$keyid !== site_registry::backend_key_id()) {
            return ['ok' => false, 'reason' => 'keyid'];
        }
        if (!ctype_digit((string)$ts) || !crypto::timestamp_within_window((int)$ts)) {
            return ['ok' => false, 'reason' => 'timestamp'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,}$/', (string)$nonce)) {
            return ['ok' => false, 'reason' => 'nonce_format'];
        }

        if (self::nonce_seen($nonce)) {
            return ['ok' => false, 'reason' => 'nonce_replay'];
        }

        $backendpub = site_registry::backend_public_key();
        if ($backendpub === null) {
            return ['ok' => false, 'reason' => 'no_backend_key'];
        }

        $canonical = crypto::canonical_string($method, $path, (int)$ts, $nonce, $body, $site);
        if (!crypto::verify($canonical, $sig, $backendpub)) {
            return ['ok' => false, 'reason' => 'signature'];
        }

        // Mark nonce as used (TTL: timestamp window + slack).
        self::record_nonce($nonce, (int)$ts);
        return ['ok' => true];
    }

    /**
     * Case-insensitive header lookup.
     *
     * @param array $headers
     * @param string $name
     * @return string
     */
    private static function header(array $headers, string $name): string {
        $needle = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $needle) {
                return is_array($v) ? (string)reset($v) : (string)$v;
            }
        }
        return '';
    }

    /**
     * Check whether a nonce has already been recorded for this site.
     *
     * @param string $nonce
     * @return bool
     */
    private static function nonce_seen(string $nonce): bool {
        global $DB;
        return $DB->record_exists('block_alphabees_nonces', ['nonce' => $nonce]);
    }

    /**
     * Record a nonce as used so subsequent requests with the same nonce are rejected.
     *
     * @param string $nonce
     * @param int $requestts Request timestamp (drives the TTL).
     * @return void
     */
    private static function record_nonce(string $nonce, int $requestts): void {
        global $DB;
        $expiry = $requestts + (crypto::TIMESTAMP_WINDOW_SECONDS * 2);
        try {
            $DB->insert_record('block_alphabees_nonces', (object)[
                'nonce' => $nonce,
                'expiresat' => $expiry,
            ]);
        } catch (\dml_write_exception $e) {
            // Race condition: another request inserted the same nonce concurrently.
            // Caller already passed the nonce_seen check, but this defensive catch
            // keeps the request from 500'ing on duplicate-key.
            unset($e);
        }
    }

    // Actions.

    /**
     * Health check action — returns plugin version, placement count, current key id.
     *
     * @return array
     */
    private static function action_ping(): array {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        return [
            'plugin_version' => $plugin->release ?? null,
            'plugin_version_code' => $plugin->version ?? null,
            'placement_count' => count(placement_repository::all_instances()),
            'key_id' => site_registry::key_id(),
            'backend_key_id' => site_registry::backend_key_id(),
            'registration_id' => site_registry::registration_id(),
            'api_key_present' => site_registry::api_key_present(),
            'api_key_status' => site_registry::api_key_status(),
            'last_sync_at' => site_registry::last_sync_at(),
            'sync_paused' => site_registry::is_sync_paused(),
            'sync_paused_at' => site_registry::sync_paused_at(),
        ];
    }

    /**
     * Fully disconnect this Moodle site from the portal.
     *
     * This is intentionally a local teardown only: it revokes active web-service
     * tokens/assignments and clears registration state, but leaves the dedicated
     * Moodle user and role definitions in place for a future reconnect.
     *
     * @return array
     */
    private static function action_disconnect_site(array $params): array {
        global $DB;

        try {
            ws_setup::disable();
        } catch (\Throwable $e) {
            return self::deny(500, [
                'success' => false,
                'action' => 'disconnect_site',
                'status' => 'failed',
                'error' => 'ws_disable_failed',
                'detail' => $e->getMessage(),
            ]);
        }

        $DB->delete_records('block_alphabees_retryqueue');
        site_registry::reset_registration();
        $reason = self::param($params, 'reason', 'reason');
        site_registry::mark_portal_disconnected($reason !== null ? (string)$reason : null);
        ws_setup::record_token_post_status('revoked_ok');

        return self::ok([
            'success' => true,
            'action' => 'disconnect_site',
            'status' => 'disconnected',
            'disconnected' => true,
            'registered' => site_registry::is_registered(),
            'portal_disconnected' => site_registry::is_portal_disconnected(),
            'ws_enabled' => (bool)get_config('block_alphabees', 'ws_enabled'),
            'allow_remote_placement' => (bool)get_config('block_alphabees', 'allow_remote_placement'),
            'retry_queue_cleared' => true,
        ]);
    }

    /**
     * Pause outbound placement syncs/events while keeping registration intact.
     *
     * @param array $params
     * @return array
     */
    private static function action_pause_site(array $params): array {
        $reason = self::param($params, 'reason', 'reason');
        site_registry::pause_syncs($reason !== null ? (string)$reason : null);

        return [
            'success' => true,
            'action' => 'pause_site',
            'status' => 'paused',
            'api_key_present' => site_registry::api_key_present(),
            'api_key_status' => site_registry::api_key_status(),
            'sync_paused' => site_registry::is_sync_paused(),
            'sync_paused_at' => site_registry::sync_paused_at(),
            'sync_pause_reason' => site_registry::sync_pause_reason(),
        ];
    }

    /**
     * Resume outbound placement syncs/events after a portal pause.
     *
     * @param array $params
     * @return array
     */
    private static function action_resume_site(array $params): array {
        $requireapi = self::truthy(self::param($params, 'require_api_key', 'requireApiKey'));
        $pausedbefore = site_registry::is_sync_paused();

        if (!site_registry::api_key_present()) {
            return self::deny(409, [
                'success' => false,
                'action' => 'resume_site',
                'status' => 'blocked',
                'error' => 'api_key_missing',
                'require_api_key' => $requireapi,
                'api_key_present' => false,
                'api_key_status' => 'missing',
            ]);
        }
        if (site_registry::is_registration_blocked()) {
            return self::deny(409, [
                'success' => false,
                'action' => 'resume_site',
                'status' => 'blocked',
                'error' => 'api_key_rejected',
                'require_api_key' => $requireapi,
                'api_key_present' => true,
                'api_key_status' => site_registry::api_key_status(),
                'detail' => site_registry::registration_block_reason(),
            ]);
        }

        self::drop_queued_lifecycle_event('site.paused');
        self::drop_queued_lifecycle_event('site.resumed');
        site_registry::resume_syncs();

        return self::ok([
            'success' => true,
            'action' => 'resume_site',
            'status' => 'resumed',
            'require_api_key' => $requireapi,
            'api_key_present' => site_registry::api_key_present(),
            'api_key_status' => site_registry::api_key_status(),
            'sync_paused_before' => $pausedbefore,
            'sync_paused' => site_registry::is_sync_paused(),
            'sync_paused_at' => site_registry::sync_paused_at(),
            'sync_pause_reason' => site_registry::sync_pause_reason(),
        ]);
    }

    /**
     * Return the full snapshot of placements known to this site.
     *
     * @return array
     */
    private static function action_list_placements(): array {
        $instances = placement_repository::all_instances();
        $payloads = [];
        foreach ($instances as $instance) {
            $payloads[] = placement_repository::build_payload($instance);
        }
        return ['placements' => $payloads];
    }

    /**
     * Apply a remote update to an existing placement (bot, visibility, primary color).
     *
     * @param array $params
     * @return array
     */
    private static function action_update_placement(array $params): array {
        global $DB;
        $uuid = self::param($params, 'placement_uuid', 'placementUuid');
        if ($uuid === null || $uuid === '') {
            return self::deny(400, ['error' => 'missing_placement_uuid']);
        }
        $instance = placement_repository::find_by_uuid($uuid);
        if ($instance === null) {
            return self::deny(404, ['error' => 'placement_not_found']);
        }

        $config = placement_repository::decode_config($instance->configdata ?? '');
        $changed = false;

        // Bot/tutor change.
        $newbot = self::param($params, 'bot_id', 'botId');
        if ($newbot !== null) {
            $oldbot = isset($config->botid) ? (string)$config->botid : '';
            if ((string)$newbot !== $oldbot) {
                $config->botid = (string)$newbot;
                unset($config->bot_label);
                $changed = true;
            }
            if (
                $oldbot === ''
                && (string)$newbot !== ''
                && self::param($params, 'visible', 'visible') === null
                && isset($config->placement_visible)
                && !$config->placement_visible
            ) {
                $config->placement_visible = 1;
                $changed = true;
            }
        }
        $newbotlabel = self::bot_label_from_params($params);
        if ($newbotlabel !== '') {
            $oldbotlabel = isset($config->bot_label) ? (string)$config->bot_label : '';
            if ($newbotlabel !== $oldbotlabel) {
                $config->bot_label = $newbotlabel;
                $changed = true;
            }
        }

        // Visibility toggle. Stored as `placement_visible` in configdata so it
        // doesn't collide with Moodle's own block_instances.visible. Empty
        // content is returned by get_content() when this is false; the block
        // remains in block_instances so re-enabling is a single param flip.
        $newvisible = self::param($params, 'visible', 'visible');
        if ($newvisible !== null) {
            $newvisbool = self::to_bool($newvisible);
            $oldvisbool = !isset($config->placement_visible) ? true : (bool)$config->placement_visible;
            if ($newvisbool !== $oldvisbool) {
                $config->placement_visible = $newvisbool ? 1 : 0;
                $changed = true;
            }
        }

        // Primary color override. When set (hex string like #abcdef), this
        // overrides the auto-fetched-from-bot color in get_content(). When
        // explicitly null, override is cleared.
        if (array_key_exists('primary_color', $params) || array_key_exists('primaryColor', $params)) {
            $newcolor = self::param($params, 'primary_color', 'primaryColor');
            if ($newcolor === null || $newcolor === '') {
                if (isset($config->primary_color_override)) {
                    unset($config->primary_color_override);
                    $changed = true;
                }
            } else {
                $hex = self::sanitize_hex_color((string)$newcolor);
                $oldcolor = isset($config->primary_color_override) ? (string)$config->primary_color_override : '';
                if ($hex !== null && $hex !== $oldcolor) {
                    $config->primary_color_override = $hex;
                    $changed = true;
                }
            }
        }

        if (empty($config->remote_managed)) {
            $config->remote_managed = true;
            $changed = true;
        }

        if ($changed) {
            $DB->update_record('block_instances', (object)[
                'id' => $instance->id,
                'configdata' => placement_repository::encode_config($config),
                'timemodified' => time(),
            ]);
        }

        return self::ok(['placement' => placement_repository::build_payload(
            (object)array_merge((array)$instance, [
                'configdata' => placement_repository::encode_config($config),
                'timemodified' => time(),
            ])
        )]);
    }

    /**
     * Coerce a JSON-decoded value to bool, accepting common truthy/falsy strings.
     *
     * @param mixed $v
     * @return bool
     */
    private static function to_bool($v): bool {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            $low = strtolower(trim($v));
            return in_array($low, ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$v;
    }

    /**
     * Validate + normalize a hex color string. Returns "#aabbcc" or null
     * if input doesn't look like a hex color. Accepts forms with or
     * without a leading `#`, 3-digit shorthand, and uppercase letters.
     */
    private static function sanitize_hex_color(string $value): ?string {
        $v = ltrim(trim($value), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $v)) {
            return '#' . strtolower($v);
        }
        if (preg_match('/^[0-9a-fA-F]{3}$/', $v)) {
            // Expand short form: abc → aabbcc.
            return '#' . strtolower($v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2]);
        }
        return null;
    }

    /**
     * Build a readable tutor label from optional backend action params.
     *
     * The canonical source for dropdown names remains the live tutor list, but
     * storing this label avoids showing a raw UUID if that list is temporarily
     * unavailable or filtered before the next form render.
     *
     * @param array $params
     * @return string
     */
    private static function bot_label_from_params(array $params): string {
        $visualname = self::string_param_any($params, [
            'bot_visual_name', 'botVisualName', 'visual_name', 'visualName', 'display_name', 'displayName',
        ]);
        $internalname = self::string_param_any($params, [
            'bot_name', 'botName', 'name', 'internal_name', 'internalName',
        ]);
        $type = self::string_param_any($params, [
            'bot_type', 'botType', 'type', 'agent_type', 'agentType',
        ]);

        $parts = [];
        if ($visualname !== '') {
            $parts[] = $visualname;
        }
        if ($internalname !== '' && $internalname !== $visualname) {
            $parts[] = $internalname;
        }
        if ($type !== '') {
            $parts[] = ucwords(str_replace(['_', '-'], ' ', $type));
        }
        return clean_param(implode(' · ', $parts), PARAM_TEXT);
    }

    /**
     * Delete a placement by its UUID.
     *
     * @param array $params
     * @return array
     */
    private static function action_delete_placement(array $params): array {
        global $CFG;
        require_once($CFG->dirroot . '/lib/blocklib.php');

        $uuid = self::param($params, 'placement_uuid', 'placementUuid');
        if ($uuid === null || $uuid === '') {
            return self::deny(400, ['error' => 'missing_placement_uuid']);
        }
        $instance = placement_repository::find_by_uuid($uuid);
        if ($instance === null) {
            return self::deny(404, ['error' => 'placement_not_found']);
        }

        blocks_delete_instance($instance);
        return self::ok(['deleted' => true]);
    }

    /**
     * Create a new alphabees block instance on a target course or module page.
     *
     * Gated by the `allow_remote_placement` admin setting (off by default) —
     * giving the portal write access to add blocks anywhere is powerful and
     * the site admin must consciously opt in.
     *
     * Required params: course_id or target_type=module + cmid.
     * Optional params: target_type (default `course`),
     *                  bot_id,
     *                  visible,
     *                  page_pattern (default `course-view-*` or `mod-{modtype}-*`),
     *                  block_region (default `side-pre`),
     *                  block_weight (default 0).
     *
     * Returns the full placement payload (same shape as list_placements rows).
     */
    private static function action_create_placement(array $params): array {
        if (empty(get_config('block_alphabees', 'allow_remote_placement'))) {
            return self::deny(403, ['error' => 'remote_placement_disabled']);
        }

        try {
            $result = self::create_placement_for_target($params);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] create_placement failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::deny(500, ['error' => 'internal', 'detail' => $e->getMessage()]);
        }
        if (empty($result['ok'])) {
            return self::deny((int)($result['status'] ?? 400), $result);
        }

        return self::ok(['placement' => $result['placement']]);
    }

    /**
     * Create multiple placements while reporting each target independently.
     *
     * @param array $params
     * @return array
     */
    private static function action_bulk_create_placements(array $params): array {
        if (empty(get_config('block_alphabees', 'allow_remote_placement'))) {
            return self::deny(403, ['error' => 'remote_placement_disabled']);
        }

        $targets = self::param($params, 'targets', 'targets');
        if (!is_array($targets) || empty($targets)) {
            return self::deny(400, ['error' => 'missing_targets']);
        }

        $results = [];
        foreach ($targets as $index => $target) {
            if (!is_array($target)) {
                $results[] = [
                    'ok' => false,
                    'index' => $index,
                    'error' => 'invalid_target',
                ];
                continue;
            }

            try {
                $result = self::create_placement_for_target($target);
            } catch (\Throwable $e) {
                debugging('[block_alphabees] bulk_create_placements target failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $result = [
                    'ok' => false,
                    'status' => 500,
                    'error' => 'internal',
                    'detail' => $e->getMessage(),
                ];
            }
            $result['index'] = $index;
            $results[] = $result;
        }

        $created = 0;
        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                $created++;
            }
        }

        return self::ok([
            'results' => $results,
            'created_count' => $created,
            'error_count' => count($results) - $created,
        ]);
    }

    /**
     * Create one block_instances row for either a course or module target.
     *
     * @param array $params
     * @return array
     */
    private static function create_placement_for_target(array $params): array {
        global $DB;

        $targettype = strtolower((string)(self::param($params, 'target_type', 'targetType') ?? 'course'));
        if ($targettype === 'activity') {
            $targettype = 'module';
        }
        if (!in_array($targettype, ['course', 'module'], true)) {
            return [
                'ok' => false,
                'status' => 400,
                'target_type' => $targettype,
                'error' => 'invalid_target_type',
            ];
        }

        $botid = clean_param((string)(self::param($params, 'bot_id', 'botId') ?? ''), PARAM_TEXT);

        $courseid = (int)(self::param($params, 'course_id', 'courseId') ?? 0);
        $cmid = null;
        $modtype = null;
        $activityname = null;
        if ($targettype === 'module') {
            $cmid = (int)(self::param($params, 'cmid', 'cmid')
                ?? self::param($params, 'course_module_id', 'courseModuleId') ?? 0);
            if ($cmid <= 0) {
                return [
                    'ok' => false,
                    'status' => 400,
                    'target_type' => 'module',
                    'course_id' => $courseid > 0 ? $courseid : null,
                    'error' => 'missing_cmid',
                ];
            }

            $sql = "SELECT cm.id, cm.course, cm.instance, m.name AS modtype
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                     WHERE cm.id = ?";
            $cm = $DB->get_record_sql($sql, [$cmid], IGNORE_MISSING);
            if (!$cm) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'target_type' => 'module',
                    'cmid' => $cmid,
                    'error' => 'module_not_found',
                ];
            }

            if ($courseid > 0 && $courseid !== (int)$cm->course) {
                return [
                    'ok' => false,
                    'status' => 400,
                    'target_type' => 'module',
                    'course_id' => $courseid,
                    'cmid' => $cmid,
                    'error' => 'module_course_mismatch',
                ];
            }

            $courseid = (int)$cm->course;
            $modtype = (string)$cm->modtype;
            $expectedmodtype = (string)(self::param($params, 'modtype', 'modType') ?? '');
            if ($expectedmodtype !== '' && $expectedmodtype !== $modtype) {
                return [
                    'ok' => false,
                    'status' => 400,
                    'target_type' => 'module',
                    'course_id' => $courseid,
                    'cmid' => $cmid,
                    'modtype' => $modtype,
                    'error' => 'modtype_mismatch',
                ];
            }

            try {
                $activityname = $DB->get_field($modtype, 'name', ['id' => $cm->instance], IGNORE_MISSING);
                $activityname = $activityname !== false ? (string)$activityname : null;
            } catch (\Throwable $e) {
                unset($e);
                $activityname = null;
            }
            $parentcontext = \context_module::instance($cmid);
        } else {
            if ($courseid <= 0) {
                return [
                    'ok' => false,
                    'status' => 400,
                    'target_type' => 'course',
                    'error' => 'missing_course_id',
                ];
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id', IGNORE_MISSING);
            if (!$course) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'target_type' => 'course',
                    'course_id' => $courseid,
                    'error' => 'course_not_found',
                ];
            }
            $parentcontext = \context_course::instance($courseid);
        }

        $region = clean_param((string)(self::param($params, 'block_region', 'blockRegion') ?? 'side-pre'), PARAM_TEXT);
        $weight = (int)(self::param($params, 'block_weight', 'blockWeight') ?? 0);
        $defaultpattern = $targettype === 'module' ? 'mod-' . $modtype . '-*' : 'course-view-*';
        $pagepattern = clean_param(
            (string)(self::param($params, 'page_pattern', 'pagePattern') ?? $defaultpattern),
            PARAM_TEXT
        );

        $configdata = new \stdClass();
        if ($botid !== '') {
            $configdata->botid = $botid;
        }
        $botlabel = self::bot_label_from_params($params);
        if ($botid !== '' && $botlabel !== '') {
            $configdata->bot_label = $botlabel;
        }
        $configdata->placement_uuid = crypto::uuid_v4();
        $configdata->remote_managed = 1;
        $visible = self::param($params, 'visible', 'visible');
        if ($visible !== null) {
            $configdata->placement_visible = self::to_bool($visible) ? 1 : 0;
        } else if ($botid === '') {
            $configdata->placement_visible = 0;
        }

        $record = new \stdClass();
        $record->blockname = 'alphabees';
        $record->parentcontextid = $parentcontext->id;
        $record->showinsubcontexts = 0;
        $record->requiredbytheme = 0;
        $record->pagetypepattern = $pagepattern;
        $record->subpagepattern = null;
        $record->defaultregion = $region;
        $record->defaultweight = $weight;
        $record->configdata = placement_repository::encode_config($configdata);
        $record->timecreated = time();
        $record->timemodified = time();
        $instanceid = $DB->insert_record('block_instances', $record);

        $instance = $DB->get_record('block_instances', ['id' => $instanceid]);
        if (!$instance) {
            return [
                'ok' => false,
                'status' => 500,
                'target_type' => $targettype,
                'course_id' => $courseid,
                'cmid' => $cmid,
                'modtype' => $modtype,
                'activity_name' => $activityname,
                'error' => 'create_failed',
            ];
        }

        $placement = placement_repository::build_payload($instance);
        return [
            'ok' => true,
            'target_type' => $targettype,
            'course_id' => $courseid,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'activity_name' => $activityname,
            'placement_uuid' => $placement['placement_uuid'] ?? null,
            'placement' => $placement,
        ];
    }

    /**
     * Return the list of courses on this Moodle, so the portal can offer
     * a course picker when an admin wants to autonomously place an
     * Alphabees block somewhere new.
     *
     * Pagination via `limit` / `offset` params (defaults 500 / 0) so we
     * stay safe on large Moodle installs without a single huge response.
     * Site course (id=1) is excluded — it's not a real course context.
     */
    private static function action_list_courses(array $params): array {
        global $DB;

        $limit = (int)(self::param($params, 'limit', 'limit') ?? 500);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 500;
        }
        $offset = (int)(self::param($params, 'offset', 'offset') ?? 0);
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = "SELECT c.id, c.shortname, c.fullname, c.category, c.format,
                       c.visible, c.startdate, c.enddate,
                       cc.name AS category_name
                  FROM {course} c
                  LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE c.id > 1
              ORDER BY c.id ASC";
        $rows = $DB->get_records_sql($sql, [], $offset, $limit);

        $total = (int)$DB->count_records_select('course', 'id > 1');

        $courses = [];
        foreach ($rows as $r) {
            $courses[] = [
                'id' => (int)$r->id,
                'shortname' => (string)$r->shortname,
                'fullname' => (string)$r->fullname,
                'category_id' => (int)$r->category,
                'category_name' => $r->category_name !== null ? (string)$r->category_name : null,
                'visible' => (bool)$r->visible,
                'format' => (string)$r->format,
                'startdate' => isset($r->startdate) && $r->startdate ? (int)$r->startdate : null,
                'enddate' => isset($r->enddate) && $r->enddate ? (int)$r->enddate : null,
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
     * Course-categorised listing for the portal's course picker.
     *
     * Same shape as list_courses but joins category metadata so the portal
     * can render a category tree without a second round-trip.
     *
     * @param array $params
     * @return array
     */
    private static function action_list_courses_categorized(array $params): array {
        $limit = (int)(self::param($params, 'limit', 'limit') ?? 500);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 500;
        }
        $offset = (int)(self::param($params, 'offset', 'offset') ?? 0);
        if ($offset < 0) {
            $offset = 0;
        }
        return course_writer::list_courses_categorized($limit, $offset);
    }

    /**
     * Atomically create or update a course (matched by id or shortname).
     *
     * @param array $params
     * @return array
     */
    private static function action_upsert_course(array $params): array {
        $courseparams = self::map_course_params($params);
        try {
            $course = course_writer::upsert_course($courseparams);
        } catch (\moodle_exception $e) {
            return self::deny(400, ['error' => 'invalid_params', 'detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] upsert_course failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::deny(500, ['error' => 'internal', 'detail' => $e->getMessage()]);
        }
        return self::ok(['course' => self::shape_course($course)]);
    }

    /**
     * Update one section of a course (name, summary HTML, visibility).
     *
     * Replaces the function previously provided by local_wsmanagesections —
     * the third-party plugin is no longer required for HTML summaries.
     *
     * @param array $params
     * @return array
     */
    private static function action_upsert_section(array $params): array {
        $courseid = (int)(self::param($params, 'course_id', 'courseId') ?? 0);
        $sectionnum = (int)(self::param($params, 'section', 'section') ?? -1);
        if ($courseid <= 0 || $sectionnum < 0) {
            return self::deny(400, ['error' => 'missing_course_or_section']);
        }
        $fields = [];
        $coursefields = [
            'name' => 'name',
            'summary' => 'summary',
            'summary_format' => 'summaryFormat',
            'visible' => 'visible',
        ];
        foreach ($coursefields as $snake => $camel) {
            $value = self::param($params, $snake, $camel);
            if ($value !== null) {
                $key = $snake === 'summary_format' ? 'summaryformat' : $snake;
                $fields[$key] = $value;
            }
        }
        try {
            $section = course_writer::upsert_section($courseid, $sectionnum, $fields);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] upsert_section failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::deny(500, ['error' => 'internal', 'detail' => $e->getMessage()]);
        }
        return self::ok(['section' => [
            'id' => (int)$section->id,
            'course_id' => (int)$section->course,
            'section' => (int)$section->section,
            'name' => $section->name !== null ? (string)$section->name : null,
            'summary' => (string)$section->summary,
            'summary_format' => (int)$section->summaryformat,
            'visible' => (bool)$section->visible,
        ]]);
    }

    /**
     * Bulk add or update course modules.
     *
     * Accepts a `modules` array; each entry is either { cmid, ... } for an
     * update or { course, section, modname, ... } for a create.
     *
     * @param array $params
     * @return array
     */
    private static function action_upsert_modules(array $params): array {
        $modules = self::param($params, 'modules', 'modules');
        if (!is_array($modules) || empty($modules)) {
            return self::deny(400, ['error' => 'missing_modules']);
        }
        $actor = self::actor_user();
        if (!$actor) {
            return self::deny(403, ['error' => 'no_actor_user']);
        }

        $results = [];
        foreach ($modules as $idx => $entry) {
            if (!is_array($entry)) {
                $results[] = ['index' => $idx, 'ok' => false, 'error' => 'not_an_object'];
                continue;
            }
            try {
                $mapped = self::map_module_params($entry);
                $cm = self::run_as($actor, static function () use ($mapped) {
                    return course_writer::upsert_module($mapped);
                });
                $results[] = [
                    'index' => $idx,
                    'ok' => true,
                    'cmid' => (int)$cm->id,
                    'course' => (int)$cm->course,
                    'section' => isset($cm->section) ? (int)$cm->section : null,
                    'modname' => (string)$cm->modname,
                    'name' => isset($cm->name) ? (string)$cm->name : null,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'index' => $idx,
                    'ok' => false,
                    'code' => $e instanceof \moodle_exception ? $e->errorcode : 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Batch semantics: a partly successful push is a 200 carrying the
        // per-entry outcome. When nothing at all could be written the caller
        // should be able to branch on the status alone, so say 4xx — that is
        // the single-module case, where an unsupported modname must trigger
        // their fallback rather than look like a success with details.
        $succeeded = count(array_filter($results, static function (array $r): bool {
            return !empty($r['ok']);
        }));
        if ($succeeded === 0) {
            return self::deny(422, ['results' => $results]);
        }
        return self::ok(['results' => $results]);
    }

    /**
     * Delete a list of course modules by their cmid.
     *
     * @param array $params
     * @return array
     */
    private static function action_delete_course_modules(array $params): array {
        $cmids = self::param($params, 'cmids', 'cmids');
        if (!is_array($cmids) || empty($cmids)) {
            return self::deny(400, ['error' => 'missing_cmids']);
        }
        try {
            $deleted = course_writer::delete_modules($cmids);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] delete_course_modules failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::deny(500, ['error' => 'internal', 'detail' => $e->getMessage()]);
        }
        return self::ok(['deleted' => $deleted, 'count' => count($deleted)]);
    }

    /**
     * Completion write-back: set the activity-completion state of one user
     * for one course module.
     *
     * Uses Moodle's completion *override* — the same mechanism as the
     * "Override completion" action in the course completion report — so it
     * works for manual and automatic completion alike and is recorded with
     * overrideby = alphabees-service. Runs as the service user because
     * completion_info::update_state() checks moodle/course:overridecompletion
     * on the current $USER and api.php has no session.
     *
     * params: { cmid: int, userid: int, completed: bool }
     *     or  { cmid: int, userid: int, state: "complete" | "incomplete" }
     *
     * Responses: 400 missing/invalid params, 404 cm/user unknown,
     * 409 completion_not_enabled | user_not_tracked (user has no active
     * enrolment counted in completion reports), 403 actor lacks capability
     * (WS integration disabled and no admin fallback), 200 with resulting state.
     *
     * @param array $params
     * @return array
     */
    private static function action_set_activity_completion(array $params): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $cmid = (int)(self::param($params, 'cmid', 'cmId') ?? self::param($params, 'cm_id', 'cmid') ?? 0);
        $userid = (int)(self::param($params, 'user_id', 'userId') ?? self::param($params, 'userid', 'userid') ?? 0);
        if ($cmid <= 0 || $userid <= 0) {
            return self::deny(400, ['error' => 'missing_cmid_or_userid']);
        }

        $completed = self::param($params, 'completed', 'completed');
        if ($completed === null) {
            $state = strtolower((string)(self::param($params, 'state', 'state')
                ?? self::param($params, 'new_state', 'newState') ?? ''));
            if ($state === 'complete' || $state === 'completed') {
                $completed = true;
            } else if ($state === 'incomplete') {
                $completed = false;
            } else {
                return self::deny(400, ['error' => 'missing_state']);
            }
        } else {
            $completed = self::truthy($completed);
        }
        $newstate = $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;

        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            return self::deny(404, ['error' => 'cm_not_found']);
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return self::deny(404, ['error' => 'user_not_found']);
        }

        try {
            $course = get_course((int)$cm->course);
            $completion = new \completion_info($course);
            if (!$completion->is_enabled($cm)) {
                return self::deny(409, ['error' => 'completion_not_enabled']);
            }
            if (!$completion->is_tracked_user($userid)) {
                return self::deny(409, ['error' => 'user_not_tracked']);
            }

            $actor = self::actor_user();
            if (!$actor) {
                return self::deny(403, ['error' => 'no_actor_user']);
            }
            if (!$completion->user_can_override_completion($actor)) {
                return self::deny(403, ['error' => 'actor_lacks_overridecompletion']);
            }

            $data = self::run_as($actor, function () use ($completion, $cm, $userid, $newstate) {
                $completion->update_state($cm, $newstate, $userid, true);
                return $completion->get_data($cm, false, $userid);
            });
        } catch (\Throwable $e) {
            debugging('[block_alphabees] set_activity_completion failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::deny(500, ['error' => 'internal', 'detail' => $e->getMessage()]);
        }

        $resultstate = (int)($data->completionstate ?? COMPLETION_INCOMPLETE);
        return self::ok([
            'cmid' => $cmid,
            'userid' => $userid,
            'courseid' => (int)$cm->course,
            'completionstate' => $resultstate,
            'completed' => $resultstate !== COMPLETION_INCOMPLETE,
            'overrideby' => (int)($data->overrideby ?? 0),
            'timemodified' => (int)($data->timemodified ?? 0),
        ]);
    }

    /**
     * User account inbound write actions run as when Moodle APIs check $USER.
     *
     * Prefers the alphabees-service user (manager role at system context),
     * falls back to the primary admin when the WS integration was never
     * enabled on this site.
     *
     * @return \stdClass|null
     */
    private static function actor_user(): ?\stdClass {
        global $DB;
        $actor = $DB->get_record('user', ['username' => ws_setup::SERVICE_USERNAME, 'deleted' => 0]);
        if (!$actor) {
            $actor = get_admin() ?: null;
        }
        return $actor ?: null;
    }

    /**
     * Run a callback with $USER temporarily switched to the given account.
     *
     * api.php runs without a session (NO_MOODLE_COOKIES), so $USER is the
     * not-logged-in stub; core APIs that capability-check the current user
     * need a real actor. The previous $USER is restored afterwards.
     *
     * @param \stdClass $user
     * @param callable $fn
     * @return mixed
     */
    private static function run_as(\stdClass $user, callable $fn) {
        global $USER;
        $previous = $USER;
        \core\session\manager::set_user($user);
        try {
            return $fn();
        } finally {
            if ($previous instanceof \stdClass) {
                try {
                    \core\session\manager::set_user($previous);
                } catch (\Throwable $e) {
                    $USER = $previous;
                }
            }
        }
    }

    /**
     * Translate the inbound course params (snake/camel mix) to the keys
     * course_writer::upsert_course expects.
     *
     * @param array $params
     * @return array
     */
    private static function map_course_params(array $params): array {
        $map = [
            'id' => self::param($params, 'id', 'id'),
            'shortname' => self::param($params, 'shortname', 'shortname'),
            'fullname' => self::param($params, 'fullname', 'fullname'),
            'summary' => self::param($params, 'summary', 'summary'),
            'summaryformat' => self::param($params, 'summary_format', 'summaryFormat'),
            'format' => self::param($params, 'format', 'format'),
            'category' => self::param($params, 'category_id', 'categoryId') ?? self::param($params, 'category', 'category'),
            'visible' => self::param($params, 'visible', 'visible'),
            'numsections' => self::param($params, 'num_sections', 'numSections'),
        ];
        // Strip nulls so course_writer treats them as "not provided".
        return array_filter($map, function ($v) {
            return $v !== null;
        });
    }

    /**
     * Translate inbound module params to course_writer::upsert_module keys.
     *
     * @param array $entry
     * @return array
     */
    private static function map_module_params(array $entry): array {
        // Every key a module type can consume has to be listed here: this is
        // an allowlist, and anything missing is dropped before course_writer
        // ever sees it — which produces a module that exists but was never
        // filled in. Each entry is [internal key, snake_case, camelCase].
        $fields = [
            // Generic.
            ['cmid', 'cmid', 'cmid'],
            ['section', 'section', 'section'],
            ['modname', 'modname', 'modname'],
            ['name', 'name', 'name'],
            ['intro', 'intro', 'intro'],
            ['introformat', 'intro_format', 'introFormat'],
            ['visible', 'visible', 'visible'],
            ['showdescription', 'show_description', 'showDescription'],
            ['display', 'display', 'display'],
            ['grade', 'grade', 'grade'],
            ['grademethod', 'grademethod', 'gradeMethod'],
            ['files_itemid', 'files_itemid', 'filesItemId'],
            // Page.
            ['content', 'content', 'content'],
            ['contentformat', 'content_format', 'contentFormat'],
            // URL.
            ['externalurl', 'external_url', 'externalUrl'],
            // Folder.
            ['showexpanded', 'show_expanded', 'showExpanded'],
            // H5P activity.
            ['displayoptions', 'displayoptions', 'displayOptions'],
            ['enabletracking', 'enabletracking', 'enableTracking'],
            ['reviewmode', 'reviewmode', 'reviewMode'],
            // Assign.
            ['duedate', 'duedate', 'dueDate'],
            ['allowsubmissionsfromdate', 'allowsubmissionsfromdate', 'allowSubmissionsFromDate'],
            ['cutoffdate', 'cutoffdate', 'cutoffDate'],
            ['gradingduedate', 'gradingduedate', 'gradingDueDate'],
            ['submissiondrafts', 'submissiondrafts', 'submissionDrafts'],
            ['submission_onlinetext', 'submission_onlinetext', 'submissionOnlinetext'],
            ['submission_file', 'submission_file', 'submissionFile'],
            ['submission_file_maxfiles', 'submission_file_maxfiles', 'submissionFileMaxfiles'],
            // Book.
            ['numbering', 'numbering', 'numbering'],
            ['navstyle', 'navstyle', 'navStyle'],
            ['customtitles', 'customtitles', 'customTitles'],
            ['chapters', 'chapters', 'chapters'],
            // Forum.
            ['forumtype', 'forumtype', 'forumType'],
            ['forcesubscribe', 'forcesubscribe', 'forceSubscribe'],
            ['discussions', 'discussions', 'discussions'],
            // Glossary.
            ['displayformat', 'displayformat', 'displayFormat'],
            ['entries', 'entries', 'entries'],
            // Quiz.
            ['timeopen', 'timeopen', 'timeOpen'],
            ['timeclose', 'timeclose', 'timeClose'],
            ['timelimit', 'timelimit', 'timeLimit'],
            ['preferredbehaviour', 'preferredbehaviour', 'preferredBehaviour'],
            ['attempts', 'attempts', 'attempts'],
            ['questionsperpage', 'questionsperpage', 'questionsPerPage'],
            ['shuffleanswers', 'shuffleanswers', 'shuffleAnswers'],
            ['questions', 'questions', 'questions'],
        ];

        $map = [
            'course' => self::param($entry, 'course_id', 'courseId') ?? self::param($entry, 'course', 'course'),
        ];
        foreach ($fields as [$key, $snake, $camel]) {
            $map[$key] = self::param($entry, $snake, $camel);
        }

        return array_filter($map, function ($v) {
            return $v !== null;
        });
    }

    /**
     * Shape a Moodle course row into the response payload format.
     *
     * @param \stdClass $course
     * @return array
     */
    private static function shape_course(\stdClass $course): array {
        return [
            'id' => (int)$course->id,
            'shortname' => (string)$course->shortname,
            'fullname' => (string)$course->fullname,
            'category' => (int)$course->category,
            'visible' => (bool)$course->visible,
            'format' => (string)$course->format,
            'summary' => (string)$course->summary,
            'summary_format' => (int)$course->summaryformat,
        ];
    }

    /**
     * Look up a parameter under either of two key spellings.
     *
     * The backend may serialize action params as snake_case (Pydantic default)
     * or camelCase (ResponseVM mapper convention). Accept either so we don't
     * silently drop parameters from one side or the other.
     */
    private static function param(array $params, string $snake, string $camel) {
        if (array_key_exists($snake, $params)) {
            return $params[$snake];
        }
        if (array_key_exists($camel, $params)) {
            return $params[$camel];
        }
        return null;
    }

    /**
     * Return the first non-empty string value found under any of the given keys.
     *
     * @param array $params
     * @param array $keys
     * @return string
     */
    private static function string_param_any(array $params, array $keys): string {
        foreach ($keys as $key) {
            if (isset($params[$key]) && is_scalar($params[$key]) && (string)$params[$key] !== '') {
                return clean_param((string)$params[$key], PARAM_TEXT);
            }
        }
        return '';
    }

    /**
     * Run a console handler, turning refusals into an envelope.
     *
     * The console bridge expects every answer in the same shape, so a missing
     * record or a learner who is not in this course comes back as a refusal
     * with a code rather than as an exception the caller has to special-case.
     *
     * @param caller $caller
     * @param string $action
     * @param array $params
     * @return array
     */
    private static function run_console_action(caller $caller, string $action, array $params): array {
        try {
            switch ($action) {
                case 'console_context':
                    return self::ok(console_actions::context($caller));
                case 'get_learner_overview':
                    return self::ok(console_actions::learner_overview($caller, $params));
                case 'send_user_message':
                    return console_actions::send_user_message($caller, $params);
                default:
                    return self::deny(404);
            }
        } catch (\required_capability_exception $e) {
            return self::deny(403, ['code' => 'capability_required']);
        } catch (\moodle_exception $e) {
            return self::deny(400, ['code' => $e->errorcode]);
        }
    }

    /**
     * Report which H5P libraries this site has installed.
     *
     * A content-only .h5p package — h5p.json plus content/, without the
     * library folders — is only importable when every preloaded dependency is
     * already present at a matching major.minor version. Moodle ships none of
     * them: they arrive when an admin fetches content types from the H5P hub
     * or uploads a full package once. Rather than have the generator guess
     * versions and find out through a failed import, let it ask.
     *
     * @param array $params Optionally machinenames[] to narrow the answer.
     * @return array
     */
    private static function action_list_h5p_libraries(array $params): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('h5p_libraries')) {
            return self::ok(['available' => false, 'libraries' => []]);
        }

        $conditions = [];
        $names = self::param($params, 'machinenames', 'machineNames');
        if (is_array($names) && !empty($names)) {
            $clean = array_values(array_filter(array_map(static function ($n) {
                return clean_param((string)$n, PARAM_ALPHANUMEXT);
            }, $names)));
            if (!empty($clean)) {
                $conditions['machinename'] = $clean;
            }
        }

        if (isset($conditions['machinename'])) {
            [$insql, $inparams] = $DB->get_in_or_equal($conditions['machinename'], SQL_PARAMS_NAMED, 'mn');
            $records = $DB->get_records_select(
                'h5p_libraries',
                'machinename ' . $insql,
                $inparams,
                'machinename ASC, majorversion ASC, minorversion ASC'
            );
        } else {
            $records = $DB->get_records(
                'h5p_libraries',
                null,
                'machinename ASC, majorversion ASC, minorversion ASC'
            );
        }

        $libraries = [];
        foreach ($records as $row) {
            $libraries[] = [
                'machinename' => (string)$row->machinename,
                'title' => (string)($row->title ?? ''),
                'major' => (int)$row->majorversion,
                'minor' => (int)$row->minorversion,
                'patch' => (int)$row->patchversion,
                'version' => (int)$row->majorversion . '.' . (int)$row->minorversion,
                'runnable' => !empty($row->runnable),
                'enabled' => !isset($row->enabled) || !empty($row->enabled),
            ];
        }

        return self::ok([
            'available' => true,
            'count' => count($libraries),
            'libraries' => $libraries,
        ]);
    }

    /**
     * Store one file in a draft area so a later module can reference it.
     *
     * Runs as the service user because draft areas live in a user context and
     * upsert_modules resolves them as that same account.
     *
     * @param array $params
     * @return array
     */
    private static function action_upload_file(array $params): array {
        $actor = self::actor_user();
        if (!$actor) {
            return self::deny(403, ['error' => 'no_actor_user']);
        }
        try {
            $result = self::run_as($actor, static function () use ($params) {
                return file_uploader::upload($params);
            });
            return self::ok($result);
        } catch (\moodle_exception $e) {
            return self::deny(400, ['code' => $e->errorcode, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return self::deny(500, ['error' => 'upload_failed']);
        }
    }

    /**
     * Deliver a backend-triggered notification to a course's responsible staff.
     *
     * The heavy lifting — recipient verification, dedup, message building —
     * lives in {@see notifier}; this only shapes the response envelope.
     *
     * @param array $params
     * @return array
     */
    private static function action_notify(array $params): array {
        try {
            return self::ok(notifier::send($params));
        } catch (\moodle_exception $e) {
            return self::deny(400, ['code' => $e->errorcode]);
        }
    }

    /**
     * Accept canonical action names and lifecycle aliases from the portal.
     *
     * @param array $body
     * @return string
     */
    private static function normalise_action(array $body): string {
        $raw = '';
        foreach (['action', 'event_type', 'event'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                $raw = strtolower(trim($body[$key]));
                break;
            }
        }

        switch ($raw) {
            case 'pause':
            case 'paused':
            case 'site.paused':
                return 'pause_site';
            case 'resume':
            case 'resumed':
            case 'site.resumed':
                return 'resume_site';
            case 'disconnect':
            case 'disconnected':
            case 'site.disconnected':
                return 'disconnect_site';
            default:
                return $raw;
        }
    }

    /**
     * Drop queued lifecycle events superseded by an inbound portal action.
     *
     * @param string $eventtype
     * @return void
     */
    private static function drop_queued_lifecycle_event(string $eventtype): void {
        global $DB;

        try {
            $rows = $DB->get_records('block_alphabees_retryqueue', [
                'endpoint' => site_registry::path_lifecycle(),
            ]);
            foreach ($rows as $row) {
                $payload = json_decode((string)$row->payload, true);
                if (
                    is_array($payload)
                    && isset($payload['event_type'])
                    && (string)$payload['event_type'] === $eventtype
                ) {
                    $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                }
            }
        } catch (\Throwable $e) {
            debugging('[block_alphabees] inbound resume queue cleanup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Interpret inbound boolean-like params consistently.
     *
     * @param mixed $value
     * @return bool
     */
    private static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    // Response helpers.

    /**
     * Build a 200 OK response envelope.
     *
     * @param array $body
     * @return array
     */
    private static function ok(array $body): array {
        return ['httpStatus' => 200, 'body' => array_merge(['ok' => true], $body)];
    }

    /**
     * Build a non-2xx response envelope with the given status.
     *
     * @param int $status
     * @param array $body
     * @return array
     */
    private static function deny(int $status, array $body = []): array {
        return ['httpStatus' => $status, 'body' => array_merge(['ok' => false], $body)];
    }
}
