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
 * A fresh start token for the widget on a page that has been open a while.
 *
 * The page hands the widget one token when it renders; it is valid for ten
 * minutes and once. A widget that reconnects later asks here for another,
 * always for the person calling — the user id is never a parameter.
 *
 * Reachable from a browser session (`ajax`) and from the Moodle app's own
 * service, deliberately not from the Alphabees service token: that account
 * would only ever get tokens naming itself.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\placement_repository;
use block_alphabees\local\session_tracker;
use block_alphabees\local\start_token;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_get_start_token.
 */
class get_start_token extends external_api {
    /** Refreshes one user may ask for per minute. */
    private const PER_MINUTE = 20;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course of the page, 0 or the site id off course pages.', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Activity of the page, 0 if none.', VALUE_DEFAULT, 0),
            'placementuuid' => new external_value(PARAM_ALPHANUMEXT, 'Placement the widget belongs to.', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Mint a token for the calling user.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $placementuuid
     * @return array
     */
    public static function execute(int $courseid = 0, int $cmid = 0, string $placementuuid = ''): array {
        global $USER;

        $args = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'placementuuid' => $placementuuid,
        ]);
        self::validate_context(\context_system::instance());

        if (!self::within_rate((int)$USER->id)) {
            throw new \moodle_exception('starttoken_ratelimited', 'block_alphabees');
        }

        // The placement only attributes the session; one this site does not
        // have is dropped rather than signed.
        $placement = $args['placementuuid'];
        if ($placement !== '' && placement_repository::find_by_uuid($placement) === null) {
            $placement = '';
        }

        // The app calls through a web-service token and has no web session, so
        // there is no sessionHash to bind to there.
        $sessionhash = (defined('WS_SERVER') && WS_SERVER) ? '' : session_tracker::session_hash();

        $issued = start_token::issue((int)$USER->id, $args['courseid'], $args['cmid'], $placement, $sessionhash);
        return [
            'token' => $issued['token'] ?? '',
            'expiresat' => $issued['expiresat'] ?? 0,
        ];
    }

    /**
     * Count this call against the user's allowance for the current minute.
     *
     * A widget needs one token per reconnect; anything near the limit is a
     * loop or a script, and each token is a signature over this site's key.
     *
     * @param int $userid
     * @return bool False once the user is over the limit.
     */
    private static function within_rate(int $userid): bool {
        $cache = \cache::make('block_alphabees', 'starttokenrate');
        $key = $userid . '_' . intdiv(time(), 60);
        $count = (int)$cache->get($key) + 1;
        $cache->set($key, $count);
        return $count <= self::PER_MINUTE;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_RAW, 'Start token, empty when none can be issued for this user and page.'),
            'expiresat' => new external_value(PARAM_INT, 'Expiry as a unix timestamp, 0 without a token.'),
        ]);
    }
}
