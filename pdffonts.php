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
 * Font Settings
 *
 * @package   local_lessonexport
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once("$CFG->libdir/adminlib.php");
require_once("$CFG->libdir/formslib.php");
require_once("$CFG->libdir/filelib.php");
require_once("$CFG->libdir/pdflib.php");
require_once(__DIR__ . "/forms/fontupload.php");

require_login();

$fontsdir = TCPDF_FONTS::_getfontpath();
$context = context_user::instance($USER->id);
$continue = new moodle_url('/local/lessonexport/pdffonts.php');

$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title("PDF Font Settings");
$PAGE->set_heading("PDF Fonts");
$PAGE->set_url($CFG->wwwroot.'/local/lessonexport/pdffonts.php');

// Note: Print the header and alert-info conditionally to prevent moodle complaining about
// redirects after printing content.

$uploadForm = new fontupload_form();
//Form processing and displaying is done here
if ($uploadForm->is_cancelled()) {
	//Handle form cancel operation, if cancel button is present on form
	redirect($continue);
} elseif ($fromform = $uploadForm->get_data()) {
	echo $OUTPUT->header();
	echo "<div class='alert alert-block alert-info'><p>Your fonts directory can be located at $fontsdir</p></div>";

	// Process the data from the form.
	$formdata = $uploadForm->get_data();
	$itemid = $formdata->fonts;

	$fs = get_file_storage();
	$files = $fs->get_area_files($context->id, "user", "draft", $itemid, 'filename', false);

	foreach ($files as $file) {
		$filename = $fontsdir.$file->get_filename();
		$file->copy_content_to($filename);
		$newfont = TCPDF_FONTS::addTTFfont($filename);
		unlink($filename);
		$file->delete();

		echo "<div class='fontUploaded'><p>Added ".$file->get_filename()." to the available TCPDF fonts as $newfont</p></div>";
	}

	echo "<div class='continuebutton'>(<a href='$continue'>Continue</a>)</div>";
} else {
	echo $OUTPUT->header();
	echo "<div class='alert alert-block alert-info'><p>Your fonts directory can be located at $fontsdir</p></div>";
    $uploadForm->display();
}

echo $OUTPUT->footer();
