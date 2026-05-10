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

        $payload = [
            'site_identifier' => site_registry::site_identifier(),
            'service_shortname' => $shortname,
            'token' => $token !== '' ? $token : null,
            'revoked' => $token === '',
            'site_url' => (string)$CFG->wwwroot,
        ];

        $path = site_registry::API_BASE . '/v1/moodle/sites/'
            . rawurlencode(site_registry::site_identifier()) . '/ws-token';
        $result = backend_client::post($path, $payload);

        if ($result['status'] === backend_client::STATUS_TRANSIENT) {
            mtrace('[block_alphabees] post_ws_token: transient failure (will retry): '
                . ($result['error'] ?? ''));
            throw new \moodle_exception('eventpost_transient', 'block_alphabees');
        }

        if ($result['status'] === backend_client::STATUS_ERROR) {
            mtrace('[block_alphabees] post_ws_token: permanent failure http='
                . $result['httpcode'] . ' err=' . ($result['error'] ?? 'unknown'));
        }
    }
}
