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
 * Action handlers reachable from a signed-in user's browser session.
 *
 * Everything here runs as the person operating the console, never as the
 * service user. {@see action_policy} has already checked the capability that
 * guards the action; these handlers add the checks that depend on the
 * parameters — that the learner really is in this course, that this teacher is
 * allowed to see grades, that Moodle permits the message.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Console-facing action handlers.
 */
class console_actions {
    /** Longest message a teacher can send in one go. */
    private const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Whether this user may open the console at all.
     *
     * Course-level for teachers, system-level for managers who oversee courses
     * they are not enrolled in. Used by console.php when no course is named,
     * where a plain capability check at one context would lock out one group or
     * the other.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_access(int $userid): bool {
        if (has_capability(action_policy::CAP_CONSOLE, \context_system::instance(), $userid)) {
            return true;
        }
        foreach (enrol_get_users_courses($userid, true, 'id') as $course) {
            $context = \context_course::instance($course->id, IGNORE_MISSING);
            if ($context && has_capability(action_policy::CAP_CONSOLE, $context, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What this person may do and where — the console renders itself from this.
     *
     * @param caller $caller
     * @return array
     */
    public static function context(caller $caller): array {
        $userid = (int)$caller->userid();
        $systemcontext = \context_system::instance();

        $courses = [];
        foreach (enrol_get_users_courses($userid, true, 'id, fullname, shortname') as $course) {
            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$coursecontext || !has_capability(action_policy::CAP_CONSOLE, $coursecontext, $userid)) {
                continue;
            }
            $courses[] = [
                'courseid' => (int)$course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                'shortname' => format_string($course->shortname, true, ['context' => $coursecontext]),
                'cansendmessage' => has_capability('moodle/site:sendmessage', $coursecontext, $userid),
                'canviewgrades' => has_capability('moodle/grade:viewall', $coursecontext, $userid),
            ];
        }

        return [
            'userid' => $userid,
            'fullname' => fullname(\core_user::get_user($userid, '*', MUST_EXIST)),
            'siteidentifier' => site_registry::site_identifier(),
            'courses' => $courses,
            // A manager holding the capability at system level is responsible
            // for courses they are not enrolled in; the list above would be
            // empty for them, so say so explicitly.
            'sitewide' => has_capability(action_policy::CAP_CONSOLE, $systemcontext, $userid),
            'canuseagent' => has_capability(action_policy::CAP_AGENT, $systemcontext, $userid),
            'operations' => action_policy::user_actions(),
        ];
    }

    /**
     * Progress and standing of one learner in one course.
     *
     * @param caller $caller
     * @param array $params courseid, userid.
     * @return array
     * @throws \moodle_exception When the learner is not in this course.
     */
    public static function learner_overview(caller $caller, array $params): array {
        global $CFG, $DB;

        $courseid = (int)($params['courseid'] ?? 0);
        $learnerid = (int)($params['userid'] ?? 0);
        if ($learnerid <= 0) {
            throw new \moodle_exception('console_userid_required', 'block_alphabees');
        }

        $course = get_course($courseid);
        $coursecontext = \context_course::instance($courseid, MUST_EXIST);

        // The capability lets a teacher into this course's console; it does not
        // let them look up somebody who is not in the course.
        if (!is_enrolled($coursecontext, $learnerid, '', false)) {
            throw new \moodle_exception('console_user_not_in_course', 'block_alphabees');
        }
        $learner = \core_user::get_user($learnerid, '*', MUST_EXIST);

        require_once($CFG->libdir . '/completionlib.php');
        $completion = new \completion_info($course);
        $percent = null;
        if ($completion->is_enabled()) {
            $raw = \core_completion\progress::get_course_progress_percentage($course, $learnerid);
            $percent = $raw === null ? null : round((float)$raw, 1);
        }

        // Grades are a separate permission from reading a conversation, so a
        // teacher without it gets the rest of the overview rather than an error.
        $grade = null;
        if (has_capability('moodle/grade:viewall', $coursecontext, $caller->userid())) {
            require_once($CFG->libdir . '/gradelib.php');
            $coursegrade = grade_get_course_grade($learnerid, $courseid);
            if ($coursegrade && $coursegrade->grade !== null) {
                $grade = [
                    'value' => (float)$coursegrade->grade,
                    'formatted' => (string)($coursegrade->str_grade ?? ''),
                ];
            }
        }

        $lastaccess = $DB->get_field('user_lastaccess', 'timeaccess', [
            'userid' => $learnerid,
            'courseid' => $courseid,
        ]);

        return [
            'courseid' => $courseid,
            'userid' => $learnerid,
            'fullname' => fullname($learner),
            'completionpercent' => $percent,
            'completionenabled' => $completion->is_enabled() ? true : false,
            'grade' => $grade,
            'lastaccess' => $lastaccess ? (int)$lastaccess : 0,
            'profileurl' => (new \moodle_url('/user/view.php', [
                'id' => $learnerid,
                'course' => $courseid,
            ]))->out(false),
        ];
    }

    /**
     * Send a Moodle message from the operating teacher to a learner.
     *
     * Deliberately a real Moodle message rather than something of ours: it
     * lands in the learner's normal inbox, is attributed to the teacher, and
     * obeys the learner's own messaging and blocking settings.
     *
     * @param caller $caller
     * @param array $params courseid, userid, message.
     * @return array Envelope { httpStatus: int, body: array }.
     */
    public static function send_user_message(caller $caller, array $params): array {
        $senderid = (int)$caller->userid();
        $courseid = (int)($params['courseid'] ?? 0);
        $recipientid = (int)($params['userid'] ?? 0);
        $text = trim((string)($params['message'] ?? ''));

        if ($recipientid <= 0 || $text === '') {
            return ['httpStatus' => 400, 'body' => ['ok' => false, 'code' => 'message_incomplete']];
        }
        if (\core_text::strlen($text) > self::MAX_MESSAGE_LENGTH) {
            return ['httpStatus' => 400, 'body' => ['ok' => false, 'code' => 'message_too_long']];
        }
        if ($recipientid === $senderid) {
            return ['httpStatus' => 400, 'body' => ['ok' => false, 'code' => 'message_to_self']];
        }

        $coursecontext = \context_course::instance($courseid, MUST_EXIST);
        require_capability('moodle/site:sendmessage', $coursecontext, $senderid);

        if (!is_enrolled($coursecontext, $recipientid, '', false)) {
            return ['httpStatus' => 403, 'body' => ['ok' => false, 'code' => 'user_not_in_course']];
        }
        if (!\core_message\api::can_send_message($recipientid, $senderid)) {
            return ['httpStatus' => 403, 'body' => ['ok' => false, 'code' => 'messaging_not_permitted']];
        }

        $conversationid = \core_message\api::get_conversation_between_users([$senderid, $recipientid]);
        if (!$conversationid) {
            $conversation = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                [$senderid, $recipientid]
            );
            $conversationid = (int)$conversation->id;
        }

        $message = \core_message\api::send_message_to_conversation(
            $senderid,
            (int)$conversationid,
            clean_param($text, PARAM_TEXT),
            FORMAT_MOODLE
        );

        return ['httpStatus' => 200, 'body' => [
            'ok' => true,
            'messageid' => (int)($message->id ?? 0),
            'conversationid' => (int)$conversationid,
        ]];
    }
}
