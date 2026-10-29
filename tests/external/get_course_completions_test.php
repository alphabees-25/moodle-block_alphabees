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

namespace block_alphabees\external;

use block_alphabees\tests\learner_course;
use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once(__DIR__ . '/../fixtures/learner_course.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_course_completions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\learner_page::class)]
/**
 * Tests for the paging of block_alphabees_get_course_completions.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_course_completions
 * @covers    \block_alphabees\local\learner_page
 */
final class get_course_completions_test extends \externallib_advanced_testcase {
    /**
     * Call the function the way the web service does.
     *
     * @param int $courseid
     * @param array $page limitfrom, limitnum, or nothing for the old call.
     * @return array
     */
    private function call(int $courseid, array $page = []): array {
        $this->setAdminUser();
        return external_api::clean_returnvalue(
            get_course_completions::execute_returns(),
            get_course_completions::execute($courseid, ...$page)
        );
    }

    public function test_pages_stitch_into_the_full_list(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $id = $f->course->id;

        $full = $this->call($id);
        $this->assertSame($f->student_ids(), array_column($full, 'userid'));
        $this->assertSame($full, $this->call($id, [0, 0]));
        $this->assertSame($full, $this->call($id, [0, 200]));
        $this->assertSame($full, array_merge($this->call($id, [0, 1]), $this->call($id, [1, 1]), $this->call($id, [2, 1])));
        $this->assertSame([$full[2]], $this->call($id, [2, 200]));
        $this->assertSame([], $this->call($id, [3, 200]));
    }

    public function test_teacher_is_not_a_learner(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $this->assertNotContains((int)$f->teacher->id, array_column($this->call($f->course->id), 'userid'));
    }
}
