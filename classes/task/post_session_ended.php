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
 * Ad-hoc task that tells the backend a tutor session's Moodle session ended.
 *
 * Queued by the logout observer rather than posted inline, for the same reason
 * placement events are: the logout request must not wait for the backend.
 * Cron delivers on its next tick; a transient failure throws so Moodle retries
 * with backoff.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\site_registry;

/**
 * Ad-hoc task that posts one session.ended report to the backend.
 */
class post_session_ended extends \core\task\adhoc_task {
    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_post_session_ended', 'block_alphabees');
    }

    /**
     * Send the queued report.
     *
     * @return void
     */
    public function execute(): void {
        if (site_registry::is_registration_blocked() || !site_registry::is_registered()) {
            mtrace('[block_alphabees] post_session_ended: site is not registered or registration is blocked; skipping.');
            return;
        }
        if (site_registry::is_sync_paused()) {
            mtrace('[block_alphabees] post_session_ended: sync paused by portal, skipping.');
            return;
        }

        $data = $this->get_custom_data();
        $payload = is_object($data) && isset($data->payload) ? (array)$data->payload : [];
        if (empty($payload)) {
            return;
        }

        $result = backend_client::post(site_registry::path_sessions(), $payload);
        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            if (!empty($result['ignored']) && ($result['health_status'] ?? null) === 'paused') {
                site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
            }
            mtrace('[block_alphabees] post_session_ended: transient failure (will retry): '
                . ($result['error'] ?? ''));
            throw new \moodle_exception('eventpost_transient', 'block_alphabees');
        }
        if ($result['status'] === backend_client::STATUS_ERROR) {
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
            }
            // Rejected. Nothing to reconcile later: the session is gone either way.
            mtrace('[block_alphabees] post_session_ended: permanent failure http='
                . (int)($result['httpcode'] ?? 0) . ' err=' . ($result['error'] ?? 'unknown'));
        }
    }
}
