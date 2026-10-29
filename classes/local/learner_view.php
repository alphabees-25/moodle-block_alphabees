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
 * Renders course text the way a learner's page shows it, but for nobody.
 *
 * A web-service read does not run filters, so template filters arrive as tags
 * ({GENERICO:type="..."}), and asking Moodle to filter makes it render as the
 * service user — whose name, and whose blank progress, then stand in the text.
 *
 * Here the filters of the text's context run as they would on the course
 * page, with three differences:
 *  - The viewer is a neutral one: the guest account's id, so no enrolment,
 *    no completion and no grade belongs to it, with the identity fields a
 *    template may print replaced by FilterCodes-style markers ({firstname},
 *    {email} ...). Whoever reads the result fills or marks them per learner.
 *  - FilterCodes itself is left out, so its own tags reach the reader as tags
 *    for the same reason.
 *  - The page is the course page, not the web-service script: Generico, for
 *    one, hands a web-service request its "alternate" content instead.
 *
 * File links are rewritten as the web service does, so they stay fetchable
 * with the token.
 *
 * Names (activity, grade item, bar title) take the same course page and the
 * same neutral viewer through on_course_page(), but keep FilterCodes: on sites
 * that put placeholders such as {course_field_unit_1_name} into names, the
 * learner reads the value. FilterCodes resolves course fields from the page's
 * course and blanks those the viewer may not see, so on the web-service script
 * — whose page is the front page — they came out empty, and as the service
 * user teacher-only fields would have shown.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Formats text as a neutral learner would see it.
 */
class learner_view {
    /** Filters left out, so their tags reach the reader unexpanded. */
    public const SKIPPED_FILTERS = ['filtercodes'];

    /** User fields replaced by markers, each with the FilterCodes tag it becomes. */
    private const MARKERS = [
        'firstname' => '{firstname}',
        'lastname' => '{lastname}',
        'firstnamephonetic' => '',
        'lastnamephonetic' => '',
        'middlename' => '',
        'alternatename' => '{alternatename}',
        'email' => '{email}',
        'username' => '{username}',
        'idnumber' => '{idnumber}',
        'institution' => '{institution}',
        'department' => '{department}',
        'city' => '{city}',
        'country' => '',
        'phone1' => '',
        'phone2' => '',
        'address' => '',
        'description' => '',
    ];

    /**
     * Run $fn with the neutral viewer on the course page of $course.
     *
     * Restores the previous user and page afterwards, also on failure.
     *
     * @param \stdClass $course
     * @param callable $fn
     * @return mixed What $fn returns.
     */
    public static function as_neutral_learner(\stdClass $course, callable $fn) {
        return self::run($course, self::SKIPPED_FILTERS, $fn);
    }

    /**
     * Run $fn on the course page as the neutral viewer, all filters active.
     *
     * For computations that format names as they go (the Completion Progress
     * block, the grade report). Everything they format comes out as a learner
     * reads it, FilterCodes included. Run the whole computation inside, not
     * just a second formatting pass: FilterCodes caches the course fields of
     * the first course it sees in a request, so a name formatted earlier on
     * the front page would poison every later one.
     *
     * Escaping is up to the caller; format_string() with escape off also keys
     * its cache apart from calls made outside.
     *
     * @param \stdClass $course
     * @param callable $fn
     * @return mixed What $fn returns.
     */
    public static function on_course_page(\stdClass $course, callable $fn) {
        return self::run($course, [], $fn);
    }

    /**
     * Run $fn with the neutral viewer on the course page, skipping some filters.
     *
     * @param \stdClass $course
     * @param string[] $skip
     * @param callable $fn
     * @return mixed
     */
    private static function run(\stdClass $course, array $skip, callable $fn) {
        global $PAGE, $USER;

        $previoususer = $USER;
        $previouspage = $PAGE;

        $viewer = clone guest_user();
        foreach (self::MARKERS as $field => $marker) {
            $viewer->$field = $marker;
        }

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));

        // A web-service request does not filter unless the client asks for
        // it; the whole point here is to filter.
        $settings = \core_external\external_settings::get_instance();
        $previousfilter = $settings->get_filter();
        $settings->set_filter(true);

        \core\session\manager::set_user($viewer);
        $PAGE = $page;
        try {
            return skipping_filter_manager::with_skipped($skip, $fn);
        } finally {
            $PAGE = $previouspage;
            \core\session\manager::set_user($previoususer);
            $settings->set_filter($previousfilter);
        }
    }

    /**
     * Format one text. Call inside as_neutral_learner().
     *
     * @param string|null $text Raw text from the database.
     * @param int $format FORMAT_*.
     * @param \context $context The text's own context.
     * @param string $component Component of its file area.
     * @param string $filearea File area.
     * @param int|null $itemid File area item id.
     * @return string
     */
    public static function format(
        ?string $text,
        int $format,
        \context $context,
        string $component,
        string $filearea,
        ?int $itemid
    ): string {
        [$formatted] = \core_external\util::format_text(
            $text,
            $format,
            $context,
            $component,
            $filearea,
            $itemid
        );
        return (string)$formatted;
    }
}
