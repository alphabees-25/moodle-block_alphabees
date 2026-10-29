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
 * Which progress sources this site has, in numbers only.
 *
 * Lets the portal offer a choice between Moodle course completion and the
 * Completion Progress block per connection. Returns counts, never data about
 * a learner. Competencies are counted only; importing them is not built.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\completion_progress_reader;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_discover_progress_sources.
 */
class discover_progress_sources extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Count what each progress source has on this site.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG, $DB;

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        $completionenabled = !empty($CFG->enablecompletion);
        $sources = [[
            'key' => 'core_completion',
            'available' => $completionenabled,
            'courses_with_completion' => $completionenabled
                ? $DB->count_records_select('course', 'enablecompletion = 1 AND id <> :site', ['site' => SITEID])
                : 0,
        ]];

        $status = completion_progress_reader::status();
        $counts = $status['installed']
            ? completion_progress_reader::counts()
            : ['instances_total' => 0, 'courses_with_instances' => 0, 'instances_selected_mode' => 0];
        $sources[] = [
            'key' => 'completion_progress',
            'component' => completion_progress_reader::COMPONENT,
            'installed' => $status['installed'],
            'version' => $status['version'],
            'adapter_supported' => $status['supported'],
            'reason' => $status['reason'],
        ] + $counts;

        $competency = class_exists('\\core_competency\\api') && \core_competency\api::is_enabled();
        $sources[] = [
            'key' => 'core_competency',
            'available' => $competency,
            'frameworks' => $competency ? $DB->count_records('competency_framework') : 0,
            'courses_with_competencies' => $competency
                ? (int)$DB->count_records_sql('SELECT COUNT(DISTINCT courseid) FROM {competency_coursecomp}')
                : 0,
        ];

        return [
            'moodle_release' => (string)$CFG->release,
            'completion_enabled' => $completionenabled,
            'sources' => $sources,
        ];
    }

    /**
     * Return definition. Each source carries only the keys that apply to it.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $int = static fn(string $desc) => new external_value(PARAM_INT, $desc, VALUE_OPTIONAL);
        $bool = static fn(string $desc) => new external_value(PARAM_BOOL, $desc, VALUE_OPTIONAL);
        return new external_single_structure([
            'moodle_release' => new external_value(PARAM_RAW, 'Moodle release string.'),
            'completion_enabled' => new external_value(PARAM_BOOL, 'Completion tracking enabled site-wide.'),
            'sources' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'core_completion | completion_progress | core_competency'),
                'component' => new external_value(PARAM_COMPONENT, 'Plugin providing the source.', VALUE_OPTIONAL),
                'available' => $bool('Source usable on this site.'),
                'installed' => $bool('Plugin installed.'),
                'version' => new external_value(PARAM_INT, 'Installed plugin version.', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'adapter_supported' => $bool('This plugin can compute progress from it.'),
                'reason' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Why not: not_installed | block_version_too_old | class_api_missing | completion_disabled.',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'instances_total' => $int('Block instances placed in courses.'),
                'courses_with_instances' => $int('Courses with at least one instance.'),
                'instances_selected_mode' => $int('Instances limited to selected activities.'),
                'courses_with_completion' => $int('Courses with completion tracking enabled.'),
                'frameworks' => $int('Competency frameworks.'),
                'courses_with_competencies' => $int('Courses with linked competencies.'),
            ])),
        ]);
    }
}
