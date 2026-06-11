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
 * Local admin-triggered resume for paused placement syncs.
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

// Capture any debug/mtrace output from the synchronous resume flow so Moodle
// can still perform a clean redirect back to the settings page.
$outputbufferlevel = ob_get_level();
ob_start();

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingalphabees']);

/**
 * Redirect after clearing output produced by task-style helpers.
 *
 * @param moodle_url $url
 * @param string $message
 * @param string $type
 * @param int $bufferlevel
 * @return void
 */
function block_alphabees_resume_redirect(
    moodle_url $url,
    string $message,
    string $type,
    int $bufferlevel
): void {
    while (ob_get_level() > $bufferlevel) {
        ob_end_clean();
    }
    redirect($url, $message, null, $type);
}

if (!block_alphabees_can_resume_site()) {
    block_alphabees_resume_redirect(
        $returnurl,
        get_string('resume_sync_requires_apikey', 'block_alphabees'),
        \core\output\notification::NOTIFY_WARNING,
        $outputbufferlevel
    );
}

if (!\block_alphabees\local\site_registry::is_registered()) {
    block_alphabees_resume_redirect(
        $returnurl,
        get_string('connect_failed', 'block_alphabees'),
        \core\output\notification::NOTIFY_ERROR,
        $outputbufferlevel
    );
}

block_alphabees_drop_queued_site_lifecycle_event('site.paused');
block_alphabees_drop_queued_site_lifecycle_event('site.resumed');

$result = block_alphabees_post_site_lifecycle_event(
    'site.resumed',
    'Resumed by Moodle admin'
);

if ($result['status'] === \block_alphabees\local\backend_client::STATUS_TRANSIENT) {
    block_alphabees_queue_site_lifecycle_event(
        'site.resumed',
        'Resumed by Moodle admin'
    );
    block_alphabees_resume_redirect(
        $returnurl,
        get_string('resume_sync_queued', 'block_alphabees'),
        \core\output\notification::NOTIFY_WARNING,
        $outputbufferlevel
    );
}

if ($result['status'] === \block_alphabees\local\backend_client::STATUS_ERROR) {
    $httpcode = (int)($result['httpcode'] ?? 0);
    $error = $result['error'] ?? 'unknown';
    if (\block_alphabees\local\backend_client::requires_reconnect($result)) {
        \block_alphabees\local\site_registry::reset_registration();
    }
    block_alphabees_resume_redirect(
        $returnurl,
        get_string('resume_sync_failed', 'block_alphabees', $error),
        \core\output\notification::NOTIFY_ERROR,
        $outputbufferlevel
    );
}

\block_alphabees\local\site_registry::resume_syncs();
try {
    \block_alphabees\local\connection_manager::activate_defaults();
} catch (\Throwable $e) {
    \block_alphabees\local\ws_setup::record_token_post_status('error', $e->getMessage());
    block_alphabees_resume_redirect(
        $returnurl,
        get_string('ws_enable_failed', 'block_alphabees', $e->getMessage()),
        \core\output\notification::NOTIFY_WARNING,
        $outputbufferlevel
    );
}

block_alphabees_resume_redirect(
    $returnurl,
    get_string('resume_sync_success', 'block_alphabees'),
    \core\output\notification::NOTIFY_SUCCESS,
    $outputbufferlevel
);
