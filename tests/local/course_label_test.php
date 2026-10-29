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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\course_label::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\output\mobile::class)]
/**
 * Tests for the course names handed to the widget.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\course_label
 * @covers    \block_alphabees\output\mobile
 */
final class course_label_test extends \advanced_testcase {
    public function test_names_as_the_learner_reads_them(): void {
        $this->resetAfterTest();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        $course = $this->getDataGenerator()->create_course([
            'fullname' => '<span lang="de" class="multilang">Statistik & Daten</span>'
                . '<span lang="en" class="multilang">Statistics & data</span>',
            'shortname' => 'STAT-101',
        ]);

        $label = course_label::of((int)$course->id);
        $this->assertSame('Statistics & data', $label['name']);
        $this->assertSame('STAT-101', $label['shortname']);

        // A record without the name columns is fetched again, not trusted.
        $this->assertSame($label, course_label::of((int)$course->id, (object)['id' => $course->id]));
    }

    public function test_no_course_gives_empty_names(): void {
        $this->resetAfterTest();
        $this->assertSame(['name' => '', 'shortname' => ''], course_label::of(0));
        $this->assertSame(['name' => '', 'shortname' => ''], course_label::of(999999));
    }

    public function test_app_view_carries_the_names(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Einführung "BWL"', 'shortname' => 'BWL1']);
        $this->setUser($this->getDataGenerator()->create_user());

        $html = \block_alphabees\output\mobile::mobile_ws_marker(['courseid' => $course->id])['templates'][0]['html'];
        $this->assertStringContainsString('data-coursename="Einführung &quot;BWL&quot;"', $html);
        $this->assertStringContainsString('data-courseshortname="BWL1"', $html);
    }
}
