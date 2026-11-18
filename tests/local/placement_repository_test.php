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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\placement_repository::class)]
/**
 * Tests for reading block configuration.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\placement_repository
 */
final class placement_repository_test extends \advanced_testcase {
    public function test_config_round_trip(): void {
        $config = (object)['botid' => 'tutor-x', 'placement_uuid' => 'u-1'];
        $this->assertEquals($config, placement_repository::decode_config(placement_repository::encode_config($config)));
    }

    public function test_stored_config_cannot_instantiate_a_class(): void {
        $payload = base64_encode(serialize(new \moodle_url('/x.php', ['a' => 1])));
        $decoded = placement_repository::decode_config($payload);
        $this->assertSame(\stdClass::class, get_class($decoded));
    }

    public function test_garbage_gives_an_empty_config(): void {
        $this->assertEquals(new \stdClass(), placement_repository::decode_config(''));
        $this->assertEquals(new \stdClass(), placement_repository::decode_config('!!not base64'));
        $this->assertEquals(new \stdClass(), placement_repository::decode_config(base64_encode('a:1:{i:0;i:1;}')));
        $this->assertEquals(new \stdClass(), placement_repository::decode_config(base64_encode('O:8:"stdClass":broken')));
    }

    /**
     * Put an Alphabees block with the given config into a course.
     *
     * @param \stdClass $course
     * @param \stdClass $config
     * @return int Instance id.
     */
    private function add_block(\stdClass $course, \stdClass $config): int {
        global $DB;
        return (int)$DB->insert_record('block_instances', (object)[
            'blockname' => 'alphabees',
            'parentcontextid' => \context_course::instance($course->id)->id,
            'showinsubcontexts' => 0,
            'pagetypepattern' => 'course-view-*',
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => placement_repository::encode_config($config),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * UUIDs of all Alphabees blocks, by instance id.
     *
     * @return array
     */
    private function uuids(): array {
        $uuids = [];
        foreach (placement_repository::all_instances() as $id => $instance) {
            $uuids[$id] = placement_repository::decode_config($instance->configdata)->placement_uuid ?? null;
        }
        ksort($uuids);
        return $uuids;
    }

    public function test_course_copy_gets_its_own_uuid(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/externallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $original = $this->add_block($course, (object)['botid' => 'b', 'placement_uuid' => 'uuid-orig']);
        placement_repository::repair_uuids();

        \core_course_external::duplicate_course($course->id, 'Copy', 'COPY', $course->category);
        $this->assertCount(1, array_unique($this->uuids()), 'restore copies the UUID verbatim');

        $this->assertSame(1, placement_repository::repair_uuids());
        $uuids = $this->uuids();
        $this->assertCount(2, array_unique($uuids));
        $this->assertSame('uuid-orig', $uuids[$original]);
        $this->assertSame(0, placement_repository::repair_uuids(), 'stable once split');

        // The copy names its origin; the original names none.
        foreach (placement_repository::all_instances() as $id => $instance) {
            $payload = placement_repository::build_payload($instance);
            $this->assertSame($id === $original ? null : 'uuid-orig', $payload['copied_from_placement_uuid']);
        }
    }

    public function test_bound_copy_names_its_origin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $original = $this->add_block($course, (object)['placement_uuid' => 'uuid-a']);
        placement_repository::repair_uuids();
        global $DB;
        $config = placement_repository::decode_config($DB->get_field('block_instances', 'configdata', ['id' => $original]));
        // A 3.3.0 copy: configdata copied verbatim, binding included.
        $copy = $this->add_block($course, $config);

        $instance = $DB->get_record('block_instances', ['id' => $copy]);
        $copyconfig = placement_repository::decode_config($instance->configdata);
        $this->assertTrue(placement_repository::claim_uuid($instance, $copyconfig));
        $this->assertNotSame('uuid-a', $copyconfig->placement_uuid);
        $this->assertSame('uuid-a', $copyconfig->placement_copied_from);

        $instance = $DB->get_record('block_instances', ['id' => $copy]);
        $this->assertSame('uuid-a', placement_repository::build_payload($instance)['copied_from_placement_uuid']);
        $original = $DB->get_record('block_instances', ['id' => $original]);
        $this->assertNull(placement_repository::build_payload($original)['copied_from_placement_uuid']);
    }

    public function test_copies_made_before_binding_keep_the_oldest(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->add_block($course, (object)['placement_uuid' => 'shared']);
        $this->add_block($course, (object)['placement_uuid' => 'shared']);
        $this->add_block($course, (object)['placement_uuid' => 'shared']);
        $this->add_block($course, (object)[]);

        $this->assertSame(3, placement_repository::repair_uuids(), 'two copies and the block without one');
        $uuids = $this->uuids();
        $this->assertCount(4, array_unique($uuids));
        $this->assertSame('shared', $uuids[$first]);

        $origins = [];
        foreach (placement_repository::all_instances() as $id => $instance) {
            $origins[$id] = placement_repository::build_payload($instance)['copied_from_placement_uuid'];
        }
        ksort($origins);
        // The oldest keeps the UUID, the two copies name it, the block that
        // never had one names nothing.
        $this->assertSame([null, 'shared', 'shared', null], array_values($origins));
    }
}
