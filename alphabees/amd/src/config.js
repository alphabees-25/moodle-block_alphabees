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
 * AMD module to configure RequireJS for the Alphabees Chat Widget.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define("block_alphabees/config", [], function() {
    "use strict";

    // Configure RequireJS paths and shims for external dependencies.
    if (typeof window.requirejs !== "undefined") {
        var bust = Math.floor(10000 + Math.random() * 90000);
        window.requirejs.config({
            paths: {
                "al-chat-widget": "https://chat.alphabees.de/production/chat-widget.amd.js?v=" + bust
            }
        });
    }

    return {};
});


