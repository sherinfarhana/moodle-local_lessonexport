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
global $CFG;

require_once($CFG->dirroot.'/local/lessonexport/lang/Language.php');

class EnglishLanguage extends Language {
    public $rtl;

    public function __construct($settings = array('rtl' => true)) {
        foreach ($settings as $setting => $value) {
            switch ($setting) {
                case 'rtl':
                    $rtl = $value;
                    break;
            }
        }
    }

    public function apply_language($pdf) {
        $pdf->setRTL(false);
    }

    public function reset_language($pdf) {
        $pdf->setRTL(false);
    }

    public function is_RTL() {
        return $this->rtl;
    }
}