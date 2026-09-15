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
 * Helpers for the optional learner-facing text overrides.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Optional custom block texts (web accordion + mobile help).
 */
class custom_texts {
    /** Plugin component name. */
    private const COMPONENT = 'block_alphabees';

    /** Setting names of all optional overrides. */
    public const SETTINGS = [
        'customtexts_enabled',
        'custom_blocktitle',
        'custom_usagetitle',
        'custom_usagetext',
        'custom_mobilehelplabel',
        'custom_mobilehelptext',
    ];

    /** The four info texts the toggle governs. The block title is not one of them. */
    public const INFO_TEXTS = [
        'custom_usagetitle',
        'custom_usagetext',
        'custom_mobilehelplabel',
        'custom_mobilehelptext',
    ];

    /**
     * Whether the custom info texts should be used at all.
     *
     * A site can leave its wording in the fields and still fall back to the
     * built-in texts by unticking the toggle — useful for trying something out
     * without losing it.
     *
     * @return bool
     */
    public static function info_texts_enabled(): bool {
        return (bool)get_config(self::COMPONENT, 'customtexts_enabled');
    }

    /**
     * One custom info text, or null when the built-in text should be used.
     *
     * @param string $setting One of INFO_TEXTS.
     * @return string|null
     */
    public static function info_text(string $setting): ?string {
        if (!self::info_texts_enabled() || !in_array($setting, self::INFO_TEXTS, true)) {
            return null;
        }
        $value = trim((string)get_config(self::COMPONENT, $setting));
        return $value === '' ? null : $value;
    }

    /**
     * The name the block carries, site-wide.
     *
     * Sites that run the tutor under their own product name set this; the
     * per-instance title in the block's own configuration overrides it again.
     * Deliberately outside the info-text toggle — renaming the block is the
     * common wish and should not need a second switch.
     *
     * @return string
     */
    public static function block_title(): string {
        $custom = trim((string)get_config(self::COMPONENT, 'custom_blocktitle'));
        return $custom !== '' ? $custom : get_string('pluginname', self::COMPONENT);
    }

    /**
     * Persist empty strings for overrides that have never been saved.
     *
     * Moodle lists every admin setting without a stored value on the
     * post-upgrade "New settings" page. These fields are purely optional
     * (empty = built-in localized text), so pre-seeding them keeps admins
     * from being prompted for something they do not need to touch. Existing
     * values are never overwritten.
     *
     * @return void
     */
    public static function seed_defaults(): void {
        foreach (self::SETTINGS as $name) {
            if (get_config(self::COMPONENT, $name) === false) {
                set_config($name, '', self::COMPONENT);
            }
        }
    }
}
