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
 * English strings for Alphabees block.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allow_remote_placement'] = 'Allow remote placement creation';
$string['allow_remote_placement_desc'] = 'When enabled, the Alphabees portal can autonomously add new Alphabees blocks to courses on this site (e.g. roll out a tutor across an entire department). Updating or removing existing placements works without this setting; only autonomous creation is gated. Default off.';
$string['allow_remote_placement_short'] = 'Lets the portal autonomously place new tutor blocks on this site. Default off.';
$string['alphabees:addinstance'] = 'Add a new Alphabees AI Tutor block';
$string['alphabees:myaddinstance'] = 'Add a new Alphabees AI Tutor block to My Moodle page';
$string['alphabees:view'] = 'View Alphabees AI Tutor block';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Enter the API key to access the Alphabees service. Get one at portal.alphalearn.ai. After saving, the site will register with the Alphabees backend automatically — the connection status below switches to "Connected" within 1–2 minutes (next Moodle cron run).';
$string['apikeymissing'] = 'API key is missing. Please configure the plugin settings.';
$string['blocksettings'] = 'Block settings';
$string['blocktitle'] = 'Alphabees';
$string['botid'] = 'AI tutor ID';
$string['connect_apikey_missing'] = 'Set an API key first, then try again.';
$string['connect_failed'] = 'Could not connect to the Alphabees backend. Check the connection status panel for details.';
$string['connect_failed_with_reason'] = 'Could not connect to the Alphabees backend: {$a}';
$string['connect_now'] = 'Connect now';
$string['connect_now_help'] = 'Skip the next cron run and try to register with the Alphabees backend immediately. Useful for testing or after fixing a connection problem.';
$string['connect_pending'] = 'Could not reach the Alphabees backend right now. The site will retry automatically in the background.';
$string['connect_success'] = 'Connected to the Alphabees backend.';
$string['connectionstatus'] = 'Connection status';
$string['connectionstatus_apikey_help'] = 'Your Alphabees API key. Get one at portal.alphalearn.ai. Saving it kicks off site registration in the background.';
$string['connectionstatus_intro'] = 'Once you save an API key, this Moodle site automatically registers with the Alphabees backend. The state below switches to <b>Connected</b> within 1–2 minutes (next Moodle cron run). Until then it stays on <b>Not connected</b> — that is normal and not an error.';
$string['connectionstatus_publickey_help'] = 'Public part of the Ed25519 signing keypair this Moodle generated locally. The matching private key never leaves your server and is used to sign requests to the Alphabees backend.';
$string['connectionstatus_showdetails'] = '▸ Technical details (site identifier, public key)';
$string['connectionstatus_siteidentifier_help'] = 'Globally unique fingerprint of this Moodle install. Lets the Alphabees portal tell your site apart from other Moodle installs of the same customer.';
$string['generalsettings'] = 'General settings';
$string['generalsettings_desc'] = 'Configure how this Moodle connects to the Alphabees backend. Toggle behaviours below; status and diagnostics are at the bottom of the page.';
$string['help'] = 'Help';
$string['helptitle'] = 'If the chat does not load automatically, pull down to refresh the page.';
$string['nobotsavailable'] = 'No AI tutors available.';
$string['nobotselected'] = 'No AI tutor has been selected.';
$string['overrideremote'] = 'Take local control';
$string['overrideremote_help'] = 'Tick to override the remote configuration. The next save will store your local choice and signal the portal that this placement is now locally managed.';
$string['placementuuid'] = 'Placement ID';
$string['pluginname'] = 'Alphabees AI Tutor';
$string['privacy:metadata'] = 'The Alphabees block sends placement and chat-session metadata to the Alphabees backend so that AI-tutor placements can be administered remotely.';
$string['registrationfailed'] = 'Registration with the Alphabees backend failed.';
$string['registrationtransient'] = 'Temporary failure during registration with the Alphabees backend; will retry.';
$string['remotemanaged_banner'] = 'This placement is currently managed from the Alphabees portal. Changes you make here will be overwritten unless you take local control below.';
$string['selectabot'] = 'Select an AI tutor';
$string['status_connected'] = 'Connected';
$string['status_disconnected'] = 'Not connected';
$string['status_lastattempt'] = 'Last registration attempt';
$string['status_lasterror'] = 'Last error';
$string['status_lastsync'] = 'Last sync';
$string['status_publickey'] = 'Site public key';
$string['status_registeredat'] = 'Registered at';
$string['status_siteidentifier'] = 'Site identifier';
$string['status_state'] = 'State';
$string['statusheading'] = 'Status & diagnostics';
$string['task_cleanup_nonces'] = 'Clean up expired Alphabees signature nonces';
$string['task_post_placement_event'] = 'Post Alphabees placement lifecycle event';
$string['task_post_ws_token'] = 'Send Alphabees web-services token to backend';
$string['task_process_retry_queue'] = 'Process Alphabees outbound retry queue';
$string['task_register_site'] = 'Register Moodle site with Alphabees backend';
$string['task_sync_placements'] = 'Sync Alphabees block placements with backend';
$string['usagetext'] = '<p>You can ask the AI Tutor questions at any time.</p><p><strong>How it works:</strong></p><ul><li>Click the chat icon in the bottom-right corner to open the tutor.</li><li>Type your question in the input bar or use the microphone icon for voice input.</li><li>Then wait for the reply — depending on complexity this may take a moment.</li></ul><p><em>Note:</em> The tutor is AI-based and administered by your Moodle site administrators. For questions about usage, please contact your course or system administration.</p>';
$string['usagetitle'] = 'How to use the AI Tutor';
$string['ws_disable_failed'] = 'Could not revoke the Alphabees web-services token: {$a}';
$string['ws_disable_success'] = 'Alphabees web-services token revoked. The integration is no longer active.';
$string['ws_enable'] = 'Enable Alphabees web-services integration';
$string['ws_enable_consent'] = 'I authorise this Moodle to expose course content, participants, grades and completion data to the Alphabees backend via web services. Saving this checkbox creates a dedicated <code>alphabees-service</code> user, assigns it the built-in manager role at system context (site-wide read/write access on courses, users and grades — but not site:config), generates a permanent token and sends it to Alphabees over the existing signed channel. Untick to revoke the token, remove the role assignments and tear the integration down.';
$string['ws_enable_failed'] = 'Could not enable the Alphabees web-services integration: {$a}';
$string['ws_enable_short'] = 'Grants the Alphabees backend site-wide read/write access to courses, users and grades via a dedicated service user. Full scope shown under Status below. Default off.';
$string['ws_enable_success'] = 'Alphabees web-services integration enabled. Token has been sent to the backend.';
$string['ws_grants_heading'] = 'What this grants';
$string['ws_grants_intro'] = 'When enabled, the Alphabees backend can call the following Moodle functions on behalf of the auto-created <code>alphabees-service</code> user:';
$string['ws_grants_summary'] = '{$a} Moodle web-service functions granted';
$string['ws_heading'] = 'Web-services integration';
$string['ws_heading_desc'] = 'Lets the Alphabees backend read course content for the knowledge base and (optionally) push generated courses back to Moodle. One checkbox replaces the legacy 11-step manual setup. Tear-down is one click.';
$string['ws_show_full_consent'] = 'Show full consent text and granted functions';
$string['ws_status_disconnected'] = 'Web-services integration not enabled.';
$string['ws_status_token_present'] = 'Web-services integration enabled. A permanent token is bound to the <code>alphabees-service</code> user.';
