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
 * Edit form for block_alphabees instances.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/edit_form.php');

/**
 * Class block_alphabees_edit_form
 *
 * Defines the form for configuring individual instances of the Alphabees block.
 */
class block_alphabees_edit_form extends block_edit_form {

    /**
     * Define the form fields specific to the Alphabees block instance.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    protected function specific_definition($mform): void {
        debugging('[block_alphabees] Loading block instance edit form.', DEBUG_DEVELOPER);

        $mform->addElement('header', 'config_header', get_string('blocksettings', 'block_alphabees'));

        $remotemanaged = !empty($this->block->config->remote_managed);
        $placementuuid = isset($this->block->config->placement_uuid)
            ? (string)$this->block->config->placement_uuid : '';

        if ($remotemanaged) {
            $banner = '<div class="alert alert-info" style="margin-bottom:1em;">'
                . get_string('remotemanaged_banner', 'block_alphabees') . '</div>';
            $mform->addElement('html', $banner);
        }

        $botoptions = $this->get_bot_options();
        $mform->addElement(
            'select',
            'config_botid',
            get_string('botid', 'block_alphabees'),
            $botoptions
        );
        $mform->setType('config_botid', PARAM_TEXT);

        // The override checkbox lets an admin take local control even when
        // the placement was last set by the portal.
        if ($remotemanaged) {
            $mform->addElement(
                'advcheckbox',
                'config_override_remote',
                get_string('overrideremote', 'block_alphabees'),
                get_string('overrideremote_help', 'block_alphabees')
            );
            $mform->setType('config_override_remote', PARAM_BOOL);
        }

        if ($placementuuid !== '') {
            $mform->addElement(
                'static',
                'config_placement_uuid_display',
                get_string('placementuuid', 'block_alphabees'),
                '<code>' . s($placementuuid) . '</code>'
            );
        }
    }

    /**
     * Hook called after data is read from the form. We use it to flip the
     * remote_managed flag back to false when the admin checked "override".
     */
    public function set_data($defaults) {
        if (!empty($this->block->config->remote_managed)) {
            // Default the override checkbox to unchecked so saving without
            // ticking it doesn't accidentally take local control.
            $defaults->config_override_remote = 0;
        }
        return parent::set_data($defaults);
    }

    /**
     * Retrieve bot options from the Alphabees backend.
     *
     * @return array<string, string>
     */
    private function get_bot_options(): array {
        $apikey = get_config('block_alphabees', 'apikey');
        if (empty($apikey)) {
            return ['' => get_string('apikeymissing', 'block_alphabees')];
        }

        $apikey = clean_param($apikey, PARAM_TEXT);
        $url = 'https://api.alphalearn.ai/al/tutors/tutor/moodle-list/' . urlencode($apikey);

        $curl = new curl(['timeout' => 10]);
        $response = $curl->get($url);
        if (!$response) {
            return ['' => get_string('nobotsavailable', 'block_alphabees')];
        }

        $responsedata = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['' => get_string('nobotsavailable', 'block_alphabees')];
        }

        $options = ['' => get_string('selectabot', 'block_alphabees')];
        foreach ($responsedata['data'] ?? [] as $bot) {
            if (isset($bot['id'], $bot['name'])) {
                $options[clean_param($bot['id'], PARAM_TEXT)] = clean_param($bot['name'], PARAM_TEXT);
            }
        }
        return $options;
    }
}
