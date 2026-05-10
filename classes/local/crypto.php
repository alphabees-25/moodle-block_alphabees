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
 * Ed25519 signing primitives + helpers used by the bidirectional auth channel.
 *
 * Wraps libsodium so the rest of the plugin never touches raw crypto APIs.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Crypto helper.
 *
 * All public methods are static and pure (no Moodle DB or config access).
 * Keep it that way — site_registry is the place for stateful concerns.
 */
class crypto {

    /** Maximum allowed clock skew between Moodle and backend. */
    public const TIMESTAMP_WINDOW_SECONDS = 300;

    /**
     * Generate a fresh Ed25519 keypair.
     *
     * @return array{0:string,1:string} [secretkey, publickey] raw bytes.
     */
    public static function generate_keypair(): array {
        $keypair = sodium_crypto_sign_keypair();
        return [
            sodium_crypto_sign_secretkey($keypair),
            sodium_crypto_sign_publickey($keypair),
        ];
    }

    /**
     * Return 16 random bytes, base64url encoded, used as nonce.
     *
     * @return string
     */
    public static function random_nonce(): string {
        return self::base64url_encode(random_bytes(16));
    }

    /**
     * Build the canonical string we sign / verify.
     *
     * Body is hashed (not embedded) so the signature is stable across
     * whitespace and any equivalent JSON serialization.
     *
     * @param string $method HTTP method.
     * @param string $path Request path.
     * @param int $timestamp Unix timestamp.
     * @param string $nonce Base64url-encoded random nonce.
     * @param string $body Raw request body.
     * @param string $siteidentifier Moodle site identifier.
     * @return string
     */
    public static function canonical_string(
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $body,
        string $siteidentifier
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body) . "\n"
            . $siteidentifier;
    }

    /**
     * Sign a canonical string with an Ed25519 secret key.
     *
     * @param string $canonical Canonical request string.
     * @param string $secretkey Raw 64-byte Ed25519 secret key.
     * @return string Base64url-encoded signature.
     */
    public static function sign(string $canonical, string $secretkey): string {
        $sig = sodium_crypto_sign_detached($canonical, $secretkey);
        return self::base64url_encode($sig);
    }

    /**
     * Verify a base64url-encoded Ed25519 signature against a canonical string.
     *
     * @param string $canonical Canonical request string.
     * @param string $signatureb64 Base64url-encoded signature.
     * @param string $publickey Raw 32-byte Ed25519 public key.
     * @return bool
     */
    public static function verify(string $canonical, string $signatureb64, string $publickey): bool {
        try {
            $sig = self::base64url_decode($signatureb64);
        } catch (\Throwable $e) {
            return false;
        }
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $canonical, $publickey);
    }

    /**
     * Strict base64url encode (no padding).
     *
     * @param string $bin Raw binary input.
     * @return string
     */
    public static function base64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Strict base64url decode. Throws on malformed input.
     *
     * @param string $b64 Base64url-encoded input.
     * @return string
     */
    public static function base64url_decode(string $b64): string {
        $remainder = strlen($b64) % 4;
        if ($remainder) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $bin = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($bin === false) {
            throw new \InvalidArgumentException('Malformed base64url input');
        }
        return $bin;
    }

    /**
     * Return true iff |now - timestamp| <= TIMESTAMP_WINDOW_SECONDS.
     *
     * @param int $timestamp Unix timestamp to check.
     * @param int|null $now Override "now" (used by tests).
     * @return bool
     */
    public static function timestamp_within_window(int $timestamp, ?int $now = null): bool {
        $now = $now ?? time();
        return abs($now - $timestamp) <= self::TIMESTAMP_WINDOW_SECONDS;
    }

    /**
     * Generate an RFC 4122 v4 UUID.
     *
     * @return string
     */
    public static function uuid_v4(): string {
        $data = random_bytes(16);
        // Version 4.
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Variant 1.
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
