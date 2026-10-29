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
 * Ties each tutor session to the Moodle session it ran in, so a logout ends it.
 *
 * The widget identifies its session to al-tutor by sha256(sesskey|wwwroot).
 * At logout that hash has to be rebuilt on the server, and the obvious place —
 * an observer on \core\event\user_loggedout — cannot do it on most sites:
 * Moodle 4.1 to 5.1 trigger that event only after the session has been
 * terminated, so sesskey() there mints the key of the next, empty session and
 * the hash matches nothing. 5.2 moved the trigger before termination.
 *
 * So the hash is recorded while the session demonstrably exists, when the
 * block hands it to the widget, keyed by a hash of the PHP session id. The
 * logout event carries that id in every version, before or after termination.
 * Only sessions that loaded the widget get a row; nobody else's logout is
 * reported.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Records widget sessions and reports their end to the backend.
 */
class session_tracker {
    /** One row per Moodle session that loaded the widget. */
    private const TABLE = 'block_alphabees_sessions';

    /** An active session's row is refreshed at most this often, in seconds. */
    private const TOUCH_INTERVAL = 3600;

    /**
     * The identifier the widget and al-tutor use for the current session.
     *
     * @return string Lowercase hex sha256 of sesskey, a pipe and wwwroot.
     */
    public static function session_hash(): string {
        global $CFG;
        return hash('sha256', sesskey() . '|' . $CFG->wwwroot);
    }

    /**
     * Record the current session and return its hash for the widget.
     *
     * Costs one indexed read per page with the block, and a write only for a
     * new session, a changed key, or once an hour to keep the row alive.
     *
     * @return string
     */
    public static function remember(): string {
        global $DB;
        $sessionhash = self::session_hash();
        $sid = (string)session_id();
        if ($sid === '') {
            return $sessionhash;
        }

        $sidhash = hash('sha256', $sid);
        $now = time();
        $row = $DB->get_record(self::TABLE, ['sidhash' => $sidhash]);
        if (!$row) {
            try {
                $DB->insert_record(self::TABLE, (object)[
                    'sidhash' => $sidhash,
                    'sessionhash' => $sessionhash,
                    'timemodified' => $now,
                ]);
            } catch (\dml_write_exception $e) {
                // A parallel request of the same session got there first.
                unset($e);
            }
        } else if ($row->sessionhash !== $sessionhash || (int)$row->timemodified < $now - self::TOUCH_INTERVAL) {
            $DB->update_record(self::TABLE, (object)[
                'id' => $row->id,
                'sessionhash' => $sessionhash,
                'timemodified' => $now,
            ]);
        }
        return $sessionhash;
    }

    /**
     * Report that a session ended, if it ever loaded the widget.
     *
     * Only queues the delivery: this runs inside the logout request, which
     * must not wait for the backend.
     *
     * @param string $sid PHP session id, as the logout event carries it.
     * @param int $userid The user who logged out.
     * @param int $time When the session ended.
     * @return bool Whether a report was queued.
     */
    public static function report_ended(string $sid, int $userid, int $time): bool {
        global $DB;
        if ($sid === '' || $userid <= 0) {
            return false;
        }
        $row = $DB->get_record(self::TABLE, ['sidhash' => hash('sha256', $sid)]);
        if (!$row) {
            return false;
        }
        $DB->delete_records(self::TABLE, ['id' => $row->id]);

        if (
            site_registry::is_registration_blocked()
            || !site_registry::is_registered()
            || site_registry::is_sync_paused()
        ) {
            return false;
        }
        $task = new \block_alphabees\task\post_session_ended();
        $task->set_custom_data(['payload' => self::payload($userid, (string)$row->sessionhash, $time)]);
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * The body of a session.ended report.
     *
     * @param int $userid
     * @param string $sessionhash
     * @param int $time
     * @return array
     */
    public static function payload(int $userid, string $sessionhash, int $time): array {
        return [
            'event' => 'session.ended',
            'reason' => 'logout',
            'siteIdentifier' => site_registry::site_identifier(),
            'userId' => $userid,
            'sessionHash' => $sessionhash,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', $time),
        ];
    }

    /**
     * Drop rows of sessions that have been idle longer than Moodle keeps one.
     *
     * Such a session timed out, or never loaded the widget again; either way
     * there is no logout left to report. Timeouts are not reported: Moodle
     * removes an expired session without an event.
     *
     * @return int Rows removed.
     */
    public static function purge_stale(): int {
        global $CFG, $DB;
        $idle = max((int)($CFG->sessiontimeout ?? 0), self::TOUCH_INTERVAL) + self::TOUCH_INTERVAL;
        $cutoff = time() - $idle;
        $count = $DB->count_records_select(self::TABLE, 'timemodified < ?', [$cutoff]);
        if ($count > 0) {
            $DB->delete_records_select(self::TABLE, 'timemodified < ?', [$cutoff]);
        }
        return $count;
    }
}
