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
 * @package   local_lessonexportepub
 * @copyright 2017 Adam King, SHEilds eLearning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/local/lessonexportepub/lib/luciepub/LuciEPUB.php');
require_once($CFG->dirroot.'/mod/lesson/locallib.php');

class local_lessonexportepub
{
    /** @var object */
    protected $cm;
    /** @var object */
    protected $lesson;
    /** @var local_lessonexport_info */
    protected $lessoninfo;

    const MAX_EXPORT_ATTEMPTS = 2;

    public function __construct($cm, $lesson)
    {
        $this->cm = $cm;
        $this->lesson = $lesson;
        $this->lessoninfo = new local_lessonexport_info();
    }

    /**
     * Generate an array of links that should be placed on the page,
     * given that the user has the necessary permissions for the current
     * Course Module.
     *
     * @param object The Course Module from the current context.
     */
    public static function get_links($cm)
    {
        $context = context_module::instance($cm->id);
        $ret = array();

        // $capability = 'local/lessonexportepub:export';
        // if (has_capability($capability, $context)) {
            $name = get_string('exportepub', 'local_lessonexportepub');
            $url = new moodle_url('/local/lessonexportepub/export.php', array('id' => $cm->id, 'type' => 'epub'));
            $ret[$name] = $url;
        // }

        return $ret;
    }

    /**
     * Ensure that the user has access to perform export operations where required.
     *
     * @throws required_capability_exception if the user does not have the capability.
     */
    public function check_access()
    {
        global $USER;
        $context = context_module::instance($this->cm->id);
        $capability = 'local/lessonexportepub:export';
        require_capability($capability, $context);
    }

    /**
     * Generate the export file and (optionally) send direct to the user's browser.
     *
     * @param bool $download (optional) true to send the file directly to the user's browser
     * @return string the path to the generated file, if not downloading directly
     */
    public function export($download = true)
    {
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

    /**
     * Find any lessons that have been updated since we last refeshed the export queue.
     * Any lessons that have been updated will have thier export attempt count reset.
     *
     * @param $config
     */
    protected static function update_queue($config)
    {
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
        $currentqueue = $DB->get_records('local_lessonexportepub_queue');
        foreach ($lessons as $lesson) {
            if (isset($currentqueue[$lesson->id])) {
                // A lesson already in the queue has been updated - reset the export attempts (if non-zero).
                $queueitem = $currentqueue[$lesson->id];
                if ($queueitem->exportattempts != 0) {
                    $DB->set_field('local_lessonexportepub_queue', 'exportattempts', 0, array('id' => $queueitem->id));
                }
            } else {
                $ins = (object)array(
                    'lessonid' => $lesson->id,
                    'exportattempts' => 0,
                );
                $DB->insert_record('local_lessonexportepub_queue', $ins, false);
            }
        }

        // Save the timestamp to detect any future lesson export changes.
        set_config('lastqueueupdate', time(), 'local_lessonexportepub');
    }

    /**
     * Get the next lesson in the queue - ignoring those that have already had too many export attempts.
     * The return object includes the lesson and cm as sub-objects.
     *
     * @return object|null null if none left to export
     */
    protected static function get_next_from_queue()
    {
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
        $DB->set_field('local_lessonexportepub_queue', 'exportattempts', $nextitem->exportattempts + 1, ['id' => $nextitem->queueid]);

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
    protected static function remove_from_queue($lesson)
    {
        global $DB;
        $DB->delete_records('local_lessonexportepub_queue', array('id' => $lesson->queueid));
    }

    protected function load_pages()
    {
        global $DB, $USER;

        $lesson = new Lesson($this->lesson);
        $pages = $lesson->load_all_pages();
        $pageids = array_keys($pages);

        $context = context_module::instance($this->cm->id);

        foreach ($pages as $page) {
            $answers = $page->get_answers();
            $contents = $page->contents;

            // Append answers to the end of question pages.
            $contents = $this->format_answers($page);

            // Fix pluginfile urls.
            $contents = file_rewrite_pluginfile_urls($contents, 'pluginfile.php', $context->id,
                                                          'mod_lesson', 'page_contents', $page->id);

            $contents = format_text($contents, FORMAT_MOODLE, array('overflowdiv' => false, 'allowid' => true, 'para' => false));

            // Fix internal links.
            // Can't really use this with the content being a local variable.
            // $this->fix_internal_links($page, $pageids);

            // Note created/modified time (if earlier / later than already recorded).
            $this->lessoninfo->update_times($page->timecreated, $page->timemodified, $USER->id);

            $page->contents = $contents;
        }

        return $pages;
    }

    /**
     * Retrieve and format question pages to include answers.
     *
     * @param A Lesson page.
     * @return Formatted page contents.
     */
    protected function format_answers($page)
    {
        $pagetype = $page->get_typeid();
        $contents = $page->contents;
        $answers = $page->answers;
        $qtype = $page->qtype;

        // Don't look for answers in lesson types and don't print
        // short answer answer patterns.
        if ($pagetype == 1 || $pagetype == 20) {
            return $contents;
        }

        $pagetypes = array(
            1 => "shortanswer",
            2 => "truefalse",
            3 => "multichoice",
            5 => "matching",
            8 => "numerical",
            10 => "essay",
            20 => "lessonpage"
        );

        $pagetype = $pagetypes[$pagetype];

        $contents .= "<div class='export_answer_".$pagetype."_wrapper'>";

        foreach ($answers as $answer) {
            // If this is a matching question type, only print the answers, not responses.
            if ($pagetype == 5 && $answer->answerformat == 1) {
                continue;
            }

            $contents .= "<div class='export_answer_$pagetype'>$answer->answer</div>";
        }

        $contents .= "</div>";

        return $contents;
    }

    /**
     * Fix internal TOC links to include the pageid (to make them unique across all pages).
     * Replaces links to other pages with anchor links to '#pageid-[page id]'.
     * Replaces unnecessary links with blank anchors.
     *
     * @param page The page to fix.
     * @param padeids An array of page identifiers, from the loaded pages.
     * @see local_lessonexportepub::load_pages() for the array of pageids.
     */
    protected function fix_internal_links($page, $pageids)
    {
        // Replace links to other pages with links to page 'pageid-[page id].html'.
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
                    $replace = 'pageid-'.$pageid.'.html';
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

    /**
     * The first step of exporting a document. This method creates an instance of the correct
     * export type and then sets the correct properties on it.
     *
     * @return object An instance of lessonexport_epub.
     */
    protected function start_export($download)
    {
        global $CFG;
        $exp = null;
        $exp = new lessonexport_epub();
        $exp->set_title($this->lesson->name);
        $exp->set_uid();
        $exp->set_date();
        if ($CFG->lang) {
            $exp->add_language($CFG->lang);
        }
        $exp->set_publisher(get_string('publishername', 'local_lessonexportepub'));

        return $exp;
    }

    /**
     * Add a page of content to the exported document. The page is built with HTML directly for EPUB.
     *
     * @param exp The export object of type lessonexport_epub.
     * @param page The page to add to the export object.
     */
    protected function export_page($exp, $page)
    {
        $content = '<h1>'.$page->title.'</h1>'.$page->contents;
        $href = 'pageid-'.$page->id.'.html';
        $exp->add_html($content, $page->title, array('tidy' => false, 'href' => $href, 'toc' => true));
    }

    /*
     * Finish exporting, export the document and produce a file from the document object.
     * The output can be a file name or a path to the document depending on $download.
     *
     * @return string The file name or path to the document.
     */
    protected function end_export($exp, $download)
    {
        global $CFG;

        $filename = $this->get_filename($download);

        /** @var LuciEPUB $exp */
        $exp->generate_nav();
        $out = $exp->generate();
        if ($download) {
            $out->sendZip($filename, 'application/epub+zip');
        } else {
            $out->setZipFile($filename);
        }

        // Remove 'dataroot' from the filename, so the email sending can put it back again.
        $filename = str_replace($CFG->dataroot.'/', '', $filename);

        return $filename;
    }

    /**
     * Generate a file name or file path based on whether the file will be
     * immediately downloaded or not.
     *
     * @param download A boolean of whether the file will be immediately downloaded.
     */
    protected function get_filename($download)
    {
        $info = (object)array(
            'timestamp' => userdate(time(), '%Y-%m-%d %H:%M'),
            'lessonname' => format_string($this->lesson->name),
        );
        $filename = get_string('filename', 'local_lessonexportepub', $info);
        $filename .= '.epub';

        $filename = clean_filename($filename);

        if (!$download) {
            $filename = str_replace(' ', '_', $filename);
            $path = make_temp_directory('local_lessonexportepub');
            $filename = $path.'/'.$filename;
        }

        return $filename;
    }

    /**
     * Determine which export type to add the cover sheet to and
     * apply it.
     *
     * @param exp The export object to add the cover-sheet to.
     */
    protected function add_coversheet($exp)
    {
        $this->add_coversheet_epub($exp);
    }

    /**
     * Add a cover sheet before all of the page contents containing the Lesson title,
     * the description, and other configurable data.
     *
     * @param exp The lessonexport_epub object to add the cover-sheet to.
     */
    protected function add_coversheet_epub(LessonLuciEPUB $exp)
    {
        global $CFG;

        $title = $this->lesson->name;
        $description = format_text($this->lesson->intro, $this->lesson->introformat);
        $info = $this->get_coversheet_info();

        $img = 'images/logo.png';
        $imgsrc = $CFG->dirroot.'/local/lessonexportepub/pix/logo.png';
        $fp = fopen($imgsrc, 'r');
        $exp->add_item_file($fp, mimeinfo('type', $imgsrc), $img);

        $html = '';

        $imgel = html_writer::empty_tag('img', array('src' => $img, 'style' => 'max-width: 90%;'));
        $html .= html_writer::div($imgel, 'fronttitle', array('style' => 'text-align: center; padding: 1em 0;'));
        $html .= html_writer::div(' ', 'fronttitletop', array('style' => 'display: block; width: 100%; height: 0.4em;
                                                                               background-color: rgb(18, 160, 83); margin-top: 1em;'));
        $html .= html_writer::tag('h1', $title, array('style' => 'display: block; width: 100%; background-color: rgb(18, 160, 83);
                                                                  min-height: 2em; text-align: center; padding-top: 0.8em;
                                                                  size: 1em; margin: 0; color: #fff;' ));
        $html .= html_writer::div(' ', 'fronttitlebottom', array('style' => 'display: block; width: 100%; height: 0.4em;
                                                                               background-color: rgb(18, 160, 83); margin-bottom: 1em;'));
        $html .= html_writer::div($description, 'frontdescription', array('style' => 'margin: 0.5em 1em;'));
        $html .= html_writer::div($info, 'frontinfo', array('style' => 'margin: 2em 1em'));

        // $html = html_writer::div($html, 'frontpage', array('style' => 'margin: 0.5em; border: solid black 1px; border-radius: 0.8em;
        //                                                                width: 90%;'));

        $exp->add_spine_item($html, 'cover.html');
    }

    /**
     * Produce an array of information, from this instance, to apply to the
     * cover page of the document and turn it into HTML.
     *
     * @return string A HTML string of the imploded export data.
     */
    protected function get_coversheet_info()
    {
        $info = array();
        if ($this->lessoninfo->has_timemodified()) {
            $strinfo = (object)array(
                'timemodified' => $this->lessoninfo->format_timemodified(),
                'modifiedby' => $this->lessoninfo->get_modifiedby()
            );
            $info[] = get_string('modified', 'local_lessonexportepub', $strinfo);
        }
        if ($this->lessoninfo->has_timeprinted()) {
            $info[] = get_string('printed', 'local_lessonexportepub', $this->lessoninfo->format_timeprinted());
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
 * Insert the 'Export as epub' link into the navigation.
 *
 * @param $unused
 */
function local_lessonexportepub_extends_navigation($unused)
{
    local_lessonexport_extend_navigation($unused);
}

function local_lessonexportepub_extend_navigation($unused)
{
    global $PAGE, $DB, $USER;

    $settingsnav = null;
    if (!$PAGE->cm || $PAGE->cm->modname != 'lesson') {
        return;
    } else {
        $settingsnav = $PAGE->settingsnav;
    }

    $groupid = groups_get_activity_group($PAGE->cm);
    $lesson = $DB->get_record('lesson', array('id' => $PAGE->cm->instance), '*', MUST_EXIST);

    if (!$links = local_lessonexportepub::get_links($PAGE->cm, $USER->id, $groupid)) {
        return;
    }

    $modulesettings = $settingsnav->get('modulesettings');
    if (!$modulesettings) {
        $modulesettings = $settingsnav->prepend(get_string('pluginadministration', 'mod_lesson'), null,
                                                navigation_node::TYPE_SETTING, null, 'modulesettings');
    }

    foreach ($links as $name => $url) {
        $modulesettings->add($name, $url, navigation_node::TYPE_SETTING);
    }

    // Use javascript to insert the epub link.
    $jslinks = array();
    foreach ($links as $name => $url) {
        $link = html_writer::link($url, $name);
        $link = html_writer::div($link, 'exportepub');
        $jslinks[] = $link;
    }

    $PAGE->requires->yui_module('moodle-local_lessonexportepub-printlinks', 'M.local_lessonexportepub.printlinks.init', array($jslinks));
}

function local_lessonexport_cron()
{
    local_lessonexportepub::cron();
}

/**
 * Class local_lessonexport_info
 */
class local_lessonexport_info
{
    protected $timecreated = 0;
    protected $timemodified = 0;
    protected $modifiedbyid = null;
    protected $modifiedby = null;
    protected $timeprinted = 0;

    public function __construct()
    {
        $this->timeprinted = time();
    }

    public function update_times($timecreated, $timemodified, $modifiedbyid)
    {
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

    public function has_timecreated()
    {
        return (bool)$this->timecreated;
    }

    public function has_timemodified()
    {
        return (bool)$this->timemodified;
    }

    public function has_timeprinted()
    {
        return (bool)$this->timeprinted;
    }

    public function format_timecreated()
    {
        return userdate($this->timecreated);
    }

    public function format_timemodified()
    {
        return userdate($this->timemodified);
    }

    public function format_timeprinted()
    {
        return userdate($this->timeprinted);
    }

    public function get_modifiedby()
    {
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
function local_lessonexport_get_image_file($fileurl, $restricttocontext = null)
{
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
 * Class lessonexport_epub
 */
class lessonexport_epub extends LessonLuciEPUB
{
    /**
     * Add HTML to the epub document, ensuring <img> tags are handled correctly.
     *
     * @param html The HTML string to apply to the document.
     * @param title The title of the page the HTML is for.
     * @param config An array of additional settings to use in the method: toc, href, tidy
     */
    public function add_html($html, $title, $config)
    {
        if ($config['tidy'] && class_exists('tidy')) {
            $tidy = new tidy();
            $tidy->parseString($html, array(), 'utf8');
            $tidy->cleanRepair();
            $html = $tidy->html()->value;
        }

        // Handle <img> tags.
        if (preg_match_all('~(<img [^>]*?)src=([\'"])(.+?)[\'"]~', $html, $matches)) {
            foreach ($matches[3] as $imageurl) {
                if ($file = local_lessonexport_get_image_file($imageurl)) {
                    $newpath = implode('/', array('images', $file->get_contextid(), $file->get_component(), $file->get_filearea(),
                                                  $file->get_itemid(), $file->get_filepath(), $file->get_filename()));
                    $newpath = str_replace(array('///', '//'), '/', $newpath);
                    $this->add_item_file($file->get_content_file_handle(), $file->get_mimetype(), $newpath);
                    $html = str_replace($imageurl, $newpath, $html);
                }
            }
        }

        // Set the href value, if specified.
        $href = null;
        if (!empty($config['href'])) {
            $href = $config['href'];
        }
        $this->add_spine_item($html, $href);
        if ($config['toc']) {
            $this->set_item_toc($title, true);
        }

        return $title;
    }

    /**
     * Create the content skeleton if it does not exist and then pass it up to the parent method
     * of the same signature.
     *
     * @see LessonLuciEPUB::addadd_spine_item()
     */
    public function add_spine_item($data, $href = null, $fallback = null, $properties = null)
    {
        $globalconf = get_config('local_lessonexportepub');
        $style = '';

        if (!empty($globalconf)) {
            $style = $globalconf->customstyle;
        }

        if (strpos('<html', $data) === false) {
            $data = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                    <!DOCTYPE html>
                    <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="en" lang="en">
                        <head>
                        </head>
                        <body>
                        <style>
                        '.$style.'
                        </style>
                        '.$data.'
                        </body>
                    </html>';
        }

        return parent::add_spine_item($data, $href, $fallback, $properties);
    }
}

class Util
{
    public static function hex_to_rgb($hex)
    {
        global $CFG;

        // If there is a hash symbol with the hex, remove it
        if (strpos($hex, '#') !== false) {
            $hex = substr($hex, 1);
        }

        // Split the hexadecimal into RGB chunks
        $hexColour = str_split($hex, 2);

        // Convert the base16 hex values into base10 decimals
        $rgbColour = array(
            hexdec($hexColour[0]),
            hexdec($hexColour[1]),
            hexdec($hexColour[2])
        );

        return $rgbColour;
    }
}
