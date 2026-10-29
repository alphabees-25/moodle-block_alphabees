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

namespace block_alphabees\tests;

/**
 * A course with learners in every state the 3.2.0 functions distinguish.
 *
 * Three students: s1 in group G1 with a completed page and a passed quiz,
 * s2 with a failed quiz and a submitted, ungraded essay, s3 with nothing — so
 * the second page, restricted by completion of the first, is locked for s3.
 * A teacher, course custom fields of all three visibilities, and an activity
 * named by a FilterCodes course-field placeholder.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class learner_course {
    /** @var \stdClass */
    public \stdClass $course;
    /** @var \stdClass[] Students s1, s2, s3. */
    public array $students = [];
    /** @var \stdClass */
    public \stdClass $teacher;
    /** @var \stdClass */
    public \stdClass $group;
    /** @var \stdClass[] Module records by role: page1, page2, assign, quiz, label. */
    public array $modules = [];

    /**
     * Build it.
     *
     * @param \testing_data_generator $gen
     * @return self
     */
    public static function create(\testing_data_generator $gen): self {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $f = new self();
        set_config('enablecompletion', 1);

        $cfgen = $gen->get_plugin_generator('core_customfield');
        $cat = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'name' => 'Units']);
        foreach (['unit_1_name' => 2, 'teacher_note' => 1, 'internal' => 0] as $shortname => $visibility) {
            $cfgen->create_field([
                'categoryid' => $cat->get('id'),
                'type' => 'text',
                'shortname' => $shortname,
                'name' => ucfirst(str_replace('_', ' ', $shortname)),
                'configdata' => ['visibility' => $visibility, 'defaultvalue' => '', 'displaysize' => 50, 'maxlength' => 1333,
                    'ispassword' => 0, 'link' => '', 'locked' => 0, 'uniquevalues' => 0],
            ]);
        }

        $f->course = $gen->create_course([
            'fullname' => 'Wissenschaftliches Arbeiten',
            'enablecompletion' => 1,
            'customfield_unit_1_name' => 'Wissenschaft – was ist das?',
            'customfield_teacher_note' => 'only teachers',
            'customfield_internal' => 'nobody',
        ]);
        $courseid = $f->course->id;

        foreach (['s1', 's2', 's3'] as $name) {
            $f->students[$name] = $gen->create_user(['firstname' => 'Stu' . $name, 'lastname' => 'Dent']);
            $gen->enrol_user($f->students[$name]->id, $courseid, 'student');
        }
        $f->teacher = $gen->create_user(['firstname' => 'Tea', 'lastname' => 'Cher']);
        $gen->enrol_user($f->teacher->id, $courseid, 'editingteacher');
        $f->group = $gen->create_group(['courseid' => $courseid, 'name' => 'G1']);
        $gen->create_group_member(['groupid' => $f->group->id, 'userid' => $f->students['s1']->id]);

        $f->modules['page1'] = $gen->create_module('page', ['course' => $courseid, 'name' => '{course_field_unit_1_name}',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $availability = json_encode(['op' => '&', 'showc' => [true],
            'c' => [['type' => 'completion', 'cm' => (int)$f->modules['page1']->cmid, 'e' => COMPLETION_COMPLETE]]]);
        $f->modules['page2'] = $gen->create_module('page', ['course' => $courseid, 'name' => 'Locked page',
            'completion' => COMPLETION_TRACKING_MANUAL, 'availability' => $availability]);
        $f->modules['assign'] = $gen->create_module('assign', ['course' => $courseid, 'name' => 'Essay',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1, 'grade' => 100,
            'assignsubmission_onlinetext_enabled' => 1]);
        $f->modules['quiz'] = $gen->create_module('quiz', ['course' => $courseid, 'name' => 'Check',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
            'gradepass' => 5, 'grade' => 10]);
        rebuild_course_cache($courseid, true);

        // Grades: essay 80 and 40, quiz 7 for s1.
        grade_update('mod/assign', $courseid, 'mod', 'assign', $f->modules['assign']->id, 0, [
            $f->students['s1']->id => ['userid' => $f->students['s1']->id, 'rawgrade' => 80],
            $f->students['s2']->id => ['userid' => $f->students['s2']->id, 'rawgrade' => 40],
        ]);
        grade_update('mod/quiz', $courseid, 'mod', 'quiz', $f->modules['quiz']->id, 0, [
            $f->students['s1']->id => ['userid' => $f->students['s1']->id, 'rawgrade' => 7],
        ]);

        // Completion as Moodle stores it. Set after the grades, which would
        // otherwise make Moodle recompute the quiz states.
        $state = function (string $module, string $student, int $value) use ($f, $DB): void {
            $conditions = ['coursemoduleid' => $f->modules[$module]->cmid, 'userid' => $f->students[$student]->id];
            $DB->delete_records('course_modules_completion', $conditions);
            $DB->insert_record('course_modules_completion', (object)($conditions + [
                'completionstate' => $value, 'timemodified' => time()]));
        };
        $state('page1', 's1', COMPLETION_COMPLETE);
        $state('quiz', 's1', COMPLETION_COMPLETE_PASS);
        $state('quiz', 's2', COMPLETION_COMPLETE_FAIL);
        $DB->insert_record('assign_submission', (object)['assignment' => $f->modules['assign']->id,
            'userid' => $f->students['s2']->id, 'timecreated' => time(), 'timemodified' => time(), 'status' => 'submitted',
            'groupid' => 0, 'attemptnumber' => 0, 'latest' => 1]);
        rebuild_course_cache($courseid, true);

        return $f;
    }

    /**
     * Add a Completion Progress block instance to the course.
     *
     * @param array $config Block configuration.
     * @return int Block instance id.
     */
    public function add_progress_bar(array $config): int {
        global $DB;
        $id = $DB->insert_record('block_instances', (object)[
            'blockname' => 'completion_progress',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize((object)$config)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        \context_block::instance($id);
        return $id;
    }

    /**
     * Student ids in id order.
     *
     * @return int[]
     */
    public function student_ids(): array {
        $ids = array_map(fn($u) => (int)$u->id, array_values($this->students));
        sort($ids);
        return $ids;
    }
}
