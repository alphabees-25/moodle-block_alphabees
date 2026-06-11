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
 * Local admin-triggered disconnect flow.
 *
 * Tears down local Alphabees backend connectivity without deleting the
 * dedicated service user or role definitions. The API key is left in place,
 * but automatic re-registration is blocked until an admin deliberately uses
 * "Connect now" or saves a new API key.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();
require_capability('moodle/site:config', context_system::instance());

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingalphabees']);

if (\block_alphabees\local\site_registry::is_registered()) {
    block_alphabees_queue_site_lifecycle_event(
        'site.disconnected',
        'Disconnected by Moodle admin'
    );
}

try {
    \block_alphabees\local\ws_setup::disable();
} catch (\Throwable $e) {
    redirect(
        $returnurl,
        get_string('disconnect_failed', 'block_alphabees', $e->getMessage()),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

global $DB;
$DB->delete_records_select(
    'block_alphabees_retryqueue',
    'endpoint <> ?',
    [\block_alphabees\local\site_registry::path_lifecycle()]
);

\block_alphabees\local\site_registry::reset_registration();
\block_alphabees\local\site_registry::mark_portal_disconnected('local_admin_disconnect');
\block_alphabees\local\ws_setup::record_token_post_status('revoked_ok');

redirect(
    $returnurl,
    get_string('disconnect_success', 'block_alphabees'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
