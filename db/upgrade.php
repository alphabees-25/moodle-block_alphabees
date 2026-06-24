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
 * Upgrade steps for the Alphabees AI Tutor block plugin.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run plugin upgrade steps when transitioning between versions.
 *
 * @param int $oldversion Previous plugin version code (from version.php).
 * @return bool
 */
function xmldb_block_alphabees_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // Web-services auto-setup: capability, services definition, role and
    // service user are added in 2026050802. db/services.php and
    // db/access.php cover fresh installs automatically; for sites already
    // running an earlier 3.0.x, Moodle's plugin upgrader picks up the new
    // capability / service definition the next time admin/upgrade.php runs,
    // so this version step is intentionally a no-op marker.
    if ($oldversion < 2026050802) {
        upgrade_block_savepoint(true, 2026050802, 'alphabees');
    }

    // 3.0.1: ensure the V3 tables exist on sites that upgraded from any
    // earlier block_alphabees release. Moodle only runs install.xml on
    // fresh installs; for upgrades it relies on this function. Without
    // this step the inbound dispatcher hits a missing block_alphabees_nonces
    // table on the first signed request and Moodle renders a themed 404
    // with "Fehler beim Lesen der Datenbank" / dml_exception.
    if ($oldversion < 2026051800) {
        $table = new xmldb_table('block_alphabees_nonces');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('nonce', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('nonce_uniq', XMLDB_INDEX_UNIQUE, ['nonce']);
            $table->add_index('expiresat_idx', XMLDB_INDEX_NOTUNIQUE, ['expiresat']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('block_alphabees_retryqueue');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('endpoint', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('method', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'POST');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('nextattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('lasterror', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('nextattempt_idx', XMLDB_INDEX_NOTUNIQUE, ['nextattempt']);
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026051800, 'alphabees');
    }

    // 3.0.1: the portal integration toggles are opt-out now. Updating the
    // admin setting defaults alone is not enough for existing installs because
    // Moodle keeps the old saved config value (`0`) in config_plugins. Set the
    // persisted value to enabled during upgrade, unless this site has already
    // been intentionally disconnected through the local/portal disconnect flow.
    if ($oldversion < 2026052200) {
        $portaldisconnected = (string)(get_config('block_alphabees', 'portal_disconnected') ?: '') === '1';
        if (!$portaldisconnected) {
            set_config('allow_remote_placement', 1, 'block_alphabees');
            set_config('ws_enabled', 1, 'block_alphabees');
        }

        upgrade_block_savepoint(true, 2026052200, 'alphabees');
    }

    // 3.0.2: lifecycle/status UX and retry behaviour changes. No schema
    // changes are required; this marker makes Moodle process the release
    // upgrade and refresh plugin caches consistently.
    if ($oldversion < 2026060900) {
        upgrade_block_savepoint(true, 2026060900, 'alphabees');
    }

    // 3.0.2 patch 1: narrow the local API-key rejection latch to the register
    // endpoint only. Normal signed routes may fail because of lifecycle state
    // mismatches and must not permanently block the saved API key.
    if ($oldversion < 2026060901) {
        upgrade_block_savepoint(true, 2026060901, 'alphabees');
    }

    // 3.0.2 patch 2: remember which API key fingerprint belongs to the
    // current registration, so changing the key later forces a reconnect
    // instead of silently keeping an old client association. Also remove the
    // legacy mobile-only key fallback so the app can never use stale config.
    if ($oldversion < 2026061100) {
        $apikey = get_config('block_alphabees', 'apikey');
        if (!empty($apikey)
            && !empty(get_config('block_alphabees', 'registered_at'))
            && !empty(get_config('block_alphabees', 'backend_public_key'))) {
            set_config('registered_api_key', hash('sha256', (string)$apikey), 'block_alphabees');
        }
        unset_config('mobile_apikey', 'block_alphabees');
        upgrade_block_savepoint(true, 2026061100, 'alphabees');
    }

    // 3.0.3: refresh the auto-created Moodle web-service integration on
    // upgrade. Fresh installs get the service from db/services.php; existing
    // installs need their already-tokenised service expanded to any newly
    // declared functions. The helper keeps the current token, skips functions
    // unavailable on this Moodle version/site, and avoids loopback REST calls
    // so upgrade cannot fail just because self-test HTTP is blocked.
    if ($oldversion < 2026062200) {
        try {
            $token = \block_alphabees\local\ws_setup::refresh_enabled_integration_for_upgrade();
            if ($token !== null
                && \block_alphabees\local\site_registry::is_registered()
                && !\block_alphabees\local\site_registry::is_registration_blocked()
                && !\block_alphabees\local\site_registry::is_sync_paused()) {
                $task = new \block_alphabees\task\post_ws_token();
                $task->set_custom_data((object)[
                    'token' => $token,
                    'service_shortname' => \block_alphabees\local\ws_setup::SERVICE_SHORTNAME,
                ]);
                \core\task\manager::queue_adhoc_task($task);
                \block_alphabees\local\ws_setup::record_token_post_status('queued');
            }
        } catch (\Throwable $e) {
            // Do not block Moodle upgrades for a recoverable integration
            // refresh. Admins can re-save the WS setting or reconnect to rerun
            // the same idempotent setup path after the upgrade completes.
            \block_alphabees\local\ws_setup::record_token_post_status(
                'error',
                'upgrade_ws_refresh_failed: ' . $e->getMessage()
            );
        }

        upgrade_block_savepoint(true, 2026062200, 'alphabees');
    }

    return true;
}
