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

#[\PHPUnit\Framework\Attributes\CoversClass(\block_alphabees\local\page_context::class)]
/**
 * Tests for naming the open activity.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \block_alphabees\local\page_context
 */
final class page_context_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $learner;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        // The lesson and H5P generators need someone signed in.
        $this->setAdminUser();
    }

    /**
     * A page as an activity script sets it up.
     *
     * @param \stdClass|null $module Generator result, null for a page without a course module.
     * @param string $pagetype
     * @return \moodle_page
     */
    private function page(?\stdClass $module, string $pagetype): \moodle_page {
        $page = new \moodle_page();
        $page->set_course($this->course);
        if ($module) {
            $page->set_cm(get_coursemodule_from_id('', $module->cmid, 0, false, MUST_EXIST));
        } else {
            $page->set_context(\context_course::instance($this->course->id));
        }
        $page->set_pagetype($pagetype);
        return $page;
    }

    public function test_an_open_activity_is_named(): void {
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $this->course->id, 'name' => 'Systemtheorie']);
        $this->setUser($this->learner);

        $context = page_context::of_page($this->page($lesson, 'mod-lesson-view'));
        $this->assertSame((int)$lesson->cmid, $context['cmid']);
        $this->assertSame('lesson', $context['modtype']);
        $this->assertSame((int)$lesson->id, $context['activityid']);
        $this->assertSame('Systemtheorie', $context['activityname']);
        $this->assertGreaterThan(0, $context['sectionid']);
        $this->assertSame('open', $context['activitystate']);
        $this->assertSame('', $context['activityreason']);
        $this->assertSame(2, $context['contextversion']);
    }

    public function test_a_course_page_has_no_activity(): void {
        $this->setUser($this->learner);
        $context = page_context::of_page($this->page(null, 'course-view-topics'));
        $this->assertSame(0, $context['cmid']);
        $this->assertSame('none', $context['activitystate']);
        $this->assertSame('', $context['activityreason']);
    }

    public function test_an_activity_page_without_a_course_module_says_so(): void {
        $this->setUser($this->learner);
        $context = page_context::of_page($this->page(null, 'mod-forum-discuss'));
        $this->assertSame(0, $context['cmid']);
        $this->assertSame(['unknown', 'no_cm'], [$context['activitystate'], $context['activityreason']]);
    }

    public function test_a_hidden_activity_is_neither_named_nor_numbered(): void {
        $hidden = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id, 'name' => 'Geheim', 'visible' => 0]
        );
        $this->setUser($this->learner);

        $context = page_context::of_page($this->page($hidden, 'mod-page-view'));
        $this->assertSame(0, $context['cmid']);
        $this->assertSame('', $context['activityname']);
        $this->assertSame('', $context['modtype']);
        $this->assertSame(['unknown', 'cm_not_visible'], [$context['activitystate'], $context['activityreason']]);
    }

    public function test_the_app_path_checks_the_named_module_for_the_user(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Offen']);
        $hidden = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'visible' => 0]);

        $context = page_context::of_cmid((int)$page->cmid, (int)$this->learner->id);
        $this->assertSame(
            ['open', (int)$page->cmid, 'Offen'],
            [$context['activitystate'], $context['cmid'], $context['activityname']]
        );

        $context = page_context::of_cmid((int)$hidden->cmid, (int)$this->learner->id);
        $this->assertSame(
            ['unknown', 'cm_not_visible', 0],
            [$context['activitystate'], $context['activityreason'], $context['cmid']]
        );

        $context = page_context::of_cmid(999999, (int)$this->learner->id);
        $this->assertSame(['unknown', 'no_cm'], [$context['activitystate'], $context['activityreason']]);

        $this->assertSame('none', page_context::of_cmid(0, (int)$this->learner->id)['activitystate']);
    }
}
