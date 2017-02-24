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
 * @package   local_lessonexport
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('modules', new admin_category('lessonexport', 'Lesson Export'));
    $page = new admin_settingpage('lessonexportpage', 'Lesson Export');

    $page->add(new admin_setting_configtextarea('local_lessonexport/customstyle', get_string('customstyle', 'local_lessonexport'),
                                            get_string('customstyle_desc', 'local_lessonexport'), '', PARAM_RAW));

    $page->add(new admin_setting_configtext('local_lessonexport/customfont', get_string('customfont', 'local_lessonexport'),
                                            get_string('customfont_desc', 'local_lessonexport'), 'helvetica', PARAM_RAW));

    $page->add(new admin_setting_configpasswordunmask('local_lessonexport/pdfUserPassword', get_string('pdfuserpassword', 'local_lessonexport'),
                                            get_string('pdfuserpassword_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_configpasswordunmask('local_lessonexport/pdfOwnerPassword', get_string('pdfownerpassword', 'local_lessonexport'),
                                            get_string('pdfownerpassword_desc', 'local_lessonexport'), ''));

    // Footer text areas.
    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterTopLeft', get_string('pdffootertopleft', 'local_lessonexport'),
                                            get_string('pdffootertopleft_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterTopMiddle', get_string('pdffootertopmiddle', 'local_lessonexport'),
                                            get_string('pdffootertopmiddle_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterTopRight', get_string('pdffootertopright', 'local_lessonexport'),
                                            get_string('pdffootertopright_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterBottomLeft', get_string('pdffooterbottomleft', 'local_lessonexport'),
                                            get_string('pdffooterbottomleft_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterBottomMiddle', get_string('pdffooterbottommiddle', 'local_lessonexport'),
                                            get_string('pdffooterbottommiddle_desc', 'local_lessonexport'), ''));

    $page->add(new admin_setting_confightmleditor('local_lessonexport/pdfFooterbottomRight', get_string('pdffooterbottomright', 'local_lessonexport'),
                                            get_string('pdffooterbottomright_desc', 'local_lessonexport'), ''));
    // PDF permission settings.
    $choices = array(
        'print'     => "Print the document",
        'modify'    => "Modify the document",
        'copy'      => "Copy the document",
        'annotate'  => "Annotate documents",
        'forms'     => "Fill forms on the document",
        'extract'   => "Extract pages fromt he document",
        'assemble'  => "Assemble the document",
        'high-def'  => "Print the document in high definition"
    );
    $defaults = array(
        // 'print'     => 'enabled',   // print
        //'modify'    => 'enabled',  // modify
        //'copy'      => 'enabled',  // copy
        //'annotate'  => 'enabled',  // annotate
        // 'forms'     => 'enabled',   // forms
        //'extract'   => 'enabled',  // extract
        //'assemble'  => 'enabled',  // assemble
        // 'high-def'  => 'enabled'    // high-def
    );
    $page->add(new admin_setting_configmulticheckbox('local_lessonexport/pdfProtection', get_string('pdfprotection','local_lessonexport'),
                                            get_string('pdfprotection_desc', 'local_lessonexport'), $defaults, $choices));

    $page->add(new admin_setting_configcheckbox('local_lessonexport/exportstrict', get_string('exportstrict', 'local_lessonexport'),
    get_string('exportstrict_desc', 'local_lessonexport'), 0));

    $ADMIN->add('lessonexport', $page);
}