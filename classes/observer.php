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
 * Event observers for block_alphabees.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees;

/**
 * Event observers, registered in db/events.php.
 */
class observer {
    /**
     * A user logged out: tell al-tutor, so it ends that tutor session.
     *
     * Deliberately does not touch sesskey() — see local\session_tracker for
     * why the session may already be gone when this runs.
     *
     * @param \core\event\user_loggedout $event
     * @return void
     */
    public static function user_loggedout(\core\event\user_loggedout $event): void {
        try {
            local\session_tracker::report_ended(
                (string)($event->other['sessionid'] ?? ''),
                (int)$event->objectid,
                (int)$event->timecreated
            );
        } catch (\Throwable $e) {
            // A logout must never fail because of the tutor.
            debugging('[block_alphabees] logout not reported: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * An activity completion changed: queue it for the backend.
     *
     * Registered as non-internal, so it runs after the change is committed
     * and never for one that was rolled back.
     *
     * @param \core\event\course_module_completion_updated $event
     * @return void
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        try {
            local\completion_events::record($event);
        } catch (\Throwable $e) {
            // Completing an activity must never fail because of the tutor.
            debugging('[block_alphabees] completion not queued: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
