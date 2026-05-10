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
 * Processes the outbound retry queue.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;

/**
 * Scheduled task that drains the outbound retry queue.
 */
class process_retry_queue extends \core\task\scheduled_task {

    /** Stop trying after this many attempts. */
    private const MAX_ATTEMPTS = 8;

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_process_retry_queue', 'block_alphabees');
    }

    /**
     * Process up to 50 due retry-queue rows, applying exponential backoff.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $now = time();
        $rows = $DB->get_records_select(
            'block_alphabees_retryqueue',
            'nextattempt <= ?',
            [$now],
            'nextattempt ASC',
            '*',
            0,
            50
        );

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload)) {
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                continue;
            }

            $result = backend_client::post((string)$row->endpoint, $payload);
            if ($result['status'] === backend_client::STATUS_OK) {
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                continue;
            }

            $attempts = (int)$row->attempts + 1;
            if ($result['status'] === backend_client::STATUS_ERROR || $attempts >= self::MAX_ATTEMPTS) {
                // 4xx → won't ever succeed without intervention; or exhausted.
                $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
                mtrace('[block_alphabees] retry: dropping ' . $row->endpoint . ' after ' . $attempts . ' attempts.');
                continue;
            }

            // Exponential backoff: 30s, 60s, 2m, 4m, 8m, 16m, 32m, 64m.
            $delay = 30 * (2 ** ($attempts - 1));
            $DB->update_record('block_alphabees_retryqueue', (object)[
                'id' => $row->id,
                'attempts' => $attempts,
                'nextattempt' => $now + $delay,
                'lasterror' => substr((string)($result['error'] ?? 'unknown'), 0, 250),
            ]);
        }
    }
}
