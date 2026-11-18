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
 * Signed proof of who opened the tutor, on which page.
 *
 * The widget runs in the learner's browser, so the Moodle user id it reports
 * is whatever the browser says. This token is what the backend trusts instead:
 * minted by Moodle for the signed-in person, signed with the site keypair the
 * backend received at registration, valid for ten minutes and once.
 *
 * Same format as console_token — `v1.<payload base64url>.<signature base64url>`,
 * the signature over the encoded payload as transmitted — and the same key, so
 * `typ` is what keeps the two apart. The backend must check it on both paths.
 *
 * Course and activity are checked here, not taken on trust: the refresh
 * endpoint and the app pass them in from the client, and a token naming a
 * course the person cannot open would hand that claim a signature. One that
 * does not check out is signed as 0 — the identity still holds.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Issues start tokens for the chat widget.
 */
class start_token {
    /** Token format marker, shared with console_token. */
    public const VERSION = 'v1';

    /** Payload type; console tokens carry 'console'. */
    public const TYPE = 'moodle_start';

    /** Lifetime in seconds. */
    public const TTL = 600;

    /**
     * Mint a token for a signed-in user on a page.
     *
     * @param int $userid
     * @param int $courseid Course of the page; SITEID or 0 off course pages.
     * @param int $cmid Activity of the page, 0 if none.
     * @param string $placementuuid Placement the widget belongs to, '' if unknown.
     * @param string $sessionhash The widget's sessionHash, '' where there is no
     *        web session (the Moodle app).
     * @return array{token: string, expiresat: int}|null Null for guests and on a
     *         site that cannot sign yet.
     */
    public static function issue(
        int $userid,
        int $courseid,
        int $cmid,
        string $placementuuid,
        string $sessionhash = ''
    ): ?array {
        if ($userid <= 0 || isguestuser($userid)) {
            return null;
        }
        $secretkey = site_registry::secret_key();
        if ($secretkey === null) {
            return null;
        }
        // Who is asking is the point of the token; where is a bonus. A course
        // or activity that does not check out is left out, not the person.
        $scope = self::checked_scope($userid, $courseid, $cmid) ?? ['course' => 0, 'cm' => 0];

        $now = time();
        $payload = [
            'v' => self::VERSION,
            'typ' => self::TYPE,
            'site' => site_registry::site_identifier(),
            'kid' => site_registry::key_id(),
            'uid' => $userid,
            'course' => $scope['course'],
            'cm' => $scope['cm'],
            'placement' => $placementuuid,
            // Ties the token to the Moodle session the widget reports, so a
            // token copied into another browser does not match its sessionHash.
            'sid' => $sessionhash,
            'jti' => crypto::random_nonce(),
            'iat' => $now,
            'exp' => $now + self::TTL,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        $body = crypto::base64url_encode($encoded);

        return [
            'token' => self::VERSION . '.' . $body . '.' . crypto::sign($body, $secretkey),
            'expiresat' => $payload['exp'],
        ];
    }

    /**
     * Course and activity as they may go into a token.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $cmid
     * @return array{course: int, cm: int}|null Null when the user may not open them.
     */
    private static function checked_scope(int $userid, int $courseid, int $cmid): ?array {
        global $DB;

        if ($cmid > 0) {
            $cmcourse = (int)$DB->get_field('course_modules', 'course', ['id' => $cmid]);
            if ($cmcourse === 0 || ($courseid > 0 && $courseid !== $cmcourse)) {
                return null;
            }
            $courseid = $cmcourse;
        }
        if ($courseid <= 0) {
            return ['course' => 0, 'cm' => 0];
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return null;
        }
        if ($courseid != SITEID) {
            $user = \core_user::get_user($userid);
            if (!$user || !can_access_course($course, $user, '', true)) {
                return null;
            }
        }
        if ($cmid > 0) {
            $cm = get_fast_modinfo($course, $userid)->cms[$cmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                return null;
            }
        }
        return ['course' => $courseid, 'cm' => $cmid];
    }

    /**
     * Read a token back with this site's own public key — for tests and diagnostics.
     *
     * @param string $token
     * @return array|null Decoded payload, or null when unusable.
     */
    public static function decode_own(string $token): ?array {
        $publickey = site_registry::public_key();
        if ($publickey === null) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }
        [, $body, $signature] = $parts;
        if (!crypto::verify($body, $signature, $publickey)) {
            return null;
        }
        $payload = json_decode((string)crypto::base64url_decode($body), true);
        if (!is_array($payload) || ($payload['typ'] ?? null) !== self::TYPE) {
            return null;
        }
        if (!isset($payload['exp']) || (int)$payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
