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
 * Font Upload Form
 *
 * @package   local_lessonexport
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class fontupload_form extends moodleform {
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        $maxbytes = 1024000 * 512; // ~512MB
        $mform->addElement('filemanager', 'fonts', "Upload Fonts", null,
                    array('subdirs' => 0, 'maxbytes' => $maxbytes, 'areamaxbytes' => $maxbytes, 'maxfiles' => 50,
                          'accepted_types' => array('.ttf', '.otf')));

        $this->add_action_buttons(true, "Upload");
    }

    function validation($data, $files) {
        return array();
    }
}