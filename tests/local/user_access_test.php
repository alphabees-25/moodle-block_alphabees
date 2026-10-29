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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\user_access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\inbound_dispatcher::class)]
/**
 * Tests for the get_user_access inbound action.
 *
 * The course mirrors the customer case: one section open only to learners
 * whose "Studienmodell" profile field contains _FLEX, plus a hidden activity,
 * a group-restricted one and a hidden section.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\user_access
 * @covers    \block_alphabees\local\inbound_dispatcher
 */
final class user_access_test extends \advanced_testcase {
    /**
     * Build the course.
     *
     * @return \stdClass course, fix, flex, teacher, and cmids by name
     */
    private function flex_fix_course(): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        set_config('enableavailability', 1);

        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'studienmodell', 'name' => 'Studienmodell']);
        $course = $gen->create_course(['numsections' => 3, 'format' => 'topics']);

        $f = (object)['course' => $course, 'cm' => []];
        foreach (['fix' => 'BWL_FIX', 'flex' => 'BWL_FLEX'] as $who => $model) {
            $f->$who = $gen->create_user();
            profile_save_data((object)['id' => $f->$who->id, 'profile_field_studienmodell' => $model]);
            $gen->enrol_user($f->$who->id, $course->id, 'student');
        }
        $f->teacher = $gen->create_user();
        $gen->enrol_user($f->teacher->id, $course->id, 'editingteacher');
        $group = $gen->create_group(['courseid' => $course->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $f->flex->id]);

        $pages = ['open' => [1, 1], 'hidden' => [1, 0], 'grouponly' => [1, 1], 'flexonly' => [2, 1], 'insidehidden' => [3, 1]];
        foreach ($pages as $name => [$section, $visible]) {
            $f->cm[$name] = (int)$gen->create_module('page', ['course' => $course->id, 'section' => $section,
                'visible' => $visible, 'name' => $name])->cmid;
        }
        $grouprule = '{"op":"&","c":[{"type":"group","id":' . (int)$group->id . '}],"showc":[false]}';
        $DB->set_field('course_modules', 'availability', $grouprule, ['id' => $f->cm['grouponly']]);
        // Shown greyed out with its restriction, as such sections usually are.
        $flexrule = '{"op":"&","c":[{"type":"profile","cf":"studienmodell","op":"contains","v":"_FLEX"}],"showc":[true]}';
        $DB->set_field('course_sections', 'availability', $flexrule, ['course' => $course->id, 'section' => 2]);
        rebuild_course_cache($course->id, true);
        set_section_visible($course->id, 3, 0);

        $f->sectionid = $DB->get_records_menu('course_sections', ['course' => $course->id], '', 'section, id');
        return $f;
    }

    /**
     * Run the action through the registry, as api.php does once the signature holds.
     *
     * @param array $params
     * @return array
     */
    private function call(array $params): array {
        return inbound_dispatcher::execute(caller::site(), 'get_user_access', $params);
    }

    public function test_fix_learner_is_kept_out_of_the_flex_section(): void {
        $this->resetAfterTest();
        $f = $this->flex_fix_course();

        $answer = $this->call(['courseid' => $f->course->id, 'userid' => $f->fix->id]);
        $this->assertSame(200, $answer['httpStatus']);
        $body = $answer['body'];
        $this->assertTrue($body['ok']);
        $this->assertSame((int)$f->course->id, $body['courseid']);
        $this->assertSame((int)$f->fix->id, $body['userid']);

        $expected = [$f->cm['hidden'], $f->cm['grouponly'], $f->cm['flexonly'], $f->cm['insidehidden']];
        sort($expected);
        $this->assertSame($expected, $body['blocked_cmids']);
        $this->assertSame([
            ['id' => (int)$f->sectionid[2], 'section' => 2],
            ['id' => (int)$f->sectionid[3], 'section' => 3],
        ], $body['blocked_sections']);
    }

    public function test_flex_learner_in_the_group_sees_more(): void {
        $this->resetAfterTest();
        $f = $this->flex_fix_course();

        $body = $this->call(['courseId' => $f->course->id, 'userId' => $f->flex->id])['body'];
        $expected = [$f->cm['hidden'], $f->cm['insidehidden']];
        sort($expected);
        $this->assertSame($expected, $body['blocked_cmids']);
        $this->assertSame([['id' => (int)$f->sectionid[3], 'section' => 3]], $body['blocked_sections']);
    }

    public function test_teacher_is_blocked_from_nothing(): void {
        $this->resetAfterTest();
        $f = $this->flex_fix_course();

        $body = $this->call(['courseid' => $f->course->id, 'userid' => $f->teacher->id])['body'];
        $this->assertSame([], $body['blocked_cmids']);
        $this->assertSame([], $body['blocked_sections']);
    }

    public function test_answer_does_not_depend_on_who_asked_before(): void {
        $this->resetAfterTest();
        $f = $this->flex_fix_course();

        // The modinfo is cached per course and user; a stale one would leak access.
        $fix = $this->call(['courseid' => $f->course->id, 'userid' => $f->fix->id])['body'];
        $this->call(['courseid' => $f->course->id, 'userid' => $f->teacher->id]);
        $this->call(['courseid' => $f->course->id, 'userid' => $f->flex->id]);
        $this->assertSame($fix, $this->call(['courseid' => $f->course->id, 'userid' => $f->fix->id])['body']);
    }

    public function test_refusals(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(400, $this->call(['courseid' => $course->id])['httpStatus']);
        $this->assertSame(400, $this->call(['userid' => $user->id])['httpStatus']);

        $unknownuser = $this->call(['courseid' => $course->id, 'userid' => $user->id + 1000]);
        $this->assertSame([404, 'user_not_found'], [$unknownuser['httpStatus'], $unknownuser['body']['error']]);

        delete_user($user);
        $deleted = $this->call(['courseid' => $course->id, 'userid' => $user->id]);
        $this->assertSame([404, 'user_not_found'], [$deleted['httpStatus'], $deleted['body']['error']]);

        $other = $this->getDataGenerator()->create_user();
        $unknowncourse = $this->call(['courseid' => $course->id + 1000, 'userid' => $other->id]);
        $this->assertSame([404, 'course_not_found'], [$unknowncourse['httpStatus'], $unknowncourse['body']['error']]);
    }

    public function test_reachable_only_through_the_signed_channel(): void {
        $this->resetAfterTest();
        $this->assertTrue(action_policy::known('get_user_access'));
        $this->assertTrue(action_policy::accepts(caller::site(), 'get_user_access'));
        $this->assertFalse(action_policy::accepts(caller::user(2), 'get_user_access'));
    }
}
