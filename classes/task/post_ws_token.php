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
 * Ad-hoc task that POSTs the Alphabees web-services token to the backend.
 *
 * Decoupled from the admin-save flow for the same reason as
 * register_site / post_placement_event: outbound HTTP from inside Moodle's
 * settings updatedcallback bleeds into the session-write-close window
 * and produces "mutated session after close" warnings + a Continue page.
 *
 * Runs on the next cron tick (typically <1 minute) after the admin
 * ticks the consent checkbox.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\task;

use block_alphabees\local\backend_client;
use block_alphabees\local\site_registry;
use block_alphabees\local\ws_setup;

/**
 * Ad-hoc task that sends the WS token (or a revocation) to the backend.
 */
class post_ws_token extends \core\task\adhoc_task {

    /**
     * Return the human-readable name for the task list UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_post_ws_token', 'block_alphabees');
    }

    /**
     * Send the token (or a null payload to signal revocation) to the backend.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG;
        $data = $this->get_custom_data();
        $token = isset($data->token) ? (string)$data->token : '';
        $shortname = isset($data->service_shortname)
            ? (string)$data->service_shortname
            : '';

        if (site_registry::is_registration_blocked() || !site_registry::is_registered()) {
            ws_setup::record_token_post_status(
                'error',
                site_registry::registration_block_reason() ?? 'site_not_registered',
                0
            );
            mtrace('[block_alphabees] post_ws_token: site is not registered or registration is blocked; skipping.');
            return;
        }
        if (site_registry::is_sync_paused()) {
            ws_setup::record_token_post_status('error', 'site_sync_paused', 0);
            mtrace('[block_alphabees] post_ws_token: sync paused; skipping.');
            return;
        }

        $payload = [
            'site_identifier' => site_registry::site_identifier(),
            'service_shortname' => $shortname,
            'token' => $token !== '' ? $token : null,
            'revoked' => $token === '',
            'site_url' => (string)$CFG->wwwroot,
        ];

        $result = backend_client::post(site_registry::path_ws_token(), $payload);

        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            if (!empty($result['ignored']) && ($result['health_status'] ?? null) === 'paused') {
                site_registry::pause_syncs((string)($result['error'] ?? 'site_paused'));
            }
            ws_setup::record_token_post_status(
                'transient',
                $result['error'] ?? 'transient_error',
                (int)($result['httpcode'] ?? 0)
            );
            mtrace('[block_alphabees] post_ws_token: transient failure (will retry): '
                . ($result['error'] ?? ''));
            throw new \moodle_exception('eventpost_transient', 'block_alphabees');
        }

        if ($result['status'] === backend_client::STATUS_ERROR) {
            $httpcode = (int)($result['httpcode'] ?? 0);
            $error = $result['error'] ?? 'unknown';
            if (backend_client::requires_reconnect($result)) {
                site_registry::reset_registration();
            }
            ws_setup::record_token_post_status(
                'error',
                $error,
                $httpcode
            );
            mtrace('[block_alphabees] post_ws_token: permanent failure http='
                . $httpcode . ' err=' . $error);
            return;
        }

        ws_setup::record_token_post_status($token === '' ? 'revoked_ok' : 'ok', null, (int)($result['httpcode'] ?? 0));
    }
}
