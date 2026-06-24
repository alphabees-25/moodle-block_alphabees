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

use block_alphabees\local\crypto;
use block_alphabees\local\placement_repository;
use block_alphabees\local\site_registry;

/**
 * Class block_alphabees
 *
 * Defines the Alphabees AI Tutor block.
 */
class block_alphabees extends block_base {

    /** Fallback widget color used when no portal-provided color is stored locally. */
    private const DEFAULT_PRIMARY_COLOR = '#72AECF';

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
     * Called once when a new instance of this block is created.
     *
     * Generates a stable placement_uuid into instance config and notifies the
     * Alphabees backend that a new placement now exists.
     */
    public function instance_create(): bool {
        $this->ensure_placement_uuid();
        $this->emit_placement_event('placement.created');
        return true;
    }

    /**
     * Ensures the in-memory + persisted instance config has a placement_uuid,
     * writing directly to the DB so we don't recursively trigger events.
     */
    private function ensure_placement_uuid(): void {
        global $DB;

        if (empty($this->instance) || empty($this->instance->id)) {
            return;
        }
        $config = is_object($this->config) ? $this->config : new stdClass();
        if (!empty($config->placement_uuid)) {
            return;
        }
        $config->placement_uuid = crypto::uuid_v4();
        $DB->set_field(
            'block_instances',
            'configdata',
            placement_repository::encode_config($config),
            ['id' => $this->instance->id]
        );
        $this->config = $config;
        if (is_object($this->instance)) {
            $this->instance->configdata = placement_repository::encode_config($config);
        }
    }

    /**
     * Called when the per-instance configuration is saved (incl. block move).
     *
     * @param object $data
     * @param bool $nolongerused
     * @return bool
     */
    public function instance_config_save($data, $nolongerused = false) {
        // If admin explicitly took back local control, drop the remote_managed
        // flag so the form unlocks on next render. The override checkbox itself
        // is a UI-only toggle and is never persisted.
        if (is_object($data)) {
            if (!empty($data->override_remote)) {
                $data->remote_managed = 0;
            }
            unset($data->override_remote);
        }

        $result = parent::instance_config_save($data, $nolongerused);
        $this->emit_placement_event('placement.updated');
        return $result;
    }

    /**
     * Called when the block instance is removed from a page.
     *
     * @return bool
     */
    public function instance_delete(): bool {
        $this->emit_placement_event('placement.deleted');
        return parent::instance_delete();
    }

    /**
     * Posts a lifecycle event for this placement to the backend.
     *
     * Uses the retry queue on transient failure so events are eventually
     * delivered even if the backend is briefly unavailable.
     */
    private function emit_placement_event(string $event): void {
        if (site_registry::is_registration_blocked() || !site_registry::is_registered()) {
            return;
        }
        if (site_registry::is_sync_paused()) {
            return;
        }
        if (empty($this->instance) || empty($this->instance->id)) {
            return;
        }

        global $DB, $USER;
        // Re-fetch the instance so we see the just-persisted config.
        $instance = $DB->get_record('block_instances', ['id' => $this->instance->id]);
        if (!$instance) {
            return;
        }

        $payload = placement_repository::build_payload($instance);
        $payload['event'] = $event;
        $payload['actor_user_id'] = !empty($USER->id) ? (int)$USER->id : null;
        $payload['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z');

        // Queue rather than POST inline. Lifecycle hooks (instance_create/
        // config_save/delete) run during regular Moodle page rendering;
        // synchronous cURL + diagnostic output here breaks the redirect
        // flow with a "Continue" intermediate page and triggers
        // "mutated session after it was closed" warnings. Cron picks the
        // task up on the next tick (~1 min) and delivers asynchronously.
        $task = new \block_alphabees\task\post_placement_event();
        $task->set_custom_data([
            'path' => site_registry::path_placements(),
            'payload' => $payload,
        ]);
        \core\task\manager::queue_adhoc_task($task);
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

        // Honor remote-managed visibility flag. When the portal disables a
        // placement (visible=false), the block stays in block_instances so
        // re-enabling is a single param flip — but renders empty content
        // here. Moodle's block_base::is_empty() reads from $content->text,
        // so an empty string makes the theme hide the block frame entirely.
        if (isset($this->config->placement_visible) && !$this->config->placement_visible) {
            $this->content->text = '';
            return $this->content;
        }

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

        if (site_registry::is_registration_blocked()) {
            $reason = site_registry::registration_block_reason() ?? '';
            $message = has_capability('moodle/site:config', context_system::instance())
                ? get_string('status_registration_blocked_chat_admin', 'block_alphabees', $reason)
                : get_string('status_registration_blocked_chat', 'block_alphabees');
            $this->content = new stdClass();
            $this->content->text = \html_writer::div(s($message), 'alert alert-warning mb-0');
            return $this->content;
        }

        // Fetch bot ID securely.
        $botid = isset($this->config->botid) ? clean_param($this->config->botid, PARAM_TEXT) : null;
        if (empty($botid)) {
            $this->content = new stdClass();
            $this->content->text = get_string('nobotselected', 'block_alphabees');
            return $this->content;
        }

        // Never call the Alphabees API while rendering a Moodle page. If the
        // backend is unreachable, get_content() must still return immediately.
        $primarycolor = $this->resolve_primary_color();
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
        $cmid = 0;
        $modtype = '';
        $activityid = 0;
        $activityname = '';
        if (!empty($this->page->cm)) {
            // On activity pages the course module carries both.
            $cmid = (int)($this->page->cm->id ?? 0);
            $modtype = clean_param((string)($this->page->cm->modname ?? ''), PARAM_ALPHANUMEXT);
            $activityid = (int)($this->page->cm->instance ?? 0);
            $activityname = clean_param((string)($this->page->cm->name ?? ''), PARAM_TEXT);
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
        $pagecontext = $this->page->context ?? null;
        $pagetype = clean_param((string)($this->page->pagetype ?? ''), PARAM_TEXT);
        $pageurl = !empty($this->page->url) ? (string)$this->page->url->out(false) : '';

        // Make sure this placement has a stable UUID. Older instances created
        // before the upgrade won't have one yet — generate it lazily here so
        // the backend can correlate sessions to a placement immediately,
        // before the next config save fires the create event.
        $this->ensure_placement_uuid();
        $placementuuid = isset($this->config->placement_uuid)
            ? clean_param($this->config->placement_uuid, PARAM_TEXT)
            : '';

        $extracontext = [
            'courseid'       => $courseid,
            'sectionnum'     => $sectionnum,
            'sectionid'      => $sectionid,
            'contextid'      => $pagecontext ? (int)$pagecontext->id : 0,
            'contextlevel'   => $pagecontext ? (int)$pagecontext->contextlevel : 0,
            'pagetype'       => $pagetype,
            'pageurl'        => $pageurl,
            'cmid'           => $cmid,
            'modtype'        => $modtype,
            'activityid'     => $activityid,
            'activityname'   => $activityname,
            'userid'         => $userid,
            'placementuuid'  => $placementuuid,
            'siteidentifier' => \block_alphabees\local\site_registry::site_identifier(),
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
     * Resolve the widget color from local placement config only.
     *
     * @return string
     */
    private function resolve_primary_color(): string {
        $color = isset($this->config->primary_color_override)
            ? clean_param((string)$this->config->primary_color_override, PARAM_TEXT)
            : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : self::DEFAULT_PRIMARY_COLOR;
    }
}
