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
 * Site identity, keypair persistence, and registration state.
 *
 * Holds the long-lived Ed25519 keys for this Moodle install, the backend's
 * public key, the active key id, and the registration timestamps.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Site registry.
 *
 * All persistent state is stored in `config_plugins` keyed under the
 * `block_alphabees` component. Secret keys are encrypted at rest via
 * `\core\encryption`, which uses `$CFG->datarootsecret` as KEK.
 */
class site_registry {

    /** Component name used for `config_plugins` lookups. */
    private const COMPONENT = 'block_alphabees';

    /**
     * Hardcoded backend host URL.
     *
     * Not exposed as a Moodle admin setting on purpose — this is a public
     * plugin and the backend host is not something admins should be tweaking.
     * Changing the production URL means a code change + plugin release.
     *
     * The actual Moodle plugin endpoints are more specific and are built by
     * appending API_BASE + route paths below. For example, registration is:
     *   https://api.alphalearn.ai/al/tutors/v1/moodle/register
     */
    public const BACKEND_URL = 'https://api.alphalearn.ai';

    /**
     * Base path prefix for the al-tutor service that hosts our endpoints.
     *
     * Together with BACKEND_URL this is the service base URL:
     *   https://api.alphalearn.ai/al/tutors
     *
     * Outbound calls are constructed as `{backend_url}{API_BASE}{relative}`.
     * Centralised so a future namespace move only changes one place.
     */
    public const API_BASE = '/al/tutors';

    /**
     * Return the full path for the moodle-plugin register endpoint.
     *
     * @return string
     */
    public static function path_register(): string {
        return self::API_BASE . '/v1/moodle/register';
    }

    /**
     * Return the lifecycle-event endpoint path for this site.
     *
     * @return string
     */
    public static function path_placements(): string {
        return self::API_BASE . '/v1/moodle/sites/'
            . rawurlencode(self::site_identifier()) . '/placements';
    }

    /**
     * Return the heartbeat sync endpoint path for this site.
     *
     * @return string
     */
    public static function path_sync(): string {
        return self::API_BASE . '/v1/moodle/sites/'
            . rawurlencode(self::site_identifier()) . '/sync';
    }

    /**
     * Return the site lifecycle endpoint path for pause/resume events.
     *
     * @return string
     */
    public static function path_lifecycle(): string {
        return self::API_BASE . '/v1/moodle/sites/'
            . rawurlencode(self::site_identifier()) . '/lifecycle';
    }

    /**
     * Return the web-services token exchange endpoint path for this site.
     *
     * @return string
     */
    public static function path_ws_token(): string {
        return self::API_BASE . '/v1/moodle/sites/'
            . rawurlencode(self::site_identifier()) . '/ws-token';
    }

    /**
     * Return Moodle's stable site identifier.
     *
     * @return string
     */
    public static function site_identifier(): string {
        global $CFG;
        return (string)$CFG->siteidentifier;
    }

    /**
     * Return the backend base URL (no trailing slash).
     *
     * @return string
     */
    public static function backend_url(): string {
        return rtrim(self::BACKEND_URL, '/');
    }

    /**
     * Generate and persist a keypair if none exists, OR if the existing pair
     * is internally inconsistent (secret_key doesn't match public_key).
     *
     * Returns the active public key bytes.
     *
     * The consistency check is cheap (one local sign+verify) and prevents the
     * subtle bug where one config row got rewritten but the other didn't —
     * the symptom is an "Unauthorized" from the backend even though the
     * canonical-string matches, because the body's public_key doesn't
     * actually correspond to the secret_key we used to sign.
     *
     * @return string Raw 32-byte public key.
     */
    public static function ensure_keypair(): string {
        $publickey = self::public_key();
        $secretkey = self::secret_key();

        if ($publickey !== null && $secretkey !== null
            && self::keypair_self_test($secretkey, $publickey)) {
            return $publickey;
        }

        [$newsecret, $newpublic] = crypto::generate_keypair();
        $encrypted = \core\encryption::encrypt($newsecret);
        set_config('moodle_secret_key_enc', $encrypted, self::COMPONENT);
        set_config('moodle_public_key', crypto::base64url_encode($newpublic), self::COMPONENT);
        set_config('key_id', '1', self::COMPONENT);
        return $newpublic;
    }

    /**
     * Locally sign+verify a fixed marker so we know the secret key and
     * public key actually pair. Belt-and-braces against config-store drift
     * (secret reset without public rotated, or vice versa).
     *
     * @param string $secretkey Raw 64-byte Ed25519 secret key.
     * @param string $publickey Raw 32-byte Ed25519 public key.
     * @return bool
     */
    private static function keypair_self_test(string $secretkey, string $publickey): bool {
        if (strlen($secretkey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || strlen($publickey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            $marker = 'block_alphabees:keypair_self_test';
            $sig = sodium_crypto_sign_detached($marker, $secretkey);
            return sodium_crypto_sign_verify_detached($sig, $marker, $publickey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return the active Ed25519 secret key (raw bytes), or null if not set.
     *
     * @return string|null
     */
    public static function secret_key(): ?string {
        $enc = get_config(self::COMPONENT, 'moodle_secret_key_enc');
        if (empty($enc)) {
            return null;
        }
        try {
            return \core\encryption::decrypt($enc);
        } catch (\Throwable $e) {
            debugging('[block_alphabees] failed to decrypt site secret key', DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Return the active Ed25519 public key (raw bytes), or null if not set.
     *
     * @return string|null
     */
    public static function public_key(): ?string {
        $b64 = get_config(self::COMPONENT, 'moodle_public_key');
        if (empty($b64)) {
            return null;
        }
        try {
            return crypto::base64url_decode($b64);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return the backend's Ed25519 public key (raw bytes), or null if not yet known.
     *
     * @return string|null
     */
    public static function backend_public_key(): ?string {
        $b64 = get_config(self::COMPONENT, 'backend_public_key');
        if (empty($b64)) {
            return null;
        }
        try {
            return crypto::base64url_decode($b64);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Persist the backend's public key (received during registration).
     *
     * @param string $rawkey Raw 32-byte public key.
     * @return void
     */
    public static function set_backend_public_key(string $rawkey): void {
        set_config('backend_public_key', crypto::base64url_encode($rawkey), self::COMPONENT);
    }

    /**
     * Persist the backend registration id, if returned by the portal.
     *
     * @param string $registrationid
     * @return void
     */
    public static function set_registration_id(string $registrationid): void {
        set_config('registration_id', $registrationid, self::COMPONENT);
    }

    /**
     * Return the backend registration id, if known.
     *
     * @return string|null
     */
    public static function registration_id(): ?string {
        $value = get_config(self::COMPONENT, 'registration_id');
        return $value ? (string)$value : null;
    }

    /**
     * Persist the backend key id used for inbound signatures.
     *
     * @param int $keyid
     * @return void
     */
    public static function set_backend_key_id(int $keyid): void {
        set_config('backend_key_id', (string)$keyid, self::COMPONENT);
    }

    /**
     * Return the backend key id used for inbound signatures.
     *
     * @return int
     */
    public static function backend_key_id(): int {
        return (int)(get_config(self::COMPONENT, 'backend_key_id') ?: self::key_id());
    }

    /**
     * Return the current key id used for outbound signing.
     *
     * @return int
     */
    public static function key_id(): int {
        return (int)(get_config(self::COMPONENT, 'key_id') ?: 1);
    }

    /**
     * True iff the site has completed registration with the backend.
     *
     * @return bool
     */
    public static function is_registered(): bool {
        return !empty(get_config(self::COMPONENT, 'registered_at'))
            && self::backend_public_key() !== null;
    }

    /**
     * Whether an API key is currently saved in plugin settings.
     *
     * @return bool
     */
    public static function api_key_present(): bool {
        return !empty(get_config(self::COMPONENT, 'apikey'));
    }

    /**
     * Status of the currently saved API key from the plugin's perspective.
     *
     * @return string One of: missing, rejected, present.
     */
    public static function api_key_status(): string {
        if (!self::api_key_present()) {
            return 'missing';
        }
        if (self::is_registration_blocked()) {
            return 'rejected';
        }
        return 'present';
    }

    /**
     * Fingerprint of the currently saved API key, or null when no key is saved.
     *
     * @return string|null
     */
    public static function current_api_key_fingerprint(): ?string {
        $apikey = get_config(self::COMPONENT, 'apikey');
        return empty($apikey) ? null : self::api_key_fingerprint((string)$apikey);
    }

    /**
     * Fingerprint of the API key used for the current successful registration.
     *
     * @return string|null
     */
    public static function registered_api_key_fingerprint(): ?string {
        $fingerprint = get_config(self::COMPONENT, 'registered_api_key');
        return $fingerprint ? (string)$fingerprint : null;
    }

    /**
     * True when the current API key is known to be permanently rejected.
     *
     * @return bool
     */
    public static function is_registration_blocked(): bool {
        $apikey = get_config(self::COMPONENT, 'apikey');
        if (empty($apikey)) {
            return false;
        }
        $state = (string)(get_config(self::COMPONENT, 'registration_blocked') ?: '');
        $fingerprint = (string)(get_config(self::COMPONENT, 'registration_blocked_key') ?: '');
        return $state === '1' && $fingerprint === self::api_key_fingerprint((string)$apikey);
    }

    /**
     * Return the persisted permanent registration error, if it applies now.
     *
     * @return string|null
     */
    public static function registration_block_reason(): ?string {
        if (!self::is_registration_blocked()) {
            return null;
        }
        $reason = get_config(self::COMPONENT, 'registration_blocked_reason');
        return $reason ? (string)$reason : null;
    }

    /**
     * Persist a permanent registration failure for the current API key.
     *
     * @param string $reason
     * @param int $httpcode
     * @return void
     */
    public static function mark_registration_blocked(string $reason, int $httpcode = 0): void {
        $apikey = (string)get_config(self::COMPONENT, 'apikey');
        set_config('registration_blocked', '1', self::COMPONENT);
        set_config('registration_blocked_key', self::api_key_fingerprint($apikey), self::COMPONENT);
        set_config('registration_blocked_at', (string)time(), self::COMPONENT);
        set_config('registration_blocked_httpcode', (string)$httpcode, self::COMPONENT);
        set_config('registration_blocked_reason', substr($reason, 0, 500), self::COMPONENT);
        set_config('last_register_attempt_at', (string)time(), self::COMPONENT);
        set_config('last_register_error', substr($reason, 0, 500), self::COMPONENT);
    }

    /**
     * Clear the permanent registration failure marker.
     *
     * @return void
     */
    public static function clear_registration_block(): void {
        unset_config('registration_blocked', self::COMPONENT);
        unset_config('registration_blocked_key', self::COMPONENT);
        unset_config('registration_blocked_at', self::COMPONENT);
        unset_config('registration_blocked_httpcode', self::COMPONENT);
        unset_config('registration_blocked_reason', self::COMPONENT);
    }

    /**
     * Record that the site has just successfully registered.
     *
     * @return void
     */
    public static function mark_registered(): void {
        set_config('registered_at', (string)time(), self::COMPONENT);
        $fingerprint = self::current_api_key_fingerprint();
        if ($fingerprint !== null) {
            set_config('registered_api_key', $fingerprint, self::COMPONENT);
        } else {
            unset_config('registered_api_key', self::COMPONENT);
        }
        self::clear_portal_disconnect();
        self::clear_registration_block();
    }

    /**
     * Update the heartbeat timestamp.
     *
     * @return void
     */
    public static function mark_synced(): void {
        set_config('last_sync_at', (string)time(), self::COMPONENT);
    }

    /**
     * Return the unix timestamp of the last successful registration, or null.
     *
     * @return int|null
     */
    public static function registered_at(): ?int {
        $val = get_config(self::COMPONENT, 'registered_at');
        return $val ? (int)$val : null;
    }

    /**
     * Return the unix timestamp of the last successful sync, or null.
     *
     * @return int|null
     */
    public static function last_sync_at(): ?int {
        $val = get_config(self::COMPONENT, 'last_sync_at');
        return $val ? (int)$val : null;
    }

    /**
     * Forget all registration state (used by reset / disconnect flow).
     *
     * @return void
     */
    public static function reset_registration(): void {
        unset_config('registered_at', self::COMPONENT);
        unset_config('last_sync_at', self::COMPONENT);
        unset_config('backend_public_key', self::COMPONENT);
        unset_config('backend_key_id', self::COMPONENT);
        unset_config('registration_id', self::COMPONENT);
        unset_config('registered_api_key', self::COMPONENT);
        unset_config('last_register_attempt_at', self::COMPONENT);
        unset_config('last_register_error', self::COMPONENT);
        self::resume_syncs();
        self::clear_registration_block();
    }

    /**
     * Record that a registration attempt happened (regardless of outcome).
     *
     * @param string|null $error Truncated error message, or null on success.
     * @return void
     */
    public static function record_register_attempt(?string $error = null): void {
        set_config('last_register_attempt_at', (string)time(), self::COMPONENT);
        if ($error === null) {
            unset_config('last_register_error', self::COMPONENT);
        } else {
            // Truncate to avoid bloating config_plugins with stack traces.
            set_config('last_register_error', substr($error, 0, 500), self::COMPONENT);
        }
    }

    /**
     * Unix timestamp of the last registration attempt, or null if none yet.
     *
     * @return int|null
     */
    public static function last_register_attempt_at(): ?int {
        $val = get_config(self::COMPONENT, 'last_register_attempt_at');
        return $val ? (int)$val : null;
    }

    /**
     * Last registration error message (truncated to 500 chars), or null.
     *
     * @return string|null
     */
    public static function last_register_error(): ?string {
        $val = get_config(self::COMPONENT, 'last_register_error');
        return $val ? (string)$val : null;
    }

    /**
     * True when outbound placement syncs/events are paused by the portal.
     *
     * @return bool
     */
    public static function is_sync_paused(): bool {
        return (string)(get_config(self::COMPONENT, 'sync_paused') ?: '') === '1';
    }

    /**
     * Pause outbound placement heartbeats/events without revoking registration.
     *
     * @param string|null $reason
     * @return void
     */
    public static function pause_syncs(?string $reason = null): void {
        set_config('sync_paused', '1', self::COMPONENT);
        set_config('sync_paused_at', (string)time(), self::COMPONENT);
        if ($reason === null || $reason === '') {
            unset_config('sync_paused_reason', self::COMPONENT);
        } else {
            set_config('sync_paused_reason', substr($reason, 0, 500), self::COMPONENT);
        }
    }

    /**
     * Resume outbound placement heartbeats/events.
     *
     * @return void
     */
    public static function resume_syncs(): void {
        unset_config('sync_paused', self::COMPONENT);
        unset_config('sync_paused_at', self::COMPONENT);
        unset_config('sync_paused_reason', self::COMPONENT);
    }

    /**
     * Return the unix timestamp when syncs were paused, or null.
     *
     * @return int|null
     */
    public static function sync_paused_at(): ?int {
        $val = get_config(self::COMPONENT, 'sync_paused_at');
        return $val ? (int)$val : null;
    }

    /**
     * Return the persisted sync pause reason, if any.
     *
     * @return string|null
     */
    public static function sync_pause_reason(): ?string {
        if (!self::is_sync_paused()) {
            return null;
        }
        $reason = get_config(self::COMPONENT, 'sync_paused_reason');
        return $reason ? (string)$reason : null;
    }

    /**
     * True when the portal intentionally disconnected this registration.
     *
     * @return bool
     */
    public static function is_portal_disconnected(): bool {
        return (string)(get_config(self::COMPONENT, 'portal_disconnected') ?: '') === '1';
    }

    /**
     * Mark this site as intentionally disconnected by the portal.
     *
     * @param string|null $reason
     * @return void
     */
    public static function mark_portal_disconnected(?string $reason = null): void {
        set_config('portal_disconnected', '1', self::COMPONENT);
        set_config('portal_disconnected_at', (string)time(), self::COMPONENT);
        if ($reason === null || $reason === '') {
            unset_config('portal_disconnected_reason', self::COMPONENT);
        } else {
            set_config('portal_disconnected_reason', substr($reason, 0, 500), self::COMPONENT);
        }
    }

    /**
     * Clear the portal-disconnected marker before a deliberate local reconnect.
     *
     * @return void
     */
    public static function clear_portal_disconnect(): void {
        unset_config('portal_disconnected', self::COMPONENT);
        unset_config('portal_disconnected_at', self::COMPONENT);
        unset_config('portal_disconnected_reason', self::COMPONENT);
    }

    /**
     * One-way fingerprint for comparing API keys without storing the secret.
     *
     * @param string $apikey
     * @return string
     */
    private static function api_key_fingerprint(string $apikey): string {
        return hash('sha256', $apikey);
    }
}
