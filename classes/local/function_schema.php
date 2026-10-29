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
 * Turns Moodle's external-function descriptions into JSON Schema.
 *
 * Every external function declares its parameters and its return value as a
 * tree of external_value / external_single_structure /
 * external_multiple_structure. Moodle checks calls against that tree, but no
 * web-service function hands it out: a token can call a function and learn
 * its name from core_webservice_get_site_info, never its shape. The backend
 * needs the shape to offer the functions to its agents as tools.
 *
 * The mapping keeps what Moodle enforces: VALUE_REQUIRED keys are required,
 * VALUE_DEFAULT keys carry their default, and an object accepts no keys it
 * does not list, because Moodle rejects unknown parameters. NULL_ALLOWED makes
 * a return value nullable but never a parameter: external_value allows null
 * by default, so every parameter would read as nullable, and REST cannot send
 * a null anyway — a key is either there or not. The PARAM_* type travels along as
 * x-moodle-type, since "string" alone says little about PARAM_ALPHANUMEXT or
 * PARAM_RAW.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External description to JSON Schema.
 */
class function_schema {
    /**
     * The JSON Schema for one description, recursively.
     *
     * @param external_description $desc
     * @param bool $nullable Whether NULL_ALLOWED shows: true for returns, false for parameters.
     * @return array
     */
    public static function of(external_description $desc, bool $nullable = true): array {
        if ($desc instanceof external_value) {
            $schema = ['type' => self::json_type((string)$desc->type), 'x-moodle-type' => (string)$desc->type];
        } else if ($desc instanceof external_single_structure) {
            $schema = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            $required = [];
            foreach ($desc->keys as $key => $child) {
                $schema['properties'][$key] = self::of($child, $nullable);
                if ((int)$child->required === VALUE_REQUIRED) {
                    $required[] = $key;
                }
            }
            if ($required) {
                $schema['required'] = $required;
            }
            if (!$schema['properties']) {
                // An empty array in JSON is a list; a schema wants an object here.
                $schema['properties'] = new \stdClass();
            }
        } else if ($desc instanceof external_multiple_structure) {
            $schema = ['type' => 'array', 'items' => self::of($desc->content, $nullable)];
        } else {
            // A description type this plugin does not know: say so rather than guess.
            $schema = ['x-moodle-type' => get_class($desc)];
        }

        if ((int)($desc->required ?? VALUE_REQUIRED) === VALUE_DEFAULT && isset($desc->default)) {
            $default = $desc->default;
            if ($default === [] && ($schema['type'] ?? '') === 'object') {
                // Moodle writes an empty default as [], which JSON reads as a list.
                $default = new \stdClass();
            }
            $schema['default'] = $default;
        }
        if ($nullable && !empty($desc->allownull) && isset($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        $text = self::text($desc->desc ?? '');
        if ($text !== '') {
            $schema['description'] = $text;
        }
        return $schema;
    }

    /**
     * A description with the source's line breaks and indentation folded.
     *
     * Core wraps long descriptions inside its PHP, and the indentation would
     * otherwise travel along in every schema.
     *
     * @param mixed $text
     * @return string
     */
    public static function text($text): string {
        return trim(preg_replace('/\s+/u', ' ', (string)$text));
    }

    /**
     * The JSON type a PARAM_* value travels as.
     *
     * @param string $paramtype
     * @return string
     */
    public static function json_type(string $paramtype): string {
        switch ($paramtype) {
            case PARAM_INT:
                return 'integer';
            case PARAM_FLOAT:
            case PARAM_LOCALISEDFLOAT:
                return 'number';
            case PARAM_BOOL:
                return 'boolean';
            default:
                return 'string';
        }
    }

    /**
     * Describe one external function.
     *
     * @param string $name Function name.
     * @return array|null Null when the function cannot be loaded on this site.
     */
    public static function describe(string $name): ?array {
        try {
            $info = \core_external\external_api::external_function_info($name, IGNORE_MISSING);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$info) {
            return null;
        }
        return [
            'name' => (string)$info->name,
            'component' => (string)($info->component ?? ''),
            'type' => (string)($info->type ?? ''),
            'description' => self::text($info->description ?? ''),
            'capabilities' => array_values(array_filter(array_map('trim', explode(',', (string)($info->capabilities ?? ''))))),
            'deprecated' => !empty($info->deprecated),
            'parameters' => self::of($info->parameters_desc, false),
            'returns' => $info->returns_desc ? self::of($info->returns_desc) : null,
        ];
    }
}
