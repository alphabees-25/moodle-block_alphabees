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
 * The Alphabees console — an Alphabees surface rendered inside Moodle.
 *
 * Teachers reach this from a notification or the course menu and work here
 * without signing in to the Alphabees portal: Moodle has already established
 * who they are, and a short-lived signed token carries that fact to the
 * backend. Everything this page does happens with the visitor's own Moodle
 * permissions.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_alphabees\local\action_policy;
use block_alphabees\local\console_actions;
use block_alphabees\local\console_token;
use block_alphabees\local\site_registry;

require_once($CFG->dirroot . '/blocks/alphabees/lib.php');

// Files can be newer than the database. Until the plugin upgrade has run the
// console capability does not exist, and every check against it would report a
// plain "no access" — which sends admins looking in the wrong place.
if (!block_alphabees_console_capability_installed()) {
    require_login();
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/blocks/alphabees/console.php'));
    $PAGE->set_title(get_string('console_title', 'block_alphabees'));
    $PAGE->set_heading(format_string($SITE->fullname));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('console_upgrade_pending', 'block_alphabees'),
        \core\output\notification::NOTIFY_WARNING
    );
    if (has_capability('moodle/site:config', context_system::instance())) {
        echo html_writer::div(
            html_writer::link(
                new moodle_url('/admin/index.php'),
                get_string('console_run_upgrade', 'block_alphabees'),
                ['class' => 'btn btn-primary']
            ),
            'mt-2'
        );
    }
    echo $OUTPUT->footer();
    exit;
}

$courseid = optional_param('course', 0, PARAM_INT);
$conversationid = optional_param('conversation', '', PARAM_ALPHANUMEXT);
$caseid = optional_param('case', '', PARAM_ALPHANUMEXT);

$params = array_filter([
    'course' => $courseid ?: null,
    'conversation' => $conversationid ?: null,
    'case' => $caseid ?: null,
]);
$pageurl = new moodle_url('/blocks/alphabees/console.php', $params);

if ($courseid && $courseid != SITEID) {
    // Course-scoped entry: Moodle checks enrolment and course visibility, we
    // check that this person may run the console here specifically.
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id, MUST_EXIST);
    require_capability(action_policy::CAP_CONSOLE, $context);
    $heading = format_string($course->fullname, true, ['context' => $context]);
} else {
    // Site-wide entry with no course named. A teacher holds the capability per
    // course and a manager holds it at system level, so neither single context
    // check would admit both — ask whether they have it anywhere.
    require_login();
    $context = context_system::instance();
    if (!console_actions::has_access($USER->id)) {
        throw new moodle_exception('console_no_access', 'block_alphabees');
    }
    $heading = format_string($SITE->fullname, true, ['context' => $context]);
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout($courseid ? 'incourse' : 'standard');
$PAGE->set_title(get_string('console_title', 'block_alphabees'));
$PAGE->set_heading($heading);
$PAGE->add_body_class('block-alphabees-console');

$token = console_token::issue((int)$USER->id);

echo $OUTPUT->header();

if ($token === null) {
    // No keypair means this site has never completed registration; the console
    // has nothing to authenticate against. Point admins at the fix rather than
    // showing an empty frame.
    echo $OUTPUT->notification(
        get_string('console_not_connected', 'block_alphabees'),
        \core\output\notification::NOTIFY_WARNING
    );
    if (has_capability('moodle/site:config', context_system::instance())) {
        echo html_writer::div(
            html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'blocksettingalphabees']),
                get_string('console_open_settings', 'block_alphabees'),
                ['class' => 'btn btn-secondary']
            ),
            'mt-2'
        );
    }
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(
    $OUTPUT->render_from_template('core/loading', []),
    'text-center py-5',
    ['id' => 'alphabees-console-loading']
);

echo html_writer::div(
    $OUTPUT->notification(
        get_string('console_unavailable', 'block_alphabees'),
        \core\output\notification::NOTIFY_INFO
    ),
    '',
    ['id' => 'alphabees-console-fallback', 'hidden' => 'hidden']
);

echo html_writer::div('', '', ['id' => 'alphabees-console-root']);

$PAGE->requires->js_call_amd('block_alphabees/console', 'init', [
    [
        'token' => $token,
        'siteIdentifier' => site_registry::site_identifier(),
        'backendUrl' => site_registry::backend_url() . site_registry::API_BASE,
        'wwwroot' => $CFG->wwwroot,
        'userId' => (int)$USER->id,
        'courseId' => (int)$courseid,
        'conversationId' => $conversationid,
        'caseId' => $caseid,
        'lang' => current_language(),
        'mount' => '#alphabees-console-root',
    ],
    site_registry::console_bundle_url(),
]);

echo $OUTPUT->footer();
