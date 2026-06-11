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
 * Shared post-registration activation flow.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Keeps the "Connect now" button and background registration task in sync.
 */
class connection_manager {

    /**
     * Enable the default portal integration features after site registration.
     *
     * @return void
     * @throws \Throwable When web-services setup fails.
     */
    public static function activate_defaults(): void {
        set_config('allow_remote_placement', 1, 'block_alphabees');

        try {
            $token = ws_setup::enable();
            set_config('ws_enabled', 1, 'block_alphabees');

            $task = new \block_alphabees\task\post_ws_token();
            $task->set_custom_data((object)[
                'token' => $token,
                'service_shortname' => ws_setup::SERVICE_SHORTNAME,
            ]);
            \core\task\manager::queue_adhoc_task($task);
            ws_setup::record_token_post_status('queued');
        } catch (\Throwable $e) {
            try {
                ws_setup::disable();
            } catch (\Throwable $disableerror) {
                unset($disableerror);
            }
            set_config('ws_enabled', 0, 'block_alphabees');
            throw $e;
        }
    }
}
