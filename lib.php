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
 * Library functions
 *
 * @package   local_lessonexport
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir.'/pdflib.php');

class local_lessonexport {
    /** @var object */
    protected $cm;
    /** @var object */
    protected $lesson;
    /** @var local_lessonexport_info */
    protected $lessoninfo;
    /** @var string */
    protected $exporttype;
    /** @var object */
    protected $userid;
    /** @var object */
    protected $groupid;

    const EXPORT_PDF = 'pdf';

    const MAX_EXPORT_ATTEMPTS = 2;

    protected static $exporttypes = array(self::EXPORT_PDF);

    public function __construct($cm, $lesson, $exporttype, $userid = 0, $groupid = 0) {
        $this->cm = $cm;
        $this->lesson = $lesson;
        if (in_array($exporttype, self::$exporttypes)) {
            $this->exporttype = $exporttype;
        } else {
            $this->exporttype = reset(self::$exporttypes); // Default to first type in the list.
        }
        $this->userid = $userid;
        if (is_object($this->userid)) {
            $this->userid = $this->userid->id;
        } else if ($this->userid === null) {
            $this->userid = 0;
        }
        $this->groupid = $groupid;
        if (is_object($this->groupid)) {
            $this->groupid = $this->groupid->id;
        } else if ($this->groupid === null) {
            $this->groupid = 0;
        }
        $this->lessoninfo = new local_lessonexport_info();
    }

    public static function get_links($cm, $userid = null, $groupid = null) {
        $ret = array();
        $context = context_module::instance($cm->id);

        // Add links for the different export types.
        foreach (self::$exporttypes as $exporttype) {
            $capability = 'local/lessonexport:export'.$exporttype;
            if (has_capability($capability, $context)) {
                $name = get_string('export'.$exporttype, 'local_lessonexport');
                $url = new moodle_url('/local/lessonexport/export.php', array('id' => $cm->id, 'type' => $exporttype));
                if ($userid) {
                    $url->param('userid', $userid);
                }
                if ($groupid) {
                    $url->param('groupid', $groupid);
                }
                $ret[$name] = $url;
            }
        }

        // Add the 'sort pages' link.
        if (has_capability('mod/lesson:managelesson', $context)) {
            $name = get_string('sortpages', 'local_lessonexport');
            $url = new moodle_url('/local/lessonexport/sortpages.php', array('id' => $cm->id));
            if ($userid) {
                $url->param('userid', $userid);
            }
            if ($groupid) {
                $url->param('groupid', $groupid);
            }
            $ret[$name] = $url;
        }
        return $ret;
    }

    public function check_access() {
        global $USER;
        $context = context_module::instance($this->cm->id);
        $capability = 'local/lessonexport:export'.$this->exporttype;
        require_capability($capability, $context);

        $groupmode = groups_get_activity_groupmode($this->cm);
        if ($this->groupid) {
            if ($groupmode == SEPARATEGROUPS) {
                if (!groups_is_member($this->groupid)) {
                    if (!has_capability('mod/lesson:managelesson', $context)) {
                        require_capability('moodle/site:accessallgroups', $context);
                    }
                }
            }
        }
        if ($this->userid) {
            if ($this->userid !== $USER->id) {
                if ($this->cm->groupmode != VISIBLEGROUPS) {
                    require_capability('mod/lesson:managelesson', $context);
                }
            }
        }
    }

    /**
     * Generate the export file and (optionally) send direct to the user's browser.
     *
     * @param bool $download (optional) true to send the file directly to the user's browser
     * @return string the path to the generated file, if not downloading directly
     */
    public function export($download = true) {
        // Raise the max execution time to 5 min, not 30 seconds.
        @set_time_limit(300);

        $pages = $this->load_pages();
        $exp = $this->start_export($download);
        $this->add_coversheet($exp);
        foreach ($pages as $page) {
            $this->export_page($exp, $page);
        }
        return $this->end_export($exp, $download);
    }

    public static function cron() {
        $config = get_config('local_lessonexport');
        if (empty($config->publishemail)) {
            return; // No email specified.
        }
        if (!$destemail = trim($config->publishemail)) {
            return; // Email is empty.
        }
        if (empty($config->lastcron)) {
            return; // Don't export every lesson on the site the first time cron runs.
        }

        // Update the list of lessons waiting to be exported.
        self::update_queue($config);

        $touser = (object)array(
            'id' => -1,
            'email' => $destemail,
            'maildisplay' => 0,
        );
        foreach (get_all_user_name_fields(false) as $fieldname) {
            $touser->$fieldname = '';
        }

        $msg = get_string('lessonupdated_body', 'local_lessonexport');
        while ($lesson = self::get_next_from_queue()) {
            if ($lesson->exportattempts == self::MAX_EXPORT_ATTEMPTS) {
                // Already failed to export the maximum allowed times - drop an email to the user to let them know, then move on
                // to the next lesson to export.
                $lessonurl = new moodle_url('/mod/lesson/view.php', array('id' => $lesson->cm->id));
                $info = (object)array(
                    'name' => $lesson->name,
                    'url' => $lessonurl->out(false),
                    'exportattempts' => $lesson->exportattempts,
                );
                $failmsg = get_string('lessonexportfailed_body', 'local_lessonexport', $info);
                email_to_user($touser, $touser, get_string('lessonexportfailed', 'local_lessonexport', $lesson->name), $failmsg);
            }

            // Attempt the export.
            try {
                $export = new local_lessonexport($lesson->cm, $lesson, self::EXPORT_PDF, $userid, $subwiki->groupid);
                $filepath = $export->export(false);
                $filename = basename($filepath);
                email_to_user($touser, $touser, get_string('lessonupdated', 'local_lessonexport', $lesson->name), $msg, '',
                              $filepath, $filename, false);
                @unlink($filepath);

                // Export successful - update the queue.
                self::remove_from_queue($lesson);
            } catch(Exception $e) {
                print_r($e);
                print_r($lesson);
            }
        }
    }

    /**
     * Find any lessons that have been updated since we last refeshed the export queue.
     * Any lessons that have been updated will have thier export attempt count reset.
     *
     * @param $config
     */
    protected static function update_queue($config) {
        global $DB;

        if (empty($config->lastqueueupdate)) {
            $config->lastqueueupdate = $config->lastcron;
        }

        // Get a list of any lessons that have been changed since the last queue update.
        $sql = "SELECT DISTINCT l.id, l.lessonid
                  FROM {lesson} l
                  JOIN {lesson_pages} p ON p.lessonid = l.id AND p.timemodified > :lastqueueupdate
                 ORDER BY l.lessonid, l.id";
        $params = array('lastqueueupdate' => $config->lastqueueupdate);
        $lessons = $DB->get_records_sql($sql, $params);

        // Save a list of all lessons to be exported.
        $currentqueue = $DB->get_records('local_lessonexport_queue');
        foreach ($lessons as $lesson) {
            if (isset($currentqueue[$lesson->id])) {
                // A lesson already in the queue has been updated - reset the export attempts (if non-zero).
                $queueitem = $currentqueue[$lesson->id];
                if ($queueitem->exportattempts != 0) {
                    $DB->set_field('local_lessonexport_queue', 'exportattempts', 0, array('id' => $queueitem->id));
                }
            } else {
                $ins = (object)array(
                    'lessonid' => $lesson->id,
                    'exportattempts' => 0,
                );
                $DB->insert_record('local_lessonexport_queue', $ins, false);
            }
        }

        // Save the timestamp to detect any future lesson export changes.
        set_config('lastqueueupdate', time(), 'local_lessonexport');
    }

    /**
     * Get the next lesson in the queue - ignoring those that have already had too many export attempts.
     * The return object includes the lesson and cm as sub-objects.
     *
     * @return object|null null if none left to export
     */    
	protected static function get_next_from_queue() {
        global $DB;

        static $cm = null;
        static $lesson = null;

        $sql = "SELECT l.id, q.id AS queueid, q.exportattempts
                FROM {local_lessonexport_queue} q
                JOIN {lesson} l ON l.id = q.lessonid
                WHERE q.exportattempts <= :maxexportattempts
                ORDER BY l.id";

        $params = array('maxexportattempts' => self::MAX_EXPORT_ATTEMPTS);
        $nextitems = $DB->get_records_sql($sql, $params, 0, 1); // Retrieve the first record found.
        $nextitem = reset($nextitems);
        if (!$nextitem) {
            return null;
        }

        // Update the 'export attempts' in the database.
        $DB->set_field('local_lessonexport_queue', 'exportattempts', $nextitem->exportattempts + 1, ['id' => $nextitem->queueid]);

        // Add the lesson + cm objects to the return object.
        if (!$lesson || $lesson->id != $nextitem->lessonid) {
            if (!$lesson == $DB->get_record('lesson', array('id' => $nextitem->lessonid))) {            
                mtrace("Page updated for lesson ID {$nextitem->lessonid}, which does not exist\n");
                return self::get_next_from_queue();
            }
            if (!$cm = get_coursemodule_from_instance('lesson', $lesson->id)) {
                mtrace("Missing course module for lesson ID {$lesson->id}\n");
                return self::get_next_from_queue();
            }
        }
        $nextitem->lesson = $lesson;
        $nextitem->cm = $cm;

        return $nextitem;
    }

    /**
     * Remove the lesson from the export queue, after it has been successfully exported.
     *
     * @param object $lesson
     */
    protected static function remove_from_queue($lesson) {
        global $DB;
        $DB->delete_records('local_lessonexport_queue', array('id' => $lesson->queueid));
    }
	
    protected function load_pages() {
        global $DB, $USER;

        $sql = "SELECT p.id, p.title, p.contents, p.timecreated, p.timemodified
                  FROM {lesson_pages} p
                  LEFT JOIN {local_lessonexport_order} xo ON xo.pageid = p.id
                 WHERE p.lessonid = :lessonid
                 ORDER BY xo.sortorder, p.title";
        $params = array('lessonid' => $this->lesson->id);
        $pages = $DB->get_records_sql($sql, $params);
        $pageids = array_keys($pages);

        $context = context_module::instance($this->cm->id);
        foreach ($pages as $page) {
            // Fix pluginfile urls.
            $page->contents = file_rewrite_pluginfile_urls($page->contents, 'pluginfile.php', $context->id,
                                                          'mod_lesson', 'page_contents', $page->id);
            $page->contents = format_text($page->contents, FORMAT_MOODLE, array('overflowdiv' => true, 'allowid' => true));

            // Fix internal links.
            $this->fix_internal_links($page, $pageids);

            // Note created/modified time (if earlier / later than already recorded).
            $this->lessoninfo->update_times($page->timecreated, $page->timemodified, $USER->id);
        }

        return $pages;
    }

    protected function fix_internal_links($page, $pageids) {
        if ($this->exporttype == self::EXPORT_PDF) {
            // Fix internal TOC links to include the pageid (to make them unique across all pages).
            if (preg_match_all('|<a href="#([^"]+)"|', $page->contents, $matches)) {
                $anchors = $matches[1];
                foreach ($anchors as $anchor) {
                    $page->contents = str_replace($anchor, $anchor.'-'.$page->id, $page->contents);
                }
            }
        }

        // Replace links to other pages with anchor links to '#pageid-[page id]' (PDF)
        $baseurl = new moodle_url('/mod/lesson/view.php', array('pageid' => 'PAGEID'));
        $baseurl = $baseurl->out(false);
        $baseurl = preg_quote($baseurl);
        $baseurl = str_replace(array('&', 'PAGEID'), array('(&|&amp;)', '(\d+)'), $baseurl);
        if (preg_match_all("|$baseurl|", $page->contents, $matches)) {
            $ids = $matches[count($matches) - 1];
            $urls = $matches[0];
            foreach ($ids as $idx => $pageid) {
                if (in_array($pageid, $pageids)) {
                    $find = $urls[$idx];
                    $replace = '#pageid-'.$pageid;
                    $page->contents = str_replace($find, $replace, $page->contents);
                }
            }
        }

        // Replace any 'create' links with blank links.
        $baseurl = new moodle_url('/mod/lesson/create.php');
        $baseurl = $baseurl->out(false);
        $baseurl = preg_quote($baseurl);
        $baseurl = str_replace(array('&'), array('(&|&amp;)'), $baseurl);
        if (preg_match_all('|href="'.$baseurl.'[^"]*"|', $page->contents, $matches)) {
            foreach ($matches[0] as $createurl) {
                $page->contents = str_replace($createurl, '', $page->contents);
            }
        }

        // Remove any 'edit' links.
        $page->contents = preg_replace('|<a href="edit\.php.*?\[edit\]</a>|', '', $page->contents);
    }

    protected function start_export($download) {
        global $CFG;
        $exp = new lessonexport_pdf();            
        $restricttocontext = false;
        if ($download) {
            $restricttocontext = context_module::instance($this->cm->id);
        }
        $exp->use_direct_image_load($restricttocontext);
        $exp->SetMargins(20, 10, -1, true); // Set up wider left margin than default.        

        return $exp;
    }

    protected function export_page($exp, $page) {
        /** @var lessonexport_pdf $exp */
        $exp->addPage();
        $exp->setDestination('pageid-'.$page->id);
        $exp->writeHTML('<h2>'.$page->title.'</h2>');
        $exp->writeHTML($page->contents);        
    }

    protected function end_export($exp, $download) {
        global $CFG;

        $filename = $this->get_filename($download);
        
        $config = get_config('local_lessonexport');
        $password = $config->pdfpassword;

        // Add the configured protection to the PDF
        $exp->protect($this->get_filename($download), $password);

        /** @var pdf $exp */
        if ($download) {
            $exp->Output($filename, 'D');
        } else {
            $exp->Output($filename, 'F');
        }

        // Remove 'dataroot' from the filename, so the email sending can put it back again.
        $filename = str_replace($CFG->dataroot.'/', '', $filename);

        return $filename;
    }

    protected function get_filename($download) {
        $info = (object)array(
            'timestamp' => userdate(time(), '%Y-%m-%d %H:%M'),
            'lessonname' => format_string($this->lesson->name),
        );
        $filename = get_string('filename', 'local_lessonexport', $info);
        $filename .= '.pdf';

        $filename = clean_filename($filename);

        if (!$download) {
            $filename = str_replace(' ', '_', $filename);
            $path = make_temp_directory('local_lessonexport');
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }

    protected function add_coversheet($exp) {
        $this->add_coversheet_pdf($exp);
    }

    protected function add_coversheet_pdf(pdf $exp) {
        global $CFG;

        $exp->startPage();
        // Rounded rectangle.
        $exp->RoundedRect(9, 9, 192, 279, 6.5);
        // Logo.
        $exp->Image($CFG->dirroot.'/local/lessonexport/pix/logo.png', 52, 27, 103, 36);
        // Title bar.
        $exp->Rect(9, 87.5, 192, 2.5, 'F', array(), array(18,160,83));
        $exp->Rect(9, 90, 192, 30, 'F', array(), array(18,160,83));
        $exp->Rect(9, 120, 192, 2.5, 'F', array(), array(18,160,83));

        // Title text.
        $title = $this->lesson->name;
        $exp->SetFontSize(20);
        $exp->Text(9, 100, $title, false, false, true, 0, 0, 'C', false, '', 1, false, 'T', 'C');
        $exp->SetFontSize(12); // Set back to default.

        // Description.
        $description = format_text($this->lesson->intro, $this->lesson->introformat);
        $exp->writeHTMLCell(140, 40, 30, 130, $description);

        // Creation / modification / printing time.
        if ($info = $this->get_coversheet_info()) {
            $exp->writeHTMLCell(176, 20, 12, 255, $info);
        }
    }

    protected function get_coversheet_info() {
        $info = array();
        if ($this->lessoninfo->has_timemodified()) {
            $strinfo = (object)array(
                'timemodified' => $this->lessoninfo->format_timemodified(),
                'modifiedby' => $this->lessoninfo->get_modifiedby()
            );
            $info[] = get_string('modified', 'local_lessonexport', $strinfo);
        }
        if ($this->lessoninfo->has_timeprinted()) {
            $info[] = get_string('printed', 'local_lessonexport', $this->lessoninfo->format_timeprinted());
        }

        if ($info) {
            $info = implode("<br/>\n", $info);
        } else {
            $info = null;
        }

        return $info;
    }
}

/**
 * Insert the 'Export as pdf' link into the navigation.
 *
 * @param $unused
 */
function local_lessonexport_extends_navigation($unused) {
    local_lessonexport_extend_navigation($unused);
}

function local_lessonexport_extend_navigation($unused) {
    global $PAGE, $DB, $USER;
    if (!$PAGE->cm || $PAGE->cm->modname != 'lesson') {
        return;
    }
    $groupid = groups_get_activity_group($PAGE->cm);
    $userid = 0;
    $lesson = $DB->get_record('lesson', array('id' => $PAGE->cm->instance), '*', MUST_EXIST);

    $userid = $USER->id;

    if (!$links = local_lessonexport::get_links($PAGE->cm, $userid, $groupid)) { // Meant to pass $userid
        return;
    }
    $settingsnav = $PAGE->settingsnav;
    $modulesettings = $settingsnav->get('modulesettings');
    if (!$modulesettings) {
        $modulesettings = $settingsnav->prepend(get_string('pluginadministration', 'mod_lesson'), null,
                                                navigation_node::TYPE_SETTING, null, 'modulesettings');
    }

    foreach ($links as $name => $url) {
        $modulesettings->add($name, $url, navigation_node::TYPE_SETTING);
    }

    // Use javascript to insert the pdf link.
    $jslinks = array();
    foreach ($links as $name => $url) {
        $link = html_writer::link($url, $name);
        $link = html_writer::div($link, 'lesson_right');
        $jslinks[] = $link;
    }
    $PAGE->requires->yui_module('moodle-local_lessonexport-printlinks', 'M.local_lessonexport.printlinks.init', array($jslinks));
}

function local_lessonexport_cron() {
    local_lessonexport::cron();
}

/**
 * Class local_lessonexport_info
 */
class local_lessonexport_info {
    protected $timecreated = 0;
    protected $timemodified = 0;
    protected $modifiedbyid = null;
    protected $modifiedby = null;
    protected $timeprinted = 0;

    public function __construct() {
        $this->timeprinted = time();
    }

    public function update_times($timecreated, $timemodified, $modifiedbyid) {
        if (!$this->timecreated || $this->timecreated > $timecreated) {
            $this->timecreated = $timecreated;
        }
        if ($this->timemodified < $timemodified) {
            $this->timemodified = $timemodified;
            if ($modifiedbyid != $this->modifiedbyid) {
                $this->modifiedbyid = $modifiedbyid;
                $this->modifiedby = null;
            }
        }
    }

    public function has_timecreated() {
        return (bool)$this->timecreated;
    }

    public function has_timemodified() {
        return (bool)$this->timemodified;
    }

    public function has_timeprinted() {
        return (bool)$this->timeprinted;
    }

    public function format_timecreated() {
        return userdate($this->timecreated);
    }

    public function format_timemodified() {
        return userdate($this->timemodified);
    }

    public function format_timeprinted() {
        return userdate($this->timeprinted);
    }

    public function get_modifiedby() {
        global $USER, $DB;

        if ($this->modifiedby === null) {
            if ($this->modifiedbyid == $USER->id) {
                $this->modifiedby = $USER;
            } else {
                $this->modifiedby = $DB->get_record('user', array('id' => $this->modifiedbyid), 'id, firstname, lastname');
            }
        }
        if (!$this->modifiedby) {
            return '';
        }
        return fullname($this->modifiedby);
    }
}

/**
 * Convert an image URL into a stored_file object, if it refers to a local file.
 * @param $fileurl
 * @param context $restricttocontext (optional) if set, only files from this lesson will be included
 * @return null|stored_file
 */
function local_lessonexport_get_image_file($fileurl, $restricttocontext = null) {
    global $CFG;
    if (strpos($fileurl, $CFG->wwwroot.'/pluginfile.php') === false) {
        return null;
    }

    $fs = get_file_storage();
    $params = substr($fileurl, strlen($CFG->wwwroot.'/pluginfile.php'));
    if (substr($params, 0, 1) == '?') { // Slasharguments off.
        $pos = strpos($params, 'file=');
        $params = substr($params, $pos + 5);
    } else { // Slasharguments on.
        if (($pos = strpos($params, '?')) !== false) {
            $params = substr($params, 0, $pos - 1);
        }
    }
    $params = urldecode($params);
    $params = explode('/', $params);
    array_shift($params); // Remove empty first param.
    $contextid = (int)array_shift($params);
    $component = clean_param(array_shift($params), PARAM_COMPONENT);
    $filearea  = clean_param(array_shift($params), PARAM_AREA);
    $itemid = array_shift($params);

    if (empty($params)) {
        $filename = $itemid;
        $itemid = 0;
    } else {
        $filename = array_pop($params);
    }

    if (empty($params)) {
        $filepath = '/';
    } else {
        $filepath = '/'.implode('/', $params).'/';
    }

    if ($restricttocontext) {
        if ($component != 'mod_lesson' || $contextid != $restricttocontext->id) {
            return null; // Only allowed to include files directly from this lesson.
        }
    }

    if (!$file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename)) {
        if ($itemid) {
            $filepath = '/'.$itemid.$filepath; // See if there was no itemid in the originalPath URL.
            $itemid = 0;
            $file = $fs->get_file($contextid, $component, $filename, $itemid, $filepath, $filename);
        }
    }

    if (!$file) {
        return null;
    }
    return $file;
}

/**
 * Class lessonexport_pdf
 */
class lessonexport_pdf extends pdf {

    protected $directimageload = false;
    protected $restricttocontext = false;

    public function use_direct_image_load($restricttocontext = false) {
        $this->directimageload = true;
        $this->restricttocontext = $restricttocontext;

        $config = get_config('local_lessonexport');
        if (empty($config->customfont))
            $font = 'helvetica';
        else
            $font = $config->customfont;

        $this->SetFont($font, '', 12);
    }

    /**
     * Override the existing function to:
     * a) Convert any spaces in filenames into '%20' (as TCPDF seems to incorrectly do the opposite).
     * b) Make any broken file errors non-fatal (replace the image with an error message).
     *
     * @param $file
     * @param string $x
     * @param string $y
     * @param int $w
     * @param int $h
     * @param string $type
     * @param string $link
     * @param string $align
     * @param bool $resize
     * @param int $dpi
     * @param string $palign
     * @param bool $ismask
     * @param bool $imgmask
     * @param int $border
     * @param bool $fitbox
     * @param bool $hidden
     * @param bool $fitonpage
     * @param bool $alt
     * @param array $altimgs
     */
    public function Image($file, $x = '', $y = '', $w = 0, $h = 0, $type = '', $link = '', $align = '', $resize = false,
                          $dpi = 300, $palign = '', $ismask = false, $imgmask = false, $border = 0, $fitbox = false, $hidden = false,
                          $fitonpage = false, $alt = false, $altimgs = array()) {
        if ($this->directimageload) {
            // Get the image data directly from the Moodle files API (needed when generating within cron, instead of downloading).
            $file = $this->get_image_data($file);
        } else {
            // Make sure the filename part of the URL is urlencoded (convert spaces => %20, etc.).
            if (strpos('pluginfile.php', $file) !== false) {
                $urlparts = explode('/', $file);
                $filename = array_pop($urlparts); // Get just the part at the end.
                $filename = rawurldecode($filename); // Decode => make sure the URL isn't double-encoded.
                $filename = rawurlencode($filename);
                $urlparts[] = $filename;
                $file = implode('/', $urlparts);
            }
        }
        try {
            parent::Image($file, $x, $y, $w, $h, $type, $link, $align, $resize, $dpi, $palign, $ismask, $imgmask, $border,
                          $fitbox, $hidden, $fitonpage, $alt, $altimgs);
        } catch (Exception $e) {
            $this->writeHTML(get_string('failedinsertimage', 'local_lessonexport', $file));
        }
    }

    public function Header() {
        // No header.
    }

    public function Footer() {
        // No footer.
    }

    /**
     * Copy the image data from the Moodle files API and return it directly.
     *
     * @param $fileurl
     * @return string either the originalPath fileurl param or the file content with '@' appended to the start.
     */
    protected function get_image_data($fileurl) {
        if ($file = local_lessonexport_get_image_file($fileurl, $this->restricttocontext)) {
            $fileurl = '@'.$file->get_content();
        }
        return $fileurl;
    }

    /**
     * Override the existing function to create anchor destinations for any '<a name="x">' tags.
     *
     * @param $dom
     * @param $key
     * @param $cell
     * @return mixed
     */
    protected function openHTMLTagHandler($dom, $key, $cell) {
        $tag = $dom[$key];
        if (array_key_exists('name', $tag['attribute'])) {
            $this->setDestination($tag['attribute']['name']); // Store the destination for TOC links.
        }
        return parent::openHTMLTagHandler($dom, $key, $cell);
    }

    public function protect($file, $password) {
        global $CFG;

        // $pagecount = parent::setSourceFile($file);
        // for ($i = 1; $i <= $pagecount; $i++) {
        //     $template = $this->importPage($i);
        //     $this->addPage();
        //     $this->useTemplate($template);
        // }

        $permissions=array('print', 'modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble', 'print-high');
        $this->SetProtection($permissions, '', $password);
        $this->Output($file, 'D');
                
        return $file;
    }
}

class local_lessonexport_sortpages {
    protected $cm;
    protected $lesson;
    protected $userid;
    protected $groupid;

    protected $action = null;
    protected $pages = array();

    public function __construct($cm, $lesson, $userid, $groupid) {
        $this->cm = $cm;
        $this->lesson = $lesson;
        $this->userid = $userid;
        if (is_object($this->userid)) {
            $this->userid = $this->userid->id;
        } else if ($this->userid === null) {
            $this->userid = 0;
        }
        $this->groupid = $groupid;
        if (is_object($this->groupid)) {
            $this->groupid = $this->groupid->id;
        } else if ($this->groupid === null) {
            $this->groupid = 0;
        }
    }

    public function check_access() {
        $context = context_module::instance($this->cm->id);
        require_capability('mod/lesson:managelesson', $context);
    }

    public function process($action) {
        global $PAGE;

        if ($action) {
            $this->action = $action;
        } else {
            $this->action = 'list';
        }

        $this->load_pages();

        if ($this->action == 'moveup' || $this->action == 'movedown' || $this->action == 'moveto') {
            $pageid = required_param('pageid', PARAM_INT);
            require_sesskey();

            if (!isset($this->pages[$pageid])) {
                throw new moodle_exception('invalidpageid', 'local_lessonexport');
            }
            $page = $this->pages[$pageid];

            if ($this->action == 'moveto') {
                $newpos = required_param('position', PARAM_INT);
            } else if ($this->action == 'moveup') {
                $newpos = $page->sortorder - 1;
            } else { // Move down.
                $newpos = $page->sortorder + 1;
            }
            $this->move_page_to($page, $newpos);

            if (AJAX_SCRIPT) {
                $result = (object)array(
                    'error' => 0,
                    'order' => $this->get_new_pageorder(),
                );
                echo json_encode($result);
                die();

            } else {
                redirect($PAGE->url);
            }

        } else if ($this->action != 'list') {
            throw new moodle_exception('invalidaction', 'local_lessonexport');
        }
    }

    public function output() {
        global $OUTPUT, $PAGE;

        $opts = array('cmid' => $this->cm->id, 'groupid' => $this->groupid, 'userid' => $this->userid, 'sesskey' => sesskey());
        $PAGE->requires->yui_module('moodle-local_lessonexport-sortpages', 'M.local_lessonexport.sortpages.init',
                                    array($opts), null, true);

        $upicon = $OUTPUT->pix_icon('t/up', get_string('moveup'));
        $downicon = $OUTPUT->pix_icon('t/down', get_string('movedown'));
        $spacericon = $OUTPUT->pix_icon('spacer', '');
        $moveicon = $OUTPUT->pix_icon('i/move_2d', get_string('move'));

        $intro = html_writer::tag('p', get_string('sortpagesintro', 'local_lessonexport'));

        $list = '';
        $lastpage = end($this->pages);
        foreach ($this->pages as $page) {
            $item = '';

            $nojsicons = '';
            if ($page->sortorder != 0) { // Cannot move the first page.
                $baseurl = new moodle_url($PAGE->url, array('pageid' => $page->id, 'sesskey' => sesskey()));
                if ($page->sortorder > 1) {
                    $url = new moodle_url($baseurl, array('action' => 'moveup'));
                    $nojsicons .= html_writer::link($url, $upicon);
                } else {
                    $nojsicons .= $spacericon;
                }
                if ($page->sortorder < $lastpage->sortorder) {
                    $url = new moodle_url($baseurl, array('action' => 'movedown'));
                    $nojsicons .= html_writer::link($url, $downicon);
                } else {
                    $nojsicons .= $spacericon;
                }

                $jsicons = $moveicon;
            } else {
                $nojsicons = $spacericon.$spacericon;
                $jsicons = $spacericon;
            }

            $item .= html_writer::span($nojsicons, 'nojsicons');
            $item .= html_writer::span($jsicons, 'jsicons');

            $title = shorten_text(format_string($page->title), 100);
            $item .= html_writer::span($title, 'lessontitle');

            $attrib = array('id' => 'lessonpageid-'.$page->id, 'class' => 'sortorder-'.$page->sortorder);
            if ($page->sortorder == 0) {
                $attrib['class'] .= ' nomove';
            }
            $list .= html_writer::tag('li', $item, $attrib);
        }
        $list = html_writer::tag('ul', $list, array('class' => 'lesson-sortpages', 'id' => 'lesson-sortpages'));
        $spinner = html_writer::div('&nbsp;', 'lesson-sortpages-spinner', array('id' => 'lesson-sortpages-spinner'));

        return $intro.$spinner.$list;
    }

    /**
     * Load the lesson pages, making sure the sortorder is set for each of them.
     * Pages will be sorted in the correct order and indexed kby the page id.
     */
    protected function load_pages() {
        global $DB;

        // Load the pages into memory.
        // $subwiki = $DB->get_record('wiki_subwikis', array('wikiid' => $this->lesson->id, 'groupid' => $this->groupid,
        //                                                   'userid' => $this->userid), '*');
        $lesson = $DB->get_record('lesson', array('id' => $this->lesson->id), '*');
        if (!$lesson) {
            return;
        }
        $sql = "SELECT p.id, p.title, xo.sortorder, xo.id AS orderid
                  FROM {lesson_pages} p
                  ORDER BY xo.sortorder, p.title";
		$this->pages = $DB->get_records_sql($sql);

        // Make sure the sortorder is set for each page in the lesson.
        $sortorder = 0;
        foreach ($this->pages as $page) {
            if ($page->sortorder !== $sortorder) {
                $page->sortorder = $sortorder;
                $this->save_sortorder($page);
            }
            $sortorder++;
        }
    }

    protected function move_page_to($movepage, $newpos) {
        if ($newpos < 0) {
            return; // Cannot move below 0.
        }
        if ($movepage->sortorder == $newpos) {
            return; // No change in position.
        }
        $move = -1; // Other pages need to move backward.
        if ($movepage->sortorder > $newpos) {
            $move = 1; // Other pages need to move forward.
        }

        $maxpos = 0;
        foreach ($this->pages as $page) {
            if ($page->id == $movepage->id) {
                continue; // Update the page being moved after moving all other pages.
            }
            if ($move > 0) {
                if ($page->sortorder >= $newpos) {
                    if ($page->sortorder < $movepage->sortorder) {
                        // Move pages newpos...oldpos one space forward.
                        $page->sortorder += 1;
                        $this->save_sortorder($page);
                    }
                }
            } else {
                if ($page->sortorder <= $newpos) {
                    if ($page->sortorder > $movepage->sortorder) {
                        // Move pages oldpos...newpos one space backward.
                        $page->sortorder -= 1;
                        $this->save_sortorder($page);
                    }
                }
            }
            $maxpos = $page->sortorder;
        }

        if ($newpos > $maxpos) {
            $newpos = $maxpos + 1; // Limit to one more than the maximum of the other sortorders.
        }

        if ($movepage->sortorder != $newpos) {
            $movepage->sortorder = $newpos;
            $this->save_sortorder($movepage);
        }
    }

    protected function save_sortorder($page) {
        global $DB;
        if ($page->orderid) {
            $DB->set_field('local_lessonexport_order', 'sortorder', $page->sortorder, array('id' => $page->orderid));
        } else {
            $ins = (object)array(
                'cmid' => $this->cm->id,
                'courseid' => $this->cm->course,
                'pageid' => $page->id,
                'sortorder' => $page->sortorder,
            );
            $page->orderid = $DB->insert_record('local_lessonexport_order', $ins);
        }
    }

    protected function get_new_pageorder() {
        $pageorder = array();
        foreach ($this->pages as $page) {
            $pageorder[$page->id] = $page->sortorder;
        }
        return $pageorder;
    }
}
