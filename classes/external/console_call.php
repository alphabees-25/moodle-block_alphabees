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
 * The console's way into the action registry.
 *
 * This is the second entrance described in classes/local/caller.php: a call
 * made from the browser of a signed-in Moodle user, running with that person's
 * capabilities. It is declared `ajax => true` and is deliberately **not** a
 * member of the Alphabees external service — a web-service token must not be
 * able to reach it, or the separation between the two entrances would be
 * decorative.
 *
 * Results vary by operation, so the payload comes back as JSON in a single
 * field rather than as a per-operation structure.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\caller;
use block_alphabees\local\inbound_dispatcher;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_console_call.
 */
class console_call extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'operation' => new external_value(
                PARAM_ALPHANUMEXT,
                'Registry action to run. Must be one the console entrance accepts.'
            ),
            'params' => new external_value(
                PARAM_RAW,
                'JSON object of action parameters.',
                VALUE_DEFAULT,
                '{}'
            ),
        ]);
    }

    /**
     * Run one console operation as the signed-in user.
     *
     * @param string $operation
     * @param string $params JSON object.
     * @return array
     */
    public static function execute(string $operation, string $params = '{}'): array {
        global $USER;

        $args = self::validate_parameters(self::execute_parameters(), [
            'operation' => $operation,
            'params' => $params,
        ]);

        $decoded = json_decode($args['params'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        // Validate in the course the operation names, so page setup and course
        // visibility are handled by Moodle rather than assumed here.
        $courseid = isset($decoded['courseid']) ? (int)$decoded['courseid'] : 0;
        $context = ($courseid > 0 && $courseid != SITEID)
            ? \context_course::instance($courseid, MUST_EXIST)
            : \context_system::instance();
        self::validate_context($context);

        $result = inbound_dispatcher::execute(caller::user((int)$USER->id), $args['operation'], $decoded);

        $body = is_array($result['body']) ? $result['body'] : [];
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'status' => (int)$result['httpStatus'],
            'ok' => !empty($body['ok']),
            'data' => $encoded === false ? '{}' : $encoded,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, 'HTTP-style status of the operation.'),
            'ok' => new external_value(PARAM_BOOL, 'Whether the operation succeeded.'),
            'data' => new external_value(PARAM_RAW, 'JSON-encoded result body.'),
        ]);
    }
}
