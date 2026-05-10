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
 * External service definition for the Alphabees AI Tutor block plugin.
 *
 * Pre-declares an external service with shortname `block_alphabees` so the
 * plugin's auto-setup flow only needs to enable web-services + REST and
 * generate a token — no manual service creation.
 *
 * The function list bundles the standard Moodle core functions the
 * Alphabees backend uses to read course / module / enrolment data, plus
 * the writeable functions used during course-export (Option B in the
 * legacy setup guide). Customers who don't want export simply ignore the
 * write paths — Moodle gates each function by capability, not by
 * service-membership.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The display name intentionally differs from the bare "Alphabees" used by
// the legacy 11-step manual setup guide. external_services.name is unique-
// indexed, and many existing customer sites still have a manually-created
// 'Alphabees' service from before this plugin shipped its auto-setup. Using
// a distinct name here lets the plugin install on those sites without
// hitting a duplicate-key violation; the manual service can stay until the
// admin removes it themselves.
$services = [
    'Alphabees AI Tutor' => [
        'shortname' => 'block_alphabees',
        'enabled' => 1,
        'restrictedusers' => 1,
        'downloadfiles' => 1,
        'uploadfiles' => 1,
        'functions' => [
            // Read paths — backend pulls course content into the knowledge base.
            'core_webservice_get_site_info',
            'core_course_get_courses',
            'core_course_get_courses_by_field',
            'core_course_get_contents',
            'core_course_get_categories',
            'mod_page_get_pages_by_courses',
            'core_enrol_get_users_courses',
            'core_enrol_get_enrolled_users',

            // User profiles — id, name, email, custom fields, last access.
            'core_user_get_users_by_field',
            'core_user_get_course_user_profiles',

            // Grades — per-user grade items, course-level overview, raw grades.
            'gradereport_user_get_grade_items',
            'gradereport_overview_get_course_grades',
            'core_grades_get_grades',
            'mod_assign_get_grades',
            'mod_quiz_get_user_attempts',

            // Activity / course completion tracking.
            'core_completion_get_activities_completion_status',
            'core_completion_get_course_completion_status',

            // Groups — for filtering enrolment lists by class/cohort.
            'core_group_get_course_groups',
            'core_group_get_course_user_groups',

            // Write paths — backend pushes generated courses into Moodle.
            'core_course_create_courses',
            'core_course_update_courses',
            'core_course_delete_modules',
            'core_update_inplace_editable',
            'enrol_manual_enrol_users',
        ],
    ],
];
