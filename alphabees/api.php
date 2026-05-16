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
 * Inbound REST endpoint. Receives Ed25519-signed requests from the Alphabees
 * backend and dispatches them to the inbound_dispatcher.
 *
 * Public, unauthenticated to Moodle's user-session machinery on purpose —
 * authentication happens via signature verification in the dispatcher.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// No login required — this endpoint authenticates via Ed25519 signature.
// phpcs:disable moodle.Files.RequireLogin.Missing
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

use block_alphabees\local\inbound_dispatcher;

$rawbody = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}

// Reconstruct path consistently with how the backend will sign it.
$path = '/blocks/alphabees/api.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$result = inbound_dispatcher::handle($method, $path, $headers, $rawbody);

http_response_code((int)$result['httpStatus']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
