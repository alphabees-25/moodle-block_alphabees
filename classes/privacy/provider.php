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
 * Privacy declaration for block_alphabees.
 *
 * The plugin keeps no personal data in Moodle's own tables — its two tables
 * hold replay nonces and delivery counters, neither tied to a person. What it
 * does do is exchange personal data with the Alphabees backend, both for the
 * learner using the tutor and for the teacher answering in the console, so the
 * declaration below describes that transfer.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider describing what leaves Moodle for the Alphabees backend.
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the data this plugin sends to Alphabees.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'alphabees_backend',
            [
                'userid' => 'privacy:metadata:alphabees_backend:userid',
                'courseid' => 'privacy:metadata:alphabees_backend:courseid',
                'message' => 'privacy:metadata:alphabees_backend:message',
                'firstname' => 'privacy:metadata:alphabees_backend:firstname',
            ],
            'privacy:metadata:alphabees_backend'
        );

        return $collection;
    }
}
