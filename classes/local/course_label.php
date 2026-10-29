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
 * The course's name as the widget hands it on, the same on web and app.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Full and short name of a course, as the person on the page reads them.
 */
class course_label {
    /**
     * Names of one course; empty strings when there is none.
     *
     * Formatted with the course's filters (multilang, FilterCodes …) and not
     * HTML-escaped: the result travels as JSON to the widget, which renders
     * it as text.
     *
     * @param int $courseid
     * @param \stdClass|null $course The course record if the caller has it.
     * @return array{name: string, shortname: string}
     */
    public static function of(int $courseid, ?\stdClass $course = null): array {
        global $DB;
        if ($courseid <= 0) {
            return ['name' => '', 'shortname' => ''];
        }
        if (!$course || (int)($course->id ?? 0) !== $courseid || !isset($course->fullname, $course->shortname)) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', IGNORE_MISSING);
        }
        $context = $course ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        if (!$course || !$context) {
            return ['name' => '', 'shortname' => ''];
        }
        $options = ['context' => $context, 'escape' => false];
        return [
            'name' => trim(strip_tags(format_string((string)$course->fullname, true, $options))),
            'shortname' => trim(strip_tags(format_string((string)$course->shortname, true, $options))),
        ];
    }
}
