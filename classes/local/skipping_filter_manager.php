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
 * A filter manager that leaves out named filters, installed for a moment.
 *
 * format_text() runs the active filters in several stages around cleaning.
 * Rebuilding that sequence to leave one filter out would drift from core on
 * the next release, so instead this stands in for the filter manager while a
 * block of formatting runs, and adds the skipped filters to every call.
 *
 * @package   block_alphabees
 * @copyright 2026 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_alphabees\local;

/**
 * Filter manager that always skips some filters.
 */
class skipping_filter_manager extends \filter_manager {
    /** @var string[] Filters to leave out, by name without the filter_ prefix. */
    private array $skip = [];

    /**
     * Run $fn with every format_text() call skipping these filters.
     *
     * @param string[] $skip
     * @param callable $fn
     * @return mixed What $fn returns.
     */
    public static function with_skipped(array $skip, callable $fn) {
        $previous = self::$singletoninstance;
        $manager = new self();
        $manager->skip = $skip;
        self::$singletoninstance = $manager;
        try {
            return $fn();
        } finally {
            self::$singletoninstance = $previous;
        }
    }

    /**
     * Filter text, leaving out the configured filters.
     *
     * @param string $text
     * @param \context $context
     * @param array $options
     * @param array|null $skipfilters
     * @return string
     */
    public function filter_text($text, $context, array $options = [], ?array $skipfilters = null) {
        return parent::filter_text($text, $context, $options, array_merge((array)$skipfilters, $this->skip));
    }
}
