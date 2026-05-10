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
 * Lets a site admin force a registration attempt instead of waiting for the
 * next Moodle cron tick. Runs `register_site::execute()` inline, captures
 * any error, and bounces back to the plugin settings page so the connection
 * status panel reflects the new state immediately.
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

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingalphabees']);

$apikey = get_config('block_alphabees', 'apikey');
if (empty($apikey)) {
    redirect(
        $returnurl,
        get_string('connect_apikey_missing', 'block_alphabees'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Make sure we have a keypair before trying to register.
\block_alphabees\local\site_registry::ensure_keypair();

// Buffer mtrace output so it doesn't break the redirect; we'll flush via
// the notification message instead.
ob_start();
$task = new \block_alphabees\task\register_site();
$exception = null;
try {
    $task->execute();
} catch (\Throwable $e) {
    $exception = $e;
}
ob_end_clean();

if (\block_alphabees\local\site_registry::is_registered()) {
    redirect(
        $returnurl,
        get_string('connect_success', 'block_alphabees'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$lasterror = \block_alphabees\local\site_registry::last_register_error();
$msg = $lasterror !== null
    ? get_string('connect_failed_with_reason', 'block_alphabees', $lasterror)
    : ($exception !== null
        ? get_string('connect_failed_with_reason', 'block_alphabees', $exception->getMessage())
        : get_string('connect_failed', 'block_alphabees'));

redirect($returnurl, $msg, null, \core\output\notification::NOTIFY_ERROR);
