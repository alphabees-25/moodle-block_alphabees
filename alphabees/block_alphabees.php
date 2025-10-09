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
 * Block definition for Alphabees AI Tutor block.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class block_alphabees
 *
 * Defines the Alphabees AI Tutor block.
 */
class block_alphabees extends block_base {

    /**
     * Initialize the block.
     *
     * @return void
     * @throws coding_exception
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_alphabees');
    }

    /**
     * Check if the block has a global configuration.
     *
     * @return bool True if the block has a global configuration.
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Allow instance-level configuration for this block.
     *
     * @return bool True if instance-level configuration is allowed.
     */
    public function instance_allow_config(): bool {
        return true;
    }

    /**
     * Allow multiple instances of this block in the same context.
     *
     * @return bool True if multiple instances are allowed.
     */
    public function instance_allow_multiple(): bool {
        return true;
    }

    /**
     * Define where this block can be added.
     *
     * @return array Applicable formats.
     */
    public function applicable_formats(): array {
        return ['all' => true];
    }

    /**
     * Indicate this block supports mobile app.
     *
     * @return bool True if mobile is supported.
     */
    public function supports_mobile(): bool {
        return true;
    }

    /**
     * Generate the block content.
     *
     * This function prepares the content for the block, including loading
     * the required JavaScript and validating the API key and bot ID.
     *
     * @return stdClass|null The content object or null if already set.
     * @throws coding_exception If any required configuration is missing.
     */
    public function get_content(): ?stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        // First initialize $this->content.
        $this->content = new stdClass();

        // Content title and usage instructions.
        $title = get_string('usagetitle', 'block_alphabees');
        $text  = get_string('usagetext', 'block_alphabees');

        $this->content->text = '
            <details class="ab-accordion">
                <summary><strong>' . $title . '</strong></summary>
                <div class="ab-accordion-body">' . $text . '</div>
            </details>
        ';

        // Ensure the user is logged in.
        require_login();

        // Fetch API key securely.
        $apikey = clean_param(get_config('block_alphabees', 'apikey'), PARAM_TEXT);
        if (empty($apikey)) {
            $this->content = new stdClass();
            $this->content->text = get_string('apikeymissing', 'block_alphabees');
            return $this->content;
        }

        // Fetch bot ID securely.
        $botid = isset($this->config->botid) ? clean_param($this->config->botid, PARAM_TEXT) : null;
        if (empty($botid)) {
            $this->content = new stdClass();
            $this->content->text = get_string('nobotselected', 'block_alphabees');
            return $this->content;
        }

        $primarycolor = $this->fetch_primary_color($apikey, $botid);
        // Build extra context for downstream processing.
        global $USER, $COURSE, $CFG, $DB;

        // Course ID best-effort: from page context or global $COURSE.
        $courseid = 0;
        if ($this->page->context instanceof context_course) {
            $courseid = (int)($this->page->course->id ?? 0);
        } else if (!empty($COURSE->id)) {
            $courseid = (int)$COURSE->id;
        }

        // Determine section number and section id.
        $sectionnum = 0;
        $sectionid  = 0;
        if (!empty($this->page->cm)) {
            // On activity pages the course module carries both.
            $sectionnum = (int)($this->page->cm->sectionnum ?? 0);
            $sectionid  = (int)($this->page->cm->section ?? 0);
        } else {
            // On course view pages, section number may be in the URL.
            $sectionnum = optional_param('section', 0, PARAM_INT);
            if ($sectionnum && $courseid) {
                $sectionid = (int)($DB->get_field('course_sections', 'id', [
                    'course'  => $courseid,
                    'section' => $sectionnum,
                ]) ?: 0);
            }
        }

        $userid = (int)$USER->id;
        $extracontext = [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'sectionid'  => $sectionid,
            'userid'     => $userid,
        ];

        // Use Moodle's AMD module to load the chat widget.
        $this->page->requires->js_call_amd(
            'block_alphabees/chat_widget',
            'init',
            [
                htmlspecialchars($apikey, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($botid, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($primarycolor, ENT_QUOTES, 'UTF-8'),
                $extracontext,
            ]
        );

        // Create and return the content object.
        return $this->content;
    }


    /**
     * Fetch the primary color for the selected bot.
     *
     * @param string $apikey The API key.
     * @param string $botid The bot ID.
     * @return string The primary color, or a fallback color if unavailable.
     */
    private function fetch_primary_color(string $apikey, string $botid): string {
        $apikey = clean_param($apikey, PARAM_TEXT);
        $botid = clean_param($botid, PARAM_TEXT);

        $url = 'https://api.alphabees.de/al/tutors/tutor/moodle-list/' . urlencode($apikey);

        $curl = new curl(['timeout' => 10]);
        $response = $curl->get($url);

        if (!$response) {
            debugging('[block_alphabees] Failed to fetch primary color. Using fallback.', DEBUG_DEVELOPER);
            return '#72AECF';
        }

        $responsedata = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            debugging('[block_alphabees] JSON decode error while fetching primary color.', DEBUG_DEVELOPER);
            return '#72AECF';
        }

        foreach ($responsedata['data'] ?? [] as $bot) {
            if (isset($bot['id'], $bot['primaryColor']) && $bot['id'] === $botid) {
                return strtolower(clean_param($bot['primaryColor'], PARAM_TEXT));
            }
        }

        debugging('[block_alphabees] Primary color not found. Using fallback.', DEBUG_DEVELOPER);
        return '#72AECF';
    }
}
