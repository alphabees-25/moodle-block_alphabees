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

/**
 * Which parts of a course one learner cannot open.
 *
 * The backend syncs a course with the service token, which sees everything,
 * so its knowledge base holds hidden and restricted parts as well. Its own
 * reading of the access rules knows dates and completion only, and for
 * sections core's web services hand out no rule at all — just uservisible
 * for the token's own user, which is always true. A restriction by profile
 * field (a "Studienmodell" containing _FIX or _FLEX), by group or by grade
 * therefore reached the learner through the agent's answers.
 *
 * So Moodle decides, not the backend: get_fast_modinfo() for the learner
 * evaluates every rule — visibility, every availability condition, the
 * section an activity sits in, and the learner's capabilities to see past
 * them — exactly as the course page does for that person.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Blocked activities and sections for one learner in one course.
 */
class user_access {
    /**
     * What the learner cannot open.
     *
     * An activity counts as blocked when Moodle would not let the learner
     * open it (uservisible false): hidden, restricted, shown greyed out with
     * a restriction note, or sitting in a section that is itself blocked.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return array{blocked_cmids: int[], blocked_sections: array}
     */
    public static function blocked(\stdClass $course, int $userid): array {
        $modinfo = get_fast_modinfo($course, $userid);

        $blockedsections = [];
        $blockedsectionnums = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                $blockedsections[] = ['id' => (int)$section->id, 'section' => (int)$section->section];
                $blockedsectionnums[(int)$section->section] = true;
            }
        }

        $blockedcmids = [];
        foreach ($modinfo->get_cms() as $cm) {
            // The cm's uservisible already includes the section's restriction;
            // the section check covers a hidden section whose activities were
            // not hidden along with it.
            if (!$cm->uservisible || isset($blockedsectionnums[(int)$cm->sectionnum])) {
                $blockedcmids[] = (int)$cm->id;
            }
        }
        sort($blockedcmids);

        return ['blocked_cmids' => $blockedcmids, 'blocked_sections' => $blockedsections];
    }
}
