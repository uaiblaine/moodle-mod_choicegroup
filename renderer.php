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
        global $choicegroupgroups, $CFG;

        if ($CFG->version >= 2024041400) {
            $marginstartclass = "ms-1";
        } else {
            $marginstartclass = "ml-1";
        }

        if ($groupdescriptionstate === false) {
            $groupdescriptionstate = get_config('choicegroup', 'defaultgroupdescriptionstate');
        }

        $target = new moodle_url('/mod/choicegroup/view.php');
        $attributes = ['method' => 'POST', 'action' => $target, 'class' => 'tableform'];

        $html = html_writer::start_tag('form', $attributes);
        $html .= html_writer::start_tag('div', ['class' => 'tablecontainer']);
        $html .= html_writer::start_tag('table', ['class' => 'choicegroups table-reboot' ]);

        $html .= html_writer::start_tag('tr');
        $html .= html_writer::tag('th', get_string('choice', 'choicegroup'), ['class' => 'width10']);

        $group = get_string('group') . ' ';
        if ($groupdescriptionstate == CHOICEGROUP_GROUPDESCRIPTIONSTATE_HIDDEN) {
            $group .= html_writer::tag(
                'a',
                get_string('showdescription', 'choicegroup'),
                ['role' => 'button',
                    'class' => 'choicegroup-descriptiondisplay choicegroup-descriptionshow btn btn-secondary ' . $marginstartclass,
                    'href' => '#',
                ]
            );
        } else {
            $group .= html_writer::tag(
                'a',
                get_string('hidedescription', 'choicegroup'),
                ['role' => 'button', 'class' => 'choicegroup-descriptiondisplay choicegroup-descriptionshow btn btn-secondary ' .
                    $marginstartclass,
                    'href' => '#',
                ]
            );
        }

        $html .= html_writer::tag('th', $group, ['class' => 'width40']);

        if (
            $showresults == CHOICEGROUP_SHOWRESULTS_ALWAYS ||
            ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $current) ||
            ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && !$choicegroupopen)
        ) {
            if ($limitanswers) {
                $html .= html_writer::tag('th', get_string('members/max', 'choicegroup'), ['class' => 'width10']);
            } else {
                $html .= html_writer::tag('th', get_string('members/', 'choicegroup'), ['class' => 'width10']);
            }

            if ($publish == CHOICEGROUP_PUBLISH_NAMES) {
                $membersdisplayhtml = html_writer::tag(
                    'a',
                    get_string('showgroupmembers', 'mod_choicegroup'),
                    ['role' => 'button',
                        'class' => 'choicegroup-memberdisplay choicegroup-membershow btn btn-secondary ' . $marginstartclass,
                        'href' => '#',
                    ]
                );
                $html .= html_writer::tag('th', get_string('groupmembers', 'choicegroup') . ' ' .
                    $membersdisplayhtml, ['class' => 'width40']);
            }
        }

        $html .= html_writer::end_tag('tr');

        $availableoption = count($options['options']);
        if ($multipleenrollmentspossible == 1) {
            $i = 0;
            $answertogroupidmappings = '';
        }

        $initiallyhidesubmitbutton = false;
        foreach ($options['options'] as $option) {
            $group = (isset($choicegroupgroups[$option->groupid])) ? ($choicegroupgroups[$option->groupid]) : (false);
            if (!$group) {
                $colspan = 2;
                if (
                    $showresults == CHOICEGROUP_SHOWRESULTS_ALWAYS ||
                    ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $current) ||
                    ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && !$choicegroupopen)
                ) {
                    $colspan++;
                    if ($publish == CHOICEGROUP_PUBLISH_NAMES) {
                        $colspan++;
                    }
                }

                $cell = html_writer::tag('td', get_string('groupdoesntexist', 'choicegroup'), ['colspan' => $colspan]);
                $html .= html_writer::tag('tr', $cell);
                break;
            }

            $html .= html_writer::start_tag('tr', ['class' => 'option']);
            $html .= html_writer::start_tag('td', ['class' => 'center']);

            if ($multipleenrollmentspossible == 1) {
                $option->attributes->name = 'answer_' . $i;
                $option->attributes->type = 'checkbox';
                $answertogroupidmappings .= '<input type="hidden" name="answer_' . $i . '_groupid" value="' . $option->groupid .
                    '">';
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
            $labeltext = html_writer::tag(
                'label',
                format_string($group->name),
                ['for' => 'choiceid_' . $option->attributes->value]
            );
            // The count is the group's real size (the limit applies to every member); the names follow core's group
            // visibility, so a member of a group whose membership is hidden is not listed to everyone.
            $userfields = 'u.id' . \core_user\fields::for_name()->get_sql('u')->selects;
            $groupmembers = get_enrolled_users($context, '', $group->id, $userfields, 'u.lastname, u.firstname', 0, 0, $onlyactive);
            $groupmembersnames = [];
            foreach (choicegroup_filter_visible_members($group, $groupmembers) as $groupmember) {
                $groupmembersnames[] = s(fullname($groupmember));
            }

            if (
                !empty($option->attributes->disabled) || ($limitanswers && count($groupmembers) >= $option->maxanswers) &&
                empty($option->attributes->checked)
            ) {
                $labeltext .= ' ' . html_writer::tag('em', get_string('full', 'choicegroup'));
                $option->attributes->disabled = true;
                $availableoption--;
            }

            $hidden = ($groupdescriptionstate == CHOICEGROUP_GROUPDESCRIPTIONSTATE_HIDDEN) ? "hidden" : "";
            $labeltext .= html_writer::tag(
                'div',
                format_text(
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
                ['class' => "choicegroups-descriptions {$hidden}"]
            );

            if (!$multipleenrollmentspossible && !empty($options['allowupdate'])) {
                $option->attributes->disabled = true;
            }

            $attributes = (array) $option->attributes;
            $attributes['id'] = 'choiceid_' . $option->attributes->value;
            $html .= html_writer::empty_tag('input', $attributes);
            $html .= html_writer::end_tag('td');
            $html .= html_writer::tag('td', $labeltext);

            if (
                $showresults == CHOICEGROUP_SHOWRESULTS_ALWAYS ||
                ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $current) ||
                ($showresults == CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && !$choicegroupopen)
            ) {
                $maxanswers = ($limitanswers) ? (' / ' . $option->maxanswers) : ('');
                $html .= html_writer::tag('td', count($groupmembers) . $maxanswers, ['class' => 'center']);
                if ($publish == CHOICEGROUP_PUBLISH_NAMES) {
                    $groupmembershtml = html_writer::tag(
                        'div',
                        implode('<br />', $groupmembersnames),
                        ['class' => 'choicegroups-membersnames hidden', 'id' => 'choicegroup_' . $option->attributes->value]
                    );
                    $html .= html_writer::tag('td', $groupmembershtml, ['class' => 'center']);
                }
            }

            $html .= html_writer::end_tag('tr');
        }

        $html .= html_writer::end_tag('table');
        $html .= html_writer::end_tag('div');
        if ($multipleenrollmentspossible == 1) {
            $html .= '<input type="hidden" name="number_of_groups" value="' . $i . '">' . $answertogroupidmappings;
        }

        $html .= html_writer::tag('div', '', ['class' => 'clearfloat']);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $coursemoduleid]);

        if (!empty($options['hascapability']) && ($options['hascapability'])) {
            if ($availableoption < 1) {
                $html .= html_writer::tag('p', get_string('choicegroupfull', 'choicegroup'));
            } else {
                if (!$disabled) {
                    $html .= html_writer::tag('button', get_string('savemychoicegroup', 'choicegroup'), [
                        'type' => 'submit',
                        'value' => get_string('savemychoicegroup', 'choicegroup'),
                        'class' => 'btn btn-primary',
                        'style' => $initiallyhidesubmitbutton ? 'display: none' : '',
                    ]);
                }
            }

            if (!empty($options['allowupdate']) && !$multipleenrollmentspossible && !$disabled) {
                $url = new moodle_url('view.php', ['id' => $coursemoduleid, 'action' => 'delchoicegroup', 'sesskey' => sesskey()]);
                $html .= ' ' . html_writer::link($url, get_string('removemychoicegroup', 'choicegroup'));
            }
        } else if (!isloggedin() || isguestuser()) { // Only display message if user is not logged in or is a guest user.
            $html .= ' ' . html_writer::tag('p', get_string('havetologin', 'choicegroup'));
        }

        $html .= html_writer::end_tag('form');

        return $html;
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
        global $context, $CFG;

        if ($CFG->version >= 2024041400) {
            $marginendclass = "me-1";
        } else {
            $marginendclass = "mr-1";
        }

        if (!has_capability('mod/choicegroup:downloadresponses', $context)) {
            return; // Only users who can download the responses see the results.
        }

        if (!$choicegroups) {
            return; // No answers yet, so don't bother.
        }

        $html = '';
        $html .= html_writer::tag('h3', format_string(get_string("responses", "choicegroup")));

        $attributes = ['method' => 'POST'];
        $attributes['action'] = new moodle_url($this->page->url);
        $attributes['id'] = 'attemptsform';
        $attributes['class'] = 'tableform';

        if ($choicegroups->viewresponsecapability) {
            $html .= html_writer::start_tag('form', $attributes);
            $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',
                'value' => $choicegroups->coursemoduleid, ]);
            $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'overview']);
        }

        $table = new html_table();
        $table->cellpadding = 0;
        $table->cellspacing = 0;
        $table->attributes['class'] = 'results names table-reboot choicegroups ';
        $table->tablealign = 'center';
        $table->data = [];

        $count = 0;
        ksort($choicegroups->options);

        $columns = [];
        foreach ($choicegroups->options as $optionid => $options) {
            $coldata = '';
            if ($choicegroups->showunanswered && $optionid == 0) {
                $coldata .= html_writer::tag(
                    'div',
                    format_string(get_string('notanswered', 'choicegroup')),
                    ['class' => 'option']
                );
            } else if ($optionid > 0) {
                $coldata .= html_writer::tag(
                    'div',
                    format_string(choicegroup_get_option_text($choicegroups, $choicegroups->options[$optionid]->groupid)),
                    ['class' => 'option']
                );
            }

            $numberofuser = 0;
            if (!empty($options->user) && count($options->user) > 0) {
                $numberofuser = count($options->user);
            }

            $coldata .= html_writer::tag(
                'div',
                ' (' . $numberofuser . ')',
                ['class' => 'numberofuser', 'title' => get_string('numberofuser', 'choicegroup')]
            );
            $columns[] = $coldata;
        }

        $table->head = $columns;

        $coldata = '';
        $columns = [];
        foreach ($choicegroups->options as $optionid => $options) {
            $coldata = '';
            if ($choicegroups->showunanswered || $optionid > 0) {
                if (!empty($options->user)) {
                    foreach ($options->user as $user) {
                        $data = '';
                        if (empty($user->imagealt)) {
                            $user->imagealt = '';
                        }

                        if ($choicegroups->viewresponsecapability && $choicegroups->deleterepsonsecapability  && $optionid > 0) {
                            $attemptaction = html_writer::checkbox('grpsmemberid[]', $user->grpsmemberid, '');
                            $data .= html_writer::tag('div', $attemptaction, ['class' => 'attemptaction']);
                        }

                        $userimage = $this->output->user_picture($user, ['courseid' => $choicegroups->courseid]);
                        $data .= html_writer::tag('div', $userimage, ['class' => 'image']);

                        $userlink = new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $choicegroups->courseid]);
                        $name = html_writer::tag(
                            'a',
                            s(fullname($user, $choicegroups->fullnamecapability)),
                            ['href' => $userlink, 'class' => 'username']
                        );
                        $data .= html_writer::tag('div', $name, ['class' => 'fullname']);
                        $data .= html_writer::tag('div', '', ['class' => 'clearfloat']);
                        $coldata .= html_writer::tag('div', $data, ['class' => 'user']);
                    }
                }
            }

            $columns[] = $coldata;
            $count++;
        }

        $table->data[] = $columns;
        foreach ($columns as $d) {
            $table->colclasses[] = 'data';
        }

        $html .= html_writer::tag('div', html_writer::table($table), ['class' => 'response tablecontainer']);

        $actiondata = '';
        if ($choicegroups->viewresponsecapability && $choicegroups->deleterepsonsecapability) {
            $selecturl = new moodle_url('#');
            $actiondata .= html_writer::start_div('selectallnone');
            $actiondata .= html_writer::link($selecturl, get_string('selectall'), ['data-select-info' => true]) . ' / ';

            $actiondata .= html_writer::link($selecturl, get_string('deselectall'), ['data-select-info' => false]);
            $actiondata .= html_writer::end_div();
            $actiondata .= html_writer::tag('label', ' ' . get_string('withselected', 'choice') .
                ' ', ['for' => 'menuaction', 'class' => $marginendclass]);

            $actionurl = new moodle_url($this->page->url, ['sesskey' => sesskey(), 'action' => 'delete_confirmation()']);
            $select = new single_select(
                $actionurl,
                'action',
                ['delete' => get_string('delete')],
                null,
                ['' => get_string('chooseaction', 'choicegroup')],
                'attemptsform'
            );

            $this->page->requires->js_call_amd('mod_choicegroup/select_all_choices', 'init');
            $actiondata .= $this->output->render($select);
        }

        $html .= html_writer::tag('div', $actiondata, ['class' => 'responseaction']);

        if ($choicegroups->viewresponsecapability) {
            $html .= html_writer::end_tag('form');
        }

        return $html;
    }


    /**
     * Returns the HTML of the results as a table of counts, without the names of the users.
     *
     * @param object $choicegroups
     * @return string|void The HTML, or nothing when the viewer may not see the results
     */
    public function display_publish_anonymous_horizontal($choicegroups) {
        global $context;

        if (!has_capability('mod/choicegroup:downloadresponses', $context)) {
            return; // Only users who can download the responses see the results.
        }

        $table = new html_table();
        $table->cellpadding = 5;
        $table->cellspacing = 0;
        $table->attributes['class'] = 'results anonymous ';
        $table->data = [];

        $count = 0;
        ksort($choicegroups->options);

        $rows = [];
        foreach ($choicegroups->options as $optionid => $options) {
            $numberofuser = 0;
            $graphcell = new html_table_cell();
            if (!empty($options->user)) {
                $numberofuser = count($options->user);
            }

            $width = 0;
            $percentageamount = 0;
            $columndata = '';
            if ($choicegroups->numberofuser > 0) {
                $width = (CHOICEGROUP_COLUMN_WIDTH * ((float)$numberofuser / (float)$choicegroups->numberofuser));
                $percentageamount = ((float)$numberofuser / (float)$choicegroups->numberofuser) * 100.0;
            }

            $displaydiagram = html_writer::tag('img', '', ['style' => 'height:50px; width:' . $width . 'px', 'alt' => '',
                'src' => $this->output->image_url('row', 'choicegroup'), ]);

            $skiplink = html_writer::tag(
                'a',
                get_string('skipresultgraph', 'choicegroup'),
                ['href' => '#skipresultgraph' . $optionid, 'class' => 'skip-block']
            );
            $skiphandler = html_writer::tag('span', '', ['class' => 'skip-block-to', 'id' => 'skipresultgraph' . $optionid]);

            $graphcell->text = $skiplink . $displaydiagram . $skiphandler;
            $graphcell->attributes = ['class' => 'graph horizontal'];

            $datacell = new html_table_cell();
            if ($choicegroups->showunanswered && $optionid == 0) {
                $columndata .= html_writer::tag(
                    'div',
                    format_string(get_string('notanswered', 'choicegroup')),
                    ['class' => 'option']
                );
            } else if ($optionid > 0) {
                $columndata .= html_writer::tag(
                    'div',
                    format_string(choicegroup_get_option_text($choicegroups, $choicegroups->options[$optionid]->groupid)),
                    ['class' => 'option']
                );
            }

            $columndata .= html_writer::tag(
                'div',
                ' (' . $numberofuser . ')',
                ['title' => get_string('numberofuser', 'choicegroup'), 'class' => 'numberofuser']
            );

            if ($choicegroups->numberofuser > 0) {
                $percentageamount = ((float)$numberofuser / (float)$choicegroups->numberofuser) * 100.0;
            }

            $columndata .= html_writer::tag('div', format_float($percentageamount, 1) . '%', ['class' => 'percentage']);

            $datacell->text = $columndata;
            $datacell->attributes = ['class' => 'header'];

            $row = new html_table_row();
            $row->cells = [$datacell, $graphcell];
            $rows[] = $row;
        }

        $table->data = $rows;

        $html = '';
        $header = html_writer::tag('h3', format_string(get_string("responses", "choicegroup")));
        $html .= html_writer::tag('div', $header, ['class' => 'responseheader']);
        $html .= html_writer::table($table);

        return $html;
    }
}
