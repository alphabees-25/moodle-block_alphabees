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
 * Short-lived proof that a named Moodle user is signed in on this site.
 *
 * Moodle already authenticated the person; this hands that fact to the
 * Alphabees backend without a second login. The token is signed with the site
 * keypair the backend received at registration, so the backend can verify it
 * with a public key it already holds and nothing new has to be exchanged.
 *
 * It carries the course scope with it. The backend is expected to enforce that
 * scope on every query rather than re-deriving it, and to treat the token as
 * an upper bound on what this session may see.
 *
 * Format: `v1.<payload base64url>.<signature base64url>` — the signature covers
 * the encoded payload exactly as transmitted.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Issues session tokens for the Alphabees console.
 */
class console_token {
    /** Token format marker, bumped if the payload shape changes. */
    public const VERSION = 'v1';

    /** Lifetime in seconds. Long enough to boot the console, short enough to be worthless if leaked. */
    public const TTL = 300;

    /** Courses beyond this are not listed; the backend asks per course instead. */
    private const MAX_COURSES = 200;

    /**
     * Mint a token for the user currently signed in to Moodle.
     *
     * @param int $userid
     * @return string|null Token, or null when this site cannot sign yet.
     */
    public static function issue(int $userid): ?string {
        $secretkey = site_registry::secret_key();
        if ($secretkey === null || $userid <= 0) {
            return null;
        }

        $now = time();
        $scope = self::scoped_courses($userid);
        $payload = [
            'v' => self::VERSION,
            'site' => site_registry::site_identifier(),
            'kid' => site_registry::key_id(),
            'uid' => $userid,
            'courses' => $scope['courses'],
            // The backend enforces the course list as an upper bound, so it has
            // to be able to tell a complete list from a cut one. Without this a
            // teacher in more than MAX_COURSES courses would silently lose
            // access to the remainder rather than the backend widening the
            // query on request.
            'truncated' => $scope['truncated'],
            'caps' => self::granted_capabilities($userid),
            'jti' => crypto::random_nonce(),
            'iat' => $now,
            'exp' => $now + self::TTL,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        $body = crypto::base64url_encode($encoded);

        return self::VERSION . '.' . $body . '.' . crypto::sign($body, $secretkey);
    }

    /**
     * Courses this user may operate the console in.
     *
     * A manager holding the capability site-wide is not enrolled anywhere, so
     * the list can legitimately be empty; the `sitewide` capability entry tells
     * the backend to widen rather than to lock the session out. A list cut at
     * MAX_COURSES is reported through the payload's `truncated` flag.
     *
     * @param int $userid
     * @return array{courses: int[], truncated: bool}
     */
    private static function scoped_courses(int $userid): array {
        $courseids = [];
        $truncated = false;
        foreach (enrol_get_users_courses($userid, true, 'id') as $course) {
            $context = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$context || !has_capability(action_policy::CAP_CONSOLE, $context, $userid)) {
                continue;
            }
            if (count($courseids) >= self::MAX_COURSES) {
                $truncated = true;
                break;
            }
            $courseids[] = (int)$course->id;
        }
        return ['courses' => $courseids, 'truncated' => $truncated];
    }

    /**
     * The site-level permissions worth telling the backend about.
     *
     * @param int $userid
     * @return string[]
     */
    private static function granted_capabilities(int $userid): array {
        $systemcontext = \context_system::instance();
        $caps = [];
        if (has_capability(action_policy::CAP_CONSOLE, $systemcontext, $userid)) {
            $caps[] = 'sitewide';
        }
        if (has_capability(action_policy::CAP_AGENT, $systemcontext, $userid)) {
            $caps[] = 'agent';
        }
        return $caps;
    }

    /**
     * Read a token back without trusting it — for diagnostics on this site only.
     *
     * Verifies the signature with our own public key and the expiry against the
     * clock. The backend performs the equivalent check on its side; this exists
     * so the status panel and tests can show what a freshly issued token says.
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
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['exp']) || (int)$payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
