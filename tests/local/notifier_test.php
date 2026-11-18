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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\notifier::class)]
/**
 * Tests for the grade_review notification.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\notifier
 */
final class notifier_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $teacher;

    /** @var \stdClass */
    private \stdClass $blindteacher;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        // Allowed to be notified, not allowed to see grades.
        $this->blindteacher = $generator->create_user();
        $role = $generator->create_role();
        $context = \context_course::instance($this->course->id);
        assign_capability(action_policy::CAP_NOTIFY, CAP_ALLOW, $role, $context->id);
        $generator->enrol_user($this->blindteacher->id, $this->course->id, $role);
        $DB->delete_records('role_capabilities', ['roleid' => $role, 'capability' => 'moodle/grade:viewall']);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Send a grade_review and catch the messages.
     *
     * @param array $extra
     * @return array{0: array, 1: \stdClass[]}
     */
    private function send(array $extra = []): array {
        $sink = $this->redirectMessages();
        $result = notifier::send($extra + [
            'provider' => 'grade_review',
            'courseid' => $this->course->id,
            'subject' => 'Noten prüfen',
            'message' => '3 Noten warten seit 3 Tagen.',
            'reviewid' => 'abc-123',
        ]);
        $messages = $sink->get_messages();
        $sink->close();
        return [$result, $messages];
    }

    public function test_goes_only_to_people_who_see_grades(): void {
        [$result, $messages] = $this->send(['userids' => [$this->teacher->id, $this->blindteacher->id]]);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['rejected']);
        $this->assertSame((int)$this->teacher->id, (int)$messages[0]->useridto);
        $this->assertSame('grade_review', $messages[0]->eventtype);

        // Without a list: the course's capability holders, filtered the same way.
        [$result] = $this->send();
        $this->assertSame(1, $result['sent']);
    }

    public function test_leads_to_the_console_or_the_gradebook(): void {
        [, $messages] = $this->send(['userids' => [$this->teacher->id]]);
        $url = new \moodle_url($messages[0]->contexturl);
        $this->assertStringEndsWith('/blocks/alphabees/console.php', $url->get_path(false));
        $this->assertSame('abc-123', $url->get_param('review'));
        $this->assertSame((string)$this->course->id, (string)$url->get_param('course'));
        $this->assertSame(get_string('notify_open_review', 'block_alphabees'), $messages[0]->contexturlname);

        [, $messages] = $this->send(['userids' => [$this->teacher->id], 'target' => 'gradebook']);
        $url = new \moodle_url($messages[0]->contexturl);
        $this->assertStringEndsWith('/grade/report/grader/index.php', $url->get_path(false));

        [, $messages] = $this->send(['userids' => [$this->teacher->id], 'target' => 'https://evil.example']);
        $this->assertStringContainsString('/blocks/alphabees/console.php', $messages[0]->contexturl);
    }
}
