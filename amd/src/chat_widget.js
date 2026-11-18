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
 * Main AMD module for loading and initializing the Alphabees Chat Widget.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'block_alphabees/config',
    'al-chat-widget',
    'core/ajax'
], function (config, externalIgnore, Ajax) {
    "use strict";

    const _loadAlChat = window._loadAlChat;

    /**
     * Start tokens for the widget: the one the page rendered, and fresh ones
     * from block_alphabees_get_start_token when it runs out or is asked for.
     *
     * Contract with the widget, all on this window:
     * - window.alphabeesMoodle.getStartToken() resolves to a new token ('' if
     *   none can be issued);
     * - a message {type: "alRequestStartToken", requestId} is answered with
     *   {type: "alStartToken", data: {startToken, expiresAt, requestId}};
     * - the same message, without requestId, announces a token renewed ahead
     *   of expiry while the page is visible.
     */
    const startTokens = (function () {
        const RENEW_BEFORE_S = 90;
        let scope = null;
        let expiresAt = 0;
        let payloadRef = null;

        const announce = function (token, requestId) {
            const data = {startToken: token, expiresAt: expiresAt};
            if (requestId !== undefined) {
                data.requestId = requestId;
            }
            window.postMessage({type: "alStartToken", data: data}, "/");
        };
        const fetchToken = function () {
            if (!scope) {
                return Promise.resolve("");
            }
            return Promise.resolve(Ajax.call([{
                methodname: "block_alphabees_get_start_token",
                args: scope
            }])[0]).then(function (result) {
                const token = result && result.token ? String(result.token) : "";
                expiresAt = token ? Number(result.expiresat) || 0 : 0;
                if (payloadRef) {
                    payloadRef.startToken = token;
                }
                return token;
            });
        };
        const renewIfDue = function () {
            if (!expiresAt || document.hidden) {
                return;
            }
            if (expiresAt - Math.floor(Date.now() / 1000) > RENEW_BEFORE_S) {
                return;
            }
            fetchToken().then(function (token) {
                announce(token);
            }).catch(function () {
                expiresAt = 0;
            });
        };

        window.addEventListener("message", function (event) {
            if (event.source !== window || !event.data || event.data.type !== "alRequestStartToken") {
                return;
            }
            const requestId = event.data.requestId;
            fetchToken().then(function (token) {
                announce(token, requestId);
            }).catch(function () {
                announce("", requestId);
            });
        });
        window.setInterval(renewIfDue, 60000);
        document.addEventListener("visibilitychange", renewIfDue);

        window.alphabeesMoodle = Object.assign(window.alphabeesMoodle || {}, {
            getStartToken: fetchToken
        });

        return {
            /**
             * Remember the page's scope and token so renewals land in the payload.
             *
             * @param {Object} payload The widget payload; startToken is kept current in it.
             * @param {number} expiry Unix expiry of the rendered token, 0 if none.
             * @param {{courseid:number, cmid:number, placementuuid:string}} pagescope Page the token names.
             */
            track: function (payload, expiry, pagescope) {
                payloadRef = payload;
                expiresAt = expiry;
                scope = expiry ? pagescope : null;
            }
        };
    })();

    return {
        /**
         * Initialize the chat widget with API key, bot ID, primary color, and context.
         *
         * @param {string} apiKey API key for Alphabees.
         * @param {string} botId Bot identifier.
         * @param {string} primaryColor Primary color hex (e.g., "#72AECF").
         * @param {string|Object} ctx Id of the element whose data-context holds the
         *     context as JSON (since 3.4.0), or the context object itself.
         */
        init: function (apiKey, botId, primaryColor, ctx) {
            if (typeof ctx === "string") {
                const holder = document.getElementById(ctx);
                try {
                    ctx = holder ? JSON.parse(holder.getAttribute("data-context") || "{}") : {};
                } catch (e) {
                    window.console && console.error("[alphabees] Unreadable widget context.", e);
                    ctx = {};
                }
            }
            if (typeof _loadAlChat !== "function") {
                window.console && console.error("[alphabees] _loadAlChat is not defined.");
                return;
            }
            try {
                const currentSessionId = function () {
                    const state = window.alphabees && window.alphabees.state ? window.alphabees.state : null;
                    if (!state) {
                        return "";
                    }
                    return String(state.sessionId || state.session_id || state.conversationId || state.conversation_id || "");
                };
                const emitMoodleContextEvent = function (payload, reason, previousCourseId) {
                    const eventPayload = {
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
                };
                const userId = Number((ctx && ctx.userid) || 0) || 0;
                const context = {
                    courseId: Number((ctx && ctx.courseid) || 0) || 0,
                    courseName: (ctx && ctx.coursename) ? String(ctx.coursename) : "",
                    courseShortname: (ctx && ctx.courseshortname) ? String(ctx.courseshortname) : "",
                    sectionId: Number((ctx && ctx.sectionid) || 0) || 0,
                    sectionNum: Number((ctx && ctx.sectionnum) || 0) || 0,
                    contextId: Number((ctx && ctx.contextid) || 0) || 0,
                    contextLevel: Number((ctx && ctx.contextlevel) || 0) || 0,
                    pageType: (ctx && ctx.pagetype) ? String(ctx.pagetype) : "",
                    pageUrl: (ctx && ctx.pageurl) ? String(ctx.pageurl) : window.location.href,
                    cmid: Number((ctx && ctx.cmid) || 0) || 0,
                    modType: (ctx && ctx.modtype) ? String(ctx.modtype) : "",
                    activityId: Number((ctx && ctx.activityid) || 0) || 0,
                    activityName: (ctx && ctx.activityname) ? String(ctx.activityname) : "",
                    // What the block knows about the open activity: open,
                    // none or unknown (with a reason). See local\page_context.
                    contextVersion: Number((ctx && ctx.contextversion) || 0) || 0,
                    activityState: (ctx && ctx.activitystate) ? String(ctx.activitystate) : "",
                    activityReason: (ctx && ctx.activityreason) ? String(ctx.activityreason) : ""
                };
                const placementUuid = (ctx && ctx.placementuuid) ? String(ctx.placementuuid) : "";
                const siteIdentifier = (ctx && ctx.siteidentifier) ? String(ctx.siteidentifier) : "";
                const payload = {
                    apiKey: apiKey,
                    botId: botId,
                    primaryColor: primaryColor,
                    development: false,
                    platform: "moodle",
                    userId: userId,
                    context: context,
                    placementUuid: placementUuid,
                    siteIdentifier: siteIdentifier
                };
                // Opt-in user profile (site setting send_userprofile): first
                // name only, top-level like userId — never inside 'context'.
                if (ctx && ctx.userprofile && ctx.userprofile.firstName) {
                    payload.userProfile = { firstName: String(ctx.userprofile.firstName) };
                }

                // This Moodle session, as sha256(sesskey|wwwroot). The plugin
                // reports the same hash when the user logs out, so al-tutor can
                // end the tutor session. Stable for the session, so it stays in
                // the navigation pushes too.
                if (ctx && ctx.sessionhash) {
                    payload.sessionHash = String(ctx.sessionhash);
                }

                // Deep link from a Moodle notification: open this exercise or
                // thread. Top-level like userId, deliberately not inside
                // 'context' — it is an instruction to the UI, not material for
                // the agent's prompt.
                if (ctx && ctx.deeplink) {
                    payload.deepLink = String(ctx.deeplink);
                }

                // Signed by Moodle: who is on this page. The backend trusts it
                // instead of userId. Valid ten minutes and once, so a widget
                // that connects later asks for a fresh one (see below).
                if (ctx && ctx.starttoken) {
                    payload.startToken = String(ctx.starttoken);
                }
                startTokens.track(payload, Number((ctx && ctx.starttokenexpiresat) || 0) || 0, {
                    courseid: context.courseId,
                    cmid: context.cmid,
                    placementuuid: placementUuid
                });

                window.console && console.log("[alphabees] Initializing chat widget…", { userId, context, placementUuid, siteIdentifier });

                window.__alphabeesMoodleLastPayload = payload;
                _loadAlChat(payload);
                window.postMessage({ type: "alUpdateState", data: payload }, "*");
                emitMoodleContextEvent(payload, "init");

                // Every Moodle page load runs init again, so the full payload
                // above already goes out on each page. A different course than
                // on the previous page in this tab additionally gets its own
                // event naming the course it came from. sessionStorage, because
                // a page load starts a fresh window; it may be unavailable.
                let previousCourseId = null;
                try {
                    const stored = window.sessionStorage.getItem("alphabees:lastCourseId");
                    previousCourseId = stored === null ? null : (Number(stored) || 0);
                    window.sessionStorage.setItem("alphabees:lastCourseId", String(context.courseId));
                } catch (e) {
                    previousCourseId = null;
                }
                const courseChanged = previousCourseId !== null && previousCourseId !== context.courseId;
                if (courseChanged) {
                    emitMoodleContextEvent(payload, "course_changed", previousCourseId);
                }

                // The widget bundle loads after the calls above, so nothing
                // was listening to them. It announces itself with alChatReady;
                // the latest state goes out again then (and after a reload of
                // the widget), followed by the course change if there was one.
                if (!window.__alphabeesMoodleReadyListener) {
                    window.__alphabeesMoodleReadyListener = true;
                    window.addEventListener("message", function (event) {
                        if (event.source !== window || !event.data || event.data.type !== "alChatReady") {
                            return;
                        }
                        const latest = window.__alphabeesMoodleLastPayload;
                        if (!latest) {
                            return;
                        }
                        window.postMessage({ type: "alUpdateState", data: latest }, "*");
                        emitMoodleContextEvent(latest, "ready");
                        const pending = window.__alphabeesMoodlePendingCourseChange;
                        if (pending) {
                            window.__alphabeesMoodlePendingCourseChange = null;
                            emitMoodleContextEvent(latest, "course_changed", pending.previousCourseId);
                        }
                    });
                }
                window.__alphabeesMoodlePendingCourseChange = courseChanged
                    ? { previousCourseId: previousCourseId }
                    : null;

                if (!window.__alphabeesMoodleContextWatcher) {
                    window.__alphabeesMoodleContextWatcher = true;
                    const pushLatestState = function () {
                        const latest = window.__alphabeesMoodleLastPayload;
                        if (!latest) {
                            return;
                        }
                        latest.context = latest.context || {};
                        latest.context.pageUrl = window.location.href;
                        // The name never changes within a session — sent once
                        // at init, omitted from navigation pushes.
                        const navPayload = Object.assign({}, latest);
                        delete navPayload.userProfile;
                        // Same for the deep link: acting on it once is the
                        // point, reopening it on every page change is not.
                        delete navPayload.deepLink;
                        window.postMessage({ type: "alUpdateState", data: navPayload }, "*");
                        emitMoodleContextEvent(navPayload, "navigation");
                    };
                    ["pushState", "replaceState"].forEach(function (method) {
                        const original = window.history && window.history[method];
                        if (typeof original !== "function") {
                            return;
                        }
                        window.history[method] = function () {
                            const result = original.apply(this, arguments);
                            window.setTimeout(pushLatestState, 0);
                            return result;
                        };
                    });
                    window.addEventListener("popstate", pushLatestState);
                    window.addEventListener("hashchange", pushLatestState);
                }
            } catch (e) {
                window.console && console.error("[alphabees] Init error:", e);
            }
        }
    };
});
