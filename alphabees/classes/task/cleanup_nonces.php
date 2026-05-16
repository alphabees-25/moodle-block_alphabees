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
 * Scheduled task. Purges expired replay-protection nonces.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

/**
 * Scheduled task that removes expired nonces from block_alphabees_nonces.
 */
class cleanup_nonces extends \core\task\scheduled_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_nonces', 'block_alphabees');
    }

    /**
     * Delete all nonce rows whose expiry is in the past.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $DB->delete_records_select('block_alphabees_nonces', 'expiresat < ?', [time()]);
    }
}
