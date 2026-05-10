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
 * Triggers a (re-)registration with the backend so the new key is exchanged
 * along with the site's public key. Runs async via an ad-hoc task to keep
 * the settings save snappy and avoid leaking backend latency into the UI.
 *
 * @return void
 */
function block_alphabees_apikey_changed(): void {
    $apikey = get_config('block_alphabees', 'apikey');
    if (empty($apikey)) {
        // Key was cleared — drop registration state so a future re-key starts fresh.
        \block_alphabees\local\site_registry::reset_registration();
        return;
    }

    // Make sure we have a keypair to advertise.
    \block_alphabees\local\site_registry::ensure_keypair();

    // Queue the registration as an ad-hoc task. The next Moodle cron tick
    // (typically within 1 minute) picks it up; for immediate feedback the
    // admin can use the "Connect now" button on the settings page, which
    // runs the same task synchronously with proper notification handling.
    //
    // Why not run it inline here: the settings updatedcallback fires inside
    // a request flow that has already started serializing the session;
    // making a multi-second outbound HTTP call from here triggers Moodle's
    // "mutated session after close" warning and forces the awkward
    // "Continue" intermediate page on save.
    \core\task\manager::queue_adhoc_task(new \block_alphabees\task\register_site());
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

    // First-time register-on-demand: if a key is configured but we've never
    // attempted to register yet, run the task inline now so the panel below
    // shows the backend's actual response immediately after a fresh API-key
    // save — without waiting on cron or fighting Moodle's notification
    // pipeline from the updatedcallback.
    //
    // Only fire on a plain GET render. Moodle includes settings.php during
    // POST save processing too (to build the admin tree); making an outbound
    // HTTP call there bleeds into the session-write-close window and triggers
    // "mutated session after it was closed" warnings + the "Continue" page.
    $apikey = get_config('block_alphabees', 'apikey');
    $requestmethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($requestmethod === 'GET'
        && !empty($apikey)
        && !\block_alphabees\local\site_registry::is_registered()
        && \block_alphabees\local\site_registry::last_register_attempt_at() === null) {
        // The mtrace() calls inside the task write to stdout. In a web context that
        // becomes part of the page output, which Moodle then sees as "Error
        // output, so disabling automatic redirect" → forces the awkward
        // "Continue" intermediate page. Wrap in an output buffer so the task
        // can do its diagnostics without leaking into the rendered page.
        ob_start();
        try {
            (new \block_alphabees\task\register_site())->execute();
        } catch (\Throwable $e) {
            // Expected for transient failures — error is already recorded
            // in registry by record_register_attempt().
            unset($e);
        }
        ob_end_clean();
    }

    $registered = $registry::is_registered();
    $registeredat = $registry::registered_at();
    $lastsync = $registry::last_sync_at();
    $publickey = $registry::public_key();
    $lastattempt = $registry::last_register_attempt_at();
    $lasterror = $registry::last_register_error();

    // State badge + Connect now button on a single header row so both
    // the visual status and the primary action live at the top of the
    // panel without consuming much vertical space.
    $statelabel = $registered
        ? \html_writer::span(get_string('status_connected', 'block_alphabees'), 'badge badge-success')
        : \html_writer::span(get_string('status_disconnected', 'block_alphabees'), 'badge badge-danger');

    global $CFG;
    $connecturl = new \moodle_url('/blocks/alphabees/connect.php', ['sesskey' => sesskey()]);
    $apikeyset = !empty(get_config('block_alphabees', 'apikey'));
    $buttonattrs = ['class' => 'btn btn-sm btn-primary ml-3'];
    if (!$apikeyset) {
        $buttonattrs['class'] .= ' disabled';
        $buttonattrs['aria-disabled'] = 'true';
        $buttonattrs['onclick'] = 'return false;';
    }
    $button = \html_writer::link(
        $connecturl,
        get_string('connect_now', 'block_alphabees'),
        $buttonattrs
    );

    $headerrow = \html_writer::div(
        \html_writer::tag('strong', get_string('connectionstatus', 'block_alphabees') . ' ')
        . $statelabel . $button,
        'd-flex align-items-center mb-2'
    );

    // Build the timestamp + diagnostic rows; both go inside the
    // collapsible <details> so the panel stays compact when healthy.
    $rows = [];
    if ($registeredat) {
        $rows[] = [get_string('status_registeredat', 'block_alphabees'), userdate($registeredat), null];
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
    $detailsopen = $lasterror !== null || !$registered;
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

    // Pull the function list from db/services.php so the UI is always in
    // sync with whatever the plugin actually authorises.
    $services = [];
    require($CFG->dirroot . '/blocks/alphabees/db/services.php');
    $functions = $services['Alphabees']['functions'] ?? [];
    $count = count($functions);

    // Compact one-liner shown directly under the badge.
    $statuskey = $enabled ? 'ws_status_token_present' : 'ws_status_disconnected';
    $statusline = \html_writer::div(
        get_string($statuskey, 'block_alphabees'),
        'small text-muted mb-2'
    );

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
    ) . \html_writer::tag(
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
 * Tick → run the full auto-setup chain and queue an ad-hoc task that sends
 * the freshly-generated token to the backend over the signed channel.
 * Untick → revoke the token. Errors surface via Moodle's notification stack
 * since the callback runs inside the admin-save flow.
 *
 * @return void
 */
function block_alphabees_ws_enabled_changed(): void {
    $checked = (int)get_config('block_alphabees', 'ws_enabled');

    if ($checked) {
        try {
            $token = \block_alphabees\local\ws_setup::enable();
        } catch (\Throwable $e) {
            // Roll the checkbox back so the UI matches actual state, and
            // surface the error to the admin via Moodle's notification stack.
            set_config('ws_enabled', 0, 'block_alphabees');
            \core\notification::error(get_string('ws_enable_failed', 'block_alphabees', $e->getMessage()));
            return;
        }
        // Send the token over the existing signed Ed25519 channel.
        // Async via ad-hoc task — same reasoning as register_site /
        // post_placement_event: lifecycle hooks must not bleed into the
        // session-write-close window.
        $task = new \block_alphabees\task\post_ws_token();
        $task->set_custom_data((object)[
            'token' => $token,
            'service_shortname' => \block_alphabees\local\ws_setup::SERVICE_SHORTNAME,
        ]);
        \core\task\manager::queue_adhoc_task($task);
        \core\notification::success(get_string('ws_enable_success', 'block_alphabees'));
        return;
    }

    try {
        \block_alphabees\local\ws_setup::disable();
    } catch (\Throwable $e) {
        \core\notification::error(get_string('ws_disable_failed', 'block_alphabees', $e->getMessage()));
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
    \core\notification::success(get_string('ws_disable_success', 'block_alphabees'));
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
