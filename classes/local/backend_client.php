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
 * Outbound signed HTTP client.
 *
 * Sends Ed25519-signed JSON POSTs to the Alphabees backend. Failures are
 * persisted to `block_alphabees_retryqueue` so an ad-hoc cron task can retry
 * them without losing data.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Backend client.
 */
class backend_client {

    /** Successfully posted; response decoded. */
    public const STATUS_OK = 'ok';

    /** Posted but server returned an error status. Not retried automatically. */
    public const STATUS_ERROR = 'error';

    /** Network-level failure. Caller should queue for retry. */
    public const STATUS_TRANSIENT = 'transient';

    /**
     * Send a signed JSON POST to a relative backend path.
     *
     * @param string $path     Begins with `/`. Joined with backend base URL.
     * @param array  $payload  Encoded as JSON.
     * @return array { status: STATUS_*, httpcode: int, response: array|null, error: string|null }
     */
    public static function post(string $path, array $payload): array {
        $secretkey = site_registry::secret_key();
        $publickey = site_registry::public_key();
        if ($secretkey === null || $publickey === null) {
            return [
                'status' => self::STATUS_ERROR,
                'httpcode' => 0,
                'response' => null,
                'error' => 'no_keypair',
            ];
        }

        $url = site_registry::backend_url() . $path;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'status' => self::STATUS_ERROR,
                'httpcode' => 0,
                'response' => null,
                'error' => 'json_encode_failed',
            ];
        }

        $timestamp = time();
        $nonce = crypto::random_nonce();
        $siteid = site_registry::site_identifier();
        $canonical = crypto::canonical_string('POST', $path, $timestamp, $nonce, $body, $siteid);
        $signature = crypto::sign($canonical, $secretkey);

        // Sanity-check: verify the signature locally before sending. If this
        // fails, our keypair is internally inconsistent and there's no point
        // hitting the network.
        $localverify = sodium_crypto_sign_verify_detached(
            crypto::base64url_decode($signature),
            $canonical,
            $publickey
        );

        // Dev-only diagnostic: dump everything the verify-side needs to diff.
        // Hex forms are included alongside base64url so the backend team
        // can compare bytes 1:1 with their decoded values. Explicit byte
        // lengths so we don't have to count manually.
        if (defined('DEBUG_DEVELOPER') && debugging('', DEBUG_DEVELOPER)) {
            $sigraw = crypto::base64url_decode($signature);
            $pubb64 = crypto::base64url_encode($publickey);
            debugging(
                "[block_alphabees] outbound POST {$path} | canonical lines:\n"
                    . "  method:    POST\n"
                    . "  path:      {$path}\n"
                    . "  timestamp: {$timestamp}\n"
                    . "  nonce:     {$nonce}    (b64_len=" . strlen($nonce) . ")\n"
                    . "  body_sha:  " . hash('sha256', $body) . "\n"
                    . "  site:      {$siteid}\n"
                    . "  ---\n"
                    . "  pub_b64u:  {$pubb64}    (b64_len=" . strlen($pubb64) . ")\n"
                    . "  pub_raw:   " . bin2hex($publickey) . "    (raw_len=" . strlen($publickey) . ")\n"
                    . "  sig_b64u:  {$signature}    (b64_len=" . strlen($signature) . ")\n"
                    . "  sig_raw:   " . bin2hex($sigraw) . "    (raw_len=" . strlen($sigraw) . ")\n"
                    . "  body_len:  " . strlen($body) . " bytes\n"
                    . "  local_verify: " . ($localverify ? 'PASS' : 'FAIL'),
                DEBUG_DEVELOPER
            );
        }

        if (!$localverify) {
            // Plugin keypair is broken — refuse to send so we don't trigger
            // a useless 401 that masks the real issue.
            return [
                'status' => self::STATUS_ERROR,
                'httpcode' => 0,
                'response' => null,
                'error' => 'local_keypair_inconsistent',
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Alphabees-Site: ' . $siteid,
            'X-Alphabees-Algo: ed25519',
            'X-Alphabees-KeyId: ' . site_registry::key_id(),
            'X-Alphabees-Timestamp: ' . $timestamp,
            'X-Alphabees-Nonce: ' . $nonce,
            'X-Alphabees-Signature: ' . $signature,
        ];

        $curl = new \curl(['timeout' => 15]);
        $curl->setopt([
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_HTTPHEADER' => $headers,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $rawresponse = $curl->post($url, $body);
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        $errno = $curl->get_errno();

        if ($errno !== 0 || $httpcode === 0) {
            return [
                'status' => self::STATUS_TRANSIENT,
                'httpcode' => $httpcode,
                'response' => null,
                'error' => $curl->error ?: 'transport_error',
            ];
        }

        $decoded = json_decode((string)$rawresponse, true);
        if ($httpcode >= 200 && $httpcode < 300) {
            return [
                'status' => self::STATUS_OK,
                'httpcode' => $httpcode,
                'response' => is_array($decoded) ? $decoded : null,
                'error' => null,
            ];
        }

        // 5xx → transient (server-side issue, retry); 4xx → error (client-side, don't retry).
        $status = ($httpcode >= 500) ? self::STATUS_TRANSIENT : self::STATUS_ERROR;
        return [
            'status' => $status,
            'httpcode' => $httpcode,
            'response' => is_array($decoded) ? $decoded : null,
            'error' => self::extract_error_message($decoded),
        ];
    }

    /**
     * Pull a human-readable error string out of whatever the backend returned.
     *
     * Supports:
     *   - { error: "..." }                            (our own convention)
     *   - { detail: "..." }                           (FastAPI shorthand)
     *   - { detail: [{loc:[...], msg:"..."}, ...] }   (FastAPI Pydantic validation)
     *   - { message: "..." }                          (some intermediaries)
     *   - anything else → null
     */
    private static function extract_error_message($decoded): ?string {
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
        if (isset($decoded['detail'])) {
            $detail = $decoded['detail'];
            if (is_string($detail)) {
                return $detail;
            }
            if (is_array($detail)) {
                $parts = [];
                foreach ($detail as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $loc = isset($item['loc']) && is_array($item['loc'])
                        ? implode('.', array_map('strval', $item['loc']))
                        : '';
                    $msg = isset($item['msg']) ? (string)$item['msg'] : '';
                    $parts[] = trim(($loc !== '' ? $loc . ': ' : '') . $msg);
                }
                if (!empty($parts)) {
                    return implode(' | ', $parts);
                }
            }
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }
        return null;
    }

    /**
     * Convenience: POST and on transient failure, queue for retry.
     *
     * Returns the same shape as post(). Never throws.
     */
    public static function post_with_retry(string $path, array $payload): array {
        $result = self::post($path, $payload);
        if ($result['status'] === self::STATUS_TRANSIENT) {
            self::queue_retry($path, $payload);
        }
        return $result;
    }

    /**
     * Persist a request to the retry queue for later processing.
     *
     * @param string $path
     * @param array $payload
     * @return void
     */
    public static function queue_retry(string $path, array $payload): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_alphabees_retryqueue', (object)[
            'endpoint' => $path,
            'method' => 'POST',
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'nextattempt' => $now + 30,
            'lasterror' => null,
            'timecreated' => $now,
        ]);
    }
}
