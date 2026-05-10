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
     * Hardcoded backend URL.
     *
     * Not exposed as a Moodle admin setting on purpose — this is a public
     * plugin and the backend host is not something admins should be tweaking.
     * Changing the production URL means a code change + plugin release.
     */
    public const BACKEND_URL = 'https://api.alphalearn.ai';

    /**
     * Base path prefix for the al-tutor service that hosts our endpoints.
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
     * Record that the site has just successfully registered.
     *
     * @return void
     */
    public static function mark_registered(): void {
        set_config('registered_at', (string)time(), self::COMPONENT);
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
        unset_config('last_register_attempt_at', self::COMPONENT);
        unset_config('last_register_error', self::COMPONENT);
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
}
