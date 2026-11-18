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
 * Mobile app output handlers for the Alphabees block.
 *
 * Provides the handlers used by the Moodle App to render a small template and
 * inject the JavaScript that initialises the Alphabees chat widget when the
 * block is present or launched from the course menu.
 *
 * @package   block_alphabees
 * @category  output
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\output;

/**
 * Output handlers for the Moodle App integration.
 */
class mobile {
    /**
     * Returns a template (data marker) and inline JS for the Moodle App.
     *
     * The handler resolves the bot ID from either the specific block instance
     * (when rendered via CoreBlockDelegate) or from the first Alphabees block
     * in the course (when launched from the course menu).
     *
     * @param array $args Arguments provided by the app (e.g., courseid, blockid/instanceid, section).
     * @return array Content payload with 'templates' and 'javascript' keys.
     */
    public static function mobile_ws_marker(array $args): array {
        global $OUTPUT, $CFG, $DB, $USER;

        $args = (object)$args;

        // App usually passes one of these when rendering the block.
        $blockid  = isset($args->blockid) ? (int)$args->blockid
                  : (isset($args->instanceid) ? (int)$args->instanceid : 0);
        $courseid = isset($args->courseid) ? (int)$args->courseid : 0;

        $apikey = get_config('block_alphabees', 'apikey') ?: '';

        // 1) Try to get placement config from the specific block instance (most reliable).
        $placementconfig = new \stdClass();
        if ($blockid) {
            $placementconfig = self::config_for_block($blockid);
        }

        // 2) Fallback: first Alphabees block placed in this course.
        if (empty($placementconfig->botid) && $courseid) {
            $placementconfig = self::config_for_course($courseid);
        }
        $botid = !empty($placementconfig->botid) ? (string)$placementconfig->botid : '';
        $placementuuid = !empty($placementconfig->placement_uuid) ? (string)$placementconfig->placement_uuid : '';
        $primarycolor = self::resolve_primary_color($placementconfig);

        // Determine section number from args when available; default to 0.
        $sectionnum = isset($args->section) ? (int)$args->section : 0;
        $sectionid = 0;
        $cmid = isset($args->cmid) ? (int)$args->cmid
            : (isset($args->coursemoduleid) ? (int)$args->coursemoduleid : 0);
        $modtype = isset($args->modtype) ? clean_param((string)$args->modtype, PARAM_ALPHANUMEXT)
            : (isset($args->modname) ? clean_param((string)$args->modname, PARAM_ALPHANUMEXT) : '');
        $activityid = isset($args->activityid) ? (int)$args->activityid : 0;
        $activityname = isset($args->activityname) ? clean_param((string)$args->activityname, PARAM_TEXT) : '';
        $pagetype = isset($args->pagetype) ? clean_param((string)$args->pagetype, PARAM_TEXT)
            : (isset($args->pageType) ? clean_param((string)$args->pageType, PARAM_TEXT) : '');
        $pageurl = isset($args->pageurl) ? clean_param((string)$args->pageurl, PARAM_URL)
            : (isset($args->pageUrl) ? clean_param((string)$args->pageUrl, PARAM_URL) : '');
        $contextid = 0;
        $contextlevel = 0;

        // What the app names is checked for this user; a module they may not
        // see is reported as unknown, without id or name (local\page_context).
        $activity = \block_alphabees\local\page_context::of_cmid($cmid, (int)$USER->id);
        $cmid = $activity['cmid'];
        if ($cmid) {
            $modtype = $activity['modtype'];
            $activityid = $activity['activityid'];
            $activityname = $activity['activityname'];
            $sectionid = $activity['sectionid'];
            $sectionnum = $sectionnum ?: $activity['sectionnum'];
            $ctx = \context_module::instance($cmid, IGNORE_MISSING);
            if ($ctx) {
                $courseid = $courseid ?: (int)$ctx->get_course_context()->instanceid;
                $contextid = (int)$ctx->id;
                $contextlevel = (int)$ctx->contextlevel;
            }
        } else {
            $modtype = '';
            $activityid = 0;
            $activityname = '';
        }

        // Attempt to resolve the DB course_sections.id when possible.
        if (!$sectionid && $courseid && $sectionnum) {
            $sectionid = (int)($DB->get_field('course_sections', 'id', [
                'course'  => $courseid,
                'section' => $sectionnum,
            ]) ?: 0);
        }
        if (!$contextid && $courseid) {
            $ctx = \context_course::instance($courseid, IGNORE_MISSING, false);
            if ($ctx) {
                $contextid = (int)$ctx->id;
                $contextlevel = (int)$ctx->contextlevel;
            }
        }

        // Real user ID, as requested.
        $userid = (int)$USER->id;

        // Signed proof of who opened the app view (see local\start_token). The
        // app has no web session, so there is no sessionHash to bind to; the
        // handler runs again on each in-app navigation, minting a fresh one.
        $starttoken = \block_alphabees\local\start_token::issue($userid, $courseid, $cmid, $placementuuid);

        // Same course names as the web block sends.
        $courselabel = \block_alphabees\local\course_label::of($courseid);

        // Opt-in (site setting, default off): first name only, for display
        // and personal address in the widget. Empty string when disabled.
        $userfirstname = get_config('block_alphabees', 'send_userprofile')
            ? clean_param((string)$USER->firstname, PARAM_TEXT)
            : '';

        // Help label and info text. Admins can override the built-in language
        // strings site-wide via plugin settings; empty settings fall back to
        // the localized defaults (resolved in the requesting user's language).
        $helplabel = \block_alphabees\local\custom_texts::info_text('custom_mobilehelplabel')
            ?? get_string('help', 'block_alphabees');
        $customhelptext = \block_alphabees\local\custom_texts::info_text('custom_mobilehelptext');
        $helptext = $customhelptext !== null
            ? format_text($customhelptext, FORMAT_HTML, ['context' => \context_system::instance()])
            : get_string('helptitle', 'block_alphabees');

        // Same name resolution as the web block: instance, then site, then plugin.
        $instancetitle = !empty($placementconfig->blocktitle)
            ? trim((string)$placementconfig->blocktitle) : '';
        $blocktitle = $instancetitle !== ''
            ? format_string($instancetitle, true, ['context' => \context_system::instance()])
            : \block_alphabees\local\custom_texts::block_title();

        // Render the marker with whatever we resolved.
        $html = $OUTPUT->render_from_template('block_alphabees/mobile_view', [
            'pluginname' => $blocktitle,
            'helplabel'  => $helplabel,
            'helptext'   => $helptext,
            'courseid'   => $courseid,
            'coursename' => $courselabel['name'],
            'courseshortname' => $courselabel['shortname'],
            'botid'      => (string)$botid,
            'apikey'     => (string)$apikey,
            'userid'     => $userid,
            'userfirstname' => $userfirstname,
            'sectionnum' => $sectionnum,
            'sectionid'  => $sectionid,
            'contextid'  => $contextid,
            'contextlevel' => $contextlevel,
            'pagetype'   => $pagetype,
            'pageurl'    => $pageurl,
            'cmid'       => $cmid,
            'modtype'    => $modtype,
            'activityid' => $activityid,
            'activityname' => $activityname,
            'activitystate' => $activity['activitystate'],
            'activityreason' => $activity['activityreason'],
            'contextversion' => $activity['contextversion'],
            'placementuuid'  => $placementuuid,
            'siteidentifier' => \block_alphabees\local\site_registry::site_identifier(),
            'primarycolor'   => $primarycolor,
            'starttoken'     => $starttoken['token'] ?? '',
        ]);

        // Inline the app JS (avoids MIME type / 404 issues from the dev server).
        $appjs = @file_get_contents($CFG->dirroot . '/blocks/alphabees/assets/js/chat-mobile-app.js') ?: '';

        return [
            'templates'  => [['id' => 'main', 'html' => $html]],
            'javascript' => $appjs,
        ];
    }

    /**
     * Resolve the configured placement data for a specific block instance.
     *
     * @param int $blockid Block instance ID.
     * @return \stdClass Placement config.
     */
    private static function config_for_block(int $blockid): \stdClass {
        $instance = \block_instance_by_id($blockid);
        if (!$instance) {
            return new \stdClass();
        }

        // Newer Moodle gives ->config as stdClass; be defensive anyway.
        if (!empty($instance->config) && is_object($instance->config)) {
            return $instance->config;
        }

        // Extremely defensive fallback if config were serialized (unlikely here).
        if (!empty($instance->config) && is_string($instance->config)) {
            return \block_alphabees\local\placement_repository::decode_config(base64_encode($instance->config));
        }
        return new \stdClass();
    }

    /**
     * Find the first Alphabees block in a course and return its config.
     *
     * @param int $courseid Course ID.
     * @return \stdClass Placement config.
     */
    private static function config_for_course(int $courseid): \stdClass {
        global $DB;
        if (!$courseid) {
            return new \stdClass();
        }

        $ctx = \context_course::instance($courseid, IGNORE_MISSING, false);
        if (!$ctx) {
            return new \stdClass();
        }

        // Pick the first block instance by ID (stable and simple).
        $recs = $DB->get_records(
            'block_instances',
            ['blockname' => 'alphabees', 'parentcontextid' => $ctx->id],
            'id ASC',
            'id, configdata',
            0,
            1
        );
        if (!$recs) {
            return new \stdClass();
        }
        $rec = reset($recs);

        return \block_alphabees\local\placement_repository::decode_config((string)($rec->configdata ?? ''));
    }

    /**
     * Resolve the widget color from local placement config only.
     *
     * @param \stdClass $config Placement config.
     * @return string Hex color.
     */
    private static function resolve_primary_color(\stdClass $config): string {
        $color = isset($config->primary_color_override)
            ? clean_param((string)$config->primary_color_override, PARAM_TEXT)
            : '';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#72aecf';
    }
}
