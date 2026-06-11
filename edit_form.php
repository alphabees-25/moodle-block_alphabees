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
require_once($CFG->libdir . '/filelib.php');

/**
 * Class block_alphabees_edit_form
 *
 * Defines the form for configuring individual instances of the Alphabees block.
 */
class block_alphabees_edit_form extends block_edit_form {

    /** @var string Notice shown when the tutor list cannot be loaded. */
    private $botoptionsnotice = '';

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

        $mform->addElement(
            'select',
            'config_botid',
            get_string('selectabot', 'block_alphabees'),
            $this->get_bot_options()
        );
        $currentbotid = $this->current_bot_id();
        if ($currentbotid !== '') {
            $mform->setDefault('config_botid', $currentbotid);
        }
        $mform->setType('config_botid', PARAM_TEXT);
        $mform->addRule(
            'config_botid',
            get_string('nobotselected', 'block_alphabees'),
            'required',
            null
        );
        if ($this->botoptionsnotice !== '') {
            $mform->addElement(
                'html',
                \html_writer::div($this->botoptionsnotice, 'alert alert-warning py-2')
            );
        }

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
        if (!empty($this->block->config->botid)) {
            $defaults->config_botid = $this->current_bot_id();
        }
        if (!empty($this->block->config->remote_managed)) {
            // Default the override checkbox to unchecked so saving without
            // ticking it doesn't accidentally take local control.
            $defaults->config_override_remote = 0;
        }
        return parent::set_data($defaults);
    }

    /**
     * Retrieve the available agent options from the Alphabees backend.
     *
     * @return array
     */
    private function get_bot_options(): array {
        $apikey = get_config('block_alphabees', 'apikey');
        if (empty($apikey)) {
            return $this->add_current_bot_option([
                '' => get_string('apikeymissing', 'block_alphabees'),
            ]);
        }

        $url = \block_alphabees\local\site_registry::backend_url()
            . \block_alphabees\local\site_registry::API_BASE
            . '/tutor/moodle-list/'
            . urlencode((string)$apikey);

        $curl = new \curl(['timeout' => 10]);
        $response = $curl->get($url);
        if (!$response) {
            $this->botoptionsnotice = get_string('botlist_unavailable_help', 'block_alphabees');
            return $this->add_current_bot_option([
                '' => get_string('nobotsavailable', 'block_alphabees'),
            ]);
        }

        $responsedata = json_decode((string)$response, true);
        if (!is_array($responsedata)) {
            $this->botoptionsnotice = get_string('botlist_unavailable_help', 'block_alphabees');
            return $this->add_current_bot_option([
                '' => get_string('nobotsavailable', 'block_alphabees'),
            ]);
        }

        $options = ['' => get_string('selectabot', 'block_alphabees')];
        foreach ($responsedata['data'] ?? [] as $bot) {
            if (!is_array($bot)) {
                continue;
            }
            $botid = $this->extract_agent_id($bot);
            if ($botid === '') {
                continue;
            }
            $options[$botid] = $this->format_agent_label($bot);
        }

        if (count($options) === 1) {
            $this->botoptionsnotice = get_string('botlist_unavailable_help', 'block_alphabees');
            return $this->add_current_bot_option([
                '' => get_string('nobotsavailable', 'block_alphabees'),
            ]);
        }

        return $this->add_current_bot_option($options);
    }

    /**
     * Ensure the currently stored tutor id is renderable even if the live
     * backend list is unavailable or no longer contains that tutor.
     *
     * Moodle 4.2 is stricter about select defaults that are not present in the
     * option list; without this fallback it renders the placeholder even though
     * configdata still contains the portal-managed botid.
     *
     * @param array $options Select options keyed by tutor id.
     * @return array
     */
    private function add_current_bot_option(array $options): array {
        $currentbotid = $this->current_bot_id();
        if ($currentbotid === '' || array_key_exists($currentbotid, $options)) {
            return $options;
        }

        $currentlabel = $this->current_bot_label();
        $options[$currentbotid] = $currentlabel !== ''
            ? $currentlabel
            : get_string('currentbotfallback', 'block_alphabees', $currentbotid);
        return $options;
    }

    /**
     * Return the current tutor id stored in this block instance.
     *
     * @return string
     */
    private function current_bot_id(): string {
        return isset($this->block->config->botid)
            ? clean_param((string)$this->block->config->botid, PARAM_TEXT)
            : '';
    }

    /**
     * Return the current tutor label stored in this block instance, if known.
     *
     * @return string
     */
    private function current_bot_label(): string {
        return isset($this->block->config->bot_label)
            ? clean_param((string)$this->block->config->bot_label, PARAM_TEXT)
            : '';
    }

    /**
     * Extract the tutor id from a backend row. Accepts both old and new payload shapes.
     *
     * @param array $agent
     * @return string
     */
    private function extract_agent_id(array $agent): string {
        foreach (['id', 'bot_id', 'botId', 'uuid'] as $key) {
            if (!empty($agent[$key])) {
                return clean_param((string)$agent[$key], PARAM_TEXT);
            }
        }
        return '';
    }

    /**
     * Build a readable dropdown label from a backwards-compatible agent row.
     *
     * @param array $agent
     * @return string
     */
    private function format_agent_label(array $agent): string {
        $visualname = $this->first_non_empty($agent, ['visual_name', 'visualName', 'display_name', 'displayName']);
        $internalname = $this->first_non_empty($agent, ['name', 'internal_name', 'internalName']);
        $fallbackid = $this->extract_agent_id($agent);

        $labelparts = [];
        if ($visualname !== '') {
            $labelparts[] = $visualname;
        }
        if ($internalname !== '' && $internalname !== $visualname) {
            $labelparts[] = $internalname;
        }
        if (empty($labelparts) && $fallbackid !== '') {
            $labelparts[] = $fallbackid;
        }

        $label = clean_param(implode(' · ', $labelparts), PARAM_TEXT);

        $typevalue = $this->first_non_empty($agent, ['type', 'agent_type', 'agentType']);
        if ($typevalue === '') {
            return $label;
        }

        $type = clean_param($typevalue, PARAM_ALPHANUMEXT);
        if ($type === '') {
            return $label;
        }

        $type = str_replace(['_', '-'], ' ', $type);
        return $label . ' · ' . ucwords($type);
    }

    /**
     * Return the first non-empty string from a list of possible keys.
     *
     * @param array $row
     * @param array $keys
     * @return string
     */
    private function first_non_empty(array $row, array $keys): string {
        foreach ($keys as $key) {
            if (!empty($row[$key])) {
                return clean_param((string)$row[$key], PARAM_TEXT);
            }
        }
        return '';
    }

}
