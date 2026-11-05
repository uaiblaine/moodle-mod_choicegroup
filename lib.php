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
 * Library of interface functions and constants for the choicegroup module
 *
 * @package    mod_choicegroup
 * @copyright  2013 Université de Lausanne
 * @author     Nicolas Dunand <Nicolas.Dunand@unil.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

define('CHOICEGROUP_COLUMN_HEIGHT', '300');
define('CHOICEGROUP_COLUMN_WIDTH', '300');

define('CHOICEGROUP_PUBLISH_ANONYMOUS', '0');
define('CHOICEGROUP_PUBLISH_NAMES', '1');
define('CHOICEGROUP_PUBLISH_DEFAULT', '1');

define('CHOICEGROUP_SHOWRESULTS_NOT', '0');
define('CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER', '1');
define('CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE', '2');
define('CHOICEGROUP_SHOWRESULTS_ALWAYS', '3');
define('CHOICEGROUP_SHOWRESULTS_DEFAULT', '3');

define('CHOICEGROUP_DISPLAY_HORIZONTAL', '0');
define('CHOICEGROUP_DISPLAY_VERTICAL', '1');

define('CHOICEGROUP_SORTGROUPS_SYSTEMDEFAULT', '0');
define('CHOICEGROUP_SORTGROUPS_CREATEDATE', '1');
define('CHOICEGROUP_SORTGROUPS_NAME', '2');

define('CHOICEGROUP_GROUPDESCRIPTIONSTATE_HIDDEN', 0);
define('CHOICEGROUP_GROUPDESCRIPTIONSTATE_VISIBLE', 1);

// Kept for parity with upstream, which still supports Moodle versions that predate these constants.
if (!defined('FEATURE_MOD_PURPOSE')) {
    define('FEATURE_MOD_PURPOSE', 'mod_purpose');
}

if (!defined('MOD_PURPOSE_COLLABORATION')) {
    define('MOD_PURPOSE_COLLABORATION', 'collaboration');
}

// Same upstream-parity guard: core defines this constant from Moodle 5.1.
if (!defined('FEATURE_MOD_OTHERPURPOSE')) {
    define('FEATURE_MOD_OTHERPURPOSE', 'mod_otherpurpose');
}

global $choicegrouppublish;
$choicegrouppublish = [CHOICEGROUP_PUBLISH_ANONYMOUS  => get_string('publishanonymous', 'choicegroup'),
                         CHOICEGROUP_PUBLISH_NAMES      => get_string('publishnames', 'choicegroup'), ];

global $choicegroupshowresults;
$choicegroupshowresults = [CHOICEGROUP_SHOWRESULTS_NOT          => get_string('publishnot', 'choicegroup'),
                         CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER => get_string('publishafteranswer', 'choicegroup'),
                         CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE  => get_string('publishafterclose', 'choicegroup'),
                         CHOICEGROUP_SHOWRESULTS_ALWAYS       => get_string('publishalways', 'choicegroup'), ];

global $choicegroupdisplay;
$choicegroupdisplay = [CHOICEGROUP_DISPLAY_HORIZONTAL   => get_string('displayhorizontal', 'choicegroup'),
                         CHOICEGROUP_DISPLAY_VERTICAL     => get_string('displayvertical', 'choicegroup'), ];

require_once($CFG->dirroot . '/group/lib.php');

// Standard functions /////////////////////////////////////////////////////////.

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $choicegroup
 * @return object|null
 */
function choicegroup_user_outline($course, $user, $mod, $choicegroup) {
    if ($groupmembership = choicegroup_get_user_answer($choicegroup, $user)) { // If user has answered.
        $result = new stdClass();
        $result->info = "'" . format_string($groupmembership->name) . "'";
        $result->time = $groupmembership->timeuseradded;
        return $result;
    }

    return null;
}

/**
 * Get the response for a user in a choicegroup.
 *
 * @param object $choicegroup
 * @param object $user
 * @param bool $returnarray
 * @param bool $refresh
 * @return array|false|mixed
 * @throws coding_exception
 * @throws dml_exception
 */
function choicegroup_get_user_answer($choicegroup, $user, $returnarray = false, $refresh = false) {
    global $DB;

    if (is_numeric($user)) {
        $userid = (int)$user;
    } else {
        $userid = (int)$user->id;
    }

    $choicegroupid = is_numeric($choicegroup) ? (int)$choicegroup : (int)$choicegroup->id;

    // Keyed by instance and user: one request reads several instances (the course overview, custom completion,
    // a web service batch), and a cache keyed by user alone returned the first instance's answer for all of them.
    $cache = choicegroup_get_request_cache();
    $cachekey = 'answer_' . $choicegroupid . '_' . $userid;
    $groups = $refresh ? false : $cache->get($cachekey);

    if ($groups === false) {
        $groups = [];
        $choicegroupgroups = choicegroup_get_groups($choicegroupid);
        if ($choicegroupgroups) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($choicegroupgroups));
            $params = array_merge([$userid], $params);
            $groupmemberships = $DB->get_records_sql(
                'SELECT * FROM {groups_members} WHERE userid = ? AND groupid ' . $insql . ' ORDER BY id',
                $params
            );
            foreach ($groupmemberships as $groupmembership) {
                $group = clone $choicegroupgroups[$groupmembership->groupid];
                $group->timeuseradded = $groupmembership->timeadded;
                $groups[] = $group;
            }
        }

        // A miss is not cached, as upstream did not: a membership another code path adds in this request must show.
        if ($groups) {
            $cache->set($cachekey, $groups);
        }
    }

    if (!$groups) {
        return false;
    }

    return ($returnarray === true) ? $groups : $groups[0];
}

/**
 * The request-scoped cache that holds the groups, answers and response data of each instance.
 *
 * A request cache rather than function statics: it is keyed per instance by its callers, and PHPUnit resets it between
 * tests, where reset sequences hand the next test the same instance ids.
 *
 * @return \core_cache\loader_interface
 */
function choicegroup_get_request_cache() {
    return \core_cache\cache::make_from_params(\core_cache\store::MODE_REQUEST, 'mod_choicegroup', 'requestdata');
}

/**
 * Forget every cached answer and response of this request, after the plugin changed a membership.
 *
 * @return void
 */
function choicegroup_purge_request_cache() {
    choicegroup_get_request_cache()->purge();
}

/**
 * Print the option the user has chosen in the choicegroup, or a notice that none was chosen.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $choicegroup
 * @return void
 */
function choicegroup_user_complete($course, $user, $mod, $choicegroup) {
    if ($groupmembership = choicegroup_get_user_answer($choicegroup, $user)) { // If user has answered.
        $result = new stdClass();
        $result->info = "'" . format_string($groupmembership->name) . "'";
        $result->time = $groupmembership->timeuseradded;
        echo get_string("answered", "choicegroup") . ": $result->info. " . get_string("updated", '', userdate($result->time));
    } else {
        print_string("notanswered", "choicegroup");
    }
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $choicegroup
 * @return int
 */
function choicegroup_add_instance($choicegroup) {
    global $DB;

    $choicegroup->timemodified = time();

    if (empty($choicegroup->timerestrict)) {
        $choicegroup->timeopen = 0;
        $choicegroup->timeclose = 0;
    }

    // Insert answers.
    $choicegroup->id = $DB->insert_record("choicegroup", $choicegroup);

    // Deserialize the selected groups.

    $groupids = choicegroup_parse_selected_groups($choicegroup->serializedselectedgroups ?? '', (int)$choicegroup->course);

    foreach ($groupids as $groupid) {
        if (!empty($groupid)) {
            $option = new stdClass();
            $option->groupid = $groupid;
            $option->choicegroupid = $choicegroup->id;
            $property = 'group_' . $groupid . '_limit';
            if (isset($choicegroup->$property)) {
                $option->maxanswers = (int)$choicegroup->$property;
            }

            $option->timemodified = time();
            $DB->insert_record("choicegroup_options", $option);
        }
    }

    if (class_exists('\core_completion\api')) {
        $completiontimeexpected = !empty($choicegroup->completionexpected) ? $choicegroup->completionexpected : null;
        \core_completion\api::update_completion_date_event(
            $choicegroup->coursemodule,
            'choicegroup',
            $choicegroup->id,
            $completiontimeexpected
        );
    }

    choicegroup_purge_request_cache();

    return $choicegroup->id;
}

/**
 * Parse the group ids the settings form serialises into `serializedselectedgroups`.
 *
 * The field is a hidden input the browser can rewrite, so only integer ids of groups of this course survive, once each
 * (a group can be offered through several groupings).
 *
 * @param string $serialized Group ids separated by `;`
 * @param int $courseid The course the activity belongs to
 * @return int[] The valid group ids, in the order they were given
 */
function choicegroup_parse_selected_groups($serialized, $courseid) {
    global $DB;

    $groupids = [];
    foreach (explode(';', (string)$serialized) as $groupid) {
        $groupid = trim($groupid);
        if ($groupid !== '' && ctype_digit($groupid)) {
            $groupids[(int)$groupid] = (int)$groupid;
        }
    }

    if (!$groupids) {
        return [];
    }

    [$insql, $params] = $DB->get_in_or_equal(array_keys($groupids));
    $params[] = $courseid;
    $coursegroupids = $DB->get_fieldset_select('groups', 'id', "id $insql AND courseid = ?", $params);
    $coursegroupids = array_map('intval', $coursegroupids);

    return array_values(array_intersect($groupids, $coursegroupids));
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $choicegroup
 * @return bool
 */
function choicegroup_update_instance($choicegroup) {
    global $DB;

    $choicegroup->id = $choicegroup->instance;
    $choicegroup->timemodified = time();

    if (empty($choicegroup->timerestrict)) {
        $choicegroup->timeopen = 0;
        $choicegroup->timeclose = 0;
    }

    if (empty($choicegroup->multipleenrollmentspossible)) {
        $choicegroup->multipleenrollmentspossible = 0;
    }

    // Deserialize the selected groups.

    if (isset($choicegroup->serializedselectedgroups)) {
        $groupids = choicegroup_parse_selected_groups($choicegroup->serializedselectedgroups, (int)$choicegroup->course);

        // Prepare pre-existing selected groups from database.
        $preexistinggroups = $DB->get_records("choicegroup_options", ["choicegroupid" => $choicegroup->id], "id");
    } else {
        // A caller without the group picker (the bulk completion form) keeps the options as they are.
        $groupids = [];
        $preexistinggroups = [];
    }

    // Walk through form-selected groups.
    foreach ($groupids as $groupid) {
        if (!empty($groupid)) {
            $option = new stdClass();
            $option->groupid = $groupid;
            $option->choicegroupid = $choicegroup->id;
            $property = 'group_' . $groupid . '_limit';
            if (isset($choicegroup->$property)) {
                $option->maxanswers = (int)$choicegroup->$property;
            }

            $option->timemodified = time();
            // Find out if this selection already exists.
            foreach ($preexistinggroups as $key => $preexistinggroup) {
                if ($option->groupid == $preexistinggroup->groupid) {
                    // Match found, so instead of creating a new record we should merely update a pre-existing record.
                    $option->id = $preexistinggroup->id;
                    $DB->update_record("choicegroup_options", $option);
                    // Remove the element from the array to not deal with it later.
                    unset($preexistinggroups[$key]);
                    continue 2; // Continue the big loop.
                }
            }

            $DB->insert_record("choicegroup_options", $option);
        }
    }

    // Remove all remaining pre-existing groups which did not appear in the form (and are thus assumed to have been deleted).
    foreach ($preexistinggroups as $preexistinggroup) {
        $DB->delete_records("choicegroup_options", ["id" => $preexistinggroup->id]);
    }

    if (class_exists('\core_completion\api')) {
        $completiontimeexpected = !empty($choicegroup->completionexpected) ? $choicegroup->completionexpected : null;
        \core_completion\api::update_completion_date_event(
            $choicegroup->coursemodule,
            'choicegroup',
            $choicegroup->id,
            $completiontimeexpected
        );
    }

    choicegroup_purge_request_cache();

    return $DB->update_record('choicegroup', $choicegroup);
}

/**
 * Prepare the options data for the choicegroup view page.
 *
 * @param object $choicegroup
 * @param object $user
 * @param object $coursemodule
 * @param array $allresponses
 * @return array
 */
function choicegroup_prepare_options($choicegroup, $user, $coursemodule, $allresponses) {

    $cdisplay = ['options' => []];

    $cdisplay['limitanswers'] = true;
    $context = context_module::instance($coursemodule->id);
    $answers = choicegroup_get_user_answer($choicegroup, $user, true, true);

    if (!isset($choicegroup->option)) {
        $choicegroup->option = [];
    }

    foreach ($choicegroup->option as $optionid => $text) {
        if (isset($text)) { // Make sure there are no dud entries in the db with blank text values.
            $option = new stdClass();
            $option->attributes = new stdClass();
            $option->attributes->value = $optionid;
            $option->groupid = $text;
            $option->maxanswers = $choicegroup->maxanswers[$optionid];
            $option->displaylayout = $choicegroup->display;

            if (isset($allresponses[$text])) {
                $option->countanswers = count($allresponses[$text]);
            } else {
                $option->countanswers = 0;
            }

            if (is_array($answers)) {
                foreach ($answers as $answer) {
                    if ($answer && $text == $answer->id) {
                        $option->attributes->checked = true;
                    }
                }
            }

            if (
                $choicegroup->limitanswers && ($option->countanswers >= $option->maxanswers) &&
                empty($option->attributes->checked)
            ) {
                $option->attributes->disabled = true;
            }

            $cdisplay['options'][] = $option;
        }
    }

    // Only enrolled users are allowed to make a choicegroup.
    $cdisplay['hascapability'] = is_enrolled($context, null, 'mod/choicegroup:choose');

    if ($choicegroup->allowupdate && is_array($answers)) {
        $cdisplay['allowupdate'] = true;
    }

    return $cdisplay;
}

/**
 * Check if the user can view the choicegroup.
 *
 * @param object $choicegroup
 * @param int $userid
 * @throws \moodle_exception
 */
function check_restrictions($choicegroup, $userid) {
    // Only the dates are checked here. Enrolment, the capability and allowupdate are rules of a change, which need the
    // module context: they live in choicegroup_check_user_can_change(), which calls this function.
    check_date_restrictions($choicegroup);
}


/**
 * Whether the member counts of an instance's groups may be shown to a user, as the instance's showresults has it.
 *
 * "After close" means past the closing date: before the opening date the activity is not closed. A holder of
 * mod/choicegroup:readresponses reads every response in the report anyway; callers that show counts to such a user
 * test that capability themselves. {@see \mod_choicegroup\local\selection} and the web services read this rule.
 *
 * @param object $choicegroup The instance record
 * @param bool $answered Whether the user holds a choice in this instance
 * @return bool
 */
function choicegroup_results_visible($choicegroup, bool $answered): bool {
    $showresults = (int)$choicegroup->showresults;
    $closed = !empty($choicegroup->timeclose) && time() > $choicegroup->timeclose;

    return $showresults === (int)CHOICEGROUP_SHOWRESULTS_ALWAYS ||
        ($showresults === (int)CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $answered) ||
        ($showresults === (int)CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && $closed);
}

/**
 * Check if the choicegroup is open or closed.
 *
 * @param object $choicegroup
 * @throws \moodle_exception
 */
function check_date_restrictions($choicegroup) {
    // Empty rather than !== '0': a record built in code carries the integer 0, which is never identical to '0'.
    if (!empty($choicegroup->timeopen) && time() < $choicegroup->timeopen) {
        throw new moodle_exception('activitydate:notavailableyet', 'choicegroup');
    }

    if (!empty($choicegroup->timeclose) && time() > $choicegroup->timeclose) {
        throw new moodle_exception('activitydate:exceeded', 'choicegroup');
    }
}

/**
 * Check that a user may change their own choice in this instance now, whatever the change is.
 *
 * The one place the rules of a self-service change live, shared by view.php and the web services: the user is
 * enrolled with mod/choicegroup:choose, the activity is open, and a user who already holds a choice may change or
 * remove it only when the instance allows updates.
 *
 * @param object $choicegroup The instance record
 * @param int $userid The user whose choice changes
 * @param context_module $context The module context
 * @return void
 * @throws moodle_exception When the change is not allowed
 */
function choicegroup_check_user_can_change($choicegroup, $userid, $context) {
    if (!is_enrolled($context, $userid, 'mod/choicegroup:choose')) {
        throw new moodle_exception('notenrolledchoose', 'choicegroup');
    }

    check_restrictions($choicegroup, $userid);

    if (empty($choicegroup->allowupdate) && choicegroup_get_user_answer($choicegroup, $userid, false, true) !== false) {
        throw new moodle_exception('updatenotallowed', 'choicegroup');
    }
}

/**
 * Return the group ids this instance offers, read from its options.
 *
 * @param object $choicegroup The instance record
 * @return int[] Group ids, keyed by option id
 */
function choicegroup_get_option_groupids($choicegroup) {
    global $DB;

    $groupids = $DB->get_records_menu('choicegroup_options', ['choicegroupid' => $choicegroup->id], 'id', 'id, groupid');

    return array_map('intval', $groupids);
}

/**
 * Save the set of options a user chose, adding and removing their memberships to match it.
 *
 * Every self-service change goes through here, from view.php and from the web services alike, so both enforce the
 * same rules: {@see choicegroup_check_user_can_change()}, every option belongs to this instance, and the number of
 * options respects the instance's minimum and maximum (multiple enrolments) or is exactly one (single choice).
 *
 * @param object $choicegroup The instance record
 * @param int $userid The user who chooses
 * @param object $course Course record
 * @param object $cm Course module record
 * @param int[] $optionids The choicegroup_options ids the user wants to hold
 * @return void
 * @throws moodle_exception When a rule refuses the change
 */
function choicegroup_save_user_choices($choicegroup, $userid, $course, $cm, array $optionids) {
    global $DB;

    $userid = (int)$userid;
    $context = context_module::instance($cm->id);

    // One save of a user's choice at a time: two saves that both read the choice before either wrote would each pass
    // the rules (allowupdate, the maximum) and each keep its own groups, leaving the user in a set nobody chose.
    // A removal takes no lock: racing a save it can only leave the user with fewer groups, never break a rule.
    $userlock = choicegroup_lock_user_choice($choicegroup, $userid);
    $locks = [];

    try {
        choicegroup_check_user_can_change($choicegroup, $userid, $context);

        $optiongroupids = choicegroup_get_option_groupids($choicegroup);
        $optionids = array_values(array_unique(array_map('intval', $optionids)));
        foreach ($optionids as $optionid) {
            if (!isset($optiongroupids[$optionid])) {
                throw new moodle_exception('invalidoption', 'choicegroup');
            }
        }

        $count = count($optionids);
        if (empty($choicegroup->multipleenrollmentspossible)) {
            if ($count !== 1) {
                throw new moodle_exception('mustchooseone', 'choicegroup');
            }
        } else {
            if (!empty($choicegroup->maxenrollments) && $count > $choicegroup->maxenrollments) {
                throw new moodle_exception('mustchoosemax', 'choicegroup', '', $choicegroup->maxenrollments);
            }

            if (!empty($choicegroup->minenrollments) && $count < $choicegroup->minenrollments) {
                throw new moodle_exception('mustchoosemin', 'choicegroup', '', $choicegroup->minenrollments);
            }
        }

        // The limited groups the set joins are locked before the transaction and released after its commit: another
        // request counting a group's members cannot see this one's additions until the commit, so a lock released
        // earlier would let both see room for one more and overfill the group.
        // Every limited group the set names is locked, joined or not yet: a membership read before the lock could
        // change before the add, and a group left unlocked would then be joined under no lock.
        $tojoin = [];
        if ($choicegroup->limitanswers) {
            foreach ($optionids as $optionid) {
                $tojoin[] = $optiongroupids[$optionid];
            }
        }
        $locks = choicegroup_lock_groups($tojoin);

        // One transaction for the whole change, a single choice being a set of one: a group that refuses the user
        // after earlier additions and removals were written would otherwise leave the user in a state nobody chose
        // (no group, or two in single mode). Core buffers the events until the commit and drops them on a rollback.
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($optiongroupids as $optionid => $groupid) {
                if (in_array($optionid, $optionids, true)) {
                    if (!groups_is_member($groupid, $userid)) {
                        choicegroup_user_submit_response($optionid, $choicegroup, $userid, $course, $cm, isset($locks[$groupid]));
                    }
                } else {
                    choicegroup_remove_member($choicegroup, $groupid, $userid, $course, $cm);
                }
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            // The request cache was refilled inside the transaction with rows that are about to vanish.
            choicegroup_purge_request_cache();
            $transaction->rollback($e);
        }
    } finally {
        // After the commit or the rollback, and also when a rule refused the change or the transaction could not start.
        choicegroup_release_locks($locks);
        $userlock->release();
    }
}

/**
 * Take the lock under which a user's choice in one instance changes.
 *
 * {@see choicegroup_save_user_choices()} takes it before it reads the user's current choice and holds it until its
 * commit; it is always taken before any group lock, so no two requests wait on each other.
 *
 * @param object $choicegroup The instance record
 * @param int $userid The user whose choice changes
 * @return \core\lock\lock The lock
 * @throws moodle_exception When the lock is not granted in time
 */
function choicegroup_lock_user_choice($choicegroup, $userid) {
    $factory = \core\lock\lock_config::get_lock_factory('mod_choicegroup');
    $lock = $factory->get_lock('choice_' . (int)$choicegroup->id . '_' . (int)$userid, 5);
    if (!$lock) {
        throw new moodle_exception('locktimeout');
    }

    return $lock;
}

/**
 * Take the member-limit lock of each group, in group id order: two requests never each hold a lock the other waits for.
 *
 * The lock is the one {@see choicegroup_user_submit_response()} takes around its add and count. Release them with
 * {@see choicegroup_release_locks()}.
 *
 * @param int[] $groupids The groups to lock
 * @return \core\lock\lock[] The locks, keyed by group id
 * @throws moodle_exception When a lock is not granted in time; the locks taken until then are released
 */
function choicegroup_lock_groups(array $groupids) {
    $groupids = array_unique(array_map('intval', $groupids));
    sort($groupids);

    $factory = \core\lock\lock_config::get_lock_factory('mod_choicegroup');
    $locks = [];
    foreach ($groupids as $groupid) {
        $lock = $factory->get_lock('group_' . $groupid, 5);
        if (!$lock) {
            choicegroup_release_locks($locks);
            throw new moodle_exception('locktimeout');
        }
        $locks[$groupid] = $lock;
    }

    return $locks;
}

/**
 * Release the locks {@see choicegroup_lock_groups()} took.
 *
 * @param \core\lock\lock[] $locks The locks
 * @return void
 */
function choicegroup_release_locks(array $locks) {
    foreach ($locks as $lock) {
        $lock->release();
    }
}

/**
 * Remove a user's own choice, all of it or one group of it.
 *
 * The self-service removal of view.php ("Remove my choice") and of the delete web service; it follows the same rules
 * as a change ({@see choicegroup_check_user_can_change()}), and a group id must be one this instance offers.
 *
 * @param object $choicegroup The instance record
 * @param int $userid The user whose choice is removed
 * @param object $course Course record
 * @param object $cm Course module record
 * @param int|null $groupid One group to leave, or null for every group of this instance
 * @return bool True when a membership was removed
 * @throws moodle_exception When a rule refuses the removal
 */
function choicegroup_user_remove_choice($choicegroup, $userid, $course, $cm, $groupid = null) {
    $userid = (int)$userid;
    $context = context_module::instance($cm->id);

    $optiongroupids = choicegroup_get_option_groupids($choicegroup);
    if ($groupid !== null && !in_array((int)$groupid, $optiongroupids, true)) {
        throw new moodle_exception('invalidoption', 'choicegroup');
    }

    if (choicegroup_get_user_answer($choicegroup, $userid, false, true) === false) {
        return false;
    }

    choicegroup_check_user_can_change($choicegroup, $userid, $context);

    $removed = false;
    foreach ($optiongroupids as $optiongroupid) {
        if ($groupid === null || $optiongroupid === (int)$groupid) {
            $removed = choicegroup_remove_member($choicegroup, $optiongroupid, $userid, $course, $cm) || $removed;
        }
    }

    return $removed;
}

/**
 * Remove a user from one of this instance's groups, log it and update completion.
 *
 * @param object $choicegroup The instance record
 * @param int $groupid The group, already known to be one of this instance's options
 * @param int $userid The user leaving the group
 * @param object $course Course record
 * @param object $cm Course module record
 * @return bool True when the user was a member and was removed
 */
function choicegroup_remove_member($choicegroup, $groupid, $userid, $course, $cm) {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    if (!groups_is_member($groupid, $userid)) {
        return false;
    }

    groups_remove_member($groupid, $userid);
    choicegroup_purge_request_cache();

    $event = \mod_choicegroup\event\choice_removed::create([
        'context' => context_module::instance($cm->id),
        'objectid' => $choicegroup->id,
        'relateduserid' => $userid,
        'other' => ['groupid' => (int)$groupid],
    ]);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('choicegroup', choicegroup_get_snapshot_record($choicegroup));
    $event->trigger();

    // Update completion state.
    $completion = new completion_info($course);
    if (
        $completion->is_enabled($cm) && !empty($choicegroup->completionsubmit) &&
        choicegroup_get_user_answer($choicegroup, $userid, false, true) === false
    ) {
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid);
    }

    return true;
}

/**
 * Return the instance as its database row, for an event snapshot.
 *
 * choicegroup_get_choicegroup() adds the option arrays to the row; a snapshot keeps the table's columns only.
 *
 * @param object $choicegroup The instance, possibly with the option arrays
 * @return stdClass
 */
function choicegroup_get_snapshot_record($choicegroup) {
    $record = clone $choicegroup;
    unset($record->option, $record->maxanswers, $record->grpmemberid);

    return $record;
}

/**
 * Process user submitted answers for a choicegroup,
 * and either updating them or saving new answers.
 *
 * @param int $formanswer The choicegroup_options id of the chosen option
 * @param object $choicegroup
 * @param int $userid
 * @param object $course Course object
 * @param object $cm
 * @param bool $locked Whether the caller holds the group's member-limit lock ({@see choicegroup_lock_groups()})
 * @throws \moodle_exception
 */
function choicegroup_user_submit_response($formanswer, $choicegroup, $userid, $course, $cm, $locked = false) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $userid = (int)$userid;
    $context = context_module::instance($cm->id);
    $eventparams = [
        'context' => $context,
        'objectid' => $choicegroup->id,
        'relateduserid' => $userid,
    ];

    check_restrictions($choicegroup, $userid);

    // The option is resolved within this instance, and its group within this course: an option id of another
    // activity would otherwise add the user to any group of the site.
    $selectedoption = $DB->get_record('choicegroup_options', ['id' => $formanswer, 'choicegroupid' => $choicegroup->id]);
    if (!$selectedoption || !$DB->record_exists('groups', ['id' => $selectedoption->groupid, 'courseid' => $course->id])) {
        throw new moodle_exception('invalidoption', 'choicegroup');
    }

    $selectedoption->groupid = (int)$selectedoption->groupid;
    $eventparams['other'] = ['groupid' => $selectedoption->groupid];

    $current = choicegroup_get_user_answer($choicegroup, $userid, false, true);
    $alreadymember = groups_is_member($selectedoption->groupid, $userid);

    $countanswers = 0;
    $groupmemberadded = true;
    if ($choicegroup->limitanswers) {
        // The add and the count run under one lock per group: two requests that both added before either counted
        // would both see themselves within the limit and overfill the group. A caller inside a transaction holds
        // the lock until its commit instead.
        $locks = $locked ? [] : choicegroup_lock_groups([$selectedoption->groupid]);

        try {
            groups_add_member($selectedoption->groupid, $userid);
            $groupmember = $DB->get_record('groups_members', ['groupid' => $selectedoption->groupid, 'userid' => $userid]);
            $countanswers = $DB->count_records_select(
                'groups_members',
                'groupid = ? AND id <= ?',
                [$selectedoption->groupid, $groupmember->id]
            );
            $maxans = (int)$selectedoption->maxanswers;
            if ($countanswers > $maxans && !$alreadymember) {
                groups_remove_member($selectedoption->groupid, $userid);
                $groupmemberadded = false;
            }
        } finally {
            choicegroup_release_locks($locks);
        }
    } else {
        groups_add_member($selectedoption->groupid, $userid);
    }

    choicegroup_purge_request_cache();

    if ($groupmemberadded) {
        if (!$alreadymember) {
            $event = \mod_choicegroup\event\choice_updated::create($eventparams);
            $event->add_record_snapshot('course_modules', $cm);
            $event->add_record_snapshot('course', $course);
            $event->add_record_snapshot('choicegroup', choicegroup_get_snapshot_record($choicegroup));
            $event->trigger();
        }

        if ($current) {
            if (!($choicegroup->multipleenrollmentspossible == 1)) {
                if ($selectedoption->groupid != $current->id) {
                    choicegroup_remove_member($choicegroup, (int)$current->id, $userid, $course, $cm);
                }
            }
        } else {
            // Update completion state.
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) && $choicegroup->completionsubmit) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
            }
        }
    } else {
        // Check to see if the current choicegroup is already selected; if not, display the error.
        if (!$current || !($current->id == $selectedoption->groupid)) {
            throw new moodle_exception('choicegroupfull', 'choicegroup', $CFG->wwwroot . '/mod/choicegroup/view.php?id=' . $cm->id);
        }
    }
}

/**
 * Show the choicegroup report link to the user.
 *
 * @param object $choicegroup
 * @param array $allresponses
 * @param object $cm
 * @return void Output is echo'd
 */
function choicegroup_show_reportlink($choicegroup, $allresponses, $cm) {
    $responsecount = 0;
    $respondents = [];
    foreach ($allresponses as $optionid => $userlist) {
        if ($optionid) {
            $responsecount += count($userlist);
            if ($choicegroup->multipleenrollmentspossible) {
                foreach ($userlist as $user) {
                    if (!in_array($user->id, $respondents)) {
                        $respondents[] = $user->id;
                    }
                }
            }
        }
    }

    echo '<div class="reportlink"><a href="report.php?id=' . $cm->id . '">' .
        get_string("viewallresponses", "choicegroup", $responsecount);
    if ($choicegroup->multipleenrollmentspossible == 1) {
        echo ' ' . get_string("byparticipants", "choicegroup", count($respondents));
    }

    echo '</a></div>';
}

/**
 * Build the rows of the responses download: one row per user, identity columns as core decides.
 *
 * The identity columns are the ones core shows in this context ({@see \core_user\fields::get_identity_fields()}, the
 * pattern of mod_choice's report), so email or idnumber leave the site only where the site shows them to this viewer.
 * Custom profile fields are not included: the response data carries the user table's columns only.
 *
 * @param object $choicegroup The instance record
 * @param object $course Course record
 * @param context_module $context The module context
 * @param array $users The response data of choicegroup_get_response_data()
 * @return array Two keys: header (column titles) and rows (lists of cell values)
 */
function choicegroup_get_export_data($choicegroup, $course, $context, $users) {
    $extrafields = \core_user\fields::get_identity_fields($context, false);

    $header = [get_string('lastname'), get_string('firstname')];
    foreach ($extrafields as $field) {
        $header[] = \core_user\fields::get_display_name($field);
    }

    $header[] = get_string('group');
    $header[] = get_string('choice', 'choicegroup');

    $groupsids = array_keys(choicegroup_get_groups($choicegroup));
    $rows = [];
    $displayed = [];
    foreach ($users ?: [] as $userlist) {
        foreach ($userlist as $user) {
            if (isset($displayed[$user->id])) {
                continue;
            }

            $displayed[$user->id] = true;
            $row = [$user->lastname, $user->firstname];
            foreach ($extrafields as $field) {
                $row[] = (string)($user->$field ?? '');
            }

            $ug2 = [];
            if ($usergrps = groups_get_all_groups($course->id, $user->id)) {
                foreach ($groupsids as $gid) {
                    if (array_key_exists($gid, $usergrps)) {
                        $ug2[] = format_string($usergrps[$gid]->name, true, ['context' => $context, 'escape' => false]);
                    }
                }
            }

            $row[] = implode(', ', $ug2);
            $rows[] = $row;
        }
    }

    return ['header' => $header, 'rows' => $rows];
}

/**
 * Make a value safe for one cell of the tab-separated download.
 *
 * A tab or a line break inside a name would shift every following column or start a forged row, and a cell that
 * starts with a formula character (=, +, -, @) runs as a formula when a spreadsheet opens the file: such a cell is
 * prefixed with an apostrophe, which spreadsheets read as "text" and do not show. The ODS and Excel downloads write
 * string cells and need neither.
 *
 * @param string $value The cell value
 * @return string The value with tabs and line breaks replaced by spaces, and a leading formula character escaped
 */
function choicegroup_clean_txt_cell($value) {
    $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$value);
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        $value = "'" . $value;
    }

    return $value;
}

/**
 * Prepare the data for the report page.
 *
 * @param object $choicegroup
 * @param object $course
 * @param object $cm
 * @param array $allresponses
 * @param bool $forcepublish Unused: the renderer decides how the results show ({@see mod_choicegroup_renderer::display_result()})
 * @return object|false The display data, or false (after printing a notice) when there are no responses
 */
function prepare_choicegroup_show_results($choicegroup, $course, $cm, $allresponses, $forcepublish = false) {
    global $OUTPUT;

    $display = clone($choicegroup);
    $display->coursemoduleid = $cm->id;
    $display->courseid = $course->id;

    // Overwrite options value.
    $display->options = [];
    $totaluser = 0;
    foreach ($choicegroup->option as $optionid => $groupid) {
        $display->options[$optionid] = new stdClass();
        $display->options[$optionid]->groupid = $groupid;
        $display->options[$optionid]->maxanswer = $choicegroup->maxanswers[$optionid];

        if (array_key_exists($groupid, $allresponses)) {
            $display->options[$optionid]->user = $allresponses[$groupid];
            foreach ($display->options[$optionid]->user as $user) {
                $user->grpsmemberid = array_search([$groupid, $user->id], $choicegroup->grpmemberid);
            }

            $totaluser += count($allresponses[$groupid]);
        }
    }

    if ($choicegroup->showunanswered) {
        $display->options[0]->user = $allresponses[0];
    }

    unset($display->option);
    unset($display->maxanswers);

    $display->numberofuser = $totaluser;
    $context = context_module::instance($cm->id);
    $display->viewresponsecapability = has_capability('mod/choicegroup:readresponses', $context);
    $display->deleterepsonsecapability = has_capability('mod/choicegroup:deleteresponses', $context);
    $display->fullnamecapability = has_capability('moodle/site:viewfullnames', $context);

    if (empty($allresponses)) {
        echo $OUTPUT->heading(get_string("nousersyet"));
        return false;
    }

    return $display;
}

/**
 * Remove the chosen memberships from the choicegroup's option groups.
 *
 * The ids are groups_members ids posted by the report, so each is checked: only memberships of this instance's option
 * groups are removed, and under separate groups only those the acting user can see, a group they may access or a
 * member of one ({@see groups_get_activity_allowed_groups()}). Any other id is skipped.
 *
 * @param array $grpsmemberids groups_members ids
 * @param object $choicegroup The instance record
 * @param object $cm Course-module object
 * @param object $course Course object
 * @return bool
 */
function choicegroup_delete_responses($grpsmemberids, $choicegroup, $cm, $course) {
    global $DB, $USER;

    if (!is_array($grpsmemberids) || empty($grpsmemberids)) {
        return false;
    }

    foreach ($grpsmemberids as $num => $grpsmemberid) {
        if (empty($grpsmemberid)) {
            unset($grpsmemberids[$num]);
        }
    }

    $context = context_module::instance($cm->id);
    $optiongroupids = choicegroup_get_option_groupids($choicegroup);

    $allowedgroupids = null;
    if (
        groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS &&
        !has_capability('moodle/site:accessallgroups', $context)
    ) {
        $allowedgroupids = array_map('intval', array_keys(groups_get_activity_allowed_groups($cm, $USER->id)));
    }

    foreach ($grpsmemberids as $grpsmemberid) {
        $groupsmember = $DB->get_record('groups_members', ['id' => $grpsmemberid]);
        if (!$groupsmember || !in_array((int)$groupsmember->groupid, $optiongroupids, true)) {
            continue;
        }

        $userid = (int)$groupsmember->userid;
        $groupid = (int)$groupsmember->groupid;
        if ($allowedgroupids !== null && !in_array($groupid, $allowedgroupids, true)) {
            $usergroupids = array_map('intval', array_keys(groups_get_all_groups($course->id, $userid)));
            if (!array_intersect($usergroupids, $allowedgroupids)) {
                continue;
            }
        }

        choicegroup_remove_member($choicegroup, $groupid, $userid, $course, $cm);
    }

    return true;
}

/**
 * Return the groups_members ids of the given users in this instance's option groups.
 *
 * view.php posts user ids where choicegroup_delete_responses() expects membership ids.
 *
 * @param object $choicegroup The instance record
 * @param int[] $userids User ids
 * @return int[] groups_members ids
 */
function choicegroup_get_membership_ids($choicegroup, array $userids) {
    global $DB;

    $userids = array_filter(array_map('intval', $userids));
    $optiongroupids = choicegroup_get_option_groupids($choicegroup);
    if (!$userids || !$optiongroupids) {
        return [];
    }

    [$usersql, $userparams] = $DB->get_in_or_equal($userids);
    [$groupsql, $groupparams] = $DB->get_in_or_equal(array_values($optiongroupids));
    $ids = $DB->get_fieldset_select(
        'groups_members',
        'id',
        "userid $usersql AND groupid $groupsql",
        array_merge($userparams, $groupparams)
    );

    return array_map('intval', $ids);
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id
 * @return bool
 */
function choicegroup_delete_instance($id) {
    global $DB;

    if (! $choicegroup = $DB->get_record("choicegroup", ["id" => "$id"])) {
        return false;
    }

    $result = true;

    if (! $DB->delete_records("choicegroup_options", ["choicegroupid" => "$choicegroup->id"])) {
        $result = false;
    }

    if (! $DB->delete_records("choicegroup", ["id" => "$choicegroup->id"])) {
        $result = false;
    }

    return $result;
}

/**
 * Returns text string which is the answer that matches the id
 *
 * @param object $choicegroup
 * @param int $id
 * @return string
 */
function choicegroup_get_option_text($choicegroup, $id) {
    global $DB;

    if ($result = $DB->get_record('groups', ['id' => $id])) {
        return $result->name;
    } else {
        return get_string("notanswered", "choicegroup");
    }
}

/**
 * Keep the members of a group whose membership the current user may see, as core's group visibility decides.
 *
 * Mirrors {@see \core_group\visibility::sql_member_visibility_where()}, which groups_get_members() applies: everyone
 * sees an ALL group's members, members see a MEMBERS group's members, a user sees only themself in an OWN group, nobody
 * sees a NONE group's members, and moodle/course:viewhiddengroups sees them all.
 *
 * @param stdClass $group The group record, with courseid and visibility
 * @param array $members User records keyed by user id
 * @return array The members the current user may see, keyed by user id
 */
function choicegroup_filter_visible_members($group, array $members) {
    global $USER;

    $visibility = (int)($group->visibility ?? GROUPS_VISIBILITY_ALL);
    if ($visibility === GROUPS_VISIBILITY_ALL) {
        return $members;
    }

    if (has_capability('moodle/course:viewhiddengroups', context_course::instance($group->courseid))) {
        return $members;
    }

    switch ($visibility) {
        case GROUPS_VISIBILITY_MEMBERS:
            return groups_is_member($group->id, $USER->id) ? $members : [];
        case GROUPS_VISIBILITY_OWN:
            return array_intersect_key($members, [$USER->id => true]);
        default:
            return [];
    }
}

/**
 * Returns DB records of groups used by the choicegroup activity
 *
 * @param object $choicegroup
 * @return array
 * @throws dml_exception
 */
function choicegroup_get_groups($choicegroup) {
    global $DB;

    if (is_numeric($choicegroup)) {
        $choicegroupid = (int)$choicegroup;
    } else {
        $choicegroupid = (int)$choicegroup->id;
    }

    // Keyed by instance: a static shared by every instance returned the first instance's groups for the next one.
    $cache = choicegroup_get_request_cache();
    $cachekey = 'groups_' . $choicegroupid;
    $groups = $cache->get($cachekey);
    if ($groups !== false) {
        return $groups;
    }

    $groups = [];
    $options = $DB->get_records('choicegroup_options', ['choicegroupid' => $choicegroupid]);
    foreach ($options as $option) {
        if ($group = $DB->get_record('groups', ['id' => $option->groupid])) {
            $groups[$group->id] = $group;
        }
    }

    $cache->set($cachekey, $groups);

    return $groups;
}

/**
 * Gets a full choicegroup record
 *
 * @param int $choicegroupid
 * @return object|bool The choicegroup or false
 */
function choicegroup_get_choicegroup($choicegroupid) {
    global $DB;

    if ($choicegroup = $DB->get_record("choicegroup", ["id" => $choicegroupid])) {
        $sortcolumn = choicegroup_get_sort_column($choicegroup);

        $params = [
            'choicegroupid' => $choicegroupid,
        ];

        $grpfilter = '';
        if (($groupid = optional_param('group', 0, PARAM_INT)) != 0) {
            $params['groupid'] = $groupid;
            $grpfilter = "AND grp_o.groupid = :groupid";
        }

        $sql = "SELECT grp_m.id grpmemberid, grp_m.userid, grp_o.id, grp_o.groupid, grp_o.maxanswers
                 FROM {groups} grp
                 INNER JOIN {choicegroup_options} grp_o on grp.id = grp_o.groupid
                 LEFT JOIN {groups_members} grp_m on grp_m.groupid = grp_o.groupid
                 WHERE grp_o.choicegroupid = :choicegroupid $grpfilter
                 ORDER BY $sortcolumn ASC";

        $rs = $DB->get_recordset_sql($sql, $params);

        $choicegroup->option = [];

        foreach ($rs as $option) {
            $choicegroup->option[$option->id] = $option->groupid;
            $choicegroup->grpmemberid[$option->grpmemberid] = [$option->groupid, $option->userid];
            $choicegroup->maxanswers[$option->id] = $option->maxanswers;
        }

        $rs->close();

        return $choicegroup;
    }

    return false;
}

/**
 * Returns the column to sort the groups by
 *
 * @param object $choicegroup
 * @return string
 * @throws dml_exception
 */
function choicegroup_get_sort_column($choicegroup) {
    if ($choicegroup->sortgroupsby == CHOICEGROUP_SORTGROUPS_SYSTEMDEFAULT) {
        $sortcolumn = get_config('choicegroup', 'sortgroupsby');
    } else {
        $sortcolumn = $choicegroup->sortgroupsby;
    }

    switch ($sortcolumn) {
        case CHOICEGROUP_SORTGROUPS_CREATEDATE:
            return 'timecreated';
        case CHOICEGROUP_SORTGROUPS_NAME:
            return 'name';
        default:
            return 'timecreated';
    }
}

/**
 * List the actions that can be performed on choicegroup module.
 *
 * @return array
 */
function choicegroup_get_view_actions() {
    return ['view', 'view all', 'report'];
}

/**
 * List the actions that correspond to a post of this module.
 *
 * @return array
 */
function choicegroup_get_post_actions() {
    return ['choose', 'choose again'];
}


/**
 * Get all responses for a choicegroup
 *
 * @param object $choicegroup
 * @param object $cm
 * @param int $groupmode Group mode
 * @param bool $onlyactive Whether to get response data for active users only
 * @return array
 */
function choicegroup_get_response_data($choicegroup, $cm, $groupmode, $onlyactive) {
    // Get the current group.
    if ($groupmode > 0) {
        $currentgroup = groups_get_activity_group($cm);
    } else {
        $currentgroup = 0;
    }

    // Keyed by instance and by what shapes the result: a static shared by every instance returned the first
    // instance's responses for the next one in the same request.
    $cache = choicegroup_get_request_cache();
    $cachekey = 'responses_' . (int)$choicegroup->id . '_' . (int)$groupmode . '_' . (int)$currentgroup . '_' .
        (empty($onlyactive) ? 0 : 1);
    $allresponses = $cache->get($cachekey);
    if ($allresponses !== false) {
        return $allresponses;
    }

    // Initialise the returned array, a matrix: $allresponses[groupid][userid] = user object.
    // Group 0 holds the unanswered users.
    $allresponses = [];

    // First get all the users who have access here.
    // To start with we assume they are all "unanswered" then move them later.
    $ctx = \context_module::instance($cm->id);
    $users = get_enrolled_users($ctx, 'mod/choicegroup:choose', $currentgroup, 'u.*', 'u.lastname, u.firstname', 0, 0, $onlyactive);
    if ($users) {
        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->get_cm($cm->id);
        $availability = new \core_availability\info_module($cminfo);
        $users = $availability->filter_user_list($users);
    }

    $allresponses[0] = $users;

    $responses = choicegroup_get_responses($choicegroup, $ctx, $currentgroup, $onlyactive);
    foreach ($responses as $response) {
        if (isset($users[$response->userid])) {
            $allresponses[$response->groupid][$response->userid] = clone $users[$response->userid];
            $allresponses[$response->groupid][$response->userid]->timemodified = $response->timeadded;

            unset($allresponses[0][$response->userid]);
        }
    }

    $cache->set($cachekey, $allresponses);

    return $allresponses;
}

/**
 * Return an array with the options selected of users of the $choicegroup
 *
 * @param object $choicegroup choicegroup record
 * @param context_module $ctx Context instance
 * @param int $currentgroup Current group
 * @param bool $onlyactive Whether to get responses for active users only
 * @return array of selected options by all users
 * @throws dml_exception
 */
function choicegroup_get_responses($choicegroup, $ctx, $currentgroup, $onlyactive) {

    global $DB;

    if (is_numeric($choicegroup)) {
        $choicegroupid = $choicegroup;
    } else {
        $choicegroupid = $choicegroup->id;
    }

    $params1 = ['choicegroupid' => $choicegroupid];
    [$esql, $params2] = get_enrolled_sql($ctx, 'mod/choicegroup:choose', $currentgroup, $onlyactive);
    $params = array_merge($params1, $params2);

    $sql = 'SELECT gm.* FROM {user} u JOIN (' . $esql . ') je ON je.id = u.id
        JOIN {groups_members} gm ON gm.userid = u.id AND groupid IN (
        SELECT groupid FROM {choicegroup_options} WHERE choicegroupid=:choicegroupid)
        WHERE u.deleted = 0 ORDER BY u.lastname ASC,u.firstname ASC';

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function choicegroup_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * List of features supported in choicegroup module
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function choicegroup_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COLLABORATION;
        case FEATURE_MOD_OTHERPURPOSE:
            return MOD_PURPOSE_ADMINISTRATION;
        default:
            return null;
    }
}

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $choicegroupnode The node to add module settings to
 */
function choicegroup_extend_settings_navigation(settings_navigation $settings, navigation_node $choicegroupnode) {
    $cm = $settings->get_page()->cm;

    if (has_capability('mod/choicegroup:readresponses', $cm->context)) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode) {
            groups_get_activity_group($cm, true);
        }

        if (!$choicegroup = choicegroup_get_choicegroup($cm->instance)) {
            throw new moodle_exception('invalidcoursemodule');
            return false;
        }

        $allresponses = choicegroup_get_response_data($choicegroup, $cm, $groupmode, $choicegroup->onlyactive);

        $responsecount = 0;
        $respondents = [];
        foreach ($allresponses as $optionid => $userlist) {
            if ($optionid) {
                $responsecount += count($userlist);
                if ($choicegroup->multipleenrollmentspossible) {
                    foreach ($userlist as $user) {
                        if (!in_array($user->id, $respondents)) {
                            $respondents[] = $user->id;
                        }
                    }
                }
            }
        }

        $viewallresponsestext = get_string("viewallresponses", "choicegroup", $responsecount);
        if ($choicegroup->multipleenrollmentspossible == 1) {
            $viewallresponsestext .= ' ' . get_string("byparticipants", "choicegroup", count($respondents));
        }

        $choicegroupnode->add($viewallresponsestext, new moodle_url('/mod/choicegroup/report.php', ['id' => $cm->id]));
    }
}

/**
 * Return a list of page types
 *
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 * @return array Page type patterns mapped to their names
 */
function choicegroup_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $modulepagetype = ['mod-choicegroup-*' => get_string('page-mod-choicegroup-x', 'choicegroup')];
    return $modulepagetype;
}

/**
 * Get the sort options for the choicegroup module.
 *
 * @return array
 * @throws coding_exception
 */
function choicegroup_get_sort_options() {
    return  [
        CHOICEGROUP_SORTGROUPS_CREATEDATE => get_string('createdate', 'choicegroup'),
        CHOICEGROUP_SORTGROUPS_NAME => get_string('name', 'choicegroup'),
    ];
}


/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_choicegroup_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory
) {
    // The event can outlive its activity (deleted, or in a course being deleted): there is no action then.
    $cm = get_fast_modinfo($event->courseid)->instances['choicegroup'][$event->instance] ?? null;
    if (!$cm) {
        return null;
    }

    $completion = new \completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    return $factory->create_instance(
        get_string('view'),
        new \moodle_url('/mod/choicegroup/view.php', ['id' => $cm->id]),
        1,
        true
    );
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $choicegroup     choicegroup object
 * @param  stdClass $course          course object
 * @param  stdClass $cm              course module object
 * @param  stdClass $context         context object
 */
function choicegroup_view($choicegroup, $course, $cm, $context) {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    // Trigger course_module_viewed event.
    $params = [
        'context' => $context,
        'objectid' => $choicegroup->id,
    ];

    $event = \mod_choicegroup\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('choicegroup', choicegroup_get_snapshot_record($choicegroup));
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Given a course_module object, return the "extra" information needed when printing this activity in a course
 * listing: its name, description, the custom completion rule and the opening and closing dates.
 * See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info|false The information the course page needs, or false when the instance does not exist.
 */
function choicegroup_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionsubmit, timeopen, timeclose';
    if (!$choicegroup = $DB->get_record('choicegroup', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $choicegroup->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('choicegroup', $choicegroup, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionsubmit'] = $choicegroup->completionsubmit;
    }

    // Populate some other values that can be used in calendar or on dashboard.
    if ($choicegroup->timeopen) {
        $result->customdata['timeopen'] = $choicegroup->timeopen;
    }

    if ($choicegroup->timeclose) {
        $result->customdata['timeclose'] = $choicegroup->timeclose;
    }

    return $result;
}

/**
 * Callback which returns human-readable strings describing the active completion custom rules for the module instance.
 *
 * @param cm_info|stdClass $cm object with fields ->completion and ->customdata['customcompletionrules']
 * @return array $descriptions the array of descriptions for the custom rules.
 */
function mod_choicegroup_get_completion_active_rule_descriptions($cm) {
    // Values will be present in cm_info, and we assume these are up to date.
    if (
        empty($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC
    ) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        switch ($key) {
            case 'completionsubmit':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionsubmit', 'choicegroup');
                }
                break;
            default:
                break;
        }
    }

    return $descriptions;
}
