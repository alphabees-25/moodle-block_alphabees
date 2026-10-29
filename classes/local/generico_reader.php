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
 * Reads the templates of the third-party Generico filter (filter_generico).
 *
 * Generico has no web-service functions. Its templates are plugin settings,
 * numbered 1..templatecount: templatekey_N, template_N, templatedefaults_N and
 * so on. Course content uses them as {GENERICO:type="key",name="value"} tags,
 * which the filter expands when the page is shown. A web-service read gets the
 * expanded output too — the template's "alternate" content where one is set —
 * never the tag, so the backend cannot learn from the content it syncs which
 * tags exist or what they take.
 *
 * This describes each template the way a generator needs it: the key, the
 * variables the template expects, their defaults (a|b|c lists the options,
 * the first is used when none is given), whether it wraps content with an end
 * tag, and where it is allowed. The JavaScript, the CSS and the dataset SQL
 * are reported as flags only.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Read-only view of the Generico templates configured on this site.
 */
class generico_reader {
    /** Frankenstyle name of the filter. */
    private const COMPONENT = 'filter_generico';

    /** Generico's own default when templatecount was never saved. */
    private const DEFAULT_TEMPLATE_COUNT = 20;

    /**
     * Variables Generico fills in itself; a tag cannot set them.
     *
     * The prefixed forms (@@USER:..@@, @@COURSE:..@@, @@URLPARAM:..@@) never
     * match the variable pattern in the first place.
     */
    private const SYSTEM_VARIABLES = ['AUTOID', 'WWWROOT', 'MOODLEPAGEID', 'CLOUDPOODLLTOKEN', 'renderedcontent'];

    /**
     * Describe the configured templates.
     *
     * @param string[] $keys Only these template keys; empty for all.
     * @param bool $withbodies Include the template HTML (start and end).
     * @param int $courseid When set, also say whether the filter is active in that course.
     * @return array
     */
    public static function describe(array $keys = [], bool $withbodies = true, int $courseid = 0): array {
        $plugin = \core_plugin_manager::instance()->get_plugin_info(self::COMPONENT);
        if (!$plugin || empty($plugin->versiondb)) {
            return ['available' => false, 'templates' => []];
        }

        $conf = (array)get_config(self::COMPONENT);
        $wanted = array_flip(array_filter(array_map('trim', $keys), 'strlen'));
        $count = (int)($conf['templatecount'] ?? self::DEFAULT_TEMPLATE_COUNT);

        $templates = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = trim((string)($conf['templatekey_' . $i] ?? ''));
            if ($key === '' || ($wanted && !isset($wanted[$key]))) {
                continue;
            }
            $templates[] = self::template($conf, $i, $key, $withbodies);
        }

        $result = [
            'available' => true,
            'version' => (int)$plugin->versiondb,
            'release' => (string)($plugin->release ?? ''),
            'state' => self::global_state(),
            'count' => count($templates),
            'templates' => $templates,
        ];
        if ($courseid > 0) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            $result['course'] = [
                'id' => $courseid,
                'exists' => (bool)$context,
                'active' => $context ? array_key_exists('generico', filter_get_active_in_context($context)) : false,
            ];
        }
        return $result;
    }

    /**
     * One template, from its numbered settings.
     *
     * @param array $conf All filter_generico settings.
     * @param int $i Template number.
     * @param string $key Template key, the tag's type.
     * @param bool $withbodies
     * @return array
     */
    private static function template(array $conf, int $i, string $key, bool $withbodies): array {
        $get = static fn(string $name): string => (string)($conf[$name . '_' . $i] ?? '');

        $body = $get('template');
        $end = $get('templateend');
        $defaults = self::parse_properties($get('templatedefaults'));

        // The variables a tag can set: everything the template substitutes,
        // in the HTML and in the script it hands its properties to, plus
        // anything only given a default.
        $variables = [];
        $scan = $body . ' ' . $end . ' ' . $get('templatealternate') . ' ' . $get('templatealternate_end')
            . ' ' . $get('templatescript') . ' ' . $get('datasetvars');
        if (preg_match_all('/@@([A-Za-z0-9_]+)@@/', $scan, $m)) {
            $variables = array_values(array_unique(array_diff($m[1], self::SYSTEM_VARIABLES)));
        }
        foreach (array_keys($defaults) as $name) {
            if (!in_array($name, $variables, true)) {
                $variables[] = $name;
            }
        }

        $described = [];
        foreach ($variables as $name) {
            $default = $defaults[$name] ?? null;
            $options = ($default !== null && strpos($default, '|') !== false) ? explode('|', $default) : [];
            $described[] = [
                'name' => $name,
                'default' => $options ? $options[0] : $default,
                'options' => $options,
            ];
        }

        $template = [
            'index' => $i,
            'key' => $key,
            'name' => $get('templatename') !== '' ? $get('templatename') : $key,
            'version' => $get('templateversion'),
            'instructions' => $get('templateinstructions'),
            'variables' => $described,
            'hasEndTag' => trim($end) !== '',
            'usage' => self::usage($key, $described, trim($end) !== ''),
            'allowedContexts' => self::csv($get('allowedcontexts')),
            'allowedContextIds' => array_map('intval', self::csv($get('allowedcontextids'))),
            'usesJavascript' => trim($get('templatescript')) !== '' || trim($get('templaterequire_js')) !== '',
            'usesCss' => trim($get('templatestyle')) !== '' || trim($get('templaterequire_css')) !== '',
            'usesDataset' => trim($get('dataset')) !== '',
            'shownInEditor' => !empty($conf['template_showatto_' . $i]),
        ];
        if ($withbodies) {
            $template['template'] = $body;
            $template['templateEnd'] = $end;
        }
        return $template;
    }

    /**
     * Parse name=value pairs the way Generico parses a tag.
     *
     * Uses Generico's own parser where it exists, so quoting and its cleaning
     * rules match what the filter will do with the tag; the fallback is a
     * copy of the same expression.
     *
     * @param string $properties The inside of a tag, e.g. width="640",height="360".
     * @return array<string, string>
     */
    private static function parse_properties(string $properties): array {
        if (trim($properties) === '') {
            return [];
        }
        $tag = '{GENERICO:' . $properties . '}';
        if (
            class_exists('\\filter_generico\\generico_utils')
            && method_exists('\\filter_generico\\generico_utils', 'fetch_filter_properties')
        ) {
            $parsed = \filter_generico\generico_utils::fetch_filter_properties($tag);
            return is_array($parsed) ? array_map('strval', $parsed) : [];
        }
        $parsed = [];
        if (preg_match_all('/([^=,]*)=("[^"]*"|[^,"]*)/', strip_tags($properties), $m)) {
            foreach ($m[1] as $n => $name) {
                $name = trim($name);
                if ($name !== '') {
                    $parsed[$name] = str_replace('@@', '', trim($m[2][$n], '"'));
                }
            }
        }
        return $parsed;
    }

    /**
     * A ready-to-use tag with every variable set to its default.
     *
     * @param string $key
     * @param array $variables As described by template().
     * @param bool $hasend
     * @return string
     */
    private static function usage(string $key, array $variables, bool $hasend): string {
        $parts = ['type="' . $key . '"'];
        foreach ($variables as $variable) {
            $parts[] = $variable['name'] . '="' . (string)($variable['default'] ?? '') . '"';
        }
        $tag = '{GENERICO:' . implode(',', $parts) . '}';
        return $hasend ? $tag . ' … {GENERICO:type="' . $key . '_end"}' : $tag;
    }

    /**
     * The filter's site-wide state: on, off (available per context) or disabled.
     *
     * @return string
     */
    private static function global_state(): string {
        $states = filter_get_global_states();
        $active = isset($states['generico']) ? (int)$states['generico']->active : TEXTFILTER_DISABLED;
        if ($active === TEXTFILTER_ON) {
            return 'on';
        }
        return $active === TEXTFILTER_OFF ? 'off' : 'disabled';
    }

    /**
     * Split one of Generico's comma lists.
     *
     * @param string $value
     * @return string[]
     */
    private static function csv(string $value): array {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }
}
