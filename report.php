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
 * This page prints a list of all student's results
 *
 * @package    mod_choicegroup
 * @copyright  2013 Université de Lausanne
 * @author     Nicolas Dunand <Nicolas.Dunand@unil.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$id         = required_param('id', PARAM_INT);   // Module id.
$format     = optional_param('format', CHOICEGROUP_PUBLISH_NAMES, PARAM_INT);
$download   = optional_param('download', '', PARAM_ALPHA);
$action     = optional_param('action', '', PARAM_ALPHA);
$grpsmemberids = optional_param_array('grpsmemberid', [], PARAM_INT); // Get array of responses to delete.

$url = new moodle_url('/mod/choicegroup/report.php', ['id' => $id]);
if ($format !== CHOICEGROUP_PUBLISH_NAMES) {
    $url->param('format', $format);
}

if ($download !== '') {
    $url->param('download', $download);
}

if ($action !== '') {
    $url->param('action', $action);
}

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('choicegroup', $id)) {
    throw new moodle_exception("invalidcoursemodule");
}

if (! $course = $DB->get_record("course", ["id" => $cm->course])) {
    throw new moodle_exception("coursemisconf");
}

require_login($course->id, false, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/choicegroup:readresponses', $context);

if (!$choicegroup = choicegroup_get_choicegroup($cm->instance)) {
    throw new moodle_exception('invalidcoursemodule');
}

$strchoicegroup = get_string("modulename", "choicegroup");
$strchoicegroups = get_string("modulenameplural", "choicegroup");
$strresponses = get_string("responses", "choicegroup");

$eventparams = [
    'context' => $context,
    'objectid' => $choicegroup->id,
];
$event = \mod_choicegroup\event\report_viewed::create($eventparams);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('choicegroup', $choicegroup);
$event->trigger();

if (data_submitted() && $action == 'delete' && has_capability('mod/choicegroup:deleteresponses', $context) && confirm_sesskey()) {
    choicegroup_delete_responses($grpsmemberids, $choicegroup, $cm, $course); // Delete responses.
    redirect("report.php?id=$cm->id");
}

if (!$download) {
    $PAGE->navbar->add($strresponses);
    $PAGE->set_title(format_string($choicegroup->name) . ": $strresponses");
    $PAGE->set_heading(format_string($course->fullname));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($choicegroup->name));
    // Check to see if groups are being used in this choicegroup.
    $groupmode = groups_get_activity_groupmode($cm);
    if ($groupmode) {
        groups_get_activity_group($cm, true);
        groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/choicegroup/report.php?id=' . $id);
    }
} else {
    $groupmode = groups_get_activity_groupmode($cm);
}

$users = choicegroup_get_response_data($choicegroup, $cm, $groupmode, $choicegroup->onlyactive);

if ($download == "ods" && has_capability('mod/choicegroup:downloadresponses', $context)) {
    require_once("$CFG->libdir/odslib.class.php");

    // Calculate file name.
    $filename = clean_filename("$course->shortname " . strip_tags(format_string($choicegroup->name, true))) . '.ods';
    // Creating a workbook.
    $workbook = new MoodleODSWorkbook("-");
    // Send HTTP headers.
    $workbook->send($filename);
    // Creating the first worksheet.
    $myxls = $workbook->add_worksheet($strresponses);

    // Print names of all the fields, then one row per user.
    $exportdata = choicegroup_get_export_data($choicegroup, $course, $context, $users);
    foreach ($exportdata['header'] as $col => $title) {
        $myxls->write_string(0, $col, $title);
    }

    foreach ($exportdata['rows'] as $i => $cells) {
        foreach ($cells as $col => $cell) {
            $myxls->write_string($i + 1, $col, $cell);
        }
    }

    // Close the workbook.
    $workbook->close();

    exit;
}

// Print spreadsheet if one is asked for.
if ($download == "xls" && has_capability('mod/choicegroup:downloadresponses', $context)) {
    require_once("$CFG->libdir/excellib.class.php");

    // Calculate file name.
    $filename = clean_filename("$course->shortname " . strip_tags(format_string($choicegroup->name, true))) . '.xls';
    // Creating a workbook.
    $workbook = new MoodleExcelWorkbook("-");
    // Send HTTP headers.
    $workbook->send($filename);
    // Create the worksheet.
    $myxls = $workbook->add_worksheet($strresponses);
    // Print names of all the fields, then one row per user.
    $exportdata = choicegroup_get_export_data($choicegroup, $course, $context, $users);
    foreach ($exportdata['header'] as $col => $title) {
        $myxls->write_string(0, $col, $title);
    }

    foreach ($exportdata['rows'] as $i => $cells) {
        foreach ($cells as $col => $cell) {
            $myxls->write_string($i + 1, $col, $cell);
        }
    }

    // Close the workbook.
    $workbook->close();
    exit;
}

// Print text file.
if ($download == "txt" && has_capability('mod/choicegroup:downloadresponses', $context)) {
    $filename = clean_filename("$course->shortname " . strip_tags(format_string($choicegroup->name, true))) . '.txt';

    header("Content-Type: application/download\n");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Expires: 0");
    header("Cache-Control: must-revalidate,post-check=0,pre-check=0");
    header("Pragma: public");

    // Print names of all the fields, then one line per user; a cell keeps no tab or line break of its own.
    $exportdata = choicegroup_get_export_data($choicegroup, $course, $context, $users);
    echo implode("\t", array_map('choicegroup_clean_txt_cell', $exportdata['header'])) . "\n";
    foreach ($exportdata['rows'] as $cells) {
        echo implode("\t", array_map('choicegroup_clean_txt_cell', $cells)) . "\t\n";
    }

    exit;
}

// Show those who haven't answered the question.
if (!empty($choicegroup->showunanswered)) {
    $choicegroup->option[0] = get_string('notanswered', 'choicegroup');
    $choicegroup->maxanswers[0] = 0;
}

$results = prepare_choicegroup_show_results($choicegroup, $course, $cm, $users);
$renderer = $PAGE->get_renderer('mod_choicegroup');
echo $renderer->display_result($results, has_capability('mod/choicegroup:readresponses', $context));

// Now give links for downloading spreadsheets.
if (!empty($users) && has_capability('mod/choicegroup:downloadresponses', $context)) {
    $downloadoptions = [];
    $options = [];
    $options["id"] = "$cm->id";
    $options["download"] = "ods";
    $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadods"));
    $downloadoptions[] = html_writer::tag('li', $button, ['class' => 'reportoption mt-1']);

    $options["download"] = "xls";
    $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadexcel"));
    $downloadoptions[] = html_writer::tag('li', $button, ['class' => 'reportoption mt-1']);

    $options["download"] = "txt";
    $button = $OUTPUT->single_button(new moodle_url("report.php", $options), get_string("downloadtext"));
    $downloadoptions[] = html_writer::tag('li', $button, ['class' => 'reportoption mt-1']);

    $downloadlist = html_writer::tag('ul', implode('', $downloadoptions));
    $downloadlist .= html_writer::tag('div', '', ['class' => 'clearfloat']);
    echo html_writer::tag('div', $downloadlist, ['class' => 'downloadreport']);
}

echo $OUTPUT->footer();
