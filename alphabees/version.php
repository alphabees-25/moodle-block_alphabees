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
 * Version details for the Alphabees AI Tutor block plugin.
 *
 * @package   block_alphabees
 * @copyright 2025 Alphabees
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The plugin's frankenstyle component name.
$plugin->component = 'block_alphabees';

// The plugin version in YYYYMMDDXX format.
$plugin->version = 2025100901;

// Minimum Moodle version required for this plugin.
$plugin->requires = 2022112800;

// List of supported Moodle versions.
$plugin->supported = [401, 501];

// Maturity level of the plugin: MATURITY_ALPHA, MATURITY_BETA, MATURITY_RC, or MATURITY_STABLE.
$plugin->maturity = MATURITY_STABLE;

// Human-readable version information for version.
$plugin->release = '2.0.1';
