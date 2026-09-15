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
 * Receives file content from the backend into a Moodle draft area.
 *
 * Module creation in Moodle takes files by draft item id, never by content:
 * `add_moduleinfo()` calls `file_save_draft_area_files()`, which reads the
 * draft out of the *current user's* context. So a file has to land in a draft
 * area first, and the same account has to be acting when the module that
 * consumes it is created — which is why both actions run as the service user.
 *
 * Several files can share one draft area by passing the itemid back in, which
 * is what mod_folder and mod_resource need.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Draft-area uploads for inbound signed requests.
 */
class file_uploader {
    /** Hard ceiling regardless of what the site allows, in bytes. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Put one file into a draft area and describe where it landed.
     *
     * @param array $params filename, content (base64), optionally itemid,
     *                      filepath, and contentisbase64 (default true).
     * @return array { itemid, filename, filepath, filesize, contenthash }
     * @throws \moodle_exception On unusable input or a storage failure.
     */
    public static function upload(array $params): array {
        global $USER;

        $filename = clean_param(trim((string)($params['filename'] ?? '')), PARAM_FILE);
        if ($filename === '') {
            throw new \moodle_exception('upload_filename_required', 'block_alphabees');
        }

        $raw = (string)($params['content'] ?? '');
        if ($raw === '') {
            throw new \moodle_exception('upload_content_required', 'block_alphabees');
        }

        // Base64 is the default because the transport is signed JSON. Strict
        // decoding matters: silently dropping invalid characters would store a
        // corrupt file that only fails much later, inside an H5P import.
        $isbase64 = !array_key_exists('contentisbase64', $params) || (bool)$params['contentisbase64'];
        if ($isbase64) {
            $content = base64_decode($raw, true);
            if ($content === false) {
                throw new \moodle_exception('upload_content_invalid', 'block_alphabees');
            }
        } else {
            $content = $raw;
        }

        $size = strlen($content);
        if ($size === 0) {
            throw new \moodle_exception('upload_content_required', 'block_alphabees');
        }
        $limit = self::size_limit();
        if ($size > $limit) {
            throw new \moodle_exception('upload_too_large', 'block_alphabees', '', display_size($limit));
        }

        $filepath = (string)($params['filepath'] ?? '/');
        if ($filepath === '' || $filepath[0] !== '/') {
            $filepath = '/' . $filepath;
        }
        if (substr($filepath, -1) !== '/') {
            $filepath .= '/';
        }

        // Reusing an itemid collects several files in one draft area; a fresh
        // one is minted otherwise.
        $itemid = isset($params['itemid']) ? (int)$params['itemid'] : 0;
        if ($itemid <= 0) {
            $itemid = file_get_unused_draft_itemid();
        }

        $usercontext = \context_user::instance((int)$USER->id);
        $fs = get_file_storage();

        $existing = $fs->get_file($usercontext->id, 'user', 'draft', $itemid, $filepath, $filename);
        if ($existing) {
            $existing->delete();
        }

        $stored = $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'userid' => (int)$USER->id,
        ], $content);

        return [
            'itemid' => $itemid,
            'filename' => $stored->get_filename(),
            'filepath' => $stored->get_filepath(),
            'filesize' => (int)$stored->get_filesize(),
            'contenthash' => $stored->get_contenthash(),
        ];
    }

    /**
     * Largest file this site will accept, never above our own ceiling.
     *
     * @return int Bytes.
     */
    private static function size_limit(): int {
        global $CFG;
        $sitelimit = (int)get_config('core', 'maxbytes');
        if ($sitelimit <= 0) {
            $sitelimit = isset($CFG->maxbytes) ? (int)$CFG->maxbytes : 0;
        }
        if ($sitelimit <= 0) {
            return self::MAX_BYTES;
        }
        return min($sitelimit, self::MAX_BYTES);
    }
}
