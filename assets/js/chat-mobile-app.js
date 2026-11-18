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
 * Main JS for loading and initializing the Alphabees Chat Widget.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function (app) {
    var container = document.getElementById("al-chat-data");
    if (!container) {
        console.warn("[alphabees] container missing");
        return;
    }

    if (
    document.getElementById("al-chat-widget") &&
    window.alphabees &&
    window.alphabees.state &&
    window.alphabees.state.isVisible === true
    ) {
        return;
    }

    var API_KEY   = container.getAttribute("data-apikey")    || "";
    var BOT_ID    = container.getAttribute("data-botid")     || "";
    var COURSE_ID = Number(container.getAttribute("data-courseid")  || 0) || 0;
    var COURSE_NAME = container.getAttribute("data-coursename") || "";
    var COURSE_SHORTNAME = container.getAttribute("data-courseshortname") || "";
    var USER_ID   = Number(container.getAttribute("data-userid")    || 0) || 0;
    // Opt-in (site setting send_userprofile): empty when disabled.
    var USER_FIRSTNAME = container.getAttribute("data-userfirstname") || "";
    var SECTION_NUM = Number(container.getAttribute("data-sectionnum") || 0) || 0;
    var SECTION_ID  = Number(container.getAttribute("data-sectionid")  || 0) || 0;
    var CONTEXT_ID = Number(container.getAttribute("data-contextid") || 0) || 0;
    var CONTEXT_LEVEL = Number(container.getAttribute("data-contextlevel") || 0) || 0;
    var PAGE_TYPE = container.getAttribute("data-pagetype") || "";
    var PAGE_URL = container.getAttribute("data-pageurl") || "";
    var CMID = Number(container.getAttribute("data-cmid") || 0) || 0;
    var MOD_TYPE = container.getAttribute("data-modtype") || "";
    var ACTIVITY_ID = Number(container.getAttribute("data-activityid") || 0) || 0;
    var ACTIVITY_NAME = container.getAttribute("data-activityname") || "";
    var ACTIVITY_STATE = container.getAttribute("data-activitystate") || "";
    var ACTIVITY_REASON = container.getAttribute("data-activityreason") || "";
    var CONTEXT_VERSION = Number(container.getAttribute("data-contextversion") || 0) || 0;
    var PLACEMENT_UUID = container.getAttribute("data-placementuuid") || "";
    var SITE_IDENTIFIER = container.getAttribute("data-siteidentifier") || "";
    var PRIMARY_COLOR = container.getAttribute("data-primarycolor") || "#72aecf";
    // Signed by Moodle for this view (see local\start_token); empty for guests.
    var START_TOKEN = container.getAttribute("data-starttoken") || "";

    // Fresh tokens through the app's own web-service connection. Same
    // contract as on the web: window.alphabeesMoodle.getStartToken(), and
    // {type: "alRequestStartToken", requestId} answered with "alStartToken".
    // The handler runs again on every in-app navigation, so the latest view's
    // scope wins.
    window.__alphabeesStartTokenScope = START_TOKEN
        ? {courseid: COURSE_ID, cmid: CMID, placementuuid: PLACEMENT_UUID}
        : null;
    if (!window.__alphabeesStartTokenBridge) {
        window.__alphabeesStartTokenBridge = true;
        var sites = app ? (app.CoreSitesProvider || app.CoreSites) : null;
        var fetchStartToken = function () {
            var scope = window.__alphabeesStartTokenScope;
            var site = sites && typeof sites.getCurrentSite === "function" ? sites.getCurrentSite() : null;
            if (!scope || !site || typeof site.write !== "function") {
                return Promise.resolve("");
            }
            return Promise.resolve(site.write("block_alphabees_get_start_token", scope)).then(function (result) {
                return result && result.token ? String(result.token) : "";
            });
        };
        window.addEventListener("message", function (event) {
            if (event.source !== window || !event.data || event.data.type !== "alRequestStartToken") {
                return;
            }
            var requestId = event.data.requestId;
            var reply = function (token) {
                window.postMessage({type: "alStartToken", data: {startToken: token, requestId: requestId}}, "/");
            };
            fetchStartToken().then(reply).catch(function () {
                reply("");
            });
        });
        window.alphabeesMoodle = Object.assign(window.alphabeesMoodle || {}, {
            getStartToken: fetchStartToken
        });
    }

    function moodleContext() {
        return {
            courseId: COURSE_ID,
            courseName: COURSE_NAME,
            courseShortname: COURSE_SHORTNAME,
            sectionNum: SECTION_NUM,
            sectionId: SECTION_ID,
            contextId: CONTEXT_ID,
            contextLevel: CONTEXT_LEVEL,
            pageType: PAGE_TYPE,
            pageUrl: PAGE_URL,
            cmid: CMID,
            modType: MOD_TYPE,
            activityId: ACTIVITY_ID,
            activityName: ACTIVITY_NAME,
            contextVersion: CONTEXT_VERSION,
            activityState: ACTIVITY_STATE,
            activityReason: ACTIVITY_REASON,
        };
    }

    function currentSessionId() {
        var state = window.alphabees && window.alphabees.state ? window.alphabees.state : null;
        if (!state) {
            return "";
        }
        return String(state.sessionId || state.session_id || state.conversationId || state.conversation_id || "");
    }

    function emitMoodleContextEvent(payload, reason, previousCourseId) {
        var eventPayload = {
            eventType: "moodle.context.changed",
            reason: reason || "init",
            occurredAt: new Date().toISOString(),
            activeSession: !!(window.alphabees && window.alphabees.state),
            sessionId: currentSessionId(),
            data: payload
        };
        if (typeof previousCourseId === "number") {
            eventPayload.previousCourseId = previousCourseId;
        }
        window.postMessage({ type: "alMoodleContextChanged", data: eventPayload }, "*");
        if (typeof window.CustomEvent === "function") {
            window.dispatchEvent(new CustomEvent("alphabees:moodle-context-changed", {
                detail: eventPayload
            }));
        }
    }

    function loadChatBundle() {
        return new Promise(function (resolve) {
            if (window._loadAlChat) {
                return resolve(true);
            }

            var s = document.createElement("script");
            s.id = "alphabees-bundle";
            const randomId = Date.now() + "-" + Math.random().toString(36).slice(2);
            // ALPHABEES_ENV_SWITCH — currently PROD. For a release
            // switch to the PROD line.
            // PROD: https://chat.alphalearn.ai/chat-widget.js
            // DEV:  https://chat.alphabees.de/development/chat-widget.js
            s.src = `https://chat.alphalearn.ai/chat-widget.js?cacheBust=${randomId}`;

            s.onload = function () {
                var initialPayload = {
                    apiKey: API_KEY,
                    botId:  BOT_ID,
                    primaryColor: PRIMARY_COLOR,
                    development: false,
                    platform: "moodle-app",
                    chatBubblePositionX: 8,
                    chatBubblePositionY: 120,
                    userId: USER_ID,
                    context: moodleContext(),
                    placementUuid: PLACEMENT_UUID,
                    siteIdentifier: SITE_IDENTIFIER
                };
                if (START_TOKEN) {
                    initialPayload.startToken = START_TOKEN;
                }
                // Top-level like userId — never inside 'context' (prompt assembly).
                if (USER_FIRSTNAME) {
                    initialPayload.userProfile = { firstName: USER_FIRSTNAME };
                }
                window._loadAlChat(initialPayload);
                emitMoodleContextEvent(initialPayload, "init");
                resolve(false);
            };
            s.onerror = function () {
                console.error("[alphabees] bundle failed");
                resolve(false);
            };
            document.head.appendChild(s);
        });
    }

    loadChatBundle()
    .then(function (alreadyLoaded) {
        // Reconfigure/update state on subsequent navigations as well.
        window.postMessage({ type: "alReset" }, "*");
        var updatePayload = {
            apiKey: API_KEY,
            botId:  BOT_ID,
            primaryColor: PRIMARY_COLOR,
            development: false,
            platform: "moodle-app",
            isVisible: true,
            chatBubblePositionX: 8,
            chatBubblePositionY: 120,
            userId: USER_ID,
            context: moodleContext(),
            placementUuid: PLACEMENT_UUID,
            siteIdentifier: SITE_IDENTIFIER
        };
        // A new view, a new token: alReset wiped the previous one.
        if (START_TOKEN) {
            updatePayload.startToken = START_TOKEN;
        }
        // Unlike the web SPA watcher this update follows an alReset, which
        // wipes widget state — without the name here it would be lost after
        // the first in-app navigation.
        if (USER_FIRSTNAME) {
            updatePayload.userProfile = { firstName: USER_FIRSTNAME };
        }
        window.postMessage({
            type: "alUpdateState",
            data: updatePayload
        }, "*");
        emitMoodleContextEvent(updatePayload, "navigation");
        // The app keeps one window across views, so the last course is still
        // known here. A different one gets its own event on top of the
        // navigation update, with the course it came from.
        var previousCourseId = window.__alphabeesLastCourseId;
        window.__alphabeesLastCourseId = COURSE_ID;
        if (typeof previousCourseId === "number" && previousCourseId !== COURSE_ID) {
            emitMoodleContextEvent(updatePayload, "course_changed", previousCourseId);
        }
        window.postMessage({ type: "alConnect" }, "*");

        // On the first view the bundle may not be listening yet when the
        // messages above go out. It announces itself with alChatReady; the
        // latest state is sent again then. One listener for the window, which
        // the app keeps across views.
        window.__alphabeesAppLastPayload = updatePayload;
        if (!window.__alphabeesAppReadyListener) {
            window.__alphabeesAppReadyListener = true;
            window.addEventListener("message", function (event) {
                if (event.source !== window || !event.data || event.data.type !== "alChatReady") {
                    return;
                }
                var latest = window.__alphabeesAppLastPayload;
                if (!latest) {
                    return;
                }
                window.postMessage({ type: "alUpdateState", data: latest }, "*");
                emitMoodleContextEvent(latest, "ready");
            });
        }
    })
    .catch(function (e) {
        console.error("[alphabees] mount error:", e);
    });
})(this);
