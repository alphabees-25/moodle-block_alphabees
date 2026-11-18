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

namespace block_alphabees\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\grade_writer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\inbound_dispatcher::class)]
/**
 * Tests for writing tutor results into the gradebook.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\grade_writer
 * @covers    \block_alphabees\local\inbound_dispatcher
 */
final class grade_writer_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass[] */
    private array $learners;

    /** @var \stdClass */
    private \stdClass $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->learners = [
            $generator->create_and_enrol($this->course, 'student'),
            $generator->create_and_enrol($this->course, 'student'),
        ];
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Run an action as the signed channel does.
     *
     * @param string $action
     * @param array $params
     * @return array{0: int, 1: array} Status and body.
     */
    private function call(string $action, array $params): array {
        $result = inbound_dispatcher::execute(caller::site(), $action, $params);
        return [$result['httpStatus'], $result['body']];
    }

    /**
     * Final grade of a learner in an item, null if none.
     *
     * @param int $itemid
     * @param int $userid
     * @return float|null
     */
    private function grade_of(int $itemid, int $userid): ?float {
        $grade = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
        return ($grade && $grade->finalgrade !== null) ? (float)$grade->finalgrade : null;
    }

    public function test_creates_the_item_and_writes_grades(): void {
        [$a, $b] = $this->learners;
        [$status, $body] = $this->call('set_grades', [
            'courseid' => $this->course->id,
            'ref' => 'ex-ch3',
            'item' => ['name' => 'Tutor: Übung Kapitel 3', 'grademax' => 20, 'gradepass' => 12, 'category' => 'Tutor-Übungen'],
            'grades' => [
                ['userid' => $a->id, 'grade' => 17.5, 'feedback' => '<p>Gut gelöst</p>'],
                ['userid' => $b->id, 'grade' => 8],
            ],
        ]);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['total' => 2, 'ok' => 2, 'failed' => 0], $body['summary']);
        $this->assertSame('ex-ch3', $body['item']['ref']);
        $this->assertSame('Tutor-Übungen', $body['item']['category']);
        $this->assertSame(2, $body['item']['graded']);

        $item = \grade_item::fetch(['id' => $body['item']['id']]);
        $this->assertSame('alphabees:ex-ch3', $item->idnumber);
        $this->assertSame('manual', $item->itemtype);
        $this->assertEquals(20, $item->grademax);
        $this->assertEquals(12, $item->gradepass);
        $this->assertSame(17.5, $this->grade_of($item->id, $a->id));
        $this->assertStringContainsString('Gut gelöst', \grade_grade::fetch(['itemid' => $item->id, 'userid' => $a->id])->feedback);

        // Same ref again: same item, renamed, own grade overwritten, a grade cleared.
        [$status, $body] = $this->call('set_grades', [
            'courseid' => $this->course->id,
            'ref' => 'ex-ch3',
            'item' => ['name' => 'Tutor: Kapitel 3'],
            'grades' => [['userid' => $a->id, 'grade' => 19], ['userid' => $b->id, 'grade' => null]],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame((int)$item->id, $body['item']['id']);
        $this->assertSame('Tutor: Kapitel 3', $body['item']['name']);
        $this->assertSame(19.0, $this->grade_of($item->id, $a->id));
        $this->assertNull($this->grade_of($item->id, $b->id));
        $this->assertCount(1, \grade_category::fetch_all(['courseid' => $this->course->id, 'fullname' => 'Tutor-Übungen']));
    }

    public function test_a_teachers_grade_wins_unless_overridden(): void {
        [$a] = $this->learners;
        $item = grade_writer::ensure_item((int)$this->course->id, 'ex-1', ['name' => 'Tutor 1']);
        $item->update_final_grade($a->id, 55, 'gradebook', false, FORMAT_MOODLE, $this->teacher->id);

        [$status, $body] = $this->call('set_grades', [
            'courseid' => $this->course->id, 'ref' => 'ex-1', 'grades' => [['userid' => $a->id, 'grade' => 90]],
        ]);
        $this->assertSame(422, $status);
        $this->assertSame('teacher_modified', $body['results'][0]['error']);
        $this->assertSame(55.0, $this->grade_of($item->id, $a->id));

        [$status] = $this->call('set_grades', [
            'courseid' => $this->course->id, 'ref' => 'ex-1', 'override_teacher' => true,
            'grades' => [['userid' => $a->id, 'grade' => 90]],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame(90.0, $this->grade_of($item->id, $a->id));
    }

    public function test_refusals_are_reported_per_learner(): void {
        [$a, $b] = $this->learners;
        $outsider = $this->getDataGenerator()->create_user();
        $item = grade_writer::ensure_item((int)$this->course->id, 'ex-2', ['name' => 'Tutor 2', 'grademax' => 10]);
        // Moodle locks a grade only once the item's final grades are computed.
        grade_regrade_final_grades($this->course->id);
        $locked = new \grade_grade(['itemid' => $item->id, 'userid' => $b->id], false);
        $locked->insert();
        $locked->grade_item = \grade_item::fetch(['id' => $item->id]);
        $this->assertTrue($locked->set_locked(1));

        [$status, $body] = $this->call('set_grades', [
            'courseid' => $this->course->id,
            'ref' => 'ex-2',
            'grades' => [
                ['userid' => $a->id, 'grade' => 11],
                ['userid' => $b->id, 'grade' => 5],
                ['userid' => $outsider->id, 'grade' => 5],
                ['userid' => 999999, 'grade' => 5],
                ['userid' => $a->id],
                ['userid' => $a->id, 'grade' => 7],
            ],
        ]);
        $this->assertSame(200, $status);
        $this->assertSame(
            ['out_of_range', 'locked', 'not_enrolled', 'user_not_found', 'missing_userid_or_grade', null],
            array_map(fn(array $r) => $r['error'] ?? null, $body['results'])
        );
        $this->assertSame(['total' => 6, 'ok' => 1, 'failed' => 5], $body['summary']);
    }

    public function test_bad_requests(): void {
        $cid = $this->course->id;
        $this->assertSame(400, $this->call('set_grades', ['courseid' => $cid])[0]);
        [$status, $body] = $this->call('set_grades', ['courseid' => $cid, 'ref' => 'no spaces']);
        $this->assertSame([400, 'invalid_item'], [$status, $body['error']]);
        [$status, $body] = $this->call('set_grades', ['courseid' => $cid, 'ref' => 'new-without-name']);
        $this->assertSame([400, 'invalid_item'], [$status, $body['error']]);
        [$status, $body] = $this->call('set_grades', ['courseid' => $cid, 'ref' => 'x',
            'item' => ['name' => 'X', 'grademax' => 0]]);
        $this->assertSame([400, 'invalid_item'], [$status, $body['error']]);
        $this->assertSame(404, $this->call('set_grades', ['courseid' => 999999, 'ref' => 'x'])[0]);
        $this->assertSame(404, $this->call('set_grades', ['courseid' => SITEID, 'ref' => 'x'])[0]);
    }

    public function test_only_own_items_are_listed_and_deleted(): void {
        $teacheritem = new \grade_item(['courseid' => $this->course->id, 'itemtype' => 'manual',
            'itemname' => 'Teacher item', 'idnumber' => 'mine', 'gradetype' => GRADE_TYPE_VALUE], false);
        $teacheritem->insert();
        grade_writer::ensure_item((int)$this->course->id, 'ex-a', ['name' => 'A']);
        grade_writer::ensure_item((int)$this->course->id, 'ex-b', ['name' => 'B']);

        [$status, $body] = $this->call('list_grade_items', ['courseid' => $this->course->id]);
        $this->assertSame(200, $status);
        $this->assertSame(['ex-a', 'ex-b'], array_column($body['items'], 'ref'));

        $this->assertSame(404, $this->call('delete_grade_item', ['courseid' => $this->course->id, 'ref' => 'mine'])[0]);
        $this->assertSame(200, $this->call('delete_grade_item', ['courseid' => $this->course->id, 'ref' => 'ex-a'])[0]);
        $this->assertNull(grade_writer::find_item((int)$this->course->id, 'ex-a'));
        $this->assertNotFalse(\grade_item::fetch(['id' => $teacheritem->id]));
    }

    public function test_a_grade_condition_can_use_the_item(): void {
        global $CFG;
        require_once($CFG->dirroot . '/availability/condition/grade/classes/condition.php');
        [$a, $b] = $this->learners;
        $item = grade_writer::ensure_item((int)$this->course->id, 'ex-gate', ['name' => 'Gate', 'grademax' => 10]);
        grade_writer::write($item, [['userid' => $a->id, 'grade' => 9], ['userid' => $b->id, 'grade' => 3]], (int)get_admin()->id);

        $cm = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $condition = new \availability_grade\condition((object)['id' => (int)$item->id, 'min' => 80.0]);
        $info = new \core_availability\info_module(get_fast_modinfo($this->course)->get_cm($cm->cmid));
        $this->assertTrue($condition->is_available(false, $info, true, $a->id));
        $this->assertFalse($condition->is_available(false, $info, true, $b->id));
    }
}
