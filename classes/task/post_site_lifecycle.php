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
 * Ad-hoc task that POSTs Moodle site lifecycle events to the backend.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\connection_manager;
use block_alphabees\local\site_registry;
use block_alphabees\local\ws_setup;

/**
 * Sends site.paused / site.resumed / site.disconnected lifecycle events.
 */
class post_site_lifecycle extends \core\task\adhoc_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_post_site_lifecycle', 'block_alphabees');
    }

    /**
     * Send the lifecycle event to the backend.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $eventtype = isset($data->event_type) ? (string)$data->event_type : '';
        $reason = isset($data->reason) ? (string)$data->reason : '';
        $occurredat = isset($data->occurred_at) ? (string)$data->occurred_at : gmdate('Y-m-d\TH:i:s\Z');
        $refreshws = !empty($data->refresh_ws_token_after);

        if ($eventtype === '') {
            return;
        }
        $isdisconnect = $eventtype === 'site.disconnected';
        if (!$isdisconnect && (site_registry::is_registration_blocked() || !site_registry::is_registered())) {
            mtrace('[block_alphabees] post_site_lifecycle: site is not registered or registration is blocked; skipping.');
            return;
        }
        if ($eventtype === 'site.resumed'
            && (!site_registry::api_key_present() || site_registry::is_registration_blocked())) {
            mtrace('[block_alphabees] post_site_lifecycle: resume requires a valid saved API key; skipping.');
            return;
        }

        $payload = [
            'event_type' => $eventtype,
            'reason' => $reason,
            'plugin_version' => self::plugin_release(),
            'occurred_at' => $occurredat,
        ];
        if ($eventtype === 'site.paused') {
            $payload['api_key_present'] = site_registry::api_key_present();
            $payload['api_key_status'] = site_registry::api_key_status();
        }

        $result = backend_client::post(site_registry::path_lifecycle(), $payload);

        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            mtrace('[block_alphabees] post_site_lifecycle: transient failure (will retry): '
                . ($result['error'] ?? ''));
            throw new \moodle_exception('eventpost_transient', 'block_alphabees');
        }

        if ($result['status'] === backend_client::STATUS_ERROR) {
            $httpcode = (int)($result['httpcode'] ?? 0);
            $error = $result['error'] ?? 'unknown';
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
            }
            mtrace('[block_alphabees] post_site_lifecycle: permanent failure http='
                . $httpcode . ' err=' . $error);
            return;
        }

        if ($eventtype === 'site.resumed') {
            site_registry::resume_syncs();
        }

        if ($eventtype === 'site.resumed' && $refreshws) {
            try {
                connection_manager::activate_defaults();
            } catch (\Throwable $e) {
                ws_setup::record_token_post_status('error', $e->getMessage());
                mtrace('[block_alphabees] post_site_lifecycle: WS token refresh failed: '
                    . $e->getMessage());
            }
        }
    }

    /**
     * Return the release string from version.php.
     *
     * @return string
     */
    private static function plugin_release(): string {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        return isset($plugin->release) ? (string)$plugin->release : '';
    }
}
