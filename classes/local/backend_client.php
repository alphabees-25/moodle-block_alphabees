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
            return self::result(self::STATUS_ERROR, 0, null, null, 'no_keypair');
        }

        $url = site_registry::backend_url() . $path;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return self::result(self::STATUS_ERROR, 0, null, null, 'json_encode_failed');
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

        if (!$localverify) {
            // Plugin keypair is broken — refuse to send so we don't trigger
            // a useless 401 that masks the real issue.
            return self::result(self::STATUS_ERROR, 0, null, null, 'local_keypair_inconsistent');
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
            return self::result(
                self::STATUS_TRANSIENT,
                $httpcode,
                null,
                null,
                $curl->error ?: 'transport_error',
                ['retryable' => true, 'code' => 'transport_error']
            );
        }

        $decoded = json_decode((string)$rawresponse, true);
        $response = is_array($decoded) ? $decoded : null;
        $payload = self::normalise_payload($response);
        $retryable = (bool)($payload['retryable'] ?? false);
        $ok = (bool)($payload['ok'] ?? ($httpcode >= 200 && $httpcode < 300));
        $error = $ok ? null : self::extract_error_message($response, $payload);

        if ($ok) {
            return self::result(self::STATUS_OK, $httpcode, $response, $payload, $error);
        }

        // Structured backend responses are the source of truth. Fall back to
        // 5xx as transient for older/unstructured responses.
        $status = ($retryable || $httpcode >= 500) ? self::STATUS_TRANSIENT : self::STATUS_ERROR;
        return self::result($status, $httpcode, $response, $payload, $error);
    }

    /**
     * Build the stable result shape consumed by task code.
     *
     * @param string $status
     * @param int $httpcode
     * @param array|null $response Raw decoded body.
     * @param array|null $payload Normalised backend payload.
     * @param string|null $error Fallback error message.
     * @param array $overrides Normalised field overrides for local errors.
     * @return array
     */
    private static function result(
        string $status,
        int $httpcode,
        ?array $response,
        ?array $payload,
        ?string $error,
        array $overrides = []
    ): array {
        $payload = $payload ?? [];
        $normalised = array_merge([
            'ok' => $status === self::STATUS_OK,
            'code' => null,
            'api_key_rejected' => false,
            'retryable' => $status === self::STATUS_TRANSIENT,
            'action' => null,
            'registration_id' => null,
            'health_status' => null,
            'ignored' => false,
        ], $payload, $overrides);

        return [
            'status' => $status,
            'httpcode' => $httpcode,
            'response' => $response,
            'payload' => $normalised,
            'error' => $error ?? self::message_from_payload($normalised) ?? ($normalised['code'] ?? null),
            'ok' => (bool)$normalised['ok'],
            'code' => $normalised['code'],
            'api_key_rejected' => (bool)$normalised['api_key_rejected'],
            'retryable' => (bool)$normalised['retryable'],
            'action' => $normalised['action'],
            'registration_id' => $normalised['registration_id'],
            'health_status' => $normalised['health_status'],
            'ignored' => (bool)$normalised['ignored'],
        ];
    }

    /**
     * Return the backend payload, unwrapping FastAPI HTTPException detail.
     *
     * @param array|null $body
     * @return array
     */
    private static function normalise_payload(?array $body): array {
        if ($body === null) {
            return [];
        }
        if (isset($body['detail']) && is_array($body['detail']) && !self::is_list_array($body['detail'])) {
            return $body['detail'];
        }
        return $body;
    }

    /**
     * Polyfill-style list-array check for PHP versions without array_is_list().
     *
     * @param array $value
     * @return bool
     */
    private static function is_list_array(array $value): bool {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /**
     * Whether a backend response says this local registration must be recreated.
     *
     * @param array $result Result returned by post().
     * @return bool
     */
    public static function requires_reconnect(array $result): bool {
        $code = (string)($result['code'] ?? '');
        return in_array($code, [
            'site_disconnected',
            'site_disconnected_reconnect_required',
        ], true);
    }

    /**
     * Whether a backend response reports a non-retryable registration mismatch.
     *
     * @param array $result Result returned by post().
     * @return bool
     */
    public static function is_registration_mismatch(array $result): bool {
        $code = (string)($result['code'] ?? '');
        return in_array($code, [
            'site_identifier_mismatch',
            'registration_mismatch',
            'site_url_mismatch',
        ], true);
    }

    /**
     * Pull a human-readable error string out of whatever the backend returned.
     *
     * Supports:
     *   - { error: "..." }                            (our own convention)
     *   - { detail: "..." }                           (FastAPI shorthand)
     *   - { detail: [{loc:[...], msg:"..."}, ...] }   (FastAPI Pydantic validation)
     *   - { message: "..." }                          (some intermediaries)
     *   - structured {code,message,...} payloads
     *   - anything else → null
     */
    private static function extract_error_message($decoded, ?array $payload = null): ?string {
        $payloadmessage = self::message_from_payload($payload ?? []);
        if ($payloadmessage !== null) {
            return $payloadmessage;
        }
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
     * Extract a compact message from a structured backend payload.
     *
     * @param array $payload
     * @return string|null
     */
    private static function message_from_payload(array $payload): ?string {
        if (isset($payload['message']) && is_string($payload['message'])) {
            $message = $payload['message'];
            if (!empty($payload['code']) && strpos($message, (string)$payload['code']) === false) {
                return (string)$payload['code'] . ': ' . $message;
            }
            return $message;
        }
        if (isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
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
    public static function queue_retry(string $path, array $payload, int $delay = 30): void {
        global $DB;
        $now = time();
        $DB->insert_record('block_alphabees_retryqueue', (object)[
            'endpoint' => $path,
            'method' => 'POST',
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'nextattempt' => $now + max(0, $delay),
            'lasterror' => null,
            'timecreated' => $now,
        ]);
    }
}
