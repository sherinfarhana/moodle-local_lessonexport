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
 * Version information
 *
 * @package   local_lessonexport
 * @author    Adam King
 * @author    Davo Smith
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2017022200;
$plugin->requires  = 2014051200; // Moodle 2.7.
$plugin->cron      = DAYSECS;
$plugin->component = 'local_lessonexport';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = "2.7+ (Build: 2017022200)";

$plugin->dependencies = array(
    'mod_lesson' => ANY_VERSION,
);
