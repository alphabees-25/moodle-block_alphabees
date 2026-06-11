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
$string['allow_remote_placement_desc'] = 'When enabled, the Alphabees portal can autonomously add new Alphabees blocks to courses on this site (e.g. roll out a tutor across an entire department). Updating or removing existing placements works without this setting; only autonomous creation is gated. Default on.';
$string['allow_remote_placement_short'] = 'Lets the portal autonomously place new tutor blocks on this site. Default on.';
$string['alphabees:addinstance'] = 'Add a new Alphabees AI Tutor block';
$string['alphabees:myaddinstance'] = 'Add a new Alphabees AI Tutor block to My Moodle page';
$string['alphabees:usewebservice'] = 'Use the Alphabees web-services integration';
$string['alphabees:view'] = 'View Alphabees AI Tutor block';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Enter the API key to access the Alphabees service. Get one at portal.alphalearn.ai. After saving, open the Alphabees settings again and check Status & diagnostics. If Connection status is not Connected, click Connect now once.';
$string['apikeymissing'] = 'API key is missing. Please configure the plugin settings.';
$string['blocksettings'] = 'Block settings';
$string['blocktitle'] = 'Alphabees';
$string['botid'] = 'AI tutor ID';
$string['botlist_unavailable_help'] = 'The list of available AI tutors could not be loaded. '
    . 'Check the Alphabees connection under Status & diagnostics and try again.';
$string['connect_apikey_missing'] = 'Save an API key first, then click Connect now again.';
$string['connect_failed'] = 'Could not connect to the Alphabees backend. Check the connection status panel for details.';
$string['connect_failed_with_reason'] = 'Could not connect to the Alphabees backend: {$a}';
$string['connect_now'] = 'Connect now';
$string['connect_now_help'] = 'Register this Moodle site with the Alphabees backend immediately. Use this after saving an API key or fixing a connection problem.';
$string['connect_pending'] = 'Could not reach the Alphabees backend right now. Check the connection status and try Connect now again.';
$string['connect_success'] = 'Connected to the Alphabees backend.';
$string['connectionstatus'] = 'Connection status';
$string['connectionstatus_apikey_help'] = 'Your Alphabees API key. Get one at portal.alphalearn.ai. Saving it stores the key; Connect now performs registration.';
$string['connectionstatus_apikey_missing_help'] = 'This site is disconnected and no API key is saved. Save a valid Alphabees API key, then check this status panel. If it is not Connected, click Connect now once.';
$string['connectionstatus_blocked_help'] = 'Alphabees rejected the current API key permanently. Moodle will not make further Alphabees backend calls until a different API key is saved.';
$string['connectionstatus_connect_required_help'] = 'Settings are saved, but this site is not connected to Alphabees yet. Click Connect now once to register it, then verify this status changes to Connected.';
$string['connectionstatus_intro'] = 'Save an API key, then click Connect now to register this Moodle site with the Alphabees backend.';
$string['connectionstatus_publickey_help'] = 'Public part of the Ed25519 signing keypair this Moodle generated locally. The matching private key never leaves your server and is used to sign requests to the Alphabees backend.';
$string['connectionstatus_showdetails'] = '▸ Technical details (site identifier, public key)';
$string['connectionstatus_siteidentifier_help'] = 'Globally unique fingerprint of this Moodle install. Lets the Alphabees portal tell your site apart from other Moodle installs of the same customer.';
$string['connectionstatus_sync_paused_apikey_missing_help'] = 'Paused, API key is missing in the Moodle plugin. Save a valid API key before resuming this connection.';
$string['connectionstatus_sync_paused_help'] = 'Alphabees paused outbound placement syncs for this site. Registration remains valid, but Moodle will not send placement heartbeats, placement events, or queued placement retries until the portal resumes syncing.';
$string['currentbotfallback'] = 'Current tutor ({$a})';
$string['disconnect_confirm'] = 'Disconnect this Moodle site from Alphabees? Existing tutor blocks stay in Moodle, but registration, syncs and web-service access are disabled until an admin reconnects.';
$string['disconnect_failed'] = 'Could not disconnect from Alphabees: {$a}';
$string['disconnect_now'] = 'Disconnect';
$string['disconnect_success'] = 'Disconnected from Alphabees. Existing tutor blocks remain in Moodle, but syncs and web-service access are disabled.';
$string['firstsetup'] = 'First setup';
$string['firstsetup_desc'] = 'Save the API key, then check Status & diagnostics. If Connection status is not Connected, click Connect now once.';
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
$string['resume_sync_failed'] = 'Could not resume Alphabees placement syncs: {$a}';
$string['resume_sync_now'] = 'Resume sync';
$string['resume_sync_queued'] = 'Resume request queued. Moodle will retry delivery to Alphabees in the background.';
$string['resume_sync_requires_apikey'] = 'Save a valid Alphabees API key before resuming this paused connection.';
$string['resume_sync_success'] = 'Alphabees placement syncs resumed.';
$string['selectabot'] = 'Select an AI tutor';
$string['status_connected'] = 'Connected';
$string['status_disconnected'] = 'Not connected';
$string['status_lastattempt'] = 'Last registration attempt';
$string['status_lasterror'] = 'Last error';
$string['status_lastsync'] = 'Last sync';
$string['status_publickey'] = 'Site public key';
$string['status_registeredat'] = 'Registered at';
$string['status_registration_blocked'] = 'API key rejected';
$string['status_registration_blocked_chat'] = 'The Alphabees AI Tutor is currently unavailable because the configured API key was rejected. Please contact the Moodle site administrator.';
$string['status_registration_blocked_chat_admin'] = 'The Alphabees AI Tutor is unavailable because the configured API key was rejected: {$a}. Save a different API key in the plugin settings to retry.';
$string['status_registration_blocked_detail'] = 'Registration blocked';
$string['status_siteidentifier'] = 'Site identifier';
$string['status_state'] = 'State';
$string['status_sync_pause_reason'] = 'Pause reason';
$string['status_sync_paused'] = 'Sync paused';
$string['status_sync_paused_detail'] = 'Sync paused since';
$string['statusheading'] = 'Status & diagnostics';
$string['task_cleanup_nonces'] = 'Clean up expired Alphabees signature nonces';
$string['task_post_placement_event'] = 'Post Alphabees placement lifecycle event';
$string['task_post_site_lifecycle'] = 'Post Alphabees site lifecycle event';
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
$string['ws_enable_short'] = 'Grants the Alphabees backend site-wide read/write access to courses, users and grades via a dedicated service user. Full scope shown under Status below. Default on.';
$string['ws_enable_success'] = 'Alphabees web-services integration enabled. Token has been sent to the backend.';
$string['ws_grants_heading'] = 'What this grants';
$string['ws_grants_intro'] = 'When enabled, the Alphabees backend can call the following Moodle functions on behalf of the auto-created <code>alphabees-service</code> user:';
$string['ws_grants_summary'] = '{$a} Moodle web-service functions granted';
$string['ws_heading'] = 'Web-services integration';
$string['ws_heading_desc'] = 'Lets the Alphabees backend read course content for the knowledge base and (optionally) push generated courses back to Moodle. One checkbox replaces the legacy 11-step manual setup. Tear-down is one click.';
$string['ws_missing_function'] = 'Moodle does not expose required web-service function: {$a}';
$string['ws_selftest_failed'] = 'The generated web-services token failed Moodle self-test: {$a}';
$string['ws_selftest_function_count'] = '{$a} functions visible to this token';
$string['ws_selftest_missing_functions'] = 'Missing required functions: {$a}';
$string['ws_selftest_status'] = 'Token self-test';
$string['ws_selftest_status_failed'] = 'Failed';
$string['ws_selftest_status_never'] = 'Not run yet';
$string['ws_selftest_status_passed'] = 'Passed';
$string['ws_show_full_consent'] = 'Show full consent text and granted functions';
$string['ws_status_disconnected'] = 'Web-services integration not enabled.';
$string['ws_status_token_present'] = 'Web-services integration enabled. A permanent token is bound to the <code>alphabees-service</code> user.';
$string['ws_token_post_httpcode'] = 'Backend HTTP status: {$a}';
$string['ws_token_post_status'] = 'Backend token exchange';
$string['ws_token_post_status_error'] = 'Failed permanently';
$string['ws_token_post_status_never'] = 'Not run yet';
$string['ws_token_post_status_ok'] = 'Delivered';
$string['ws_token_post_status_queued'] = 'Queued';
$string['ws_token_post_status_revoked_ok'] = 'Revocation delivered';
$string['ws_token_post_status_revoked_queued'] = 'Revocation queued';
$string['ws_token_post_status_transient'] = 'Temporary failure — Moodle will retry';
