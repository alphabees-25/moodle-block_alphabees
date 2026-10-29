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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_course_fields::class)]
/**
 * Tests for block_alphabees_get_course_fields.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_course_fields
 */
final class get_course_fields_test extends \externallib_advanced_testcase {
    public function test_all_three_visibilities_with_values(): void {
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_course_fields::execute_returns(),
            get_course_fields::execute($f->course->id)
        );
        $fields = array_column($result['fields'], null, 'shortname');
        $this->assertSame(2, $fields['unit_1_name']['visibility']);
        $this->assertSame(1, $fields['teacher_note']['visibility']);
        $this->assertSame(0, $fields['internal']['visibility']);
        $this->assertSame('Wissenschaft – was ist das?', $fields['unit_1_name']['value']);
        $this->assertSame('text', $fields['unit_1_name']['type']);
    }

    public function test_value_is_filtered_as_a_learner_sees_it(): void {
        global $DB;
        if (!\core_component::get_component_directory('filter_filtercodes')) {
            $this->markTestSkipped('filter_filtercodes is not installed.');
        }
        $this->resetAfterTest();
        set_config('filterall', 1);
        filter_set_global_state('filtercodes', TEXTFILTER_ON);
        filter_set_applies_to_strings('filtercodes', true);
        $f = learner_course::create($this->getDataGenerator());
        $field = $DB->get_record('customfield_field', ['shortname' => 'unit_1_name']);
        $where = ['fieldid' => $field->id, 'instanceid' => $f->course->id];
        $DB->set_field('customfield_data', 'value', 'Hallo {firstname}', $where);
        $DB->set_field('customfield_data', 'charvalue', 'Hallo {firstname}', $where);

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_course_fields::execute_returns(),
            get_course_fields::execute($f->course->id)
        );
        $value = array_column($result['fields'], 'value', 'shortname')['unit_1_name'];
        $this->assertStringNotContainsString(get_admin()->firstname, $value);
    }
}
