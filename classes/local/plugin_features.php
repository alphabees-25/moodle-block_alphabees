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
 * What this installation can do, for the backend to decide by.
 *
 * The backend decides by capability, never by release string. A web-service
 * function shows up in the token's function list on its own; this list covers
 * everything else — new parameters, changed answers, inbound actions — and
 * conditions that depend on the site, such as whether the Completion
 * Progress block is installed in a version this plugin can read.
 *
 * Sent at registration and in the answers to ping and describe_actions,
 * together with the version code ($plugin->version).
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Feature list and version of this installation.
 */
class plugin_features {
    /**
     * The features present.
     *
     * @return string[]
     */
    public static function list(): array {
        $features = [
            'completion_events',
            'course_completions.paged',
            'course_fields',
            'course_grades',
            'function_schemas',
            'generico_templates',
            'progress_sources',
            'rendered_course_texts',
            'session_ended',
            'user_access',
        ];
        if (completion_progress_reader::status()['supported']) {
            $features[] = 'progress_trackers.completion_progress';
        }
        sort($features);
        return $features;
    }

    /**
     * Release string and version code of the files on disk.
     *
     * Read from version.php, not from the database: during an upgrade window
     * the files are what actually runs.
     *
     * @return array{release: string, version: int}
     */
    public static function version(): array {
        $plugin = new \stdClass();
        require(__DIR__ . '/../../version.php');
        return [
            'release' => (string)($plugin->release ?? ''),
            'version' => (int)($plugin->version ?? 0),
        ];
    }
}
