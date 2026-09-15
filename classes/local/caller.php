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
 * Identity of whoever is asking the action registry to do something.
 *
 * The registry has two entrances and they carry very different authority:
 *
 *   site — a request signed with the site keypair, arriving at api.php from
 *          the Alphabees backend. Runs with the privileges of the plugin's
 *          service user (manager at system context). Used for portal-driven
 *          management, batch work and sync.
 *
 *   user — a call made from the browser session of a signed-in Moodle user
 *          (console page, assistant). Runs with exactly that person's
 *          capabilities; Moodle enforces them a second time when the handler
 *          touches a course.
 *
 * Actions declare which kinds may call them in {@see action_policy}. Nothing
 * executes without that decision having been made explicitly.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Value object describing the origin and authority of an action request.
 */
final class caller {
    /** Signed request from the Alphabees backend. */
    public const KIND_SITE = 'site';

    /** Call from the browser session of a signed-in Moodle user. */
    public const KIND_USER = 'user';

    /** @var string One of the KIND_* constants. */
    private string $kind;

    /** @var int|null Moodle user id, only set for KIND_USER. */
    private ?int $userid;

    /**
     * Use the named constructors instead.
     *
     * @param string $kind
     * @param int|null $userid
     */
    private function __construct(string $kind, ?int $userid = null) {
        $this->kind = $kind;
        $this->userid = $userid;
    }

    /**
     * A request that arrived signed with the site keypair.
     *
     * @return self
     */
    public static function site(): self {
        return new self(self::KIND_SITE);
    }

    /**
     * A request made in the session of a signed-in Moodle user.
     *
     * @param int $userid
     * @return self
     */
    public static function user(int $userid): self {
        return new self(self::KIND_USER, $userid);
    }

    /**
     * Which entrance this request came through.
     *
     * @return string
     */
    public function kind(): string {
        return $this->kind;
    }

    /**
     * Whether this is a signed backend request.
     *
     * @return bool
     */
    public function is_site(): bool {
        return $this->kind === self::KIND_SITE;
    }

    /**
     * Whether this is a call from a signed-in user's session.
     *
     * @return bool
     */
    public function is_user(): bool {
        return $this->kind === self::KIND_USER;
    }

    /**
     * Moodle user id behind this request, or null for a site request.
     *
     * @return int|null
     */
    public function userid(): ?int {
        return $this->userid;
    }

    /**
     * Short, log-safe description. Contains no secrets.
     *
     * @return string
     */
    public function describe(): string {
        return $this->is_user() ? 'user:' . (int)$this->userid : 'site';
    }
}
