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
 * Which activity the learner has open, and how sure the plugin is.
 *
 * moodle_page offers `cm`, `url`, `context` … through __get() and has no
 * __isset(), so empty() and isset() on `$PAGE->cm` are always "not set" — the
 * check that kept every page reporting cmid 0 until 3.4.0. Rule for this
 * plugin: never empty()/isset() directly on $PAGE->… ; read first, then test.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Describes the open activity for the widget context.
 */
class page_context {
    /** Version of the context contract: 2 = the activity is reported reliably, with a state. */
    public const VERSION = 2;

    /** An activity is open and named. */
    public const STATE_OPEN = 'open';

    /** The page is not an activity (course page, dashboard, participants). */
    public const STATE_NONE = 'none';

    /** The page belongs to an activity the plugin could not or may not name. */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * The answer for "no activity", which the other answers start from.
     *
     * @param string $state
     * @param string $reason no_cm | cm_not_visible | exception, only with unknown.
     * @return array
     */
    private static function blank(string $state = self::STATE_NONE, string $reason = ''): array {
        return [
            'cmid' => 0,
            'modtype' => '',
            'activityid' => 0,
            'activityname' => '',
            'sectionnum' => 0,
            'sectionid' => 0,
            'activitystate' => $state,
            'activityreason' => $reason,
            'contextversion' => self::VERSION,
        ];
    }

    /**
     * The activity of a rendered page.
     *
     * @param \moodle_page $page
     * @return array cmid, modtype, activityid, activityname, sectionnum,
     *         sectionid, activitystate, activityreason, contextversion.
     */
    public static function of_page(\moodle_page $page): array {
        try {
            // Read, then test: see the file comment.
            $cm = $page->cm;
            if ($cm) {
                return self::describe($cm);
            }
            if (strpos((string)$page->pagetype, 'mod-') === 0) {
                return self::blank(self::STATE_UNKNOWN, 'no_cm');
            }
            return self::blank();
        } catch (\Throwable $e) {
            return self::blank(self::STATE_UNKNOWN, 'exception');
        }
    }

    /**
     * The activity a client names by its course module id (the Moodle app).
     *
     * @param int $cmid 0 for "no activity".
     * @param int $userid Whose view decides visibility.
     * @return array As of_page().
     */
    public static function of_cmid(int $cmid, int $userid): array {
        if ($cmid <= 0) {
            return self::blank();
        }
        try {
            $record = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            if (!$record) {
                return self::blank(self::STATE_UNKNOWN, 'no_cm');
            }
            $cm = get_fast_modinfo((int)$record->course, $userid)->cms[$cmid] ?? null;
            return $cm ? self::describe($cm) : self::blank(self::STATE_UNKNOWN, 'no_cm');
        } catch (\Throwable $e) {
            return self::blank(self::STATE_UNKNOWN, 'exception');
        }
    }

    /**
     * Describe a course module, or withhold it when its viewer may not see it.
     *
     * @param \cm_info $cm Loaded for the viewer.
     * @return array
     */
    private static function describe(\cm_info $cm): array {
        if (!$cm->uservisible) {
            // No id and no name: the learner got here by a direct link.
            return self::blank(self::STATE_UNKNOWN, 'cm_not_visible');
        }
        return [
            'cmid' => (int)$cm->id,
            'modtype' => clean_param((string)$cm->modname, PARAM_ALPHANUMEXT),
            'activityid' => (int)$cm->instance,
            'activityname' => clean_param((string)$cm->name, PARAM_TEXT),
            'sectionnum' => (int)$cm->sectionnum,
            'sectionid' => (int)$cm->section,
            'activitystate' => self::STATE_OPEN,
            'activityreason' => '',
            'contextversion' => self::VERSION,
        ];
    }
}
