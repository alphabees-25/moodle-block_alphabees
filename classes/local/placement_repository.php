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
 * Placement queries and lifecycle event helpers.
 *
 * The block's instance configuration is the source of truth for placement
 * state (botid, placement_uuid, remote_managed). This class queries
 * `block_instances` and shapes payloads for backend communication.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Placement repository.
 */
class placement_repository {

    /**
     * Return the placement_uuid stored in instance config, generating one if missing.
     *
     * @param \stdClass $config Decoded instance config.
     * @return string
     */
    public static function ensure_placement_uuid(\stdClass $config): string {
        if (!empty($config->placement_uuid)) {
            return (string)$config->placement_uuid;
        }
        return crypto::uuid_v4();
    }

    /**
     * Build a payload describing one block instance for the backend.
     *
     * Pulls course / context metadata so the portal can render a useful list.
     *
     * @param \stdClass $instance Row from {block_instances}.
     * @return array
     */
    public static function build_payload(\stdClass $instance): array {
        global $DB;

        $config = self::decode_config($instance->configdata ?? '');
        $courseid = 0;
        $courseshortname = null;
        $coursefullname = null;
        $categoryid = null;
        $targettype = 'unknown';
        $cmid = null;
        $modtype = null;
        $activityname = null;
        $contextlevel = null;

        try {
            $context = \context::instance_by_id((int)$instance->parentcontextid, IGNORE_MISSING);
            if ($context) {
                $contextlevel = (int)$context->contextlevel;
            }
            if ($context instanceof \context_course) {
                $targettype = 'course';
                $course = $DB->get_record('course', ['id' => $context->instanceid],
                    'id,shortname,fullname,category', IGNORE_MISSING);
                if ($course) {
                    $courseid = (int)$course->id;
                    $courseshortname = (string)$course->shortname;
                    $coursefullname = (string)$course->fullname;
                    $categoryid = (int)$course->category;
                }
            } else if ($context instanceof \context_module) {
                $targettype = 'module';
                $sql = "SELECT cm.id, cm.course, cm.instance, m.name AS modtype
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module
                         WHERE cm.id = ?";
                $cm = $DB->get_record_sql($sql, [$context->instanceid], IGNORE_MISSING);
                if ($cm) {
                    $cmid = (int)$cm->id;
                    $modtype = (string)$cm->modtype;
                    $course = $DB->get_record('course', ['id' => $cm->course], 'id,shortname,fullname,category', IGNORE_MISSING);
                    if ($course) {
                        $courseid = (int)$course->id;
                        $courseshortname = (string)$course->shortname;
                        $coursefullname = (string)$course->fullname;
                        $categoryid = (int)$course->category;
                    }
                    if ($modtype !== '') {
                        $activityname = $DB->get_field($modtype, 'name', ['id' => $cm->instance], IGNORE_MISSING);
                        $activityname = $activityname !== false ? (string)$activityname : null;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Context lookup is best-effort; missing context shouldn't block reporting.
            unset($e);
        }

        // The visible field reflects the portal-managed visibility flag we store in
        // configdata (placement_visible). Default: true. The legacy
        // block_instances.visible column is independent and unused here —
        // the portal cares about whether the chat widget renders.
        $placementvisible = !isset($config->placement_visible) ? true : (bool)$config->placement_visible;
        $primarycolor = isset($config->primary_color_override) && $config->primary_color_override !== ''
            ? (string)$config->primary_color_override : null;

        // Snake_case keys to match the backend Pydantic models.
        return [
            'placement_uuid' => isset($config->placement_uuid) ? (string)$config->placement_uuid : null,
            'instance_id' => (int)$instance->id,
            'course_id' => $courseid,
            'course_shortname' => $courseshortname,
            'course_fullname' => $coursefullname,
            'category_id' => $categoryid,
            'target_type' => $targettype,
            'placement_scope' => $targettype,
            'cmid' => $cmid,
            'modtype' => $modtype,
            'activity_name' => $activityname,
            'page_context_level' => $contextlevel,
            'page_pattern' => isset($instance->pagetypepattern) ? (string)$instance->pagetypepattern : null,
            'sub_page_pattern' => isset($instance->subpagepattern) ? (string)$instance->subpagepattern : null,
            'block_region' => isset($instance->defaultregion) ? (string)$instance->defaultregion : null,
            'block_weight' => isset($instance->defaultweight) ? (int)$instance->defaultweight : null,
            'visible' => $placementvisible,
            'bot_id' => isset($config->botid) ? (string)$config->botid : null,
            'primary_color' => $primarycolor,
            'remote_managed' => !empty($config->remote_managed),
            'created_at' => isset($instance->timecreated) ? self::iso8601((int)$instance->timecreated) : null,
            'updated_at' => isset($instance->timemodified) ? self::iso8601((int)$instance->timemodified) : null,
        ];
    }

    /**
     * Returns all alphabees block instances on this site.
     *
     * @return \stdClass[]
     */
    public static function all_instances(): array {
        global $DB;
        return $DB->get_records('block_instances', ['blockname' => 'alphabees']);
    }

    /**
     * Look up a block instance by its placement_uuid stored in configdata.
     *
     * @param string $uuid
     * @return \stdClass|null
     */
    public static function find_by_uuid(string $uuid): ?\stdClass {
        $instances = self::all_instances();
        foreach ($instances as $instance) {
            $config = self::decode_config($instance->configdata ?? '');
            if (!empty($config->placement_uuid) && (string)$config->placement_uuid === $uuid) {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Decode a base64-encoded serialized config blob into an stdClass.
     *
     * @param string $configdata
     * @return \stdClass
     */
    public static function decode_config(string $configdata): \stdClass {
        if ($configdata === '') {
            return new \stdClass();
        }
        $decoded = base64_decode($configdata, true);
        if ($decoded === false) {
            return new \stdClass();
        }
        $unserialized = @unserialize($decoded);
        return ($unserialized instanceof \stdClass) ? $unserialized : new \stdClass();
    }

    /**
     * Encode an stdClass back into the base64+serialize form Moodle stores.
     *
     * @param \stdClass $config
     * @return string
     */
    public static function encode_config(\stdClass $config): string {
        return base64_encode(serialize($config));
    }

    /**
     * Convert unix timestamp to ISO 8601 UTC string.
     *
     * @param int $ts
     * @return string
     */
    private static function iso8601(int $ts): string {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
