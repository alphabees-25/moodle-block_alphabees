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
 * Ad-hoc task that sends waiting completion changes to the backend in batches.
 *
 * Queued at most once at a time (see local\completion_events::record()). It
 * drains the outbox batch by batch; a transient failure stops it with the rest
 * still waiting and throws, so Moodle retries with backoff. A rejection drops
 * the batch: the backend's nightly fetch still covers those learners.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\completion_events;
use block_alphabees\local\site_registry;

/**
 * Delivers the completion outbox.
 */
class deliver_completion_events extends \core\task\adhoc_task {
    /** Batches per run, so one run cannot hold cron for long. */
    private const MAX_BATCHES = 50;

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_deliver_completion_events', 'block_alphabees');
    }

    /**
     * Send what is waiting.
     *
     * @return void
     */
    public function execute(): void {
        if (!completion_events::connected()) {
            $dropped = completion_events::drop_all();
            mtrace('[block_alphabees] deliver_completion_events: not connected; dropped ' . $dropped . ' event(s).');
            return;
        }

        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $batch = completion_events::next_batch();
            if (!$batch['ids']) {
                return;
            }
            $result = backend_client::post(site_registry::path_completions(), [
                'siteIdentifier' => site_registry::site_identifier(),
                'events' => $batch['events'],
            ]);
            if ($result['status'] === backend_client::STATUS_OK) {
                completion_events::forget($batch['ids']);
                continue;
            }
            if ($result['status'] === backend_client::STATUS_TRANSIENT) {
                if (!empty($result['ignored']) && ($result['health_status'] ?? null) === 'paused') {
                    site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
                }
                mtrace('[block_alphabees] deliver_completion_events: transient failure (will retry): '
                    . ($result['error'] ?? ''));
                throw new \moodle_exception('eventpost_transient', 'block_alphabees');
            }
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
            }
            completion_events::forget($batch['ids']);
            mtrace('[block_alphabees] deliver_completion_events: batch rejected, dropped; http='
                . (int)($result['httpcode'] ?? 0) . ' err=' . ($result['error'] ?? 'unknown'));
        }

        // More than one run's worth was waiting: queue the next run.
        \core\task\manager::queue_adhoc_task(new self(), true);
    }
}
