<?php
// This file is part of Moodle http://moodle.org/
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
 * @package   local_lessonexportepub
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    if ($ADMIN->locate('lessonexport') == null) {
        $ADMIN->add('modules', new admin_category('lessonexport', get_string('plugingroup', 'local_lessonexportepub')));
    }
    $page = new admin_settingpage('lessonexportepubpage', get_string('pluginname', 'local_lessonexportepub'));

    $customStyleDefault = '
        html, body {
            font-family: "Helvetica", sans-serif;
        }';
    $page->add(new admin_setting_configtextarea('local_lessonexportepub/customstyle',
                                            get_string('customstyle', 'local_lessonexportepub'),
                                            get_string('customstyle_desc', 'local_lessonexportepub'), $customStyleDefault, PARAM_RAW));

    $page->add(new admin_setting_configcheckbox('local_lessonexportepub/exportstrict',
                                            get_string('exportstrict', 'local_lessonexportepub'),
                                            get_string('exportstrict_desc', 'local_lessonexportepub'), 0));

    $page->add(new admin_setting_configcolourpicker('local_lessonexportepub/coverColour',
                                            get_string('covercolour', 'local_lessonexportepub'),
                                            get_string('covercolour_desc', 'local_lessonexportepub'), '#12A053'));

    $ADMIN->add('lessonexport', $page);
}