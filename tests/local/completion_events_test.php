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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\completion_events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\task\deliver_completion_events::class)]
/**
 * Tests for reporting activity completion changes to the backend.
 *
 * The changes are made through Moodle's own completion API, so the event, the
 * observer registration and the outbox are exercised as on a real site.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\completion_events
 * @covers    \block_alphabees\observer
 * @covers    \block_alphabees\task\deliver_completion_events
 */
final class completion_events_test extends \advanced_testcase {
    /**
     * Make the site look registered with the backend, with throwaway keys.
     */
    private function connect(): void {
        // The observer is non-internal: it runs after commit, and a test reset
        // by rollback never commits.
        $this->preventResetByRollback();
        set_config('registered_at', time(), 'block_alphabees');
        set_config('backend_public_key', rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'block_alphabees');
    }

    /**
     * A course with completion, one student and a manually completed page.
     *
     * @return array [course, student, cm_info]
     */
    private function course_with_page(): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        return [$course, $student, get_fast_modinfo($course)->get_cm($page->cmid)];
    }

    /**
     * Outbox payloads, oldest first.
     *
     * @return array
     */
    private function outbox(): array {
        global $DB;
        return array_map(
            fn($r) => json_decode($r->payload, true),
            array_values($DB->get_records(completion_events::TABLE, null, 'id ASC'))
        );
    }

    public function test_each_change_is_recorded_and_one_delivery_queued(): void {
        global $DB;
        $this->resetAfterTest();
        $this->connect();
        [$course, $student, $cm] = $this->course_with_page();

        $this->setUser($student);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $student->id);

        $events = $this->outbox();
        $this->assertCount(2, $events);
        $this->assertSame('completion.updated', $events[0]['event']);
        $this->assertSame((int)$student->id, $events[0]['userId']);
        $this->assertSame((int)$course->id, $events[0]['courseId']);
        $this->assertSame((int)$cm->id, $events[0]['cmid']);
        $this->assertSame('page', $events[0]['modname']);
        $this->assertSame('complete', $events[0]['state']);
        $this->assertTrue($events[0]['complete']);
        $this->assertFalse($events[0]['overridden']);
        $this->assertSame('incomplete', $events[1]['state']);
        $this->assertFalse($events[1]['complete']);
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\block_alphabees\\task\\deliver_completion_events']
        ));
    }

    public function test_override_is_flagged(): void {
        $this->resetAfterTest();
        $this->connect();
        [$course, $student, $cm] = $this->course_with_page();
        $this->setAdminUser();
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id, true);
        $this->assertTrue($this->outbox()[0]['overridden']);
    }

    public function test_nothing_recorded_without_a_connection(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$course, $student, $cm] = $this->course_with_page();
        $this->setUser($student);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertSame([], $this->outbox());
    }

    public function test_failed_grade_counts_unless_a_pass_is_required(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $anygrade = $gen->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'grade' => 100]);
        $passgrade = $gen->create_module('assign', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1, 'completionpassgrade' => 1, 'gradepass' => 50, 'grade' => 100]);
        $modinfo = get_fast_modinfo($course);

        $this->assertTrue(completion_events::counts_as_complete($modinfo->get_cm($anygrade->cmid), COMPLETION_COMPLETE_FAIL));
        $this->assertFalse(completion_events::counts_as_complete($modinfo->get_cm($passgrade->cmid), COMPLETION_COMPLETE_FAIL));
        $this->assertTrue(completion_events::counts_as_complete($modinfo->get_cm($passgrade->cmid), COMPLETION_COMPLETE_PASS));
        $this->assertSame('failed_hidden', completion_events::state_name(4));
    }

    public function test_batches_are_oldest_first_and_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        for ($i = 1; $i <= 250; $i++) {
            $DB->insert_record(completion_events::TABLE, (object)['kind' => completion_events::KIND,
                'payload' => json_encode(['n' => $i]), 'timecreated' => time()]);
        }
        $seen = [];
        while ($batch = completion_events::next_batch()) {
            if (!$batch['ids']) {
                break;
            }
            $this->assertLessThanOrEqual(completion_events::BATCH_SIZE, count($batch['events']));
            $seen = array_merge($seen, array_column($batch['events'], 'n'));
            completion_events::forget($batch['ids']);
        }
        $this->assertSame(range(1, 250), $seen);
    }

    public function test_delivery_drops_the_outbox_when_no_longer_connected(): void {
        $this->resetAfterTest();
        $this->connect();
        [$course, $student, $cm] = $this->course_with_page();
        $this->setUser($student);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertCount(1, $this->outbox());

        unset_config('registered_at', 'block_alphabees');
        ob_start();
        (new \block_alphabees\task\deliver_completion_events())->execute();
        ob_end_clean();
        $this->assertSame([], $this->outbox());
    }

    public function test_stale_rows_are_purged(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record(completion_events::TABLE, (object)['kind' => completion_events::KIND, 'payload' => '{}',
            'timecreated' => time() - completion_events::MAX_AGE - 60]);
        $DB->insert_record(completion_events::TABLE, (object)['kind' => completion_events::KIND, 'payload' => '{}',
            'timecreated' => time()]);
        $this->assertSame(1, completion_events::purge_stale());
        $this->assertSame(1, $DB->count_records(completion_events::TABLE));
    }
}
