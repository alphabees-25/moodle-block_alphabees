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
require_once($CFG->libdir . '/completionlib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\inbound_dispatcher::class)]
/**
 * Tests for the completion write-back: Alphabees changes only states that are
 * empty or its own.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\inbound_dispatcher
 */
final class activity_completion_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $learner;

    /** @var \stdClass */
    private \stdClass $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);
        $this->learner = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * A page with manual completion.
     *
     * @return \cm_info
     */
    private function manual_page(): \cm_info {
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id],
            ['completion' => COMPLETION_TRACKING_MANUAL]
        );
        return get_fast_modinfo($this->course)->get_cm($page->cmid);
    }

    /**
     * Run set_activity_completion as the signed channel does.
     *
     * @param int $cmid
     * @param bool $completed
     * @param bool $override
     * @return array{0: int, 1: array}
     */
    private function call(int $cmid, bool $completed, bool $override = false): array {
        $params = ['cmid' => $cmid, 'userid' => $this->learner->id, 'completed' => $completed];
        if ($override) {
            $params['override_teacher'] = true;
        }
        $result = inbound_dispatcher::execute(caller::site(), 'set_activity_completion', $params);
        return [$result['httpStatus'], $result['body']];
    }

    /**
     * The learner's completion row.
     *
     * @param \cm_info $cm
     * @return \stdClass
     */
    private function state(\cm_info $cm): \stdClass {
        return (new \completion_info($this->course))->get_data($cm, false, $this->learner->id);
    }

    public function test_writes_an_empty_state_and_takes_its_own_back(): void {
        $cm = $this->manual_page();
        [$status, $body] = $this->call($cm->id, true);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue($body['completed']);
        $this->assertFalse($body['unchanged']);
        $this->assertSame(COMPLETION_INCOMPLETE, $body['previousstate']);
        $this->assertGreaterThan(0, $body['overrideby']);

        [$status, $body] = $this->call($cm->id, true);
        $this->assertSame(200, $status);
        $this->assertTrue($body['unchanged']);

        // Its own completion it may undo.
        [$status, $body] = $this->call($cm->id, false);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertFalse($body['completed']);
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$this->state($cm)->completionstate);
    }

    public function test_a_teachers_override_wins_unless_overridden(): void {
        $cm = $this->manual_page();
        // The teacher ticks it and takes it back: incomplete, overridden by the teacher.
        $this->setUser($this->teacher);
        $completion = new \completion_info($this->course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->learner->id, true);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $this->learner->id, true);
        $this->setUser(null);
        $this->assertSame((int)$this->teacher->id, (int)$this->state($cm)->overrideby);

        [$status, $body] = $this->call($cm->id, true);
        $this->assertSame(409, $status);
        $this->assertSame('teacher_modified', $body['error']);
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$this->state($cm)->completionstate);

        [$status, $body] = $this->call($cm->id, true, true);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(COMPLETION_COMPLETE, (int)$this->state($cm)->completionstate);
    }

    public function test_a_learners_own_completion_is_never_turned_into_an_override(): void {
        $cm = $this->manual_page();
        $this->setUser($this->learner);
        (new \completion_info($this->course))->update_state($cm, COMPLETION_COMPLETE, $this->learner->id);
        $this->setUser(null);

        [$status, $body] = $this->call($cm->id, true);
        $this->assertSame(200, $status);
        $this->assertTrue($body['unchanged']);
        $this->assertSame(0, $body['overrideby']);
        $this->assertNull($this->state($cm)->overrideby);

        [$status, $body] = $this->call($cm->id, false);
        $this->assertSame(409, $status);
        $this->assertSame('learner_result', $body['error']);
        $this->assertSame(COMPLETION_COMPLETE, (int)$this->state($cm)->completionstate);
    }
}
