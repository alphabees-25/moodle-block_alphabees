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
 * Scheduled task. Sends a full snapshot of all alphabees block placements
 * to the backend so it can detect drift, missed deletions, and updated
 * course metadata.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\placement_repository;
use block_alphabees\local\site_registry;

/**
 * Scheduled task that syncs the full placement snapshot to the backend.
 */
class sync_placements extends \core\task\scheduled_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_placements', 'block_alphabees');
    }

    /**
     * Push every alphabees block instance to the backend's sync endpoint.
     *
     * @return void
     */
    public function execute(): void {
        if (site_registry::is_registration_blocked()) {
            mtrace('[block_alphabees] sync_placements: registration blocked for current API key, skipping.');
            return;
        }
        if (!site_registry::is_registered()) {
            mtrace('[block_alphabees] sync_placements: site not yet registered, skipping.');
            return;
        }
        if (site_registry::is_sync_paused()) {
            mtrace('[block_alphabees] sync_placements: sync paused by portal, skipping.');
            return;
        }

        $instances = placement_repository::all_instances();
        $placements = [];
        foreach ($instances as $instance) {
            $placements[] = placement_repository::build_payload($instance);
        }

        $payload = [
            'site_identifier' => site_registry::site_identifier(),
            'placements' => $placements,
            'placement_count' => count($placements),
            'snapshot_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $result = backend_client::post(site_registry::path_sync(), $payload);

        if ($result['status'] === backend_client::STATUS_OK) {
            site_registry::mark_synced();
            mtrace('[block_alphabees] sync_placements: synced ' . count($placements) . ' placement(s).');
            return;
        }

        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            if (!empty($result['ignored']) && ($result['health_status'] ?? null) === 'paused') {
                site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
            }
            mtrace('[block_alphabees] sync_placements: transient failure, will retry next run. '
                . ($result['code'] ?? ($result['error'] ?? '')));
            return;
        }

        $httpcode = (int)$result['httpcode'];
        $error = $result['error'] ?? 'unknown';
        if (backend_client::requires_reconnect($result)) {
            site_registry::reset_registration();
        }
        mtrace('[block_alphabees] sync_placements: permanent failure http='
            . $httpcode . ' err=' . $error);
    }
}
