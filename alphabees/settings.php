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
 * Settings for the Alphabees AI Tutor block.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Renderer lives in lib.php (auto-loaded once per request) to avoid a
    // "Cannot redeclare" fatal when Moodle re-includes settings.php.
    require_once($CFG->dirroot . '/blocks/alphabees/lib.php');

    // Setup section.
    // All actionable inputs (API key + the two opt-in checkboxes) live at
    // the top with one-line help texts. Anything diagnostic / verbose is
    // pushed into the Status panel below so admins can configure quickly
    // without scrolling past large information blocks.
    $settings->add(new admin_setting_heading(
        'block_alphabees/general_settings',
        get_string('generalsettings', 'block_alphabees'),
        get_string('generalsettings_desc', 'block_alphabees')
    ));

    $apikeysetting = new admin_setting_configtext(
        'block_alphabees/apikey',
        get_string('apikey', 'block_alphabees'),
        get_string('apikey_desc', 'block_alphabees'),
        '',
        PARAM_TEXT
    );
    $apikeysetting->set_updatedcallback('block_alphabees_apikey_changed');
    $settings->add($apikeysetting);

    $settings->add(new admin_setting_configcheckbox(
        'block_alphabees/allow_remote_placement',
        get_string('allow_remote_placement', 'block_alphabees'),
        get_string('allow_remote_placement_short', 'block_alphabees'),
        0
    ));

    $wssetting = new admin_setting_configcheckbox(
        'block_alphabees/ws_enabled',
        get_string('ws_enable', 'block_alphabees'),
        get_string('ws_enable_short', 'block_alphabees'),
        0
    );
    $wssetting->set_updatedcallback('block_alphabees_ws_enabled_changed');
    $settings->add($wssetting);

    // Status and diagnostics section.
    // Two stacked compact cards (connection / web services) with everything
    // verbose tucked behind native <details> disclosures. Heading
    // description hosts the entire panel HTML, so this is one Moodle
    // setting block instead of four.
    $settings->add(new admin_setting_heading(
        'block_alphabees/status',
        get_string('statusheading', 'block_alphabees'),
        block_alphabees_render_status_panel()
    ));
}
