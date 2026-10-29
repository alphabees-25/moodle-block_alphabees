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
 * Progress as the Completion Progress block (block_completion_progress) shows it.
 *
 * Some sites measure progress with that block rather than with Moodle's course
 * completion. Its own web service computes for $USER only — the service user
 * here — its table is a cache, and its configuration is serialised into
 * block_instances.configdata, so the numbers cannot be rebuilt outside Moodle
 * without drifting from what learners see. This computes with the block's own
 * class instead, unchanged since block version 2022020100, and never writes:
 * compute_overview_percentages() is not called.
 *
 * Whether a learner sees an instance at all follows the block: the group or
 * grouping it is restricted to (unless the learner may access all groups),
 * block/completion_progress:showbar in the block's context, and whether the
 * block is hidden on the course page.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Reads Completion Progress instances and computes them per learner.
 */
class completion_progress_reader {
    /** Frankenstyle name of the block. */
    public const COMPONENT = 'block_completion_progress';

    /** First block version whose class has the API used here. */
    public const MIN_VERSION = 2022020100;

    /** The block's class. */
    private const CLASSNAME = '\\block_completion_progress\\completion_progress';

    /** Methods of that class this relies on. */
    private const METHODS = [
        'for_user', 'for_block_instance', 'has_visible_activities', 'get_visible_activities',
        'get_activities', 'get_completions', 'get_percentage',
    ];

    /**
     * Whether progress can be computed here, and if not, why.
     *
     * @return array{installed: bool, version: int|null, supported: bool, reason: string|null}
     */
    public static function status(): array {
        global $CFG;
        $plugin = \core_plugin_manager::instance()->get_plugin_info(self::COMPONENT);
        $version = ($plugin && !empty($plugin->versiondb)) ? (int)$plugin->versiondb : null;

        $reason = null;
        if ($version === null) {
            $reason = 'not_installed';
        } else if ($version < self::MIN_VERSION) {
            $reason = 'block_version_too_old';
        } else if (!self::class_api_present()) {
            $reason = 'class_api_missing';
        } else if (empty($CFG->enablecompletion)) {
            $reason = 'completion_disabled';
        }
        return [
            'installed' => $version !== null,
            'version' => $version,
            'supported' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * The block instances placed in a course, in id order.
     *
     * @param \context_course $context
     * @return \stdClass[] block_instances records keyed by id.
     */
    public static function instances(\context_course $context): array {
        global $DB;
        return $DB->get_records(
            'block_instances',
            ['blockname' => 'completion_progress', 'parentcontextid' => $context->id],
            'id ASC'
        );
    }

    /**
     * Describe each instance and compute it for every learner given.
     *
     * Call inside learner_view::on_course_page(), so names come out as the
     * learner reads them.
     *
     * @param \stdClass $course
     * @param \stdClass[] $learners User records with at least the name fields.
     * @return array{trackers: array, learners: array}
     */
    public static function compute(\stdClass $course, array $learners): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        $context = \context_course::instance($course->id);
        $classname = self::CLASSNAME;

        $trackers = [];
        $progresses = [];
        foreach (self::instances($context) as $instance) {
            // One object per instance, specialised for each learner in turn:
            // for_user() reloads that learner's completions and recomputes the
            // visible activities, the way the block's overview does it.
            $progress = (new $classname($course))->for_block_instance($instance);
            $config = $progress->get_block_config();
            $hidden = self::hidden_on_course_page($instance, $context);

            $activities = [];
            foreach ($progress->get_activities() as $activity) {
                $activities[] = [
                    'cmid' => (int)$activity->id,
                    'modname' => (string)$activity->type,
                    'name' => (string)$activity->name,
                    'section' => (int)$activity->section,
                    'expected' => (int)$activity->expected,
                    'url' => (string)$activity->url,
                ];
            }
            $title = trim((string)($config->progressTitle ?? ''));
            $group = (string)($config->group ?? '0');
            $trackers[] = [
                'instanceid' => (int)$instance->id,
                'title' => $title !== ''
                    ? format_string($title, true, ['context' => $context, 'escape' => false])
                    : get_string('pluginname', self::COMPONENT),
                'mode' => ($config->activitiesincluded ?? '') === 'selectedactivities' ? 'selected' : 'all',
                'orderby' => ($config->orderby ?? 'orderbycourse') === 'orderbytime' ? 'time' : 'course',
                'group' => ($group === '' || $group === '0') ? null : $group,
                'hidden' => $hidden,
                'activities' => $activities,
            ];
            if (!$hidden) {
                $progresses[(int)$instance->id] = [$progress, $group, \context_block::instance($instance->id)];
            }
        }

        $results = [];
        foreach ($learners as $learner) {
            $userid = (int)$learner->id;
            $mine = [];
            foreach ($progresses as $instanceid => [$progress, $group, $blockcontext]) {
                if (!self::learner_sees($course, $context, $blockcontext, $group, $userid)) {
                    continue;
                }
                $progress->for_user($learner);
                $items = [];
                if ($progress->has_visible_activities()) {
                    $completions = $progress->get_completions();
                    foreach ($progress->get_visible_activities() as $activity) {
                        $items[] = [
                            'cmid' => (int)$activity->id,
                            'state' => self::state($completions[$activity->id] ?? COMPLETION_INCOMPLETE),
                            'available' => !empty($activity->available),
                        ];
                    }
                }
                $mine[] = [
                    'instanceid' => $instanceid,
                    'percentage' => $items ? $progress->get_percentage() : null,
                    'items' => $items,
                ];
            }
            $results[] = ['userid' => $userid, 'trackers' => $mine];
        }

        return ['trackers' => $trackers, 'learners' => $results];
    }

    /**
     * How many instances there are, and where, without any learner data.
     *
     * @return array{instances_total: int, courses_with_instances: int, instances_selected_mode: int}
     */
    public static function counts(): array {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT bi.id, bi.configdata, ctx.instanceid AS courseid
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid AND ctx.contextlevel = :level
              WHERE bi.blockname = :name",
            ['level' => CONTEXT_COURSE, 'name' => 'completion_progress']
        );
        $courses = [];
        $selected = 0;
        foreach ($records as $record) {
            $courses[(int)$record->courseid] = true;
            $config = (array)unserialize(base64_decode((string)$record->configdata), ['allowed_classes' => ['stdClass']]);
            if (($config['activitiesincluded'] ?? '') === 'selectedactivities') {
                $selected++;
            }
        }
        return [
            'instances_total' => count($records),
            'courses_with_instances' => count($courses),
            'instances_selected_mode' => $selected,
        ];
    }

    /**
     * Whether the block would show this instance to this learner.
     *
     * Mirrors the block's own checks, but for the learner rather than $USER.
     *
     * @param \stdClass $course
     * @param \context_course $context
     * @param \context_block $blockcontext
     * @param string $group '0', 'group-<id>' or 'grouping-<id>'.
     * @param int $userid
     * @return bool
     */
    private static function learner_sees(
        \stdClass $course,
        \context_course $context,
        \context_block $blockcontext,
        string $group,
        int $userid
    ): bool {
        if (!has_capability('block/completion_progress:showbar', $blockcontext, $userid)) {
            return false;
        }
        if ($group === '' || $group === '0' || has_capability('moodle/site:accessallgroups', $context, $userid)) {
            return true;
        }
        if (substr($group, 0, 6) === 'group-' && ($groupid = (int)substr($group, 6))) {
            return groups_is_member($groupid, $userid);
        }
        if (substr($group, 0, 9) === 'grouping-' && ($groupingid = (int)substr($group, 9))) {
            return array_key_exists($groupingid, groups_get_user_groups($course->id, $userid));
        }
        return false;
    }

    /**
     * Whether the instance is hidden on the course page, where learners meet it.
     *
     * @param \stdClass $instance
     * @param \context_course $context
     * @return bool
     */
    private static function hidden_on_course_page(\stdClass $instance, \context_course $context): bool {
        global $DB;
        return $DB->record_exists_select(
            'block_positions',
            'blockinstanceid = :id AND contextid = :ctx AND visible = 0 AND ' . $DB->sql_like('pagetype', ':pt'),
            ['id' => $instance->id, 'ctx' => $context->id, 'pt' => 'course-view-%']
        );
    }

    /**
     * Name a completion state the way the block's cells distinguish it.
     *
     * COMPLETION_COMPLETE_FAIL_HIDDEN (4) shows as not complete in the block.
     *
     * @param int|string $state
     * @return string
     */
    private static function state($state): string {
        if ($state === 'submitted') {
            return 'submitted';
        }
        switch ((int)$state) {
            case COMPLETION_COMPLETE:
                return 'complete';
            case COMPLETION_COMPLETE_PASS:
                return 'passed';
            case COMPLETION_COMPLETE_FAIL:
                return 'failed';
            default:
                return 'incomplete';
        }
    }

    /**
     * Whether the block's class offers everything used here.
     *
     * @return bool
     */
    private static function class_api_present(): bool {
        if (!class_exists(self::CLASSNAME)) {
            return false;
        }
        foreach (self::METHODS as $method) {
            if (!method_exists(self::CLASSNAME, $method)) {
                return false;
            }
        }
        return true;
    }
}
