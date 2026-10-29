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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_course_grades::class)]
/**
 * Tests for block_alphabees_get_course_grades.
 *
 * The reference is gradereport_user_get_grade_items called by the learner:
 * every value must match, item names apart (formatted here, raw in core).
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_course_grades
 */
final class get_course_grades_test extends \externallib_advanced_testcase {
    /**
     * Call the function the way the web service does.
     *
     * @param int $courseid
     * @param int $from
     * @param int $num
     * @return array
     */
    private function call(int $courseid, int $from = 0, int $num = 0): array {
        $this->setAdminUser();
        return external_api::clean_returnvalue(
            get_course_grades::execute_returns(),
            get_course_grades::execute($courseid, $from, $num)
        );
    }

    /**
     * Grade items without their names.
     *
     * @param array $items
     * @return array
     */
    private function without_names(array $items): array {
        return array_map(function (array $item): array {
            unset($item['itemname']);
            return $item;
        }, $items);
    }

    public function test_same_values_as_the_learners_own_report(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $result = $this->call($f->course->id);
        $this->assertSame(3, $result['total_learners']);
        foreach ($result['usergrades'] as $usergrade) {
            $this->setUser($usergrade['userid']);
            $core = \gradereport_user\external\user::get_grade_items($f->course->id, $usergrade['userid']);
            $core = external_api::clean_returnvalue(\gradereport_user\external\user::get_grade_items_returns(), $core);
            $this->assertEquals(
                $this->without_names($core['usergrades'][0]['gradeitems']),
                $this->without_names($usergrade['gradeitems']),
                'user ' . $usergrade['userid']
            );
        }
    }

    public function test_hidden_item_and_hidden_until_grade_stay_hidden(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $quiz = \grade_item::fetch(['courseid' => $f->course->id, 'itemmodule' => 'quiz',
            'iteminstance' => $f->modules['quiz']->id]);
        $quiz->set_hidden(1);
        $essay = \grade_item::fetch(['courseid' => $f->course->id, 'itemmodule' => 'assign',
            'iteminstance' => $f->modules['assign']->id]);
        \grade_grade::fetch(['itemid' => $essay->id, 'userid' => $f->students['s2']->id])->set_hidden(time() + DAYSECS);
        grade_regrade_final_grades($f->course->id);

        $byuser = array_column($this->call($f->course->id)['usergrades'], null, 'userid');
        $essayof = function (int $userid) use ($byuser): array {
            $items = array_filter($byuser[$userid]['gradeitems'], fn($i) => ($i['itemmodule'] ?? '') === 'assign');
            return reset($items);
        };
        foreach ($byuser as $usergrade) {
            $this->assertEmpty(array_filter($usergrade['gradeitems'], fn($i) => ($i['itemmodule'] ?? '') === 'quiz'));
        }
        $this->assertEquals(80.0, $essayof($f->students['s1']->id)['graderaw']);
        $this->assertNull($essayof($f->students['s2']->id)['graderaw'] ?? null);
    }

    public function test_permanently_hidden_grade_stays_hidden(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $essay = \grade_item::fetch(['courseid' => $f->course->id, 'itemmodule' => 'assign',
            'iteminstance' => $f->modules['assign']->id]);
        \grade_grade::fetch(['itemid' => $essay->id, 'userid' => $f->students['s1']->id])->set_hidden(1);
        grade_regrade_final_grades($f->course->id);

        $byuser = array_column($this->call($f->course->id)['usergrades'], null, 'userid');
        $items = array_filter($byuser[$f->students['s1']->id]['gradeitems'], fn($i) => ($i['itemmodule'] ?? '') === 'assign');
        $this->assertNull(reset($items)['graderaw'] ?? null);
        $this->setUser($f->students['s1']);
        $core = \gradereport_user\external\user::get_grade_items($f->course->id, $f->students['s1']->id);
        $core = array_filter($core['usergrades'][0]['gradeitems'], fn($i) => ($i['itemmodule'] ?? '') === 'assign');
        $this->assertSame(reset($core)['graderaw'] ?? null, reset($items)['graderaw'] ?? null);
    }

    public function test_paging(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $ids = $f->student_ids();
        foreach ([0, 1, 2] as $from) {
            $page = $this->call($f->course->id, $from, 1);
            $this->assertSame([$ids[$from]], array_column($page['usergrades'], 'userid'));
            $this->assertSame(3, $page['total_learners']);
        }
        $this->assertSame([], $this->call($f->course->id, 3, 1)['usergrades']);
        $this->assertSame($ids, array_column($this->call($f->course->id, 0, 200)['usergrades'], 'userid'));
    }
}
