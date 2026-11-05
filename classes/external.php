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
 * Choice group module external API
 *
 * @package    mod_choicegroup
 * @category   external
 * @copyright  2018 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/mod/choicegroup/lib.php');

/**
 * Choice group module external functions
 *
 * @copyright  2018 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_choicegroup_external extends external_api {
    /**
     * Describes the parameters for get_choicegroup_options.
     *
     * @return external_function_parameters
     */
    public static function get_choicegroup_options_parameters() {
        return new external_function_parameters(
            [
                'choicegroupid' => new external_value(PARAM_INT, 'Choice group instance id'),
                'userid' => new external_value(PARAM_INT, 'User id'),
            ]
        );
    }

    /**
     * Returns the options list for the provided choice group instance.
     *
     * Another user's choice is returned only to a caller who may read the responses; the Moodle App passes the
     * caller's own id ({@see \mod_choicegroup\output\mobile::mobile_course_view()}).
     *
     * @param int $choicegroupid The choice group id.
     * @param int $userid The user id.
     * @param boolean $alloptionsdisabled True when all the options should be disabled,
     * because activity is not open or a limit has been reached.
     * @return array The choice group options.
     * @throws moodle_exception When the instance does not exist
     */
    public static function get_choicegroup_options($choicegroupid, $userid, $alloptionsdisabled = false) {
        global $USER;

        $result = [];
        $returnedoptions = [];
        $warnings = [];

        $params = [
            'choicegroupid' => $choicegroupid,
            'userid' => $userid,
        ];
        $params = self::validate_parameters(self::get_choicegroup_options_parameters(), $params);
        if (!$choicegroup = choicegroup_get_choicegroup($params['choicegroupid'])) {
            throw new moodle_exception('invalidcoursemodule', 'error');
        }

        $cm = get_coursemodule_from_instance('choicegroup', $choicegroup->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/choicegroup:choose', $context);

        $userid = empty($params['userid']) ? (int)$USER->id : (int)$params['userid'];
        if ($userid !== (int)$USER->id) {
            require_capability('mod/choicegroup:readresponses', $context);

            // The capability says the caller may read responses, not that this user is theirs to read.
            if (!is_enrolled($context, $userid)) {
                throw new moodle_exception('notenrolledchoose', 'choicegroup');
            }

            // Same rule as choicegroup_delete_responses(): under separate groups the target must share a group.
            if (
                groups_get_activity_groupmode($cm) == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context)
            ) {
                $allowedgroupids = array_map('intval', array_keys(groups_get_activity_allowed_groups($cm, $USER->id)));
                $usergroupids = array_map('intval', array_keys(groups_get_all_groups($cm->course, $userid)));
                if (!array_intersect($usergroupids, $allowedgroupids)) {
                    throw new required_capability_exception($context, 'mod/choicegroup:readresponses', 'nopermissions', '');
                }
            }
        }

        $groupmode = groups_get_activity_groupmode($cm);
        $allresponses = choicegroup_get_response_data($choicegroup, $cm, $groupmode, $choicegroup->onlyactive);
        $answers = choicegroup_get_user_answer($choicegroup, $userid, true);
        $choicegroupgroups = choicegroup_get_groups($choicegroup);

        // The counts follow showresults for the user the options are built for, as on view.php; the caller's own
        // capability to read the responses shows them too, since the report lists every member anyway.
        $countsvisible = has_capability('mod/choicegroup:readresponses', $context) ||
            choicegroup_results_visible($choicegroup, !empty($answers));

        foreach ($choicegroup->option as $optionid => $text) {
            if (isset($text)) {
                $option = [];
                $option['id'] = $optionid;
                $option['groupid'] = $text;
                $option['name'] = format_string($choicegroupgroups[$text]->name, true, ['context' => $context]);
                $option['maxanswers'] = $choicegroup->maxanswers[$optionid];
                $option['displaylayout'] = $choicegroup->display;

                $countanswers = isset($allresponses[$text]) ? count($allresponses[$text]) : 0;
                if ($countsvisible) {
                    $option['countanswers'] = $countanswers;
                }

                // Check if the option has been answered previously by the user.
                $option['checked'] = false;
                if (is_array($answers)) {
                    foreach ($answers as $answer) {
                        if ($answer && $text == $answer->id) {
                            $option['checked'] = true;
                        }
                    }
                }

                // Check if the option has to be disabled because the limit has been reached.
                // A full group is named full whatever showresults says, as on view.php; only the count is hidden.
                $limitreached = $choicegroup->limitanswers && ($countanswers >= $option['maxanswers']);
                $stillnotanswered = $option['checked'] === false;
                $option['disabled'] = $alloptionsdisabled;
                if ($limitreached && $stillnotanswered) {
                    $option['disabled'] = true;
                    $option['name'] .= ' ' . get_string('full', 'choicegroup');
                }

                $returnedoptions[] = $option;
            }
        }

        $result = [];
        $result['options'] = $returnedoptions;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_choicegroup_options return value.
     *
     * @return external_single_structure
     */
    public static function get_choicegroup_options_returns() {

        return new external_single_structure(
            [
                'options' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'Option id'),
                            'groupid' => new external_value(PARAM_INT, 'Group id'),
                            'name' => new external_value(PARAM_RAW, 'Group choice name'),
                            'maxanswers' => new external_value(PARAM_INT, 'Maximum number of accepted answers', VALUE_OPTIONAL),
                            'displaylayout' => new external_value(PARAM_INT, 'Display layout', VALUE_OPTIONAL),
                            'countanswers' => new external_value(
                                PARAM_INT,
                                'Current number of answers, absent when showresults hides the counts from the user',
                                VALUE_OPTIONAL
                            ),
                            'checked' => new external_value(PARAM_BOOL, 'Checked', VALUE_OPTIONAL),
                            'disabled' => new external_value(PARAM_BOOL, 'Disabled', VALUE_OPTIONAL),
                        ]
                    )
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for view_choicegroup.
     *
     * @return external_function_parameters
     */
    public static function view_choicegroup_parameters() {
        return new external_function_parameters(
            [
                'choicegroupid' => new external_value(PARAM_INT, 'Choice group instance id'),
            ]
        );
    }

    /**
     * Trigger the course module viewed event and update the module completion status.
     *
     * @param int $choicegroupid The choice group id.
     * @return array of warnings and status result
     * @throws moodle_exception
     */
    public static function view_choicegroup($choicegroupid) {
        global $DB;

        $params = [
            'choicegroupid' => $choicegroupid,
        ];
        $params = self::validate_parameters(self::view_choicegroup_parameters(), $params);
        $warnings = [];

        // Request and permission validation.
        $choicegroup = $DB->get_record('choicegroup', ['id' => $params['choicegroupid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($choicegroup, 'choicegroup');

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/choicegroup:choose', $context);

        choicegroup_view($choicegroup, $course, $cm, $context);

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     */
    public static function view_choicegroup_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'Status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for submit_response.
     *
     * @return external_function_parameters
     */
    public static function submit_choicegroup_response_parameters() {
        return new external_function_parameters(
            [
                'choicegroupid' => new external_value(PARAM_INT, 'Choice group instance id'),
                'data' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_RAW, 'Data name'),
                            'value' => new external_value(PARAM_RAW, 'Data value'),
                        ]
                    ),
                    'The data to be saved',
                    VALUE_DEFAULT,
                    []
                ),
            ]
        );
    }

    /**
     * Save the current user's choice in the provided choice group instance.
     *
     * @param int $choicegroupid The choice group id.
     * @param array $data The user responses.
     * @return array Status and warnings.
     */
    public static function submit_choicegroup_response($choicegroupid, $data) {
        global $USER;

        $warnings = [];

        $params = [
            'choicegroupid' => $choicegroupid,
            'data' => $data,
        ];

        $params = self::validate_parameters(self::submit_choicegroup_response_parameters(), $params);

        if (!$choicegroup = choicegroup_get_choicegroup($params['choicegroupid'])) {
            throw new moodle_exception('invalidcoursemodule', 'error');
        }

        [$course, $cm] = get_course_and_cm_from_instance($choicegroup, 'choicegroup');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/choicegroup:choose', $context);

        $timenow = time();
        if (!empty($choicegroup->timeopen) && ($choicegroup->timeopen > $timenow)) {
            throw new moodle_exception('notopenyet', 'choicegroup', '', userdate($choicegroup->timeopen));
        } else if (!empty($choicegroup->timeclose) && ($timenow > $choicegroup->timeclose)) {
            throw new moodle_exception('expired', 'choicegroup', '', userdate($choicegroup->timeclose));
        }

        $responses = self::parse_data_to_responses(
            $params['data'],
            $choicegroup->multipleenrollmentspossible
        );

        // A single choice with no answer posted changes nothing, as before. Every other submission goes through the
        // helper view.php uses: enrolment, dates, allowupdate, options of this instance, minimum and maximum.
        if ($choicegroup->multipleenrollmentspossible || count($responses) == 1) {
            choicegroup_save_user_choices($choicegroup, $USER->id, $course, $cm, $responses);
        }

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the submit_choicegroup_response return value.
     *
     * @return external_single_structure
     */
    public static function submit_choicegroup_response_returns() {

        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'Status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Extract user responses from the WS data.
     * @param  array $data The data received from the WS.
     * @param  boolean $allowmultiple True if more than one response can be selected.
     * @return array The optionid from the selected responses.
     */
    protected static function parse_data_to_responses($data, $allowmultiple) {
        $responses = [];
        foreach ($data as $index => $datavalue) {
            $name = $datavalue['name'];
            $value = $datavalue['value'];
            if ($allowmultiple) {
                if ($name != 'responses' && $value === 'true') {
                    $responses[] = substr($name, strrpos($name, '_') + 1);
                }
            } else if ($name === 'responses') {
                $responses[] = $value;
                break;
            }
        }

        return $responses;
    }

    /**
     * Describes the parameters for delete_choicegroup_responses.
     *
     * @return external_function_parameters
     */
    public static function delete_choicegroup_responses_parameters() {
        return new external_function_parameters(
            [
                'choicegroupid' => new external_value(PARAM_INT, 'Choice group instance id'),
            ]
        );
    }

    /**
     * Remove the current user's own choice in a choice group, when the instance allows updating a single choice.
     *
     * @param int $choicegroupid The choicegroup instance id
     * @return array status information and warnings
     * @throws moodle_exception
     */
    public static function delete_choicegroup_responses($choicegroupid) {
        global $USER;

        $status = false;
        $warnings = [];

        $params = [
            'choicegroupid' => $choicegroupid,
        ];

        $params = self::validate_parameters(self::delete_choicegroup_responses_parameters(), $params);

        if (!$choicegroup = choicegroup_get_choicegroup($params['choicegroupid'])) {
            throw new moodle_exception('invalidcoursemodule', 'error');
        }

        [$course, $cm] = get_course_and_cm_from_instance($choicegroup, 'choicegroup');
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/choicegroup:choose', $context);

        $timenow = time();
        if (!empty($choicegroup->timeopen) && ($choicegroup->timeopen > $timenow)) {
            throw new moodle_exception('notopenyet', 'choicegroup', '', userdate($choicegroup->timeopen));
        } else if (!empty($choicegroup->timeclose) && ($timenow > $choicegroup->timeclose)) {
            throw new moodle_exception('expired', 'choicegroup', '', userdate($choicegroup->timeclose));
        }

        $answergiven = choicegroup_get_user_answer($choicegroup, $USER, true);
        if (!empty($answergiven)) {
            if ($choicegroup->allowupdate && !$choicegroup->multipleenrollmentspossible) {
                $status = choicegroup_user_remove_choice($choicegroup, $USER->id, $course, $cm);
            } else {
                throw new moodle_exception('missingrequiredcapability', 'webservice', '', 'allowupdate');
            }
        } else {
            // User didn't give any answer, so there's no need to delete anything.
            $status = true;
        }

        $result = [
            'status' => $status,
            'warnings' => $warnings,
        ];
        return $result;
    }

    /**
     * Describes the delete_choicegroup_responses return value.
     *
     * @return external_single_structure
     */
    public static function delete_choicegroup_responses_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status, True if everything went right'),
                'warnings' => new external_warnings(),
            ]
        );
    }
}
