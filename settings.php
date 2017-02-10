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
 * Global settings
 *
 * @package   local_lessonexport
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    if (!$page = $ADMIN->locate('modsettinglesson')) {
        // No settings page exists for the lesson - add it.
        $lessonname = get_string('pluginname', 'lesson');
        $page = new admin_settingpage('modsettinglesson', $lessonname);

        // Insert the new lesson settings page in the correct alphabetical order.
        $beforesibling = null;
        $modules = $ADMIN->locate('modsettings');
        foreach ($modules->children as $module) {
            if (strcmp($module->visiblename, $lessonname) > 0) {
                $beforesibling = $module->name;
                break;
            }
        }
        $ADMIN->add('modsettings', $page, $beforesibling);
    }

    $page->add(new admin_setting_configtext('local_lessonexport/publishemail', get_string('publishemail', 'local_lessonexport'),
                                            get_string('publishemail_desc', 'local_lessonexport'), '', PARAM_EMAIL));

    $page->add(new admin_setting_configtextarea('local_lessonexport/customtyle', get_string('customstyle', 'local_lessonexport'),
                                            get_string('customstyle_desc', 'local_lessonexport'), '', PARAM_RAW));
}