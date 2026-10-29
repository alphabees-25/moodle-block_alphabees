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
 * Course custom fields with the visibility each one has.
 *
 * core_course_get_courses_by_field hands the service user every custom field,
 * including those only teachers may see, and does not say which is which. The
 * backend fills field values into text learners read, so it needs to know.
 *
 * Values are exported on the course page as the neutral learner of
 * local\learner_view, so filters in a value resolve as for a learner and
 * never with the service user's data.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_get_course_fields.
 */
class get_course_fields extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
        ]);
    }

    /**
     * Every custom field of the course, with its visibility and value.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        $course = get_course($params['courseid']);
        $handler = \core_course\customfield\course_handler::create();
        $instancedata = $handler->get_instance_data($course->id, true);

        $fields = \block_alphabees\local\learner_view::on_course_page($course, static function () use ($instancedata): array {
            $fields = [];
            foreach ($instancedata as $data) {
                $field = $data->get_field();
                $value = $data->export_value();
                $fields[] = [
                    'shortname' => (string)$field->get('shortname'),
                    'name' => $field->get_formatted_name(false),
                    'type' => (string)$field->get('type'),
                    'visibility' => (int)$field->get_configdata_property('visibility'),
                    'value' => $value === null ? null : (string)$value,
                ];
            }
            return $fields;
        });

        return ['courseid' => (int)$course->id, 'fields' => $fields];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'shortname' => new external_value(PARAM_RAW, 'Field short name.'),
                    'name' => new external_value(PARAM_RAW, 'Field name, formatted.'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Field type: text, textarea, select, date, checkbox ...'),
                    'visibility' => new external_value(
                        PARAM_INT,
                        'Who sees the field in the course listing: 0 nobody, 1 teachers, 2 everybody.'
                    ),
                    'value' => new external_value(
                        PARAM_RAW,
                        'Value as exported for display (export_value()); null when empty.',
                        VALUE_OPTIONAL,
                        null,
                        NULL_ALLOWED
                    ),
                ])
            ),
        ]);
    }
}
