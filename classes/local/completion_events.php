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
 * Tells the backend when a learner's activity completion changes.
 *
 * Until now the backend learned about completions only by fetching them,
 * live or at night. Moodle triggers \core\event\course_module_completion_updated
 * on every change of state — completing, passing, failing, an override, an
 * undo — with the new state in the event, so no query is needed to report it.
 *
 * Every change is reported, not only "completed": an override back to
 * incomplete matters as much. The observer only writes a row to an outbox and
 * makes sure one delivery task is queued; that task sends the rows in batches.
 * On a site with a quarter of a million enrolments, one HTTP call per
 * completion would be the wrong shape.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Outbox of completion changes and their delivery.
 */
class completion_events {
    /** Outbox table. */
    public const TABLE = 'block_alphabees_outbox';

    /** Outbox kind for completion changes. */
    public const KIND = 'completion';

    /** Events per POST. */
    public const BATCH_SIZE = 100;

    /** Rows older than this are dropped undelivered; the nightly fetch covers them. */
    public const MAX_AGE = 3 * 86400;

    /**
     * Record one completion change. Called by the observer after commit.
     *
     * @param \core\event\course_module_completion_updated $event
     * @return bool Whether it was recorded.
     */
    public static function record(\core\event\course_module_completion_updated $event): bool {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        if (!self::connected()) {
            return false;
        }
        $state = (int)($event->other['completionstate'] ?? COMPLETION_INCOMPLETE);
        $cm = get_fast_modinfo($event->courseid, -1)->get_cm($event->contextinstanceid);

        $DB->insert_record(self::TABLE, (object)[
            'kind' => self::KIND,
            'payload' => json_encode([
                'event' => 'completion.updated',
                'userId' => (int)$event->relateduserid,
                'courseId' => (int)$event->courseid,
                'cmid' => (int)$cm->id,
                'modname' => (string)$cm->modname,
                'completionState' => $state,
                'state' => self::state_name($state),
                'complete' => self::counts_as_complete($cm, $state),
                'overridden' => !empty($event->other['overrideby']),
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', (int)$event->timecreated),
            ]),
            'timecreated' => time(),
        ]);
        // One pending delivery is enough however many changes are waiting.
        \core\task\manager::queue_adhoc_task(new \block_alphabees\task\deliver_completion_events(), true);
        return true;
    }

    /**
     * The next batch of waiting events, oldest first.
     *
     * @return array{ids: int[], events: array}
     */
    public static function next_batch(): array {
        global $DB;
        $rows = $DB->get_records(self::TABLE, ['kind' => self::KIND], 'id ASC', 'id, payload', 0, self::BATCH_SIZE);
        $events = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)$row->payload, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return ['ids' => array_map('intval', array_keys($rows)), 'events' => $events];
    }

    /**
     * Remove delivered (or abandoned) rows.
     *
     * @param int[] $ids
     * @return void
     */
    public static function forget(array $ids): void {
        global $DB;
        if ($ids) {
            $DB->delete_records_list(self::TABLE, 'id', $ids);
        }
    }

    /**
     * Drop everything waiting, e.g. when nobody is connected to receive it.
     *
     * @return int Rows dropped.
     */
    public static function drop_all(): int {
        global $DB;
        $count = $DB->count_records(self::TABLE, ['kind' => self::KIND]);
        $DB->delete_records(self::TABLE, ['kind' => self::KIND]);
        return $count;
    }

    /**
     * Drop rows that waited too long, for a backend that was down for days.
     *
     * @return int Rows dropped.
     */
    public static function purge_stale(): int {
        global $DB;
        $cutoff = time() - self::MAX_AGE;
        $count = $DB->count_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        }
        return $count;
    }

    /**
     * Whether there is a backend to report to right now.
     *
     * @return bool
     */
    public static function connected(): bool {
        return site_registry::is_registered()
            && !site_registry::is_registration_blocked()
            && !site_registry::is_sync_paused();
    }

    /**
     * Name a completion state.
     *
     * @param int $state COMPLETION_* value.
     * @return string
     */
    public static function state_name(int $state): string {
        switch ($state) {
            case COMPLETION_COMPLETE:
                return 'complete';
            case COMPLETION_COMPLETE_PASS:
                return 'passed';
            case COMPLETION_COMPLETE_FAIL:
                return 'failed';
            case 4: // COMPLETION_COMPLETE_FAIL_HIDDEN, defined since 4.0.
                return 'failed_hidden';
            default:
                return 'incomplete';
        }
    }

    /**
     * Whether Moodle shows the activity as done to the learner.
     *
     * Mirrors \core_completion\cm_completion_details::is_overall_complete():
     * a failed grade still completes an activity that asks only for a grade,
     * but not one that asks for a passing grade or has custom rules.
     *
     * @param \cm_info $cm
     * @param int $state
     * @return bool
     */
    public static function counts_as_complete(\cm_info $cm, int $state): bool {
        if ((int)$cm->completion === COMPLETION_TRACKING_MANUAL) {
            return $state === COMPLETION_COMPLETE;
        }
        // A module lists its custom rules with their settings; only the ones
        // switched on are rules (assign lists completionsubmit even when 0).
        $customrules = array_filter((array)($cm->customdata['customcompletionrules'] ?? []));
        if ($customrules || !empty($cm->completionpassgrade)) {
            return in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
        }
        return in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true);
    }
}
