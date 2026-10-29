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
 * The parameter and return schemas of every function in the Alphabees service.
 *
 * Lets the backend offer this site's functions to its agents as tools — with
 * the parameters, types, defaults and descriptions this Moodle version and
 * these plugins actually have — instead of hand-writing one wrapper per
 * function and keeping it in step with every release. See
 * local\function_schema for the mapping.
 *
 * Schemas are JSON-encoded strings: a JSON Schema is a recursive tree, and the
 * external API cannot declare a return value of arbitrary depth. `hash`
 * changes whenever any schema does; sent back as known_hash, it turns the
 * answer into `unchanged` without the schemas, which for the whole service
 * are about half a megabyte.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\external;

use block_alphabees\local\function_schema;
use block_alphabees\local\plugin_features;
use block_alphabees\local\ws_setup;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External function block_alphabees_describe_functions.
 */
class describe_functions extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'functions' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Function name.'),
                'Only these functions; empty for the whole service.',
                VALUE_DEFAULT,
                []
            ),
            'known_hash' => new external_value(
                PARAM_ALPHANUM,
                'Hash from an earlier answer for the same selection; if it still holds, no schemas are sent.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Describe the service's functions.
     *
     * @param array $functions
     * @param string $knownhash
     * @return array
     */
    public static function execute(array $functions = [], string $knownhash = ''): array {
        global $CFG, $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['functions' => $functions, 'known_hash' => $knownhash]
        );
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);
        require_capability('block/alphabees:usewebservice', $systemcontext);

        $service = $DB->get_record('external_services', ['shortname' => ws_setup::SERVICE_SHORTNAME], 'id', MUST_EXIST);
        $names = $DB->get_fieldset_select(
            'external_services_functions',
            'functionname',
            'externalserviceid = ? ORDER BY functionname',
            [$service->id]
        );
        if ($params['functions']) {
            $names = array_values(array_intersect($names, $params['functions']));
        }

        $described = [];
        $unavailable = [];
        foreach ($names as $name) {
            $function = function_schema::describe($name);
            if ($function === null) {
                $unavailable[] = $name;
                continue;
            }
            $described[] = $function;
        }

        $out = [];
        foreach ($described as $function) {
            $out[] = [
                'name' => $function['name'],
                'component' => $function['component'],
                'type' => $function['type'],
                'description' => $function['description'],
                'capabilities' => $function['capabilities'],
                'deprecated' => $function['deprecated'],
                'parameters' => json_encode($function['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'returns' => $function['returns'] === null
                    ? null
                    : json_encode($function['returns'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        $hash = sha1(json_encode([$out, $unavailable]));
        $unchanged = $params['known_hash'] !== '' && hash_equals($hash, $params['known_hash']);
        return [
            'service' => ws_setup::SERVICE_SHORTNAME,
            'moodle_release' => (string)$CFG->release,
            'plugin_version_code' => plugin_features::version()['version'],
            'hash' => $hash,
            'unchanged' => $unchanged,
            'functions' => $unchanged ? [] : $out,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'service' => new external_value(PARAM_ALPHANUMEXT, 'Service shortname.'),
            'moodle_release' => new external_value(PARAM_RAW, 'Moodle release string.'),
            'plugin_version_code' => new external_value(PARAM_INT, 'Plugin version code.'),
            'hash' => new external_value(PARAM_ALPHANUM, 'Changes whenever any schema changes.'),
            'unchanged' => new external_value(PARAM_BOOL, 'known_hash still holds; functions is then empty.'),
            'functions' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'Function name, as called by token.'),
                'component' => new external_value(PARAM_COMPONENT, 'Component that provides it.'),
                'type' => new external_value(PARAM_ALPHA, 'read | write'),
                'description' => new external_value(PARAM_RAW, 'What the function does, from its declaration.'),
                'capabilities' => new external_multiple_structure(
                    // Raw: a declaration may name a capability this site lacks,
                    // and PARAM_CAPABILITY would then fail the whole answer.
                    new external_value(PARAM_RAW, 'Capability.'),
                    'Capabilities the function checks, as declared.'
                ),
                'deprecated' => new external_value(PARAM_BOOL, 'Deprecated in this Moodle version.'),
                'parameters' => new external_value(PARAM_RAW, 'JSON Schema of the parameters, JSON-encoded.'),
                'returns' => new external_value(
                    PARAM_RAW,
                    'JSON Schema of the return value, JSON-encoded; null when it returns nothing.',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
            ])),
            'unavailable' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Function name.'),
                'In the service but not loadable on this site.'
            ),
        ]);
    }
}
