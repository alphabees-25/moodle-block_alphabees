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
 * Message providers for block_alphabees.
 *
 * Five providers rather than one, because they carry different urgency and
 * address two different audiences. A recipient can keep the urgent ones on the
 * phone while reading the summary by mail.
 *
 * Defaults use `MESSAGE_PERMITTED` and `MESSAGE_DEFAULT_ENABLED` only. The
 * older `MESSAGE_DEFAULT_LOGGEDIN` / `MESSAGE_DEFAULT_LOGGEDOFF` pair split the
 * default by whether the recipient was online; Moodle dropped that distinction
 * and the constants no longer exist, so using them makes the plugin upgrade
 * abort with "Undefined constant" while Moodle reads this file.
 *
 * None of the providers declares a `capability`. That key is evaluated against
 * the system context, and the capabilities that actually govern these
 * notifications — block/alphabees:receivenotifications and
 * :receivelearnernotifications — are held in course context. Declaring them
 * would hide the channel settings from exactly the people who receive the
 * messages. Who actually gets notified is decided at send time in
 * \block_alphabees\local\notifier, which checks the capability in the course
 * the event belongs to.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [

    // A learner is waiting for a human in one of your courses.
    'learner_question' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // The tutor could not resolve something and handed it over deliberately.
    // Same channels, separate provider so it can be routed differently.
    'escalation' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // Addressed to a learner: a teacher sent you an exercise through the tutor.
    'exercise_assigned' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // Addressed to a learner: a human answered the question you asked the tutor.
    'tutor_reply' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // Periodic roll-up of what happened in your courses. Never urgent, so the
    // bell stays available but off until somebody asks for it.
    'digest' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
