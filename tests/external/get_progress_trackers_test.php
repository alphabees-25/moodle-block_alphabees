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
#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\completion_progress_reader::class)]
/**
 * Tests for block_alphabees_get_progress_trackers and discover_progress_sources.
 *
 * The reference is the Completion Progress block itself, asked as the learner
 * through its own web service: the percentage and every cell must match.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\external\get_progress_trackers
 * @covers    \block_alphabees\external\discover_progress_sources
 * @covers    \block_alphabees\local\completion_progress_reader
 */
final class get_progress_trackers_test extends \externallib_advanced_testcase {
    /** @var learner_course */
    private learner_course $f;
    /** @var int Bar over all activities. */
    private int $all;
    /** @var int Bar over essay and quiz, for group G1 only. */
    private int $selected;

    /**
     * Course with two bars; skipped where the block is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!\core_component::get_component_directory('block_completion_progress')) {
            $this->markTestSkipped('block_completion_progress is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->f = learner_course::create($this->getDataGenerator());
        $this->all = $this->f->add_progress_bar(['activitiesincluded' => 'activitycompletion',
            'orderby' => 'orderbycourse', 'progressTitle' => 'Alles', 'group' => '0']);
        $this->selected = $this->f->add_progress_bar(['activitiesincluded' => 'selectedactivities', 'orderby' => 'orderbycourse',
            'group' => 'group-' . $this->f->group->id,
            'selectactivities' => ['assign-' . $this->f->modules['assign']->id, 'quiz-' . $this->f->modules['quiz']->id]]);
    }

    /**
     * Call the function the way the web service does.
     *
     * @param int $from
     * @param int $num
     * @return array
     */
    private function trackers(int $from = 0, int $num = 0): array {
        $this->setAdminUser();
        $result = get_progress_trackers::execute($this->f->course->id, $from, $num);
        return external_api::clean_returnvalue(get_progress_trackers::execute_returns(), $result);
    }

    /**
     * Learners keyed by id.
     *
     * @param array $result
     * @return array
     */
    private function by_learner(array $result): array {
        return array_column($result['learners'], null, 'userid');
    }

    public function test_numbers_match_the_block_for_each_learner(): void {
        $learners = $this->by_learner($this->trackers());
        $compared = 0;
        foreach ($learners as $userid => $learner) {
            $this->setUser($userid);
            foreach ($learner['trackers'] as $tracker) {
                $block = \block_completion_progress\external\get_blockinstance_data::execute(
                    $this->f->course->id,
                    $tracker['instanceid'],
                    $userid
                );
                $cells = json_decode(json_encode($block), true)['cells'];
                $done = count(array_filter($cells, fn($c) => !empty($c['completed'])));
                $this->assertSame($cells ? (int)round(100 * $done / count($cells)) : null, $tracker['percentage']);

                $theirs = [];
                foreach ($cells as $cell) {
                    $theirs[$cell['activityid']] = !empty($cell['submittednotcomplete']) ? 'submitted'
                        : (!empty($cell['completed']) ? 'done' : 'not');
                }
                $ours = [];
                foreach ($tracker['items'] as $item) {
                    $ours[$item['cmid']] = $item['state'] === 'submitted' ? 'submitted'
                        : (in_array($item['state'], ['complete', 'passed'], true) ? 'done' : 'not');
                }
                $this->assertSame($theirs, $ours, "user $userid, bar {$tracker['instanceid']}");
                $compared++;
            }
        }
        $this->assertSame(4, $compared);
    }

    public function test_states_and_locked_activity(): void {
        $learners = $this->by_learner($this->trackers());
        $m = $this->f->modules;
        $state = fn(int $userid, int $cmid) => array_column($learners[$userid]['trackers'][0]['items'], null, 'cmid')[$cmid];

        $s1 = $this->f->students['s1']->id;
        $s2 = $this->f->students['s2']->id;
        $s3 = $this->f->students['s3']->id;
        $this->assertSame('complete', $state($s1, $m['page1']->cmid)['state']);
        $this->assertSame('passed', $state($s1, $m['quiz']->cmid)['state']);
        $this->assertTrue($state($s1, $m['page2']->cmid)['available']);
        $this->assertSame('failed', $state($s2, $m['quiz']->cmid)['state']);
        $this->assertSame('submitted', $state($s2, $m['assign']->cmid)['state']);
        $this->assertFalse($state($s3, $m['page2']->cmid)['available']);
        $this->assertSame(50, $learners[$s1]['trackers'][0]['percentage']);
    }

    public function test_bars_follow_group_and_selection(): void {
        $result = $this->trackers();
        $learners = $this->by_learner($result);
        $this->assertSame(
            [$this->all, $this->selected],
            array_column($learners[$this->f->students['s1']->id]['trackers'], 'instanceid')
        );
        $this->assertSame([$this->all], array_column($learners[$this->f->students['s2']->id]['trackers'], 'instanceid'));

        $trackers = array_column($result['trackers'], null, 'instanceid');
        $this->assertSame('Alles', $trackers[$this->all]['title']);
        $this->assertSame('selected', $trackers[$this->selected]['mode']);
        $this->assertSame('group-' . $this->f->group->id, $trackers[$this->selected]['group']);
        $this->assertSame(
            [(int)$this->f->modules['assign']->cmid, (int)$this->f->modules['quiz']->cmid],
            array_column($trackers[$this->selected]['activities'], 'cmid')
        );
    }

    public function test_grouping_restriction(): void {
        $gen = $this->getDataGenerator();
        $grouping = $gen->create_grouping(['courseid' => $this->f->course->id]);
        $gen->create_grouping_group(['groupingid' => $grouping->id, 'groupid' => $this->f->group->id]);
        $bar = $this->f->add_progress_bar(['activitiesincluded' => 'activitycompletion', 'orderby' => 'orderbycourse',
            'group' => 'grouping-' . $grouping->id]);

        $learners = $this->by_learner($this->trackers());
        $this->assertContains($bar, array_column($learners[$this->f->students['s1']->id]['trackers'], 'instanceid'));
        $this->assertNotContains($bar, array_column($learners[$this->f->students['s2']->id]['trackers'], 'instanceid'));
    }

    public function test_gradebook_exclusion_removes_the_activity(): void {
        global $DB;
        $s3 = $this->f->students['s3']->id;
        $item = \grade_item::fetch(['courseid' => $this->f->course->id, 'itemmodule' => 'quiz',
            'iteminstance' => $this->f->modules['quiz']->id]);
        $grade = new \grade_grade(['itemid' => $item->id, 'userid' => $s3]);
        $grade->excluded = time();
        $grade->insert();
        $this->assertTrue($DB->record_exists('grade_grades', ['itemid' => $item->id, 'userid' => $s3]));

        $learners = $this->by_learner($this->trackers());
        $cmids = array_column($learners[$s3]['trackers'][0]['items'], 'cmid');
        $this->assertNotContains((int)$this->f->modules['quiz']->cmid, $cmids);
        $this->assertCount(3, $cmids);
    }

    public function test_paging(): void {
        $ids = $this->f->student_ids();
        foreach ([0, 1, 2] as $from) {
            $page = $this->trackers($from, 1);
            $this->assertSame([$ids[$from]], array_column($page['learners'], 'userid'));
            $this->assertSame(3, $page['total_learners']);
        }
        $this->assertSame([], $this->trackers(3, 1)['learners']);
        $this->assertSame($ids, array_column($this->trackers(0, 200)['learners'], 'userid'));
    }

    public function test_activity_names_are_filtered_like_the_learner_sees_them(): void {
        if (!\core_component::get_component_directory('filter_filtercodes')) {
            $this->markTestSkipped('filter_filtercodes is not installed.');
        }
        set_config('filterall', 1);
        filter_set_global_state('filtercodes', TEXTFILTER_ON);
        filter_set_applies_to_strings('filtercodes', true);
        $trackers = array_column($this->trackers()['trackers'], null, 'instanceid');
        $names = array_column($trackers[$this->all]['activities'], 'name', 'cmid');
        $this->assertSame('Wissenschaft – was ist das?', $names[$this->f->modules['page1']->cmid]);
    }

    public function test_course_without_completion_gives_nothing(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            get_progress_trackers::execute_returns(),
            get_progress_trackers::execute($course->id)
        );
        $this->assertFalse($result['completion_enabled']);
        $this->assertSame([], $result['trackers']);
        $this->assertSame([], $result['learners']);
    }

    public function test_discover_counts_without_learner_data(): void {
        $this->setAdminUser();
        $result = external_api::clean_returnvalue(
            discover_progress_sources::execute_returns(),
            discover_progress_sources::execute()
        );
        $sources = array_column($result['sources'], null, 'key');
        $this->assertTrue($sources['completion_progress']['adapter_supported']);
        $this->assertNull($sources['completion_progress']['reason']);
        $this->assertSame(2, $sources['completion_progress']['instances_total']);
        $this->assertSame(1, $sources['completion_progress']['courses_with_instances']);
        $this->assertSame(1, $sources['completion_progress']['instances_selected_mode']);
        $this->assertGreaterThanOrEqual(1, $sources['core_completion']['courses_with_completion']);
        $this->assertStringNotContainsString('userid', json_encode($result));
    }

    public function test_requires_the_service_capability(): void {
        $this->setUser($this->f->teacher);
        $this->expectException(\required_capability_exception::class);
        get_progress_trackers::execute($this->f->course->id);
    }
}
