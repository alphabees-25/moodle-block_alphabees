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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\get_progress_trackers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\external\discover_progress_sources::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\plugin_features::class)]
/**
 * The progress functions on a site without the Completion Progress block.
 *
 * Runs only where the block is absent; get_progress_trackers_test covers the
 * other case. A site's test run covers one of the two.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_progress_trackers
 * @covers    \block_alphabees\external\discover_progress_sources
 * @covers    \block_alphabees\local\plugin_features
 */
final class progress_without_block_test extends \externallib_advanced_testcase {
    public function test_reports_not_installed_and_computes_nothing(): void {
        if (\core_component::get_component_directory('block_completion_progress')) {
            $this->markTestSkipped('block_completion_progress is installed; covered by get_progress_trackers_test.');
        }
        $this->resetAfterTest();
        $f = learner_course::create($this->getDataGenerator());
        $this->setAdminUser();

        $sources = external_api::clean_returnvalue(
            discover_progress_sources::execute_returns(),
            discover_progress_sources::execute()
        );
        $cp = array_column($sources['sources'], null, 'key')['completion_progress'];
        $this->assertFalse($cp['installed']);
        $this->assertFalse($cp['adapter_supported']);
        $this->assertSame('not_installed', $cp['reason']);
        $this->assertSame(0, $cp['instances_total']);

        $result = external_api::clean_returnvalue(
            get_progress_trackers::execute_returns(),
            get_progress_trackers::execute($f->course->id)
        );
        $this->assertFalse($result['adapter_supported']);
        $this->assertSame([], $result['trackers']);
        $this->assertSame([], $result['learners']);
        $this->assertNotContains('progress_trackers.completion_progress', \block_alphabees\local\plugin_features::list());
    }
}
