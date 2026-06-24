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
    'al-chat-widget'
], function(config, externalIgnore) {
    "use strict";

    const _loadAlChat = window._loadAlChat;

    return {
        /**
         * Initialize the chat widget with API key, bot ID, primary color, and context.
         *
         * @param {string} apiKey API key for Alphabees.
         * @param {string} botId Bot identifier.
         * @param {string} primaryColor Primary color hex (e.g., "#72AECF").
         * @param {{courseid:number, sectionnum:number, sectionid:number, userid:number}} ctx Context data.
         */
        init: function(apiKey, botId, primaryColor, ctx) {
            if (typeof _loadAlChat !== "function") {
                window.console && console.error("[alphabees] _loadAlChat is not defined.");
                return;
            }
            try {
                const currentSessionId = function() {
                    const state = window.alphabees && window.alphabees.state ? window.alphabees.state : null;
                    if (!state) {
                        return "";
                    }
                    return String(state.sessionId || state.session_id || state.conversationId || state.conversation_id || "");
                };
                const emitMoodleContextEvent = function(payload, reason) {
                    const eventPayload = {
                        eventType: "moodle.context.changed",
                        reason: reason || "init",
                        occurredAt: new Date().toISOString(),
                        activeSession: !!(window.alphabees && window.alphabees.state),
                        sessionId: currentSessionId(),
                        data: payload
                    };
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
                    sectionId: Number((ctx && ctx.sectionid) || 0) || 0,
                    sectionNum: Number((ctx && ctx.sectionnum) || 0) || 0,
                    contextId: Number((ctx && ctx.contextid) || 0) || 0,
                    contextLevel: Number((ctx && ctx.contextlevel) || 0) || 0,
                    pageType: (ctx && ctx.pagetype) ? String(ctx.pagetype) : "",
                    pageUrl: (ctx && ctx.pageurl) ? String(ctx.pageurl) : window.location.href,
                    cmid: Number((ctx && ctx.cmid) || 0) || 0,
                    modType: (ctx && ctx.modtype) ? String(ctx.modtype) : "",
                    activityId: Number((ctx && ctx.activityid) || 0) || 0,
                    activityName: (ctx && ctx.activityname) ? String(ctx.activityname) : ""
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

                window.console && console.log("[alphabees] Initializing chat widget…", { userId, context, placementUuid, siteIdentifier });

                window.__alphabeesMoodleLastPayload = payload;
                _loadAlChat(payload);
                window.postMessage({ type: "alUpdateState", data: payload }, "*");
                emitMoodleContextEvent(payload, "init");

                if (!window.__alphabeesMoodleContextWatcher) {
                    window.__alphabeesMoodleContextWatcher = true;
                    const pushLatestState = function() {
                        const latest = window.__alphabeesMoodleLastPayload;
                        if (!latest) {
                            return;
                        }
                        latest.context = latest.context || {};
                        latest.context.pageUrl = window.location.href;
                        window.postMessage({ type: "alUpdateState", data: latest }, "*");
                        emitMoodleContextEvent(latest, "navigation");
                    };
                    ["pushState", "replaceState"].forEach(function(method) {
                        const original = window.history && window.history[method];
                        if (typeof original !== "function") {
                            return;
                        }
                        window.history[method] = function() {
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
