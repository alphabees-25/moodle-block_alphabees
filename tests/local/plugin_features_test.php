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



#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\plugin_features::class)]
/**
 * Tests for the feature list reported to the backend.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\plugin_features
 */
final class plugin_features_test extends \advanced_testcase {
    public function test_lists_features_and_version(): void {
        $features = plugin_features::list();
        $expected = ['course_completions.paged', 'course_fields', 'course_grades', 'function_schemas', 'progress_sources',
            'rendered_course_texts', 'user_access'];
        foreach ($expected as $f) {
            $this->assertContains($f, $features);
        }
        $installed = (bool)\core_component::get_component_directory('block_completion_progress');
        $this->assertSame($installed, in_array('progress_trackers.completion_progress', $features, true));
        $version = plugin_features::version();
        $this->assertSame((int)get_config('block_alphabees', 'version'), $version['version']);
        $this->assertNotSame('', $version['release']);
    }
}
