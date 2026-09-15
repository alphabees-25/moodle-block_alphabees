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
 * Boots the Alphabees console bundle on the plugin's console page.
 *
 * The bundle is hosted by Alphabees and mounts itself into the page, the same
 * arrangement the chat widget uses. Until it exists on a given environment the
 * page must still be usable, so a load failure reveals the fallback notice the
 * server rendered rather than leaving an empty frame.
 *
 * @module     block_alphabees/console
 * @copyright  2026 Alphabees
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define("block_alphabees/console", ["core/ajax"], function (Ajax) {
    "use strict";

    var SCRIPT_ID = "alphabees-console-bundle";
    var LOAD_TIMEOUT_MS = 15000;

    /**
     * Reveal the server-rendered notice explaining that the console is not
     * available, and remove the loading placeholder.
     *
     * @param {String} reason Short machine-readable cause, for the console log.
     */
    function showFallback(reason) {
        var fallback = document.getElementById("alphabees-console-fallback");
        var loading = document.getElementById("alphabees-console-loading");
        if (loading) {
            loading.hidden = true;
        }
        if (fallback) {
            fallback.hidden = false;
        }
        if (window.console && window.console.warn) {
            window.console.warn("[alphabees] console bundle unavailable: " + reason);
        }
    }

    /**
     * Load the remote bundle once.
     *
     * @param {String} url Absolute URL of the console bundle.
     * @return {Promise} Resolves when window._loadAlConsole is available.
     */
    function loadBundle(url) {
        return new Promise(function (resolve, reject) {
            if (typeof window._loadAlConsole === "function") {
                resolve();
                return;
            }
            if (document.getElementById(SCRIPT_ID)) {
                reject(new Error("already_attempted"));
                return;
            }

            var settled = false;
            var timer = window.setTimeout(function () {
                if (!settled) {
                    settled = true;
                    reject(new Error("timeout"));
                }
            }, LOAD_TIMEOUT_MS);

            var script = document.createElement("script");
            script.id = SCRIPT_ID;
            script.async = true;
            script.src = url + (url.indexOf("?") === -1 ? "?" : "&") +
                "cacheBust=" + Date.now().toString(36);

            script.onload = function () {
                window.clearTimeout(timer);
                if (settled) {
                    return;
                }
                settled = true;
                if (typeof window._loadAlConsole === "function") {
                    resolve();
                } else {
                    reject(new Error("entrypoint_missing"));
                }
            };
            script.onerror = function () {
                window.clearTimeout(timer);
                if (!settled) {
                    settled = true;
                    reject(new Error("network"));
                }
            };

            document.head.appendChild(script);
        });
    }

    /**
     * Run one registry operation in Moodle as the signed-in user.
     *
     * This is the bridge the bundle uses for anything that must happen inside
     * Moodle rather than in the Alphabees backend. It goes through the plugin's
     * ajax-enabled external function, so Moodle authenticates the session and
     * checks the operator's own capabilities — the bundle cannot reach past
     * what this person is allowed to do.
     *
     * @param {String} operation Registry action name.
     * @param {Object} params Action parameters.
     * @return {Promise} Resolves with the decoded result body.
     */
    function moodleCall(operation, params) {
        return Ajax.call([{
            methodname: "block_alphabees_console_call",
            args: {
                operation: operation,
                params: JSON.stringify(params || {})
            }
        }])[0].then(function (response) {
            var body;
            try {
                body = JSON.parse(response.data);
            } catch (e) {
                body = {};
            }
            if (!response.ok) {
                var failure = new Error(body.code || "operation_failed");
                failure.status = response.status;
                failure.body = body;
                throw failure;
            }
            return body;
        });
    }

    return {
        /**
         * Entry point called from console.php.
         *
         * @param {Object} payload Everything the bundle needs to start: the
         *                         signed session token, the site identifier,
         *                         the deep-link target and the theme.
         * @param {String} bundleUrl Absolute URL of the console bundle.
         */
        init: function (payload, bundleUrl) {
            if (!bundleUrl) {
                showFallback("no_bundle_url");
                return;
            }
            if (!payload || !payload.token) {
                showFallback("no_session_token");
                return;
            }

            // Published before the bundle loads so it can call straight away.
            window.alphabeesMoodle = {version: 1, call: moodleCall};

            loadBundle(bundleUrl).then(function () {
                var loading = document.getElementById("alphabees-console-loading");
                if (loading) {
                    loading.hidden = true;
                }
                window._loadAlConsole(payload);
                return true;
            }).catch(function (error) {
                showFallback(error && error.message ? error.message : "unknown");
            });
        }
    };
});
