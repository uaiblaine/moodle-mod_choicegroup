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
 * This page prints a particular instance of choicegroup
 *
 * @package    mod_choicegroup
 * @copyright  2013 Université de Lausanne
 * @author     Nicolas Dunand <Nicolas.Dunand@unil.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id         = required_param('id', PARAM_INT);                 // Course Module ID.
$action     = optional_param('action', '', PARAM_ALPHA);
$userids    = optional_param_array('userid', [], PARAM_INT); // Array of attempt ids for delete action.
$notify     = optional_param('notify', '', PARAM_ALPHA);

$url = new moodle_url('/mod/choicegroup/view.php', ['id' => $id]);
if ($action !== '') {
    $url->param('action', $action);
}

$PAGE->set_url($url);

if (! $cm = get_coursemodule_from_id('choicegroup', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", ["id" => $cm->course])) {
    throw new moodle_exception('coursemisconf');
}

require_login($course, false, $cm);
$PAGE->requires->js_call_amd('mod_choicegroup/choicegroupdatadisplay', 'init');
if (!$choicegroup = choicegroup_get_choicegroup($cm->instance)) {
    throw new moodle_exception('invalidcoursemodule');
}

// Load real-time enrollment limit validator when min or max is configured; the cards count on their own.
if (
    $choicegroup->multipleenrollmentspossible == 1 &&
    (int)$choicegroup->selectionlayout !== \mod_choicegroup\local\selection::LAYOUT_CARDS
) {
    $jsmin = (int)($choicegroup->minenrollments ?? 0);
    $jsmax = (int)($choicegroup->maxenrollments ?? 0);
    if ($jsmin > 0 || $jsmax > 0) {
        $PAGE->requires->strings_for_js(
            ['enrollmentlimit_exact', 'enrollmentlimit_min', 'enrollmentlimit_toomany', 'enrollmentlimit_toomany_exact'],
            'choicegroup'
        );
        $PAGE->requires->js_call_amd('mod_choicegroup/enrollment_limit_validator', 'init', [[
            'minenrollments' => $jsmin,
            'maxenrollments' => $jsmax,
        ]]);
    }
}

$choicegroupgroups = choicegroup_get_groups($choicegroup);
$choicegroupusers = [];

$strchoicegroup = get_string('modulename', 'choicegroup');
$strchoicegroups = get_string('modulenameplural', 'choicegroup');

if (!$context = context_module::instance($cm->id)) {
    throw new moodle_exception('badcontext');
}

$current = choicegroup_get_user_answer($choicegroup, $USER);
if ($action == 'delchoicegroup' && confirm_sesskey()) {
    // User wants to delete his own choice; the helper enforces enrolment, the dates and allowupdate.
    choicegroup_user_remove_choice($choicegroup, $USER->id, $course, $cm);
    $current = choicegroup_get_user_answer($choicegroup, $USER, false, true);
}

$PAGE->set_title(format_string($choicegroup->name));
$PAGE->set_heading($course->fullname);

// Mark as viewed and log the view, before any output.
choicegroup_view($choicegroup, $course, $cm, $context);

// Submit any new data if there is any.
if (data_submitted() && is_enrolled($context, null, 'mod/choicegroup:choose') && confirm_sesskey()) {
    if ($choicegroup->multipleenrollmentspossible == 1) {
        if ((int)$choicegroup->selectionlayout === \mod_choicegroup\local\selection::LAYOUT_CARDS) {
            // The cards post their option ids as answers[], the ones outside the loaded pages included.
            $selectedoptionids = array_values(array_unique(optional_param_array('answers', [], PARAM_INT)));
            $enrollmentscount = count($selectedoptionids);
        } else {
            // The form posts one checkbox per option; a larger number posted by hand is capped to the options there are.
            $numberofgroups = min(optional_param('number_of_groups', 0, PARAM_INT), count($choicegroup->option));
            $enrollmentscount = 0;
            $selectedoptionids = [];

            for ($i = 0; $i < $numberofgroups; $i++) {
                $answervalue = optional_param('answer_' . $i, '', PARAM_INT);
                if ($answervalue != '') {
                    $enrollmentscount++;
                    $selectedoptionids[] = (int)$answervalue;
                }
            }
        }

        if ($choicegroup->maxenrollments > 0 && $enrollmentscount > $choicegroup->maxenrollments) {
            redirect(new moodle_url(
                '/mod/choicegroup/view.php',
                ['id' => $cm->id, 'notify' => 'mustchoosemax', 'sesskey' => sesskey()]
            ));
        }

        if (!empty($choicegroup->minenrollments) && $choicegroup->minenrollments > 0) {
            if ($enrollmentscount < $choicegroup->minenrollments) {
                redirect(new moodle_url(
                    '/mod/choicegroup/view.php',
                    ['id' => $cm->id, 'notify' => 'mustchoosemin', 'sesskey' => sesskey()]
                ));
            }
        }

        // The helper removes the user from this instance's option groups left unticked, so the posted
        // answer_N_groupid values, which could name any group of the site, are not read.
        choicegroup_save_user_choices($choicegroup, $USER->id, $course, $cm, $selectedoptionids);
    } else { // Multipleenrollmentspossible != 1.
        $timenow = time();
        if (has_capability('mod/choicegroup:deleteresponses', $context)) {
            if ($action == 'delete') { // Some responses need to be deleted.
                // The posted values are user ids; choicegroup_delete_responses() takes groups_members ids.
                $grpsmemberids = choicegroup_get_membership_ids($choicegroup, $userids);
                choicegroup_delete_responses($grpsmemberids, $choicegroup, $cm, $course); // Delete responses.
                redirect("view.php?id=$cm->id");
            }
        }

        $answer = optional_param('answer', '', PARAM_INT);

        if (empty($answer)) {
            redirect(new moodle_url(
                '/mod/choicegroup/view.php',
                ['id' => $cm->id, 'notify' => 'mustchooseone', 'sesskey' => sesskey()]
            ));
        } else {
            choicegroup_save_user_choices($choicegroup, $USER->id, $course, $cm, [$answer]);
            redirect(new moodle_url(
                '/mod/choicegroup/view.php',
                ['id' => $cm->id, 'notify' => 'choicegroupsaved', 'sesskey' => sesskey()]
            ));
        }
    }
}


// Display the choicegroup and possibly results.


echo $OUTPUT->header();
if ($CFG->branch < 400) {
    echo $OUTPUT->heading(format_string($choicegroup->name));
}

if ($notify && confirm_sesskey()) {
    if ($notify === 'choicegroupsaved') {
        echo $OUTPUT->notification(get_string('choicegroupsaved', 'choicegroup'), 'notifysuccess');
    } else if ($notify === 'mustchooseone') {
        echo $OUTPUT->notification(get_string('mustchooseone', 'choicegroup'), 'notifyproblem');
    } else if ($notify === 'mustchoosemax') {
        echo $OUTPUT->notification(get_string('mustchoosemax', 'choicegroup', $choicegroup->maxenrollments), 'notifyproblem');
    } else if ($notify === 'mustchoosemin') {
        echo $OUTPUT->notification(get_string('mustchoosemin', 'choicegroup', $choicegroup->minenrollments), 'notifyproblem');
    }
}

if ($CFG->branch < 400) {
    if (class_exists('\core_completion\cm_completion_details') && class_exists('\core\activity_dates')) {
        // Show the activity dates and completion details.
        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cm->id);
        $cmcompletion = \core_completion\cm_completion_details::get_instance($cminfo, $USER->id);
        $activitydates = \core\activity_dates::get_dates_for_module($cminfo, $USER->id);
        echo $OUTPUT->activity_information($cminfo, $cmcompletion, $activitydates);
    }
}

// Check to see if groups are being used in this choicegroup.
$groupmode = groups_get_activity_groupmode($cm);

if ($groupmode) {
    groups_get_activity_group($cm, true);
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/choicegroup/view.php?id=' . $id);
}

$allresponses = choicegroup_get_response_data($choicegroup, $cm, $groupmode, $choicegroup->onlyactive);


if (has_capability('mod/choicegroup:readresponses', $context)) {
    choicegroup_show_reportlink($choicegroup, $allresponses, $cm);
}

echo '<div class="clearer"></div>';

if ($choicegroup->intro) {
    if ($CFG->branch < 400) {
        echo $OUTPUT->box(format_module_intro('choicegroup', $choicegroup, $cm->id), 'generalbox', 'intro');
    }
}

// Show enrollment conditions box above "Your selection" when multiple enrollments have min/max configured.
if ($choicegroup->multipleenrollmentspossible == 1) {
    $ecmin = (int)($choicegroup->minenrollments ?? 0);
    $ecmax = (int)($choicegroup->maxenrollments ?? 0);
    $enrollmentinfomsg = '';
    if ($ecmin > 0 && $ecmax > 0 && $ecmin === $ecmax) {
        $enrollmentinfomsg = get_string('enrollmentlimit_exact', 'choicegroup', $ecmin);
    } else if ($ecmin > 0 && $ecmax > 0) {
        $eca = new stdClass();
        $eca->min = $ecmin;
        $eca->max = $ecmax;
        $enrollmentinfomsg = get_string('enrollmentlimit_range', 'choicegroup', $eca);
    } else if ($ecmin > 0) {
        $enrollmentinfomsg = get_string('enrollmentlimit_min', 'choicegroup', $ecmin);
    } else if ($ecmax > 0) {
        $enrollmentinfomsg = get_string('enrollmentlimit_max', 'choicegroup', $ecmax);
    }
    if ($enrollmentinfomsg) {
        echo $OUTPUT->box(
            html_writer::tag('strong', get_string('enrollmentconditions', 'choicegroup') . ': ') . $enrollmentinfomsg,
            'generalbox',
            'choicegroup-enrollment-conditions'
        );
    }
}

// If user has already made a selection, and they are not allowed to update it, show their selected answer.
if (isloggedin() && ($current !== false)) {
    if ($choicegroup->multipleenrollmentspossible == 1) {
        $currents = choicegroup_get_user_answer($choicegroup, $USER, true, true);

        $names = [];
        if (is_array($currents)) {
            foreach ($currents as $current) {
                $names[] = format_string($current->name);
            }
        }

        $formattednames = join(
            ' ' . get_string("and", "choicegroup") . ' ',
            array_filter(array_merge(
                [join(', ', array_slice($names, 0, -1))],
                array_slice($names, -1)
            ))
        );
        echo $OUTPUT->box(get_string(
            "yourselection",
            "choicegroup",
            userdate($choicegroup->timeopen)
        ) . ": " . $formattednames, 'generalbox', 'yourselection');
    } else {
        echo $OUTPUT->box(get_string(
            "yourselection",
            "choicegroup",
            userdate($choicegroup->timeopen)
        ) . ": " . format_string($current->name), 'generalbox', 'yourselection');
    }
}

// Print the form.
$choicegroupopen = true;
$timenow = time();
if ($choicegroup->timeclose != 0) {
    if ($choicegroup->timeopen > $timenow) {
        echo $OUTPUT->box(get_string("notopenyet", "choicegroup", userdate($choicegroup->timeopen)), "generalbox notopenyet");
        echo $OUTPUT->footer();
        exit;
    } else if ($timenow > $choicegroup->timeclose) {
        echo $OUTPUT->box(get_string("expired", "choicegroup", userdate($choicegroup->timeclose)), "generalbox expired");
        $choicegroupopen = false;
    }
}

$options = choicegroup_prepare_options($choicegroup, $USER, $cm, $allresponses);
$renderer = $PAGE->get_renderer('mod_choicegroup');

if ((int)$choicegroup->selectionlayout === \mod_choicegroup\local\selection::LAYOUT_CARDS) {
    // The cards decide for themselves whether the viewer may choose (open, enrolled, allowupdate).
    echo $renderer->display_selection_cards($choicegroup, $cm);
} else if ((!$current || $choicegroup->allowupdate) && $choicegroupopen && is_enrolled($context, null, 'mod/choicegroup:choose')) {
    // They haven't made their choicegroup yet or updates allowed and choicegroup is open.

    echo $renderer->display_options(
        $options,
        $cm->id,
        $choicegroup->display,
        $choicegroup->publish,
        $choicegroup->limitanswers,
        $choicegroup->showresults,
        $current,
        $choicegroupopen,
        false,
        $choicegroup->multipleenrollmentspossible,
        $choicegroup->onlyactive,
        $choicegroup->defaultgroupdescriptionstate
    );
} else {
    // Form can not be updated.
    echo $renderer->display_options(
        $options,
        $cm->id,
        $choicegroup->display,
        $choicegroup->publish,
        $choicegroup->limitanswers,
        $choicegroup->showresults,
        $current,
        $choicegroupopen,
        true,
        $choicegroup->multipleenrollmentspossible,
        $choicegroup->onlyactive,
        $choicegroup->defaultgroupdescriptionstate
    );
}

$choicegroupformshown = true;

$sitecontext = context_system::instance();

if (isguestuser()) {
    // Guest account.
    echo $OUTPUT->confirm(
        get_string('noguestchoose', 'choicegroup') . '<br /><br />' . get_string('liketologin'),
        get_login_url(),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
} else if (!is_enrolled($context)) {
    // Only people enrolled can make a choicegroup.
    $SESSION->wantsurl = $FULLME;
    $SESSION->enrolcancel = (!empty($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : '';

    $coursecontext = context_course::instance($course->id);
    $courseshortname = format_string($course->shortname, true, ['context' => $coursecontext]);

    echo $OUTPUT->box_start('generalbox', 'notice');
    echo '<p class="center">' . get_string('notenrolledchoose', 'choicegroup') . '</p>';
    echo $OUTPUT->container_start('continuebutton');
    echo $OUTPUT->single_button(
        new moodle_url('/enrol/index.php?', ['id' => $course->id]),
        get_string('enrolme', 'core_enrol', $courseshortname)
    );
    echo $OUTPUT->container_end();
    echo $OUTPUT->box_end();
}

// When the results are visible the report block is already printed above, so this branch stays empty on purpose;
// the others print why no results are shown.
// phpcs:disable Generic.CodeAnalysis.EmptyStatement
if (
    $choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_ALWAYS ||
    ($choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $current) ||
    ($choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && !$choicegroupopen)
) {
} else if ($choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_NOT) {
    echo $OUTPUT->box(get_string('neverresultsviewable', 'choicegroup'));
} else if ($choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && !$current) {
    echo $OUTPUT->box(get_string('afterresultsviewable', 'choicegroup'));
} else if ($choicegroup->showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && $choicegroupopen) {
    echo $OUTPUT->box(get_string('notyetresultsviewable', 'choicegroup'));
} else if (!$choicegroupformshown) {
    echo $OUTPUT->box(get_string('noresultsviewable', 'choicegroup'));
}

echo $OUTPUT->footer();
