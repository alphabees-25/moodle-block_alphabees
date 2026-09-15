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

// The console lives outside the settings page, so it is registered as its own
// admin page. Added before the fulltree check because external pages must
// exist in the tree whether or not the settings body is being built.
//
// The admin tree is built on every admin request, including the one that runs
// the plugin upgrade — and until that upgrade completes, the capability from
// db/access.php does not exist in the database yet. Guarding the page with it
// unconditionally makes Moodle log "Capability ... was not found" throughout
// that window, so fall back to the site-config permission until it is there.
require_once($CFG->dirroot . '/blocks/alphabees/lib.php');
$consolecapability = block_alphabees_console_capability_installed()
    ? 'block/alphabees:useconsole'
    : 'moodle/site:config';

$ADMIN->add('blocksettings', new admin_externalpage(
    'block_alphabees_console',
    get_string('console_title', 'block_alphabees'),
    new moodle_url('/blocks/alphabees/console.php'),
    $consolecapability
));

if ($ADMIN->fulltree) {
    // Renderer lives in lib.php (auto-loaded once per request) to avoid a
    // "Cannot redeclare" fatal when Moodle re-includes settings.php.
    require_once($CFG->dirroot . '/blocks/alphabees/lib.php');

    // Setup section.
    // All actionable inputs (API key + the opt-in checkboxes) live at
    // the top with one-line help texts. Anything diagnostic / verbose is
    // pushed into the Status panel below so admins can configure quickly
    // without scrolling past large information blocks.
    $settings->add(new admin_setting_heading(
        'block_alphabees/brandheader',
        '',
        block_alphabees_render_brand_header()
    ));

    $settings->add(new admin_setting_heading(
        'block_alphabees/general_settings',
        get_string('generalsettings', 'block_alphabees'),
        get_string('generalsettings_desc', 'block_alphabees')
    ));

    $settings->add(new admin_setting_heading(
        'block_alphabees/first_setup',
        get_string('firstsetup', 'block_alphabees'),
        get_string('firstsetup_desc', 'block_alphabees')
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

    $remoteplacementsetting = new admin_setting_configcheckbox(
        'block_alphabees/allow_remote_placement',
        get_string('allow_remote_placement', 'block_alphabees'),
        get_string('allow_remote_placement_short', 'block_alphabees'),
        1
    );
    $settings->add($remoteplacementsetting);

    $wssetting = new admin_setting_configcheckbox(
        'block_alphabees/ws_enabled',
        get_string('ws_enable', 'block_alphabees'),
        get_string('ws_enable_short', 'block_alphabees'),
        1
    );
    $wssetting->set_updatedcallback('block_alphabees_ws_enabled_changed');
    $settings->add($wssetting);

    $settings->add(new admin_setting_configcheckbox(
        'block_alphabees/send_userprofile',
        get_string('send_userprofile', 'block_alphabees'),
        get_string('send_userprofile_short', 'block_alphabees'),
        0
    ));

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

    // Wording and naming live on their own page: four long fields — one of
    // them a full HTML editor — would otherwise dominate a page most admins
    // only open to check the connection. A line with a link costs nothing.
    $settings->add(new admin_setting_heading(
        'block_alphabees/customtexts_pointer',
        get_string('customtexts', 'block_alphabees'),
        \html_writer::div(
            \html_writer::div(
                get_string('customtexts_pointer', 'block_alphabees'),
                'mb-3'
            )
            . \html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'block_alphabees_texts']),
                \html_writer::tag('i', '', [
                    'class' => 'icon fa fa-cog fa-fw',
                    'aria-hidden' => 'true',
                ]) . get_string('customtexts_open', 'block_alphabees'),
                ['class' => 'btn btn-secondary']
            ),
            'alphabees-settings-pointer'
        )
    ));
}

// Second page for wording and naming, registered as a sibling of the main
// settings page under Plugins > Blocks.
$textspage = new admin_settingpage(
    'block_alphabees_texts',
    get_string('customtexts', 'block_alphabees'),
    'moodle/site:config'
);

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/blocks/alphabees/lib.php');

    $textspage->add(new admin_setting_heading(
        'block_alphabees/customtexts_brand',
        '',
        block_alphabees_render_brand_header()
    ));

    $textspage->add(new admin_setting_heading(
        'block_alphabees/customtexts_intro',
        '',
        get_string('customtexts_desc', 'block_alphabees')
    ));

    $textspage->add(new admin_setting_configtext(
        'block_alphabees/custom_blocktitle',
        get_string('custom_blocktitle', 'block_alphabees'),
        block_alphabees_custom_text_desc('custom_blocktitle_desc', 'pluginname'),
        '',
        PARAM_TEXT
    ));

    $textspage->add(new admin_setting_configcheckbox(
        'block_alphabees/customtexts_enabled',
        get_string('customtexts_enabled', 'block_alphabees'),
        get_string('customtexts_enabled_desc', 'block_alphabees'),
        0
    ));

    $textspage->add(new admin_setting_configtext(
        'block_alphabees/custom_usagetitle',
        get_string('custom_usagetitle', 'block_alphabees'),
        block_alphabees_custom_text_desc('custom_usagetitle_desc', 'usagetitle'),
        '',
        PARAM_TEXT
    ));

    $textspage->add(new admin_setting_confightmleditor(
        'block_alphabees/custom_usagetext',
        get_string('custom_usagetext', 'block_alphabees'),
        block_alphabees_custom_text_desc('custom_usagetext_desc', 'usagetext'),
        '',
        PARAM_RAW
    ));

    $textspage->add(new admin_setting_configtext(
        'block_alphabees/custom_mobilehelplabel',
        get_string('custom_mobilehelplabel', 'block_alphabees'),
        block_alphabees_custom_text_desc('custom_mobilehelplabel_desc', 'help'),
        '',
        PARAM_TEXT
    ));

    $textspage->add(new admin_setting_configtextarea(
        'block_alphabees/custom_mobilehelptext',
        get_string('custom_mobilehelptext', 'block_alphabees'),
        block_alphabees_custom_text_desc('custom_mobilehelptext_desc', 'helptitle'),
        '',
        PARAM_RAW
    ));

    // Collapse the four info texts unless the toggle above is ticked. Moodle
    // hides them client-side; the values survive an unticked toggle, so
    // switching back off returns to the built-in texts without losing what
    // somebody typed.
    if (method_exists($textspage, 'hide_if')) {
        foreach (\block_alphabees\local\custom_texts::INFO_TEXTS as $field) {
            $textspage->hide_if(
                'block_alphabees/' . $field,
                'block_alphabees/customtexts_enabled',
                'notchecked'
            );
        }
    }
}

$ADMIN->add('blocksettings', $textspage);
