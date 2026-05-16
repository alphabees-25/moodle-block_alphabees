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
    // Web-services auto-setup: capability, services definition, role and
    // service user are added in 2026050802. db/services.php and
    // db/access.php cover fresh installs automatically; for sites already
    // running an earlier 3.0.x, Moodle's plugin upgrader picks up the new
    // capability / service definition the next time admin/upgrade.php runs,
    // so this version step is intentionally a no-op marker.
    if ($oldversion < 2026050802) {
        upgrade_block_savepoint(true, 2026050802, 'alphabees');
    }

    return true;
}
