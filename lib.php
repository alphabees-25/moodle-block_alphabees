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
 * Plugin-wide functions for block_alphabees.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Settings updatedcallback fired when the API key changes.
 *
 * Clearing the key pauses a registered site. Saving a non-empty key only
 * updates local state; admins explicitly use "Connect now" or "Resume sync"
 * for backend actions.
 *
 * @return void
 */
function block_alphabees_apikey_changed(): void {
    unset_config('connect_requested', 'block_alphabees');
    unset_config('connect_requested_at', 'block_alphabees');

    $apikey = get_config('block_alphabees', 'apikey');
    if (empty($apikey)) {
        unset_config('mobile_apikey', 'block_alphabees');
        // Key was cleared by a Moodle admin. Treat that as a site pause, not
        // a disconnect: keep registration, signing keys, and WS tokens intact.
        if (\block_alphabees\local\site_registry::is_registered()) {
            block_alphabees_queue_site_lifecycle_event(
                'site.paused',
                'Portal API key missing'
            );
            \block_alphabees\local\site_registry::pause_syncs('Paused by Moodle admin');
        } else {
            \block_alphabees\local\site_registry::reset_registration();
        }
        return;
    }

    unset_config('mobile_apikey', 'block_alphabees');

    $currentfingerprint = \block_alphabees\local\site_registry::current_api_key_fingerprint();
    $registeredfingerprint = \block_alphabees\local\site_registry::registered_api_key_fingerprint();
    $sameasregistered = $currentfingerprint !== null
        && $registeredfingerprint !== null
        && hash_equals($registeredfingerprint, $currentfingerprint);

    if (\block_alphabees\local\site_registry::is_registered()
        && \block_alphabees\local\site_registry::is_sync_paused()
        && $sameasregistered
        && !\block_alphabees\local\site_registry::is_registration_blocked()) {
        return;
    }

    // A changed API key invalidates the previous registration and clears the
    // permanent-failure gate. Registration itself is an explicit Connect action.
    \block_alphabees\local\site_registry::reset_registration();
    \block_alphabees\local\site_registry::clear_portal_disconnect();
}

/**
 * Queue a signed site lifecycle event for the Alphabees backend.
 *
 * @param string $eventtype
 * @param string $reason
 * @return void
 */
function block_alphabees_queue_site_lifecycle_event(
    string $eventtype,
    string $reason
): void {
    \block_alphabees\local\backend_client::queue_retry(
        \block_alphabees\local\site_registry::path_lifecycle(),
        block_alphabees_site_lifecycle_payload($eventtype, $reason),
        0
    );
}

/**
 * Drop queued lifecycle events superseded by a newer explicit admin action.
 *
 * @param string $eventtype
 * @return void
 */
function block_alphabees_drop_queued_site_lifecycle_event(string $eventtype): void {
    global $DB;

    $rows = $DB->get_records('block_alphabees_retryqueue', [
        'endpoint' => \block_alphabees\local\site_registry::path_lifecycle(),
    ]);
    foreach ($rows as $row) {
        $payload = json_decode((string)$row->payload, true);
        if (is_array($payload)
            && isset($payload['event_type'])
            && (string)$payload['event_type'] === $eventtype) {
            $DB->delete_records('block_alphabees_retryqueue', ['id' => $row->id]);
        }
    }
}

/**
 * Send a signed site lifecycle event to the Alphabees backend immediately.
 *
 * @param string $eventtype
 * @param string $reason
 * @return array backend_client result
 */
function block_alphabees_post_site_lifecycle_event(
    string $eventtype,
    string $reason
): array {
    return \block_alphabees\local\backend_client::post(
        \block_alphabees\local\site_registry::path_lifecycle(),
        block_alphabees_site_lifecycle_payload($eventtype, $reason)
    );
}

/**
 * Build the canonical lifecycle payload used for direct sends and retries.
 *
 * @param string $eventtype
 * @param string $reason
 * @return array
 */
function block_alphabees_site_lifecycle_payload(
    string $eventtype,
    string $reason
): array {
    $plugin = new \stdClass();
    require(__DIR__ . '/version.php');

    $payload = [
        'event_type' => $eventtype,
        'reason' => $reason,
        'plugin_version' => isset($plugin->release) ? (string)$plugin->release : '',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($eventtype === 'site.paused') {
        $payload['api_key_present'] = \block_alphabees\local\site_registry::api_key_present();
        $payload['api_key_status'] = \block_alphabees\local\site_registry::api_key_status();
    }
    return $payload;
}

/**
 * Whether local resume is allowed for the current saved API key.
 *
 * @return bool
 */
function block_alphabees_can_resume_site(): bool {
    return \block_alphabees\local\site_registry::api_key_present()
        && !\block_alphabees\local\site_registry::is_registration_blocked();
}

/**
 * Render the combined Status & Diagnostics panel for the settings page.
 *
 * Hosts both the backend-connection status and the web-services integration
 * status in a single visually consistent block. Each sub-panel keeps its
 * primary state visible (badge + Connect-now button) and pushes everything
 * else (timestamps, technical details, function list, full consent text)
 * behind native <details> disclosure widgets, so the page stays calm at a
 * glance and admins drill down only when troubleshooting.
 *
 * @return string
 */
function block_alphabees_render_status_panel(): string {
    return block_alphabees_render_connection_status()
        . block_alphabees_render_ws_status();
}

/**
 * Build a compact HTML snippet describing the current backend-connection state.
 *
 * Lives in lib.php (not settings.php) because Moodle re-includes settings.php
 * during a single admin request — defining the function there causes a
 * "Cannot redeclare" fatal on the second include.
 *
 * @return string
 */
function block_alphabees_render_connection_status(): string {
    $registry = '\block_alphabees\local\site_registry';

    // Do not perform registration while rendering this settings panel. Moodle
    // page loads must never wait on the Alphabees backend; admins use the
    // explicit "Connect now" action when they want a registration attempt.

    $registered = $registry::is_registered();
    $registeredat = $registry::registered_at();
    $lastsync = $registry::last_sync_at();
    $publickey = $registry::public_key();
    $lastattempt = $registry::last_register_attempt_at();
    $lasterror = $registry::last_register_error();
    $blocked = $registry::is_registration_blocked();
    $blockreason = $registry::registration_block_reason();
    $syncpaused = $registry::is_sync_paused();
    $syncpausedat = $registry::sync_paused_at();
    $syncpausereason = $registry::sync_pause_reason();
    // State badge + contextual action buttons on a single header row so both
    // the visual status and primary actions live at the top of the panel.
    $statelabel = $blocked
        ? \html_writer::span(get_string('status_registration_blocked', 'block_alphabees'), 'badge badge-danger')
        : ($registered && $syncpaused
        ? \html_writer::span(get_string('status_sync_paused', 'block_alphabees'), 'badge badge-warning')
        : ($registered
        ? \html_writer::span(get_string('status_connected', 'block_alphabees'), 'badge badge-success')
        : \html_writer::span(get_string('status_disconnected', 'block_alphabees'), 'badge badge-danger')));

    global $CFG;
    $connecturl = new \moodle_url('/blocks/alphabees/connect.php', ['sesskey' => sesskey()]);
    $disconnecturl = new \moodle_url('/blocks/alphabees/disconnect.php', ['sesskey' => sesskey()]);
    $resumeurl = new \moodle_url('/blocks/alphabees/resume_sync.php', ['sesskey' => sesskey()]);
    $apikeyset = !empty(get_config('block_alphabees', 'apikey'));
    $canresume = block_alphabees_can_resume_site();
    $buttons = '';
    if (!$registered || $blocked) {
        $buttonattrs = ['class' => 'btn btn-sm btn-primary ml-3'];
        if (!$apikeyset) {
            $buttonattrs['class'] .= ' disabled';
            $buttonattrs['aria-disabled'] = 'true';
            $buttonattrs['onclick'] = 'return false;';
        }
        $buttons .= \html_writer::link(
            $connecturl,
            get_string('connect_now', 'block_alphabees'),
            $buttonattrs
        );
    }
    if ($registered && $syncpaused && $canresume) {
        $buttons .= \html_writer::link(
            $resumeurl,
            get_string('resume_sync_now', 'block_alphabees'),
            ['class' => 'btn btn-sm btn-warning ' . ($buttons === '' ? 'ml-3' : 'ml-2')]
        );
    }
    if ($registered) {
        $buttons .= \html_writer::link(
            $disconnecturl,
            get_string('disconnect_now', 'block_alphabees'),
            [
                'class' => 'btn btn-sm btn-outline-danger ' . ($buttons === '' ? 'ml-3' : 'ml-2'),
                'onclick' => 'return confirm(' . json_encode(get_string('disconnect_confirm', 'block_alphabees')) . ');',
            ]
        );
    }

    $headerrow = \html_writer::div(
        \html_writer::tag('strong', get_string('connectionstatus', 'block_alphabees') . ' ')
        . $statelabel . $buttons,
        'd-flex align-items-center mb-2'
    );
    if ($blocked) {
        $headerrow .= \html_writer::div(
            get_string('connectionstatus_blocked_help', 'block_alphabees'),
            'alert alert-danger py-2 mb-2'
        );
    } else if (!$registered && !$apikeyset) {
        $headerrow .= \html_writer::div(
            get_string('connectionstatus_apikey_missing_help', 'block_alphabees'),
            'alert alert-warning py-2 mb-2'
        );
    } else if (!$registered && $apikeyset) {
        $headerrow .= \html_writer::div(
            get_string('connectionstatus_connect_required_help', 'block_alphabees'),
            'alert alert-info py-2 mb-2'
        );
    } else if ($registered && $syncpaused) {
        $headerrow .= \html_writer::div(
            get_string('connectionstatus_sync_paused_help', 'block_alphabees'),
            'alert alert-warning py-2 mb-2'
        );
        if (!$canresume) {
            $resumehelp = \block_alphabees\local\site_registry::api_key_status() === 'missing'
                ? get_string('connectionstatus_sync_paused_apikey_missing_help', 'block_alphabees')
                : get_string('resume_sync_requires_apikey', 'block_alphabees');
            $headerrow .= \html_writer::div(
                $resumehelp,
                'alert alert-info py-2 mb-2'
            );
        }
    }

    // Build the timestamp + diagnostic rows; both go inside the
    // collapsible <details> so the panel stays compact when healthy.
    $rows = [];
    if ($blocked) {
        $rows[] = [
            get_string('status_registration_blocked_detail', 'block_alphabees'),
            \html_writer::span(s($blockreason ?? ''), 'text-danger'),
            get_string('connectionstatus_blocked_help', 'block_alphabees'),
        ];
    }
    if ($registeredat) {
        $rows[] = [get_string('status_registeredat', 'block_alphabees'), userdate($registeredat), null];
    }
    if ($syncpaused) {
        $rows[] = [
            get_string('status_sync_paused_detail', 'block_alphabees'),
            $syncpausedat ? userdate($syncpausedat) : get_string('status_sync_paused', 'block_alphabees'),
            get_string('connectionstatus_sync_paused_help', 'block_alphabees'),
        ];
        if ($syncpausereason !== null) {
            $rows[] = [
                get_string('status_sync_pause_reason', 'block_alphabees'),
                s($syncpausereason),
                null,
            ];
        }
    }
    if ($lastsync) {
        $rows[] = [get_string('status_lastsync', 'block_alphabees'), userdate($lastsync), null];
    }
    if ($lastattempt) {
        $rows[] = [get_string('status_lastattempt', 'block_alphabees'), userdate($lastattempt), null];
    }
    if ($lasterror) {
        $rows[] = [
            get_string('status_lasterror', 'block_alphabees'),
            \html_writer::span(s($lasterror), 'text-danger'),
            null,
        ];
    }
    $rows[] = [
        get_string('status_siteidentifier', 'block_alphabees'),
        \html_writer::tag('code', s($registry::site_identifier())),
        get_string('connectionstatus_siteidentifier_help', 'block_alphabees'),
    ];
    if ($publickey !== null) {
        $rows[] = [
            get_string('status_publickey', 'block_alphabees'),
            \html_writer::tag('code', s(\block_alphabees\local\crypto::base64url_encode($publickey))),
            get_string('connectionstatus_publickey_help', 'block_alphabees'),
        ];
    }
    $detailtable = block_alphabees_render_status_table($rows);

    // Collapsed by default for healthy sites; auto-opens when there's an
    // active error so the admin lands on the diagnostic context.
    $detailsopen = $blocked || $syncpaused || $lasterror !== null || !$registered;
    $details = \html_writer::tag(
        'details',
        \html_writer::tag(
            'summary',
            get_string('connectionstatus_showdetails', 'block_alphabees'),
            ['class' => 'text-muted small', 'style' => 'cursor:pointer;']
        ) . $detailtable,
        $detailsopen ? ['open' => 'open'] : []
    );

    return \html_writer::div(
        $headerrow . $details,
        'card card-body bg-light mb-3'
    );
}

/**
 * Render the small status panel that sits inside the Web-Services heading.
 *
 * Shows whether the integration is currently active, plus a transparent
 * list of every Moodle function the auto-created token is allowed to
 * call — admins should see the granted scope before they tick the
 * checkbox.
 *
 * @return string
 */
function block_alphabees_render_ws_status(): string {
    global $CFG;
    $enabled = \block_alphabees\local\ws_setup::is_enabled();

    $badge = $enabled
        ? \html_writer::span(get_string('status_connected', 'block_alphabees'), 'badge badge-success')
        : \html_writer::span(get_string('status_disconnected', 'block_alphabees'), 'badge badge-secondary');

    $headerrow = \html_writer::div(
        \html_writer::tag('strong', get_string('ws_heading', 'block_alphabees') . ' ') . $badge,
        'd-flex align-items-center mb-2'
    );

    // Pull the function list from db/services.php via the service shortname so
    // the UI stays in sync even when the human-readable service name changes.
    $functions = \block_alphabees\local\ws_setup::declared_service_functions();
    $count = count($functions);

    // Compact one-liner shown directly under the badge.
    $statuskey = $enabled ? 'ws_status_token_present' : 'ws_status_disconnected';
    $statusline = \html_writer::div(
        get_string($statuskey, 'block_alphabees'),
        'small text-muted mb-2'
    );

    $diagnostics = \block_alphabees\local\ws_setup::diagnostics();
    $selftestlabels = [
        'never' => get_string('ws_selftest_status_never', 'block_alphabees'),
        'passed' => get_string('ws_selftest_status_passed', 'block_alphabees'),
        'failed' => get_string('ws_selftest_status_failed', 'block_alphabees'),
    ];
    $postlabels = [
        'never' => get_string('ws_token_post_status_never', 'block_alphabees'),
        'queued' => get_string('ws_token_post_status_queued', 'block_alphabees'),
        'ok' => get_string('ws_token_post_status_ok', 'block_alphabees'),
        'transient' => get_string('ws_token_post_status_transient', 'block_alphabees'),
        'error' => get_string('ws_token_post_status_error', 'block_alphabees'),
        'revoked_queued' => get_string('ws_token_post_status_revoked_queued', 'block_alphabees'),
        'revoked_ok' => get_string('ws_token_post_status_revoked_ok', 'block_alphabees'),
    ];

    $selftestvalue = $selftestlabels[$diagnostics['selftest_status']]
        ?? s($diagnostics['selftest_status']);
    if (!empty($diagnostics['selftest_at'])) {
        $selftestvalue .= ' (' . userdate($diagnostics['selftest_at']) . ')';
    }
    if (!empty($diagnostics['selftest_function_count'])) {
        $selftestvalue .= \html_writer::div(
            get_string('ws_selftest_function_count', 'block_alphabees', $diagnostics['selftest_function_count']),
            'small text-muted'
        );
    }
    if (!empty($diagnostics['selftest_missing_functions'])) {
        $selftestvalue .= \html_writer::div(
            get_string(
                'ws_selftest_missing_functions',
                'block_alphabees',
                implode(', ', $diagnostics['selftest_missing_functions'])
            ),
            'small text-danger'
        );
    }
    if (!empty($diagnostics['selftest_error'])) {
        $selftestvalue .= \html_writer::div(s($diagnostics['selftest_error']), 'small text-danger');
    }

    $postvalue = $postlabels[$diagnostics['post_status']] ?? s($diagnostics['post_status']);
    if (!empty($diagnostics['post_at'])) {
        $postvalue .= ' (' . userdate($diagnostics['post_at']) . ')';
    }
    if (!empty($diagnostics['post_httpcode'])) {
        $postvalue .= \html_writer::div(
            get_string('ws_token_post_httpcode', 'block_alphabees', $diagnostics['post_httpcode']),
            'small text-muted'
        );
    }
    if (!empty($diagnostics['post_error'])) {
        $postvalue .= \html_writer::div(s($diagnostics['post_error']), 'small text-danger');
    }

    $diagnostictable = block_alphabees_render_status_table([
        [get_string('ws_selftest_status', 'block_alphabees'), $selftestvalue, null],
        [get_string('ws_token_post_status', 'block_alphabees'), $postvalue, null],
    ]);

    // Function list lives inside the collapsible — admins don't need it
    // every time, but it's one click away when they want to audit scope.
    $items = '';
    foreach ($functions as $fn) {
        $items .= \html_writer::tag('li', \html_writer::tag('code', s($fn)));
    }
    $functionlist = \html_writer::tag('ul', $items, ['class' => 'mb-0 mt-2']);

    $detailsbody = \html_writer::div(
        get_string('ws_enable_consent', 'block_alphabees'),
        'mb-3'
    ) . $diagnostictable . \html_writer::tag(
        'strong',
        get_string('ws_grants_summary', 'block_alphabees', $count)
    ) . \html_writer::div(
        get_string('ws_grants_intro', 'block_alphabees'),
        'mt-1 small text-muted'
    ) . $functionlist;

    $details = \html_writer::tag(
        'details',
        \html_writer::tag(
            'summary',
            get_string('ws_show_full_consent', 'block_alphabees') . ' (' . $count . ')',
            ['class' => 'text-muted small', 'style' => 'cursor:pointer;']
        ) . \html_writer::div($detailsbody, 'mt-2'),
        []
    );

    return \html_writer::div(
        $headerrow . $statusline . $details,
        'card card-body bg-light mb-3'
    );
}

/**
 * Settings updatedcallback for the WS-integration checkbox.
 *
 * Tick → run the local auto-setup chain. If the site is already connected and
 * active, queue an ad-hoc task that sends the freshly-generated token to the
 * backend over the signed channel. Untick → revoke the local token and inform
 * the backend only when normal signed backend calls are currently allowed.
 * Errors are persisted into the status panel diagnostics; settings callbacks
 * must not mutate Moodle notifications because admin/upgradesettings.php may
 * already have closed the session.
 *
 * @return void
 */
function block_alphabees_ws_enabled_changed(): void {
    $checked = (int)get_config('block_alphabees', 'ws_enabled');

    if ($checked) {
        try {
            $token = \block_alphabees\local\ws_setup::enable();
        } catch (\Throwable $e) {
            try {
                \block_alphabees\local\ws_setup::disable();
            } catch (\Throwable $disableerror) {
                unset($disableerror);
            }
            // Roll the checkbox back so the UI matches actual state and store
            // the failure for the diagnostics panel.
            set_config('ws_enabled', 0, 'block_alphabees');
            \block_alphabees\local\ws_setup::record_token_post_status('error', $e->getMessage());
            return;
        }
        if (!\block_alphabees\local\site_registry::is_registered()
            || \block_alphabees\local\site_registry::is_registration_blocked()
            || \block_alphabees\local\site_registry::is_sync_paused()) {
            \block_alphabees\local\ws_setup::record_token_post_status('never');
            return;
        }

        // Send the token over the existing signed Ed25519 channel. Async via
        // ad-hoc task so settings saves do not wait on the backend.
        $task = new \block_alphabees\task\post_ws_token();
        $task->set_custom_data((object)[
            'token' => $token,
            'service_shortname' => \block_alphabees\local\ws_setup::SERVICE_SHORTNAME,
        ]);
        \core\task\manager::queue_adhoc_task($task);
        \block_alphabees\local\ws_setup::record_token_post_status('queued');
        return;
    }

    try {
        \block_alphabees\local\ws_setup::disable();
    } catch (\Throwable $e) {
        set_config('ws_enabled', 1, 'block_alphabees');
        \block_alphabees\local\ws_setup::record_token_post_status('error', $e->getMessage());
        return;
    }
    if (!\block_alphabees\local\site_registry::is_registered()
        || \block_alphabees\local\site_registry::is_registration_blocked()
        || \block_alphabees\local\site_registry::is_sync_paused()) {
        \block_alphabees\local\ws_setup::record_token_post_status('never');
        return;
    }

    // Inform the backend that the token is revoked so it doesn't keep
    // calling Moodle with a now-invalid credential.
    $task = new \block_alphabees\task\post_ws_token();
    $task->set_custom_data((object)[
        'token' => null,
        'service_shortname' => \block_alphabees\local\ws_setup::SERVICE_SHORTNAME,
    ]);
    \core\task\manager::queue_adhoc_task($task);
    \block_alphabees\local\ws_setup::record_token_post_status('revoked_queued');
}

/**
 * Render the inner HTML of one "status table" given a list of [label, value, help] tuples.
 *
 * @param array $rows
 * @return string
 */
function block_alphabees_render_status_table(array $rows): string {
    if (empty($rows)) {
        return '';
    }
    $tablerows = '';
    foreach ($rows as [$label, $value, $help]) {
        $labelcell = \html_writer::tag('strong', s($label));
        if ($help) {
            $labelcell .= \html_writer::div(s($help), 'small text-muted');
        }
        $tablerows .= \html_writer::tag('tr',
            \html_writer::tag('th', $labelcell, ['style' => 'text-align:left;vertical-align:top;width:35%;'])
            . \html_writer::tag('td', $value, ['style' => 'vertical-align:top;'])
        );
    }
    return \html_writer::tag('table', $tablerows, ['class' => 'generaltable']);
}
