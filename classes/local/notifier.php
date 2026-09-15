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
 * Sends Moodle notifications on behalf of the Alphabees backend.
 *
 * The backend decides *whether* to notify and, knowing tenant policy and each
 * teacher's own participation setting, *whom* to leave out. It cannot decide
 * whom to add: every recipient it names is checked here against the course
 * capability before anything is sent, so the backend can narrow the audience
 * but never widen it.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Notification dispatch for backend-triggered events.
 */
class notifier {
    /** Notifications about other people's conversations — for course staff. */
    public const AUDIENCE_STAFF = 'staff';

    /** Notifications about your own learning — for the learner themselves. */
    public const AUDIENCE_LEARNER = 'learner';

    /**
     * Message providers declared in db/messages.php, and who each one addresses.
     *
     * The audience decides which capability a recipient must hold, and where
     * clicking the notification takes them: staff land in the console, a
     * learner lands back in the course with the tutor opened on the thing the
     * notification was about.
     *
     * @var array<string, string>
     */
    private const PROVIDER_AUDIENCE = [
        'learner_question' => self::AUDIENCE_STAFF,
        'escalation' => self::AUDIENCE_STAFF,
        'digest' => self::AUDIENCE_STAFF,
        'exercise_assigned' => self::AUDIENCE_LEARNER,
        'tutor_reply' => self::AUDIENCE_LEARNER,
    ];

    /** Message providers declared in db/messages.php. */
    public const PROVIDERS = [
        'learner_question',
        'escalation',
        'digest',
        'exercise_assigned',
        'tutor_reply',
    ];

    /** Never page more than this many people for one event. */
    private const MAX_RECIPIENTS = 200;

    /** URL parameter the tutor block reads to open something specific. */
    public const DEEPLINK_PARAM = 'alphabees';

    /** How long a dedup key blocks a repeat, in seconds. */
    private const DEDUP_TTL = 6 * HOURSECS;

    /**
     * Deliver one notification event to the responsible people in a course.
     *
     * @param array $params provider, courseid, subject, message, and optionally
     *                      userids, contexturl, customdata, dedupkey.
     * @return array { sent: int, candidates: int, rejected: int, deduplicated: bool }
     * @throws \moodle_exception On unusable input.
     */
    public static function send(array $params): array {
        global $DB;

        $provider = isset($params['provider']) ? clean_param((string)$params['provider'], PARAM_ALPHANUMEXT) : '';
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new \moodle_exception('notify_unknown_provider', 'block_alphabees', '', $provider);
        }

        $courseid = isset($params['courseid']) ? (int)$params['courseid'] : 0;
        if ($courseid <= 0 || $courseid == SITEID) {
            throw new \moodle_exception('notify_courseid_required', 'block_alphabees');
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);
        $context = \context_course::instance($courseid, MUST_EXIST);
        $audience = self::PROVIDER_AUDIENCE[$provider];

        $subject = trim((string)($params['subject'] ?? ''));
        $body = trim((string)($params['message'] ?? $params['body'] ?? ''));
        if ($subject === '' || $body === '') {
            throw new \moodle_exception('notify_content_required', 'block_alphabees');
        }
        $subject = clean_param($subject, PARAM_TEXT);
        $body = clean_param($body, PARAM_TEXT);

        // A repeat of an event we already delivered is dropped rather than
        // paging everyone twice. Cheap insurance against backend retries.
        $dedupkey = trim((string)($params['dedupkey'] ?? ''));
        if ($dedupkey !== '' && self::already_sent($dedupkey)) {
            return ['sent' => 0, 'candidates' => 0, 'rejected' => 0, 'deduplicated' => true];
        }

        $candidates = self::resolve_recipients($params, $context, $audience);
        $capability = $audience === self::AUDIENCE_LEARNER
            ? action_policy::CAP_LEARNER_NOTIFY
            : action_policy::CAP_NOTIFY;

        $recipients = [];
        $rejected = 0;
        foreach ($candidates as $userid) {
            // A learner-addressed message additionally requires an active
            // enrolment: holding the capability through a system-level role is
            // not a reason to receive somebody's course exercise.
            $eligible = has_capability($capability, $context, $userid)
                && ($audience !== self::AUDIENCE_LEARNER || is_enrolled($context, $userid, '', true));
            if ($eligible) {
                $recipients[] = $userid;
            } else {
                $rejected++;
            }
        }
        $recipients = array_slice(array_values(array_unique($recipients)), 0, self::MAX_RECIPIENTS);

        $sent = 0;
        foreach ($recipients as $userid) {
            if (self::send_one($provider, $audience, $userid, $course, $subject, $body, $params)) {
                $sent++;
            }
        }

        if ($dedupkey !== '' && $sent > 0) {
            self::record_sent($dedupkey, $provider, $courseid, $sent);
        }

        return [
            'sent' => $sent,
            'candidates' => count($candidates),
            'rejected' => $rejected,
            'deduplicated' => false,
        ];
    }

    /**
     * Work out who should be considered for this event.
     *
     * When the backend names recipients we take that list as an upper bound and
     * verify it. When it does not, we fall back to the people actively enrolled
     * in the course who hold the notification capability.
     *
     * @param array $params
     * @param \context_course $context
     * @param string $audience
     * @return int[] Candidate user ids.
     * @throws \moodle_exception When a learner message names nobody.
     */
    private static function resolve_recipients(array $params, \context_course $context, string $audience): array {
        $given = $params['userids'] ?? null;
        if (is_array($given) && !empty($given)) {
            return array_values(array_filter(array_map('intval', $given), static function (int $id): bool {
                return $id > 0;
            }));
        }

        // Falling back to "everyone responsible for the course" makes sense for
        // staff. A learner message is addressed to one person by definition, so
        // an empty list is a mistake rather than a broadcast.
        if ($audience === self::AUDIENCE_LEARNER) {
            throw new \moodle_exception('notify_recipients_required', 'block_alphabees');
        }

        $users = get_enrolled_users(
            $context,
            action_policy::CAP_NOTIFY,
            0,
            'u.id',
            'u.id ASC',
            0,
            self::MAX_RECIPIENTS,
            true
        );
        return array_map('intval', array_keys($users));
    }

    /**
     * Build and send one notification.
     *
     * @param string $provider
     * @param int $userid
     * @param \stdClass $course
     * @param string $subject
     * @param string $body
     * @param array $params
     * @return bool Whether Moodle accepted the message.
     */
    private static function send_one(
        string $provider,
        string $audience,
        int $userid,
        \stdClass $course,
        string $subject,
        string $body,
        array $params
    ): bool {
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return false;
        }

        $url = self::resolve_contexturl($params, (int)$course->id, $audience);

        $message = new \core\message\message();
        $message->component = 'block_alphabees';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($body));
        $message->smallmessage = \core_text::substr($body, 0, 160);
        $message->notification = 1;
        $message->courseid = (int)$course->id;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $audience === self::AUDIENCE_LEARNER
            ? get_string('notify_open_tutor', 'block_alphabees')
            : get_string('console_open', 'block_alphabees');

        // Rides along to the mobile app and the notification popover so the
        // console can open the exact case without a second round trip.
        $customdata = [
            'provider' => $provider,
            'audience' => $audience,
            'courseid' => (int)$course->id,
        ];
        foreach (['conversationid', 'caseid', 'placementuuid', 'deeplink'] as $key) {
            if (!empty($params[$key])) {
                $customdata[$key] = clean_param((string)$params[$key], PARAM_TEXT);
            }
        }
        $message->customdata = $customdata;

        return (bool)message_send($message);
    }

    /**
     * Where clicking the notification should land.
     *
     * Only our own console page is ever used as a target — a URL supplied from
     * outside would make the notification a redirect gadget.
     *
     * @param array $params
     * @param int $courseid
     * @param string $audience
     * @return \moodle_url
     */
    private static function resolve_contexturl(array $params, int $courseid, string $audience): \moodle_url {
        if ($audience === self::AUDIENCE_LEARNER) {
            // Learners have no console. Send them to the course page that hosts
            // the tutor, carrying a reference the widget resolves — the exercise
            // it should open, or the answered thread.
            $query = ['id' => $courseid];
            $reference = $params['deeplink'] ?? $params['conversationid'] ?? '';
            if ($reference !== '') {
                $query[self::DEEPLINK_PARAM] = clean_param((string)$reference, PARAM_ALPHANUMEXT);
            }
            return new \moodle_url('/course/view.php', $query);
        }

        $query = ['course' => $courseid];
        foreach (['conversationid' => 'conversation', 'caseid' => 'case'] as $param => $key) {
            if (!empty($params[$param])) {
                $query[$key] = clean_param((string)$params[$param], PARAM_ALPHANUMEXT);
            }
        }
        return new \moodle_url('/blocks/alphabees/console.php', $query);
    }

    /**
     * Whether this event was already delivered inside the dedup window.
     *
     * @param string $dedupkey
     * @return bool
     */
    private static function already_sent(string $dedupkey): bool {
        global $DB;
        return $DB->record_exists_select(
            'block_alphabees_notifylog',
            'dedupkey = :key AND timecreated > :since',
            ['key' => self::hash_key($dedupkey), 'since' => time() - self::DEDUP_TTL]
        );
    }

    /**
     * Remember that this event was delivered.
     *
     * @param string $dedupkey
     * @param string $provider
     * @param int $courseid
     * @param int $recipients
     * @return void
     */
    private static function record_sent(string $dedupkey, string $provider, int $courseid, int $recipients): void {
        global $DB;
        try {
            $DB->insert_record('block_alphabees_notifylog', (object)[
                'dedupkey' => self::hash_key($dedupkey),
                'provider' => $provider,
                'courseid' => $courseid,
                'recipients' => $recipients,
                'timecreated' => time(),
            ]);
        } catch (\dml_exception $e) {
            // A concurrent delivery won the unique index. The event went out
            // once, which is the outcome we wanted.
            unset($e);
        }
    }

    /**
     * Normalise a caller-supplied key to a fixed-width column value.
     *
     * @param string $dedupkey
     * @return string
     */
    private static function hash_key(string $dedupkey): string {
        return hash('sha256', $dedupkey);
    }

    /**
     * Drop dedup rows that can no longer suppress anything.
     *
     * @return int Rows removed.
     */
    public static function purge_expired_log(): int {
        global $DB;
        $cutoff = time() - self::DEDUP_TTL;
        $count = $DB->count_records_select('block_alphabees_notifylog', 'timecreated <= :cutoff', ['cutoff' => $cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('block_alphabees_notifylog', 'timecreated <= :cutoff', ['cutoff' => $cutoff]);
        }
        return $count;
    }
}
