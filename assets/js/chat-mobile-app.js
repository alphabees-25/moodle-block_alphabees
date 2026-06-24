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

(function () {
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
  var USER_ID   = Number(container.getAttribute("data-userid")    || 0) || 0;
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
  var PLACEMENT_UUID = container.getAttribute("data-placementuuid") || "";
  var SITE_IDENTIFIER = container.getAttribute("data-siteidentifier") || "";
  var PRIMARY_COLOR = container.getAttribute("data-primarycolor") || "#72aecf";

  function moodleContext() {
    return {
      courseId: COURSE_ID,
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
    };
  }

  function currentSessionId() {
    var state = window.alphabees && window.alphabees.state ? window.alphabees.state : null;
    if (!state) {
      return "";
    }
    return String(state.sessionId || state.session_id || state.conversationId || state.conversation_id || "");
  }

  function emitMoodleContextEvent(payload, reason) {
    var eventPayload = {
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
  }

  function loadChatBundle() {
    return new Promise(function (resolve) {
      if (window._loadAlChat) return resolve(true);

      var s = document.createElement("script");
      s.id = "alphabees-bundle";
      const randomId = Date.now() + "-" + Math.random().toString(36).slice(2);
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
      window.postMessage({
        type: "alUpdateState",
        data: updatePayload
      }, "*");
      emitMoodleContextEvent(updatePayload, "navigation");
      window.postMessage({ type: "alConnect" }, "*");
    })
    .catch(function (e) {
      console.error("[alphabees] mount error:", e);
    });
})();
