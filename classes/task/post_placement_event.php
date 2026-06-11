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
 * Ad-hoc task that POSTs a single placement lifecycle event to the backend.
 *
 * Lifecycle events (placement.created/updated/deleted) used to be sent
 * synchronously inside the block's instance_* hooks. That caused two
 * problems on the user-facing request that triggered the hook:
 *   - mtrace + debugging() outputs from the HTTP layer leaked into the
 *     rendered page → Moodle saw "Error output" and disabled the
 *     automatic redirect, presenting an awkward "(Continue)" page.
 *   - cURL latency could exceed the session-write-close window, leading
 *     to "mutated session after it was closed" warnings.
 *
 * Queueing the event here keeps the user-facing operation snappy and
 * deterministic. Cron picks it up on the next tick (typically <1 min)
 * and delivers; transient failures throw so Moodle reschedules with
 * exponential backoff.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\site_registry;

/**
 * Ad-hoc task that posts a single placement lifecycle event to the backend.
 */
class post_placement_event extends \core\task\adhoc_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_post_placement_event', 'block_alphabees');
    }

    /**
     * Send the queued lifecycle event payload to the backend.
     *
     * @return void
     */
    public function execute(): void {
        if (site_registry::is_registration_blocked() || !site_registry::is_registered()) {
            mtrace('[block_alphabees] post_placement_event: site is not registered or registration is blocked; skipping.');
            return;
        }
        if (site_registry::is_sync_paused()) {
            mtrace('[block_alphabees] post_placement_event: sync paused by portal, skipping.');
            return;
        }

        $data = $this->get_custom_data();
        if (!is_object($data)) {
            return;
        }
        $path = isset($data->path) ? (string)$data->path : '';
        $payload = isset($data->payload) ? (array)$data->payload : [];
        if ($path === '' || empty($payload)) {
            return;
        }

        $result = backend_client::post($path, $payload);

        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            if (!empty($result['ignored']) && ($result['health_status'] ?? null) === 'paused') {
                site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
            }
            mtrace('[block_alphabees] post_placement_event: transient failure (will retry): '
                . ($result['error'] ?? ''));
            throw new \moodle_exception('eventpost_transient', 'block_alphabees');
        }

        if ($result['status'] === backend_client::STATUS_ERROR) {
            $httpcode = (int)($result['httpcode'] ?? 0);
            $error = $result['error'] ?? 'unknown';
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
            }
            // 4xx — backend rejected the event. Log + drop; the hourly
            // sync_placements heartbeat will reconcile state eventually.
            mtrace('[block_alphabees] post_placement_event: permanent failure http='
                . $httpcode . ' err=' . $error);
        }
    }
}
