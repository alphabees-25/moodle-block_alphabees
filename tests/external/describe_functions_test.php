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

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\describe_functions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\function_schema::class)]
/**
 * Tests for block_alphabees_describe_functions.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\describe_functions
 * @covers    \block_alphabees\local\function_schema
 */
final class describe_functions_test extends \externallib_advanced_testcase {
    /**
     * Call the function as admin and clean the answer as the web service would.
     *
     * @param array $functions
     * @return array
     */
    private function describe(array $functions = [], string $knownhash = ''): array {
        $this->setAdminUser();
        return external_api::clean_returnvalue(
            describe_functions::execute_returns(),
            describe_functions::execute($functions, $knownhash)
        );
    }

    public function test_whole_service_is_described(): void {
        global $DB;
        $this->resetAfterTest();
        $result = $this->describe();

        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'block_alphabees']);
        $inservice = $DB->count_records('external_services_functions', ['externalserviceid' => $serviceid]);
        $this->assertSame($inservice, count($result['functions']) + count($result['unavailable']));
        $this->assertSame('block_alphabees', $result['service']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $result['hash']);

        $names = array_column($result['functions'], 'name');
        $this->assertContains('core_course_get_contents', $names);
        $this->assertContains('block_alphabees_describe_functions', $names);
        $this->assertContains('block_alphabees_get_course_grades', $names);
    }

    public function test_core_function_schema(): void {
        $this->resetAfterTest();
        $result = $this->describe(['core_course_get_contents']);
        $this->assertCount(1, $result['functions']);
        $function = $result['functions'][0];
        $this->assertSame('read', $function['type']);
        $this->assertSame('moodle', $function['component']);

        $params = json_decode($function['parameters'], true);
        $this->assertSame('object', $params['type']);
        $this->assertFalse($params['additionalProperties']);
        $this->assertSame(['courseid'], $params['required']);
        $this->assertSame('integer', $params['properties']['courseid']['type']);
        $this->assertSame(PARAM_INT, $params['properties']['courseid']['x-moodle-type']);
        $this->assertSame('array', $params['properties']['options']['type']);
        $this->assertSame([], $params['properties']['options']['default']);
        $option = $params['properties']['options']['items'];
        $this->assertSame(['name', 'value'], $option['required']);

        // Return values keep Moodle's NULL_ALLOWED, the default for a value.
        $returns = json_decode($function['returns'], true);
        $this->assertSame('array', $returns['type']);
        $section = $returns['items'];
        // Core's wrapped, indented descriptions arrive on one line.
        $this->assertStringNotContainsString('\n', $function['returns']);
        $this->assertSame(['integer', 'null'], $section['properties']['id']['type']);
        $this->assertSame('array', $section['properties']['modules']['type']);
        $this->assertSame(['integer', 'null'], $section['properties']['modules']['items']['properties']['id']['type']);
    }

    public function test_defaults_nullables_and_empty_objects(): void {
        $this->resetAfterTest();
        $function = $this->describe(['block_alphabees_describe_functions'])['functions'][0];

        $params = json_decode($function['parameters'], true);
        $this->assertArrayNotHasKey('required', $params);
        $this->assertSame([], $params['properties']['functions']['default']);
        $this->assertSame('string', $params['properties']['functions']['items']['type']);

        $returns = json_decode($function['returns'], true);
        $entry = $returns['properties']['functions']['items'];
        $this->assertSame(['string', 'null'], $entry['properties']['returns']['type']);
        $this->assertNotContains('returns', $entry['required']);
        $this->assertSame(['boolean', 'null'], $entry['properties']['deprecated']['type']);
        $this->assertSame('object', $entry['type']);
    }

    public function test_filter_hash_and_unavailable(): void {
        global $DB;
        $this->resetAfterTest();
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'block_alphabees']);
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $serviceid,
            'functionname' => 'mod_gone_get_things',
        ]);

        $all = $this->describe();
        $this->assertContains('mod_gone_get_things', $all['unavailable']);
        $this->assertSame($all['hash'], $this->describe()['hash']);
        $this->assertFalse($all['unchanged']);

        $again = $this->describe([], $all['hash']);
        $this->assertTrue($again['unchanged']);
        $this->assertSame([], $again['functions']);
        $this->assertSame($all['hash'], $again['hash']);
        $this->assertFalse($this->describe([], str_repeat('0', 40))['unchanged']);

        $two = $this->describe(['core_course_get_contents', 'core_course_get_courses', 'not_in_service']);
        $this->assertSame(['core_course_get_contents', 'core_course_get_courses'], array_column($two['functions'], 'name'));
        $this->assertNotSame($all['hash'], $two['hash']);

        // An empty default on an object stays an object, not a JSON list.
        $courses = array_column($two['functions'], 'parameters', 'name')['core_course_get_courses'];
        $this->assertStringContainsString('"additionalProperties":false,"default":{}', $courses);
    }

    public function test_requires_the_service_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        describe_functions::execute([]);
    }
}
