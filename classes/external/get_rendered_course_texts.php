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
 * The course's texts as a learner's page renders them, for no one in particular.
 *
 * Template filters such as Generico keep their text in the site settings; a
 * web-service read returns only the tag. This renders every section summary
 * and every module text that contains a filter tag, through
 * local\learner_view: the filters of the text's context, FilterCodes left
 * out, a neutral viewer instead of the service user.
 *
 * Only texts containing a tag are returned — the backend uses no other — so a
 * large course costs one pass over its texts and a render of the few with tags.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\learner_view;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_get_rendered_course_texts.
 */
class get_rendered_course_texts extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id.'),
        ]);
    }

    /**
     * Render the course's tagged texts.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        $course = get_course($params['courseid']);
        $modinfo = get_fast_modinfo($course, -1);
        $coursecontext = \context_course::instance($course->id);

        // Collect first, render afterwards in one neutral-viewer block.
        $jobs = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            if (self::has_tag($section->summary)) {
                $jobs[] = ['section', (int)$section->id, 'summary', $section->summary, (int)$section->summaryformat,
                    $coursecontext, 'course', 'section', (int)$section->id];
            }
        }

        $bymod = [];
        foreach ($modinfo->get_cms() as $cm) {
            $bymod[$cm->modname][(int)$cm->instance] = $cm;
        }
        foreach ($bymod as $modname => $cms) {
            if (!plugin_supports('mod', $modname, FEATURE_MOD_INTRO, true)) {
                continue;
            }
            $fields = 'id, intro, introformat' . ($modname === 'page' ? ', content, contentformat, revision' : '');
            $records = $DB->get_records_list($modname, 'id', array_keys($cms), '', $fields);
            foreach ($records as $record) {
                $cm = $cms[(int)$record->id];
                $context = \context_module::instance($cm->id);
                if ($modname === 'page' && self::has_tag($record->content)) {
                    $jobs[] = ['module', (int)$cm->id, 'content', $record->content, (int)$record->contentformat,
                        $context, 'mod_page', 'content', (int)$record->revision];
                }
                if (self::has_tag($record->intro)) {
                    // On the course page a label shows its intro as its body.
                    $key = $modname === 'label' ? 'description' : 'intro';
                    $jobs[] = ['module', (int)$cm->id, $key, $record->intro, (int)$record->introformat,
                        $context, 'mod_' . $modname, 'intro', null];
                }
            }
        }

        $rendered = learner_view::as_neutral_learner($course, static function () use ($jobs): array {
            $out = [];
            foreach ($jobs as [$kind, $id, $key, $text, $format, $context, $component, $filearea, $itemid]) {
                $out[] = [$kind, $id, $key, learner_view::format($text, $format, $context, $component, $filearea, $itemid)];
            }
            return $out;
        });

        $sections = [];
        $modules = [];
        foreach ($rendered as [$kind, $id, $key, $html]) {
            if ($kind === 'section') {
                $sections[] = ['id' => $id, $key => $html];
            } else {
                $modules[$id] = ($modules[$id] ?? ['id' => $id]) + [$key => $html];
            }
        }

        return ['sections' => $sections, 'modules' => array_values($modules)];
    }

    /**
     * Whether a text contains something a filter could expand.
     *
     * @param string|null $text
     * @return bool
     */
    private static function has_tag(?string $text): bool {
        return $text !== null && strpos($text, '{') !== false && strpos($text, '}') !== false;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sections' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section id.'),
                'summary' => new external_value(PARAM_RAW, 'Summary, rendered.'),
            ])),
            'modules' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course module id.'),
                'description' => new external_value(PARAM_RAW, 'Label body as on the course page, rendered.', VALUE_OPTIONAL),
                'content' => new external_value(PARAM_RAW, 'Page content, rendered.', VALUE_OPTIONAL),
                'intro' => new external_value(PARAM_RAW, 'Activity description, rendered.', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
