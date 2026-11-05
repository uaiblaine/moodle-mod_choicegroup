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
 * Choicegroup output functions
 *
 * @package    mod_choicegroup
 * @copyright  2013 Université de Lausanne
 * @author     Nicolas Dunand <Nicolas.Dunand@unil.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CHOICEGROUP_DISPLAY_HORIZONTAL_LAYOUT', 0);
define('CHOICEGROUP_DISPLAY_VERTICAL_LAYOUT', 1);

/**
 * Renderer for the mod_choicegroup plugin.
 */
class mod_choicegroup_renderer extends plugin_renderer_base {
    /**
     * Displays the choice group options.
     *
     * @param array $options The options to display.
     * @param int $coursemoduleid The course module ID.
     * @param bool $vertical Whether to display options vertically.
     * @param bool $publish Whether to publish the results.
     * @param bool $limitanswers Whether to limit the number of answers.
     * @param bool $showresults Whether to show the results.
     * @param bool $current Whether the current user has answered.
     * @param bool $choicegroupopen Whether the choice group is open.
     * @param bool $disabled Whether the options are disabled.
     * @param bool $multipleenrollmentspossible Whether multiple enrollments are possible.
     * @param bool $onlyactive Whether to show only active users.
     * @param bool $groupdescriptionstate The state of the group description.
     * @return string The HTML to display the options.
     */
    public function display_options(
        $options,
        $coursemoduleid,
        $vertical = true,
        $publish = false,
        $limitanswers = false,
        $showresults = false,
        $current = false,
        $choicegroupopen = false,
        $disabled = false,
        $multipleenrollmentspossible = false,
        $onlyactive = false,
        $groupdescriptionstate = false
    ) {
        global $choicegroupgroups;

        if ($groupdescriptionstate === false) {
            $groupdescriptionstate = get_config('choicegroup', 'defaultgroupdescriptionstate');
        }

        $descriptionshidden = $groupdescriptionstate == CHOICEGROUP_GROUPDESCRIPTIONSTATE_HIDDEN;
        $showresultscolumns = $showresults == CHOICEGROUP_SHOWRESULTS_ALWAYS ||
            ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $current) ||
            ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && !$choicegroupopen);
        $showmembers = $showresultscolumns && $publish == CHOICEGROUP_PUBLISH_NAMES;

        $availableoption = count($options['options']);
        $i = 0;
        $mappings = [];
        $rows = [];
        $descriptionids = [];
        $memberids = [];

        $initiallyhidesubmitbutton = false;
        foreach ($options['options'] as $option) {
            $group = (isset($choicegroupgroups[$option->groupid])) ? ($choicegroupgroups[$option->groupid]) : (false);
            if (!$group) {
                $rows[] = [
                    'missing' => true,
                    'colspan' => 2 + ($showresultscolumns ? 1 : 0) + ($showmembers ? 1 : 0),
                ];
                break;
            }

            if ($multipleenrollmentspossible == 1) {
                $option->attributes->name = 'answer_' . $i;
                $option->attributes->type = 'checkbox';
                $mappings[] = ['name' => 'answer_' . $i . '_groupid', 'groupid' => $option->groupid];
                $i++;
            } else {
                $option->attributes->name = 'answer';
                $option->attributes->type = 'radio';
                if (
                    property_exists($option, 'attributes') && property_exists($option->attributes, 'checked') &&
                    $option->attributes->checked == true
                ) {
                    $initiallyhidesubmitbutton = true;
                }
            }

            $context = \context_course::instance($group->courseid);
            // The count is the group's real size (the limit applies to every member); the names follow core's group
            // visibility, so a member of a group whose membership is hidden is not listed to everyone.
            $userfields = 'u.id' . \core_user\fields::for_name()->get_sql('u')->selects;
            $groupmembers = get_enrolled_users($context, '', $group->id, $userfields, 'u.lastname, u.firstname', 0, 0, $onlyactive);
            $groupmembersnames = [];
            foreach (choicegroup_filter_visible_members($group, $groupmembers) as $groupmember) {
                // The template escapes the name: it goes in plain.
                $groupmembersnames[] = ['name' => fullname($groupmember), 'first' => !$groupmembersnames];
            }

            $full = false;
            if (
                !empty($option->attributes->disabled) || ($limitanswers && count($groupmembers) >= $option->maxanswers) &&
                empty($option->attributes->checked)
            ) {
                $full = true;
                $option->attributes->disabled = true;
                $availableoption--;
            }

            if (!$multipleenrollmentspossible && !empty($options['allowupdate'])) {
                $option->attributes->disabled = true;
            }

            $optionid = $option->attributes->value;
            $descriptionids[] = 'choicegroup-description-' . $optionid;
            $memberids[] = 'choicegroup_' . $optionid;
            $rows[] = [
                'missing' => false,
                'optionid' => $optionid,
                'name' => format_string($group->name, true, ['escape' => false]),
                'full' => $full,
                'description' => format_text(
                    file_rewrite_pluginfile_urls(
                        $group->description,
                        'pluginfile.php',
                        $context->id,
                        'group',
                        'description',
                        $group->id
                    ),
                    $group->descriptionformat,
                    ['context' => $context]
                ),
                'inputname' => $option->attributes->name,
                'inputtype' => $option->attributes->type,
                'checked' => !empty($option->attributes->checked),
                'disabled' => !empty($option->attributes->disabled),
                'count' => count($groupmembers),
                'maxanswers' => $option->maxanswers,
                'members' => $groupmembersnames,
            ];
        }

        $hascapability = !empty($options['hascapability']);
        $removeurl = '';
        if ($hascapability && !empty($options['allowupdate']) && !$multipleenrollmentspossible && !$disabled) {
            $removeurl = (new moodle_url(
                '/mod/choicegroup/view.php',
                ['id' => $coursemoduleid, 'action' => 'delchoicegroup', 'sesskey' => sesskey()]
            ))->out(false);
        }

        $data = [
            'actionurl' => (new moodle_url('/mod/choicegroup/view.php'))->out(false),
            'descriptionshidden' => $descriptionshidden,
            'descriptionids' => implode(' ', $descriptionids),
            'showresults' => $showresultscolumns,
            'limitanswers' => (bool)$limitanswers,
            'showmembers' => $showmembers,
            'memberids' => implode(' ', $memberids),
            'rows' => $rows,
            'multiple' => $multipleenrollmentspossible == 1,
            'numberofgroups' => $i,
            'mappings' => $mappings,
            'sesskey' => sesskey(),
            'cmid' => $coursemoduleid,
            'hascapability' => $hascapability,
            'allfull' => $hascapability && $availableoption < 1,
            'cansubmit' => $hascapability && $availableoption >= 1 && !$disabled,
            'hidesubmit' => $initiallyhidesubmitbutton,
            'removeurl' => $removeurl,
            // Only display the message if the user is not logged in or is a guest.
            'havetologin' => !$hascapability && (!isloggedin() || isguestuser()),
        ];

        return $this->render_from_template('mod_choicegroup/options_table', $data);
    }

    /**
     * Returns the card layout of the options: search, filters, the first page of cards and the save bar.
     *
     * The JavaScript takes over the toolbar and "load more" through mod_choicegroup_get_cards; without it the first
     * page still posts like the table does.
     *
     * @param stdClass $choicegroup The instance, as choicegroup_get_choicegroup() returns it
     * @param stdClass $cm The course module
     * @return string The HTML
     */
    public function display_selection_cards(stdClass $choicegroup, stdClass $cm): string {
        global $USER;

        $selection = new \mod_choicegroup\local\selection($choicegroup, $cm, (int)$USER->id);
        $perpage = max(1, min((int)$choicegroup->cardsperpage, \mod_choicegroup\local\selection::MAX_PAGE_SIZE));
        $page = $selection->query('', [], false, 'default', 0, $perpage);
        $multiple = !empty($choicegroup->multipleenrollmentspossible);
        $canchoose = $selection->can_choose();

        // The viewer's choices outside the first page go along as hidden inputs, or saving would drop them.
        $shownids = array_column($page['cards'], 'optionid');
        $kept = [];
        foreach (array_diff($selection->get_chosen(), $shownids) as $optionid) {
            $kept[] = ['name' => $multiple ? 'answers[]' : 'answer', 'value' => $optionid];
        }

        $filters = $selection->get_filters();
        $search = $selection->has_search();
        $freefilter = $selection->has_free_filter();
        $groupings = $selection->get_groupings();
        $hastoolbar = $search || $filters || $freefilter || $groupings;
        $sorts = [];
        if ($hastoolbar) {
            foreach ($selection->get_sort_keys() as $key) {
                $label = match ($key) {
                    'name' => get_string('cardsort_name', 'choicegroup'),
                    'date' => get_string('cardsort_date', 'choicegroup'),
                    'free' => get_string('cardsort_free', 'choicegroup'),
                    default => get_string('cardsort_default', 'choicegroup'),
                };
                $sorts[] = ['value' => $key, 'label' => $label];
            }
        }

        $message = '';
        if (!isloggedin() || isguestuser()) {
            $message = get_string('havetologin', 'choicegroup');
        } else if ($canchoose && $selection->is_all_full()) {
            $message = get_string('choicegroupfull', 'choicegroup');
            $canchoose = false;
        }

        $removeurl = '';
        if ($canchoose && !$multiple && $choicegroup->allowupdate && $selection->get_chosen()) {
            $removeurl = (new moodle_url(
                '/mod/choicegroup/view.php',
                ['id' => $cm->id, 'action' => 'delchoicegroup', 'sesskey' => sesskey()]
            ))->out(false);
        }

        $chosen = count($selection->get_chosen());
        $data = [
            'uniqid' => 'mod_choicegroup-selection-' . $cm->id,
            'actionurl' => (new moodle_url('/mod/choicegroup/view.php'))->out(false),
            'sesskey' => sesskey(),
            'cmid' => $cm->id,
            'instructions' => $multiple ? '' : get_string('cardchooseone', 'choicegroup'),
            'hastoolbar' => $hastoolbar,
            'search' => $search,
            'haspanel' => $filters || $groupings || $freefilter || count($sorts) > 1,
            'filters' => $filters,
            'hasgroupings' => (bool)$groupings,
            'groupings' => $groupings,
            'multiple' => $multiple,
            'hassorts' => count($sorts) > 1,
            'sorts' => $sorts,
            'freefilter' => $freefilter,
            'cards' => $page['cards'],
            'found' => $page['total'] === 1 ? get_string('cardfoundone', 'choicegroup') :
                get_string('cardfound', 'choicegroup', $page['total']),
            'shown' => get_string('cardshown', 'choicegroup', (object)[
                'shown' => count($page['cards']),
                'total' => $page['total'],
            ]),
            'hasmore' => $page['total'] > count($page['cards']),
            'empty' => $page['total'] === 0,
            'kept' => $kept,
            'canchoose' => $canchoose,
            'counter' => $multiple ? self::selection_counter($chosen, (int)($choicegroup->maxenrollments ?? 0)) : '',
            'removeurl' => $removeurl,
            'message' => $message,
        ];

        // The chips of the save bar name the stored choices, the ones on unloaded pages included.
        $chosennames = [];
        foreach ($selection->get_chosen() as $optionid) {
            $chosennames[] = ['optionid' => $optionid, 'name' => $selection->get_option_name($optionid)];
        }

        $this->page->requires->js_call_amd('mod_choicegroup/selection', 'init', [$data['uniqid'], [
            'cmid' => (int)$cm->id,
            'multiple' => $multiple,
            'min' => $multiple ? (int)($choicegroup->minenrollments ?? 0) : 0,
            'max' => $multiple ? (int)($choicegroup->maxenrollments ?? 0) : 0,
            'perpage' => $perpage,
            'total' => $page['total'],
            'chosen' => $selection->get_chosen(),
            'chosennames' => $multiple ? $chosennames : [],
            'canchoose' => $canchoose,
            'remote' => isloggedin() && !isguestuser(),
        ]]);

        return $this->render_from_template('mod_choicegroup/selection_page', $data);
    }

    /**
     * The save bar's count of chosen groups, against the maximum when there is one.
     *
     * @param int $count Groups chosen
     * @param int $max The most the activity allows, 0 for no maximum
     * @return string
     */
    private static function selection_counter(int $count, int $max): string {
        if ($max > 0) {
            return get_string('cardselectedmax', 'choicegroup', (object)['count' => $count, 'max' => $max]);
        }

        return get_string('cardselected', 'choicegroup', $count);
    }

    /**
     * Returns the HTML of the results, named or anonymous according to the publish setting.
     *
     * @param object $choicegroups
     * @param bool $forcepublish
     * @return string|void The HTML, or nothing when the viewer may not see the results
     */
    public function display_result($choicegroups, $forcepublish = false) {
        if (empty($forcepublish)) { // Allow the publish setting to be overridden.
            $forcepublish = $choicegroups->publish;
        }

        if ($forcepublish) {  // CHOICEGROUP_PUBLISH_NAMES.
            return $this->display_publish_name_vertical($choicegroups);
        } else { // CHOICEGROUP_PUBLISH_ANONYMOUS.
            // There is no vertical anonymous display (it was never written): the vertical layout falls back to the
            // horizontal one instead of calling a method that does not exist.
            return $this->display_publish_anonymous_horizontal($choicegroups);
        }
    }

    /**
     * Returns the HTML of the results with the names of the users listed under each group.
     *
     * @param object $choicegroups
     * @return string|void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function display_publish_name_vertical($choicegroups) {
        if (!$choicegroups) {
            return; // No answers yet, so don't bother.
        }

        // The instance's own context: a global $context is whatever page set it last, and reading the responses is what
        // the report asks of its viewer, downloading them a separate right.
        $context = context_module::instance($choicegroups->coursemoduleid);
        if (!has_capability('mod/choicegroup:readresponses', $context)) {
            return; // Only users who can read the responses see the results.
        }

        ksort($choicegroups->options);
        $candelete = $choicegroups->viewresponsecapability && $choicegroups->deleterepsonsecapability;

        $columns = [];
        foreach ($choicegroups->options as $optionid => $options) {
            $name = '';
            if ($optionid > 0) {
                $name = format_string(
                    choicegroup_get_option_text($choicegroups, $choicegroups->options[$optionid]->groupid),
                    true,
                    ['escape' => false]
                );
            }

            $users = [];
            if (($choicegroups->showunanswered || $optionid > 0) && !empty($options->user)) {
                foreach ($options->user as $user) {
                    if (empty($user->imagealt)) {
                        $user->imagealt = '';
                    }

                    $users[] = [
                        'checkbox' => $candelete && $optionid > 0,
                        'grpsmemberid' => $user->grpsmemberid,
                        'picture' => $this->output->user_picture($user, ['courseid' => $choicegroups->courseid]),
                        'profileurl' => (new moodle_url(
                            '/user/view.php',
                            ['id' => $user->id, 'course' => $choicegroups->courseid]
                        ))->out(false),
                        'fullname' => fullname($user, $choicegroups->fullnamecapability),
                    ];
                }
            }

            $columns[] = [
                'notanswered' => $choicegroups->showunanswered && $optionid == 0,
                'isoption' => $optionid > 0,
                'name' => $name,
                'count' => empty($options->user) ? 0 : count($options->user),
                'users' => $users,
            ];
        }

        $actionselect = '';
        if ($candelete) {
            $actionurl = new moodle_url($this->page->url, ['sesskey' => sesskey(), 'action' => 'delete_confirmation()']);
            $select = new single_select(
                $actionurl,
                'action',
                ['delete' => get_string('delete')],
                null,
                ['' => get_string('chooseaction', 'choicegroup')],
                'attemptsform'
            );
            // The id the "With selected" label names.
            $select->attributes['id'] = 'menuaction';

            $this->page->requires->js_call_amd('mod_choicegroup/select_all_choices', 'init');
            $actionselect = $this->output->render($select);
        }

        return $this->render_from_template('mod_choicegroup/results_names', [
            'hasform' => (bool)$choicegroups->viewresponsecapability,
            'actionurl' => (new moodle_url($this->page->url))->out(false),
            'cmid' => $choicegroups->coursemoduleid,
            'sesskey' => sesskey(),
            'columns' => $columns,
            'candelete' => (bool)$candelete,
            'actionselect' => $actionselect,
        ]);
    }

    /**
     * Returns the HTML of the results as a table of counts, without the names of the users.
     *
     * @param object $choicegroups
     * @return string|void The HTML, or nothing when the viewer may not see the results
     */
    public function display_publish_anonymous_horizontal($choicegroups) {
        // The instance's own context: a global $context is whatever page set it last, and reading the responses is what
        // the report asks of its viewer, downloading them a separate right.
        $context = context_module::instance($choicegroups->coursemoduleid);
        if (!has_capability('mod/choicegroup:readresponses', $context)) {
            return; // Only users who can read the responses see the results.
        }

        ksort($choicegroups->options);

        $rows = [];
        foreach ($choicegroups->options as $optionid => $options) {
            $numberofuser = empty($options->user) ? 0 : count($options->user);

            $width = 0;
            $percentageamount = 0;
            if ($choicegroups->numberofuser > 0) {
                $width = (CHOICEGROUP_COLUMN_WIDTH * ((float)$numberofuser / (float)$choicegroups->numberofuser));
                $percentageamount = ((float)$numberofuser / (float)$choicegroups->numberofuser) * 100.0;
            }

            $name = '';
            if ($optionid > 0) {
                $name = format_string(
                    choicegroup_get_option_text($choicegroups, $choicegroups->options[$optionid]->groupid),
                    true,
                    ['escape' => false]
                );
            }

            $rows[] = [
                'optionid' => $optionid,
                'notanswered' => $choicegroups->showunanswered && $optionid == 0,
                'isoption' => $optionid > 0,
                'name' => $name,
                'count' => $numberofuser,
                'percentage' => format_float($percentageamount, 1) . '%',
                'width' => $width,
            ];
        }

        return $this->render_from_template('mod_choicegroup/results_anonymous', [
            'rows' => $rows,
            'barurl' => $this->output->image_url('row', 'choicegroup')->out(false),
        ]);
    }
}
