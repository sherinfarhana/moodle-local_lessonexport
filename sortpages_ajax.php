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
 * Sorting lesson pages, ready for export.
 *
 * @package   local_lessonexport
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname(__FILE__).'/../../config.php');
global $CFG, $DB, $USER, $PAGE, $OUTPUT;
require_once($CFG->dirroot.'/local/lessonexport/lib.php');

$cmid = required_param('id', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$action = optional_param('action', null, PARAM_ALPHA);

$user = null;
$cm = get_coursemodule_from_id('lesson', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$lesson = $DB->get_record('lesson', array('id' => $cm->instance), '*', MUST_EXIST);

$userid = required_param('userid', PARAM_INT);
if ($userid == $USER->id) {
    $user = $USER;    
} else {
    $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
}

$group = null;
if ($groupid && $cm->groupmode != NOGROUPS) {
    $group = $DB->get_record('groups', array('id' => $groupid, 'courseid' => $course->id), '*', MUST_EXIST);
}

$url = new moodle_url('/local/lessonexport/sortpages.php', array('id' => $cm->id));
if ($user) {
    $url->param('userid', $user->id);
}
if ($group) {
    $url->param('groupid', $group->id);
}
$PAGE->set_url($url);

require_login($course, false, $cm);

$sortpages = new local_lessonexport_sortpages($cm, $lesson, $user, $group);
$sortpages->check_access();
$sortpages->process($action);
