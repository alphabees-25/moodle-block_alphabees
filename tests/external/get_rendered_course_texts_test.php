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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_rendered_course_texts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\learner_view::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\skipping_filter_manager::class)]
/**
 * Tests for block_alphabees_get_rendered_course_texts.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_rendered_course_texts
 * @covers    \block_alphabees\local\learner_view
 * @covers    \block_alphabees\local\skipping_filter_manager
 */
final class get_rendered_course_texts_test extends \externallib_advanced_testcase {
    /**
     * A course whose label, page and quiz description use a Generico template.
     */
    protected function setUp(): void {
        parent::setUp();
        foreach (['filter_generico', 'filter_filtercodes'] as $plugin) {
            if (!\core_component::get_component_directory($plugin)) {
                $this->markTestSkipped("$plugin is not installed.");
            }
        }
        $this->resetAfterTest();
        filter_set_global_state('generico', TEXTFILTER_ON);
        filter_set_global_state('filtercodes', TEXTFILTER_ON);
        // A template printing the viewer, with FilterCodes inside it, and
        // alternate content that a web-service request would get instead.
        set_config('templatecount', 20, 'filter_generico');
        set_config('templatekey_1', 'greet', 'filter_generico');
        set_config(
            'template_1',
            '<div class="greet">Hallo @@USER:FIRSTNAME@@ (@@USER:EMAIL@@) {coursename} @@unit@@</div>',
            'filter_generico'
        );
        set_config('templatealternate_1', '<p>ALTERNATE</p>', 'filter_generico');
    }

    public function test_renders_for_a_neutral_learner(): void {
        global $USER;
        $f = learner_course::create($this->getDataGenerator());
        $gen = $this->getDataGenerator();
        $label = $gen->create_module('label', ['course' => $f->course->id,
            'intro' => '<p>{GENERICO:type="greet",unit="2"} – {course_field_unit_1_name}</p>']);
        $page = $gen->create_module('page', ['course' => $f->course->id,
            'content' => '<p>{GENERICO:type="greet",unit="1"}</p><p>Hi {firstname}</p>']);
        $quiz = $gen->create_module('quiz', ['course' => $f->course->id, 'intro' => '<p>{GENERICO:type="greet",unit="3"}</p>']);

        // The service user, by name and address, is who calls in production.
        $service = $gen->create_user(['firstname' => 'Alphabees', 'lastname' => 'Service', 'email' => 'svc@example.com']);
        $this->setAdminUser();
        $admin = $USER;
        $result = external_api::clean_returnvalue(
            get_rendered_course_texts::execute_returns(),
            get_rendered_course_texts::execute($f->course->id)
        );
        $this->assertSame($admin->id, $USER->id, 'acting user restored');

        $modules = array_column($result['modules'], null, 'id');
        $this->assertStringContainsString('Hallo {firstname} ({email}) {coursename} 1', $modules[$page->cmid]['content']);
        $this->assertStringContainsString('Hi {firstname}', $modules[$page->cmid]['content']);
        $this->assertStringContainsString('{course_field_unit_1_name}', $modules[$label->cmid]['description']);
        $this->assertStringContainsString('class="greet"', $modules[$quiz->cmid]['intro']);
        $this->assertArrayNotHasKey($f->modules['page2']->cmid, $modules, 'texts without a tag are not returned');

        $json = json_encode($result);
        $this->assertStringNotContainsString('ALTERNATE', $json);
        foreach ([$admin->firstname, $admin->email, $service->email, 'Guest'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }
}
