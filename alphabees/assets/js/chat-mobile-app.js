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

  function loadChatBundle() {
    return new Promise(function (resolve) {
      if (window._loadAlChat) return resolve(true);

      var s = document.createElement("script");
      s.id = "alphabees-bundle";
      const randomId = Date.now() + "-" + Math.random().toString(36).slice(2);
      s.src = `https://chat.alphalearn.ai/chat-widget.js?cacheBust=${randomId}`;

      s.onload = function () {
        window._loadAlChat({
          apiKey: API_KEY,
          botId:  BOT_ID,
          platform: "moodle-app",
          chatBubblePositionX: 8,
          chatBubblePositionY: 120,
          userId: USER_ID,
          context: {
            courseId: COURSE_ID,
            sectionNum: SECTION_NUM,
            sectionId: SECTION_ID,
          }
        });
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
      window.postMessage({
        type: "alUpdateState",
        data: {
          apiKey: API_KEY,
          botId:  BOT_ID,
          platform: "moodle-app",
          isVisible: true,
          chatBubblePositionX: 8,
          chatBubblePositionY: 120,
          userId: USER_ID,
          context: {
            courseId: COURSE_ID,
            sectionNum: SECTION_NUM,
            sectionId: SECTION_ID,
          }
        }
      }, "*");
      window.postMessage({ type: "alConnect" }, "*");
    })
    .catch(function (e) {
      console.error("[alphabees] mount error:", e);
    });
})();

