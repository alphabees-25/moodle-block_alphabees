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
 * Completion Progress bars of one course, per learner, one page at a time.
 *
 * See local\completion_progress_reader for why this is computed here and how
 * closely it follows the block.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\completion_progress_reader;
use block_alphabees\local\learner_page;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_get_progress_trackers.
 */
class get_progress_trackers extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset into the learners, ordered by user id.', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Learners per page; 0 for all.', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The course's progress bars, and each learner's state on them.
     *
     * @param int $courseid
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function execute(int $courseid, int $limitfrom = 0, int $limitnum = 0): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        require_once($CFG->libdir . '/completionlib.php');
        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        $enabled = (new \completion_info($course))->is_enabled();
        $status = completion_progress_reader::status();

        $result = [
            'courseid' => (int)$course->id,
            'completion_enabled' => (bool)$enabled,
            'adapter_supported' => $status['supported'],
            'reason' => $status['reason'],
            'trackers' => [],
            'learners' => [],
            'total_learners' => 0,
        ];
        if (!$enabled || !$status['supported']) {
            return $result;
        }

        $fields = 'u.id, ' . implode(', ', array_map(
            static fn(string $f): string => 'u.' . $f,
            \core_user\fields::get_name_fields()
        ));
        $learners = learner_page::users($context, $params['limitfrom'], $params['limitnum'], $fields);
        $computed = \block_alphabees\local\learner_view::on_course_page(
            $course,
            static fn(): array => completion_progress_reader::compute($course, $learners)
        );

        $result['trackers'] = $computed['trackers'];
        $result['learners'] = $computed['learners'];
        $result['total_learners'] = learner_page::total($context);
        return $result;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
            'completion_enabled' => new external_value(PARAM_BOOL, 'Completion tracking enabled for the course.'),
            'adapter_supported' => new external_value(PARAM_BOOL, 'Completion Progress can be computed on this site.'),
            'reason' => new external_value(
                PARAM_ALPHANUMEXT,
                'Why not, see discover_progress_sources.',
                VALUE_OPTIONAL,
                null,
                NULL_ALLOWED
            ),
            'trackers' => new external_multiple_structure(new external_single_structure([
                'instanceid' => new external_value(PARAM_INT, 'Block instance id.'),
                'title' => new external_value(PARAM_RAW, 'Bar title as shown (progressTitle, else the block name).'),
                'mode' => new external_value(PARAM_ALPHA, 'all | selected'),
                'orderby' => new external_value(PARAM_ALPHA, 'course | time'),
                'group' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'group-<id> or grouping-<id> the bar is limited to; null for everybody.',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'hidden' => new external_value(PARAM_BOOL, 'Hidden on the course page; no learner gets it.'),
                'activities' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id.'),
                    'modname' => new external_value(PARAM_PLUGIN, 'Module type.'),
                    'name' => new external_value(PARAM_RAW, 'Activity name, formatted.'),
                    'section' => new external_value(PARAM_INT, 'Section number.'),
                    'expected' => new external_value(PARAM_INT, 'Expected completion date, 0 if none.'),
                    'url' => new external_value(PARAM_RAW, 'Activity URL.'),
                ]), 'In display order, before any learner filter.'),
            ])),
            'learners' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User id.'),
                'trackers' => new external_multiple_structure(new external_single_structure([
                    'instanceid' => new external_value(PARAM_INT, 'Block instance id.'),
                    'percentage' => new external_value(
                        PARAM_INT,
                        'Percentage as the bar shows it; null when the learner has no visible activity.',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                    'items' => new external_multiple_structure(new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id.'),
                        'state' => new external_value(PARAM_ALPHA, 'incomplete | complete | passed | failed | submitted'),
                        'available' => new external_value(PARAM_BOOL, 'False when visible but locked by restrictions.'),
                    ]), 'The activities this learner sees, in display order; their count is the denominator.'),
                ]), 'Only the bars this learner sees.'),
            ])),
            'total_learners' => new external_value(PARAM_INT, 'Learners across all pages.'),
        ]);
    }
}
