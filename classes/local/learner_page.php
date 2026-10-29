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
 * One page of the learners of a course, the same set everywhere.
 *
 * Completions, progress trackers and grades must describe the same people in
 * the same order, or a paged sync stitches pages of different sets together.
 * The set is Moodle's own for completion reports: active enrolments holding
 * moodle/course:isincompletionreports, ordered by user id.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Pages over the tracked learners of a course.
 */
class learner_page {
    /** Capability that puts a user into Moodle's completion reports. */
    private const CAPABILITY = 'moodle/course:isincompletionreports';

    /**
     * The learners on one page.
     *
     * @param \context_course $context
     * @param int $limitfrom Offset, 0-based.
     * @param int $limitnum Page size; 0 means all.
     * @param string $fields User fields to load, prefixed with u.
     * @return \stdClass[] Keyed by user id, ascending.
     */
    public static function users(\context_course $context, int $limitfrom, int $limitnum, string $fields = 'u.id'): array {
        return get_enrolled_users(
            $context,
            self::CAPABILITY,
            0,
            $fields,
            'u.id ASC',
            max(0, $limitfrom),
            max(0, $limitnum),
            true
        );
    }

    /**
     * How many learners there are across all pages.
     *
     * @param \context_course $context
     * @return int
     */
    public static function total(\context_course $context): int {
        return count_enrolled_users($context, self::CAPABILITY, 0, true);
    }
}
