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
 * Synchronous "Connect now" trigger.
 *
 * Lets a site admin run a registration attempt explicitly. Runs
 * `register_site::execute()` inline, captures any error, and bounces back to
 * the plugin settings page so the connection status panel reflects the new
 * state immediately.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_sesskey();
require_capability('moodle/site:config', context_system::instance());

// Capture any mtrace/debug output from the synchronous connect flow so Moodle
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
function block_alphabees_connect_redirect(
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

$apikey = get_config('block_alphabees', 'apikey');
if (empty($apikey)) {
    block_alphabees_connect_redirect(
        $returnurl,
        get_string('connect_apikey_missing', 'block_alphabees'),
        \core\output\notification::NOTIFY_WARNING,
        $outputbufferlevel
    );
}

// Make sure we have a keypair before trying to register.
\block_alphabees\local\site_registry::ensure_keypair();
\block_alphabees\local\site_registry::clear_portal_disconnect();

// Let an explicit admin action retry registration even if an earlier backend
// response latched the current key as rejected. The register endpoint is the
// source of truth for whether the key is actually usable now.
\block_alphabees\local\site_registry::clear_registration_block();
\block_alphabees\local\site_registry::reset_registration();

$exception = null;
$task = new \block_alphabees\task\register_site();
try {
    $task->execute();
} catch (\Throwable $e) {
    $exception = $e;
}

if (\block_alphabees\local\site_registry::is_registered()) {
    try {
        \block_alphabees\local\connection_manager::activate_defaults();
    } catch (\Throwable $e) {
        block_alphabees_connect_redirect(
            $returnurl,
            get_string('ws_enable_failed', 'block_alphabees', $e->getMessage()),
            \core\output\notification::NOTIFY_ERROR,
            $outputbufferlevel
        );
    }

    block_alphabees_connect_redirect(
        $returnurl,
        get_string('connect_success', 'block_alphabees'),
        \core\output\notification::NOTIFY_SUCCESS,
        $outputbufferlevel
    );
}

$lasterror = \block_alphabees\local\site_registry::last_register_error();
$msg = $lasterror !== null
    ? get_string('connect_failed_with_reason', 'block_alphabees', $lasterror)
    : ($exception !== null
        ? get_string('connect_failed_with_reason', 'block_alphabees', $exception->getMessage())
        : get_string('connect_failed', 'block_alphabees'));

block_alphabees_connect_redirect(
    $returnurl,
    $msg,
    \core\output\notification::NOTIFY_ERROR,
    $outputbufferlevel
);
