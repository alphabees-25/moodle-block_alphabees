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
 * Ad-hoc task that performs initial registration with the Alphabees backend.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\connection_manager;
use block_alphabees\local\crypto;
use block_alphabees\local\site_registry;

/**
 * register_site task.
 *
 * Runs when an admin explicitly connects this site. Sends:
 *   - apiKey, siteIdentifier, siteUrl, siteName, adminEmail
 *   - moodleVersion, pluginVersion, language
 *   - publicKey, keyId
 *
 * Receives:
 *   - backendPublicKey
 *   - registeredAt (informational)
 */
class register_site extends \core\task\adhoc_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_register_site', 'block_alphabees');
    }

    /**
     * Perform the registration POST and persist the backend's public key.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $SITE;
        $apikey = get_config('block_alphabees', 'apikey');
        if (empty($apikey)) {
            mtrace('[block_alphabees] register_site: no apikey configured, skipping.');
            return;
        }
        if (site_registry::is_portal_disconnected()) {
            mtrace('[block_alphabees] register_site: site was disconnected by portal, skipping.');
            return;
        }
        if (site_registry::is_registration_blocked()) {
            mtrace('[block_alphabees] register_site: registration blocked for current API key, skipping.');
            return;
        }

        $publickey = site_registry::ensure_keypair();
        // Backend Pydantic models use snake_case keys by default. Match that
        // for every outbound payload — keep symmetry across all routes.
        $payload = [
            'api_key' => (string)$apikey,
            'site_identifier' => site_registry::site_identifier(),
            'site_url' => (string)$CFG->wwwroot,
            'site_name' => isset($SITE->fullname) ? (string)$SITE->fullname : '',
            'admin_email' => self::primary_admin_email(),
            'moodle_version' => (string)$CFG->release,
            'plugin_version' => self::plugin_release(),
            'language' => current_language(),
            'public_key' => crypto::base64url_encode($publickey),
            'key_id' => site_registry::key_id(),
        ];

        $result = backend_client::post(site_registry::path_register(), $payload);

        if ($result['status'] === backend_client::STATUS_OK) {
            $response = $result['payload'] ?? ($result['response'] ?? []);
            // Accept both snake_case (Pydantic default) and camelCase (VM-mapper
            // convention) so we work against either backend serializer.
            $backendpubb64 = '';
            if (isset($response['backend_public_key'])) {
                $backendpubb64 = (string)$response['backend_public_key'];
            } else if (isset($response['backendPublicKey'])) {
                $backendpubb64 = (string)$response['backendPublicKey'];
            }

            // Snapshot of what the backend actually sent — capped at 200 chars
            // so we can debug format mismatches (PEM vs raw base64 vs hex)
            // without flooding the error log.
            $shape = self::describe_value($backendpubb64);

            if ($backendpubb64 === '') {
                $keys = is_array($response) ? implode(',', array_keys($response)) : '<not_array>';
                $msg = 'Backend response missing backend_public_key (response keys: ' . $keys . ')';
                site_registry::record_register_attempt($msg);
                mtrace('[block_alphabees] register_site: ' . $msg);
                throw new \moodle_exception('registrationfailed', 'block_alphabees');
            }
            try {
                $rawpub = self::decode_backend_pubkey($backendpubb64);
            } catch (\Throwable $e) {
                $msg = 'Malformed backend_public_key — ' . $shape;
                site_registry::record_register_attempt($msg);
                mtrace('[block_alphabees] register_site: ' . $msg);
                throw new \moodle_exception('registrationfailed', 'block_alphabees');
            }
            if (strlen($rawpub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $msg = 'backend_public_key decoded to wrong length: ' . strlen($rawpub)
                    . ' bytes (expected ' . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ') — ' . $shape;
                site_registry::record_register_attempt($msg);
                mtrace('[block_alphabees] register_site: ' . $msg);
                throw new \moodle_exception('registrationfailed', 'block_alphabees');
            }
            site_registry::set_backend_public_key($rawpub);
            if (!empty($response['registration_id'])) {
                site_registry::set_registration_id((string)$response['registration_id']);
            }
            if (isset($response['backend_key_id']) && is_numeric($response['backend_key_id'])) {
                site_registry::set_backend_key_id((int)$response['backend_key_id']);
            } else if (isset($response['backendKeyId']) && is_numeric($response['backendKeyId'])) {
                site_registry::set_backend_key_id((int)$response['backendKeyId']);
            }
            site_registry::mark_registered();
            site_registry::record_register_attempt(null);
            mtrace('[block_alphabees] register_site: registered successfully');

            // Once registration succeeds, enable the default integration
            // toggles and web-services setup so the explicit Connect action
            // leaves the site fully usable.
            try {
                connection_manager::activate_defaults();
                mtrace('[block_alphabees] register_site: default integrations enabled.');
            } catch (\Throwable $e) {
                mtrace('[block_alphabees] register_site: default integration setup failed: '
                    . $e->getMessage());
            }

            // Initial backfill: push every existing block placement to the
            // backend so the portal sees the full state immediately, instead
            // of waiting up to an hour for the next scheduled heartbeat.
            // Wrapped in try/catch so a transient sync failure doesn't roll
            // back a successful registration — the hourly task will retry.
            try {
                (new sync_placements())->execute();
            } catch (\Throwable $e) {
                mtrace('[block_alphabees] register_site: initial sync failed (will retry hourly): '
                    . $e->getMessage());
            }
            return;
        }

        // Transient → throw so Moodle reschedules with backoff.
        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            $msg = 'Transient failure (network / backend unreachable): '
                . ($result['error'] ?? 'unknown')
                . ' [http=' . $result['httpcode'] . ']';
            site_registry::record_register_attempt($msg);
            mtrace('[block_alphabees] register_site: ' . $msg);
            throw new \moodle_exception('registrationtransient', 'block_alphabees');
        }

        // Permanent error (4xx). Don't retry. Only a structured register
        // response with api_key_rejected=true means the API key itself should
        // be latched locally as rejected.
        $msg = 'Backend rejected request: http=' . $result['httpcode']
            . ' err=' . ($result['error'] ?? 'unknown');
        site_registry::record_register_attempt($msg);
        if (($result['action'] ?? null) === 'register' && !empty($result['api_key_rejected'])) {
            site_registry::mark_registration_blocked($msg, (int)$result['httpcode']);
        }
        mtrace('[block_alphabees] register_site: permanent failure, abandoning. ' . $msg);
    }

    /**
     * Decode an Ed25519 public key from one of the formats a backend might
     * reasonably send: raw base64 / base64url, hex, or PEM-wrapped SPKI.
     *
     * Returns 32 raw bytes or throws.
     */
    private static function decode_backend_pubkey(string $value): string {
        $trimmed = trim($value);

        // PEM-wrapped SPKI: strip header/footer/whitespace, decode, slice off
        // the last 32 bytes (which for Ed25519 SPKI are the raw public key).
        if (strpos($trimmed, '-----BEGIN') !== false) {
            $stripped = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $trimmed);
            $der = base64_decode($stripped, true);
            if ($der !== false && strlen($der) >= SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return substr($der, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            }
            throw new \InvalidArgumentException('PEM decode failed');
        }

        // Hex string: 64 chars, all hex.
        if (strlen($trimmed) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2
            && ctype_xdigit($trimmed)) {
            $bin = hex2bin($trimmed);
            if ($bin !== false) {
                return $bin;
            }
        }

        // Default: base64url (with optional padding) or standard base64.
        return crypto::base64url_decode($trimmed);
    }

    /**
     * Short human-readable description of a value for diagnostic messages.
     *
     * @param string $value
     * @return string
     */
    private static function describe_value(string $value): string {
        $len = strlen($value);
        $head = substr($value, 0, 60);
        $shape = 'raw_b64url';
        if (strpos($value, '-----BEGIN') !== false) {
            $shape = 'PEM';
        } else if ($len > 0 && ctype_xdigit($value)) {
            $shape = 'hex';
        } else if (strpos($value, '+') !== false || strpos($value, '/') !== false || strpos($value, '=') !== false) {
            $shape = 'standard_b64';
        }
        return "shape={$shape} len={$len} head='{$head}'";
    }

    /**
     * Email of the lowest-id active admin, best-effort.
     *
     * @return string
     */
    private static function primary_admin_email(): string {
        $admins = get_admins();
        $admin = reset($admins);
        return ($admin && !empty($admin->email)) ? (string)$admin->email : '';
    }

    /**
     * Plugin release string from version.php.
     *
     * @return string
     */
    private static function plugin_release(): string {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        return isset($plugin->release) ? (string)$plugin->release : '';
    }
}
