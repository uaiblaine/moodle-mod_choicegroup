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
 * Tests for the choice rules in lib.php.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rules a choice obeys: the option, the group, enrolment, dates, allowupdate, limits, and the teacher's deletion.
 *
 * Every test that asserts a change did not happen first shows the same call succeeding when the rule allows it, so a
 * refusal is the rule's doing and not a setup that could never have worked.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('choicegroup_user_submit_response')]
#[CoversFunction('choicegroup_save_user_choices')]
#[CoversFunction('choicegroup_user_remove_choice')]
#[CoversFunction('choicegroup_check_user_can_change')]
#[CoversFunction('check_date_restrictions')]
#[CoversFunction('choicegroup_delete_responses')]
#[CoversFunction('choicegroup_get_membership_ids')]
#[CoversFunction('choicegroup_parse_selected_groups')]
#[CoversFunction('choicegroup_add_instance')]
#[CoversFunction('choicegroup_update_instance')]
#[CoversFunction('choicegroup_get_groups')]
#[CoversFunction('choicegroup_get_user_answer')]
#[CoversFunction('choicegroup_get_response_data')]
#[CoversFunction('choicegroup_filter_visible_members')]
#[CoversFunction('choicegroup_get_export_data')]
#[CoversFunction('choicegroup_clean_txt_cell')]
#[CoversFunction('mod_choicegroup_core_calendar_provide_event_action')]
#[CoversFunction('choicegroup_view')]
final class lib_test extends \advanced_testcase {
    /**
     * Load the library under test.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choicegroup/lib.php');
        require_once($CFG->dirroot . '/calendar/lib.php');
        require_once($CFG->dirroot . '/mod/choicegroup/tests/fixtures/refusing_lock_factory.php');
        require_once($CFG->dirroot . '/mod/choicegroup/tests/fixtures/recording_lock_factory.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create a course with three groups, two students and an instance offering the first two groups.
     *
     * @param array $settings Instance settings to override
     * @return \stdClass course, cm, choicegroup, g1, g2, g3 (not offered), s1, s2, and option (group id => option id)
     */
    private function create_setup(array $settings = []): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $g1 = $generator->create_group(['courseid' => $course->id, 'name' => 'Group one']);
        $g2 = $generator->create_group(['courseid' => $course->id, 'name' => 'Group two']);
        $g3 = $generator->create_group(['courseid' => $course->id, 'name' => 'Group three']);
        $s1 = $generator->create_and_enrol($course, 'student');
        $s2 = $generator->create_and_enrol($course, 'student');

        $record = array_merge(
            ['course' => $course->id, 'groups' => [$g1->id, $g2->id], 'allowupdate' => 1],
            $settings
        );
        $module = $generator->create_module('choicegroup', $record);

        return $this->load_setup((object)[
            'course' => get_course($course->id),
            'moduleid' => (int)$module->id,
            'g1' => $g1,
            'g2' => $g2,
            'g3' => $g3,
            's1' => $s1,
            's2' => $s2,
        ]);
    }

    /**
     * Reload the instance of a setup, after its row changed.
     *
     * @param \stdClass $setup The setup
     * @return \stdClass The same setup with cm, choicegroup and option refreshed
     */
    private function load_setup(\stdClass $setup): \stdClass {
        $setup->choicegroup = choicegroup_get_choicegroup($setup->moduleid);
        $setup->cm = get_coursemodule_from_instance('choicegroup', $setup->moduleid);
        $setup->option = array_map('intval', array_flip($setup->choicegroup->option));

        return $setup;
    }

    /**
     * An option id of another instance is refused, and a nonexistent one too, with a moodle_exception.
     *
     * @return void
     */
    public function test_submit_resolves_the_option_within_the_instance(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $other = $this->getDataGenerator()->create_module(
            'choicegroup',
            ['course' => $a->course->id, 'groups' => [$a->g3->id]]
        );
        $otheroption = (int)array_key_first(choicegroup_get_choicegroup($other->id)->option);
        $this->setUser($a->s1);

        // Control: an option of this instance adds the membership.
        choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));

        try {
            choicegroup_user_submit_response($otheroption, $a->choicegroup, $a->s1->id, $a->course, $a->cm);
            $this->fail('An option of another instance was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidoption', $e->errorcode);
        }
        $this->assertFalse(groups_is_member($a->g3->id, $a->s1->id));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidoption', 'choicegroup'));
        choicegroup_user_submit_response(999999, $a->choicegroup, $a->s1->id, $a->course, $a->cm);
    }

    /**
     * An option whose group belongs to another course is refused.
     *
     * @return void
     */
    public function test_submit_refuses_a_group_of_another_course(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);
        $DB->set_field('choicegroup_options', 'groupid', $foreign->id, ['id' => $a->option[$a->g2->id]]);
        $this->setUser($a->s1);

        // Control: the untouched option still works.
        choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));

        try {
            choicegroup_user_submit_response($a->option[$a->g2->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
            $this->fail('A group of another course was joined.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidoption', $e->errorcode);
        }
        $this->assertFalse(groups_is_member($foreign->id, $a->s1->id));
    }

    /**
     * Without allowupdate a saved choice can be neither switched nor removed; with it, both work.
     *
     * @return void
     */
    public function test_a_saved_choice_changes_only_when_updates_are_allowed(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup(['allowupdate' => 0]);
        $this->setUser($a->s1);

        // Control: the first choice needs no allowupdate.
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));

        try {
            choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g2->id]]);
            $this->fail('A switch was saved without allowupdate.');
        } catch (\moodle_exception $e) {
            $this->assertSame('updatenotallowed', $e->errorcode);
        }

        try {
            choicegroup_user_remove_choice($a->choicegroup, $a->s1->id, $a->course, $a->cm);
            $this->fail('A removal was saved without allowupdate.');
        } catch (\moodle_exception $e) {
            $this->assertSame('updatenotallowed', $e->errorcode);
        }
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertFalse(groups_is_member($a->g2->id, $a->s1->id));

        $DB->set_field('choicegroup', 'allowupdate', 1, ['id' => $a->moduleid]);
        $a = $this->load_setup($a);
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g2->id]]);
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g2->id, $a->s1->id));

        $this->assertTrue(choicegroup_user_remove_choice($a->choicegroup, $a->s1->id, $a->course, $a->cm));
        $this->assertFalse(groups_is_member($a->g2->id, $a->s1->id));
    }

    /**
     * A removal after the closing date is refused like a change.
     *
     * @return void
     */
    public function test_a_removal_after_closing_is_refused(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $this->setUser($a->s1);
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));

        $DB->set_field('choicegroup', 'timeclose', time() - 60, ['id' => $a->moduleid]);
        $a = $this->load_setup($a);

        try {
            choicegroup_user_remove_choice($a->choicegroup, $a->s1->id, $a->course, $a->cm);
            $this->fail('A removal after closing was saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('activitydate:exceeded', $e->errorcode);
        }
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * A removal names a group of this instance or is refused.
     *
     * @return void
     */
    public function test_a_removal_names_an_offered_group(): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1]);
        groups_add_member($a->g3->id, $a->s1->id);
        $this->setUser($a->s1);
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);

        try {
            choicegroup_user_remove_choice($a->choicegroup, $a->s1->id, $a->course, $a->cm, $a->g3->id);
            $this->fail('A group the instance does not offer was left.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidoption', $e->errorcode);
        }
        $this->assertTrue(groups_is_member($a->g3->id, $a->s1->id));

        // Control: an offered group is left.
        $this->assertTrue(choicegroup_user_remove_choice($a->choicegroup, $a->s1->id, $a->course, $a->cm, $a->g1->id));
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * Saving a set of choices leaves the user's groups that the instance does not offer alone.
     *
     * The web form's hidden answer_N_groupid fields once named the group to leave; the set is now resolved against
     * the instance's options.
     *
     * @return void
     */
    public function test_saving_a_set_never_touches_groups_not_offered(): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1]);
        groups_add_member($a->g3->id, $a->s1->id);
        $this->setUser($a->s1);

        choicegroup_save_user_choices(
            $a->choicegroup,
            $a->s1->id,
            $a->course,
            $a->cm,
            [$a->option[$a->g1->id], $a->option[$a->g2->id]]
        );
        $this->assertTrue(groups_is_member($a->g2->id, $a->s1->id));

        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);

        // Control: the offered group left out of the set is left.
        $this->assertFalse(groups_is_member($a->g2->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g3->id, $a->s1->id));
    }

    /**
     * Only an enrolled user with the capability may choose.
     *
     * @return void
     */
    public function test_choosing_requires_enrolment(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        try {
            choicegroup_save_user_choices($a->choicegroup, $outsider->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);
            $this->fail('A user who is not enrolled chose.');
        } catch (\moodle_exception $e) {
            $this->assertSame('notenrolledchoose', $e->errorcode);
        }
        $this->assertFalse(groups_is_member($a->g1->id, $outsider->id));

        // Control: an enrolled student chooses.
        $this->setUser($a->s1);
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * The minimum and the maximum number of groups are enforced before anything is written.
     *
     * @return array
     */
    public static function enrolment_limits_provider(): array {
        return [
            'below the minimum' => [2, 0, 1, 'mustchoosemin'],
            'above the maximum' => [0, 1, 2, 'mustchoosemax'],
            'within both' => [1, 2, 2, null],
        ];
    }

    /**
     * Multiple enrolments respect the instance's minimum and maximum.
     *
     * @param int $min The minimum
     * @param int $max The maximum
     * @param int $chosen How many options are chosen
     * @param string|null $error The expected error code, or null when the set is saved
     * @return void
     */
    #[DataProvider('enrolment_limits_provider')]
    public function test_multiple_enrolments_respect_min_and_max(int $min, int $max, int $chosen, ?string $error): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1, 'minenrollments' => $min, 'maxenrollments' => $max]);
        $this->setUser($a->s1);
        $optionids = array_slice([$a->option[$a->g1->id], $a->option[$a->g2->id]], 0, $chosen);

        if ($error !== null) {
            try {
                choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, $optionids);
                $this->fail('The limit was not enforced.');
            } catch (\moodle_exception $e) {
                $this->assertSame($error, $e->errorcode);
            }
            $this->assertFalse(choicegroup_get_user_answer($a->choicegroup, $a->s1->id, false, true));
            return;
        }

        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, $optionids);
        $this->assertCount($chosen, choicegroup_get_user_answer($a->choicegroup, $a->s1->id, true, true));
    }

    /**
     * Dates stored as integers, as a record built in code has them, are read as unset.
     *
     * @return void
     */
    public function test_integer_zero_dates_do_not_close_the_activity(): void {
        check_date_restrictions((object)['timeopen' => 0, 'timeclose' => 0]);
        check_date_restrictions((object)['timeopen' => '0', 'timeclose' => '0']);

        // Control: a past closing date closes it.
        $this->expectException(\moodle_exception::class);
        check_date_restrictions((object)['timeopen' => 0, 'timeclose' => time() - 10]);
    }

    /**
     * A teacher's deletion removes memberships of this instance's groups only.
     *
     * @return void
     */
    public function test_deleting_responses_is_limited_to_the_option_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g3->id, $a->s1->id);
        $this->setAdminUser();

        $foreignid = (int)$DB->get_field('groups_members', 'id', ['groupid' => $a->g3->id, 'userid' => $a->s1->id]);
        $ownid = (int)$DB->get_field('groups_members', 'id', ['groupid' => $a->g1->id, 'userid' => $a->s1->id]);
        choicegroup_delete_responses([$foreignid, $ownid, 999999], $a->choicegroup, $a->cm, $a->course);

        $this->assertTrue(groups_is_member($a->g3->id, $a->s1->id));
        // Control: the option group's membership, posted with it, is removed.
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * Under separate groups a teacher without accessallgroups deletes only the responses of users they can see.
     *
     * @return void
     */
    public function test_deleting_responses_under_separate_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $DB->update_record(
            'course',
            (object)['id' => $a->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]
        );
        $a->course = get_course($a->course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($a->course, 'teacher');
        groups_add_member($a->g1->id, $teacher->id);
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g2->id, $a->s2->id);
        $s1row = (int)$DB->get_field('groups_members', 'id', ['groupid' => $a->g1->id, 'userid' => $a->s1->id]);
        $s2row = (int)$DB->get_field('groups_members', 'id', ['groupid' => $a->g2->id, 'userid' => $a->s2->id]);

        $this->setUser($teacher);
        choicegroup_delete_responses([$s1row, $s2row], $a->choicegroup, $a->cm, $a->course);
        // Control: the student of the teacher's group is removed.
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g2->id, $a->s2->id));

        $editor = $this->getDataGenerator()->create_and_enrol($a->course, 'editingteacher');
        $this->setUser($editor);
        choicegroup_delete_responses([$s2row], $a->choicegroup, $a->cm, $a->course);
        $this->assertFalse(groups_is_member($a->g2->id, $a->s2->id));
    }

    /**
     * The user ids view.php posts become the users' memberships in this instance's groups only.
     *
     * @return void
     */
    public function test_membership_ids_of_users_are_those_of_the_option_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g3->id, $a->s1->id);

        $ids = choicegroup_get_membership_ids($a->choicegroup, [$a->s1->id, $a->s2->id]);

        $expected = (int)$DB->get_field('groups_members', 'id', ['groupid' => $a->g1->id, 'userid' => $a->s1->id]);
        $this->assertSame([$expected], $ids);
    }

    /**
     * The group limit is checked under a lock; when the lock is not granted nothing is written.
     *
     * @return void
     */
    public function test_a_limited_group_is_joined_under_a_lock(): void {
        global $CFG;

        $this->resetAfterTest();
        $a = $this->create_setup(['limitanswers' => 1]);
        $this->set_limit($a, $a->g1->id, 5);
        $this->setUser($a->s1);

        $CFG->lock_factory = '\mod_choicegroup_refusing_lock_factory';
        try {
            choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
            $this->fail('The group was joined without the lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('locktimeout', $e->errorcode);
        }
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));

        // Control: with the site's lock factory the same call joins.
        unset($CFG->lock_factory);
        choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * A full group refuses the next student and keeps the one already in.
     *
     * @return void
     */
    public function test_a_full_group_refuses_the_next_student(): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['limitanswers' => 1]);
        $this->set_limit($a, $a->g1->id, 1);

        $this->setUser($a->s1);
        choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s1->id, $a->course, $a->cm);
        $this->setUser($a->s2);
        try {
            choicegroup_user_submit_response($a->option[$a->g1->id], $a->choicegroup, $a->s2->id, $a->course, $a->cm);
            $this->fail('A full group took one more member.');
        } catch (\moodle_exception $e) {
            $this->assertSame('choicegroupfull', $e->errorcode);
        }
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertFalse(groups_is_member($a->g1->id, $a->s2->id));
    }

    /**
     * A set that fails on its last option leaves the user in the groups they held before.
     *
     * @return void
     */
    public function test_a_failed_set_leaves_the_membership_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        // PHPUnit wraps a test in a transaction, and a rollback inside a nested one undoes nothing until the outer ends.
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $groups = [];
        foreach (['A', 'B', 'C'] as $name) {
            $groups[$name] = $generator->create_group(['courseid' => $course->id]);
        }
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => array_map(static fn($group) => $group->id, $groups),
            'allowupdate' => 1,
            'multipleenrollmentspossible' => 1,
            'limitanswers' => 1,
        ]);
        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_instance('choicegroup', $module->id);
        $option = array_map('intval', array_flip($choicegroup->option));
        $DB->set_field('choicegroup_options', 'maxanswers', 1, ['id' => $option[$groups['C']->id]]);
        $DB->set_field('choicegroup_options', 'maxanswers', 5, ['id' => $option[$groups['A']->id]]);
        $DB->set_field('choicegroup_options', 'maxanswers', 5, ['id' => $option[$groups['B']->id]]);
        $choicegroup = choicegroup_get_choicegroup($module->id);

        $user = $generator->create_and_enrol($course, 'student');
        $other = $generator->create_and_enrol($course, 'student');
        groups_add_member($groups['A']->id, $user->id);
        groups_add_member($groups['C']->id, $other->id);

        $this->setUser($user);
        try {
            choicegroup_save_user_choices(
                $choicegroup,
                $user->id,
                $course,
                $cm,
                [$option[$groups['B']->id], $option[$groups['C']->id]]
            );
            $this->fail('A full group took one more member.');
        } catch (\moodle_exception $e) {
            $this->assertSame('choicegroupfull', $e->errorcode);
        }
        // B was added and A removed before C refused: none of it may stay.
        $this->assertTrue(groups_is_member($groups['A']->id, $user->id));
        $this->assertFalse(groups_is_member($groups['B']->id, $user->id));
        $this->assertFalse(groups_is_member($groups['C']->id, $user->id));

        // Control: the same set without the full group is saved.
        choicegroup_save_user_choices($choicegroup, $user->id, $course, $cm, [$option[$groups['B']->id]]);
        $this->assertFalse(groups_is_member($groups['A']->id, $user->id));
        $this->assertTrue(groups_is_member($groups['B']->id, $user->id));
    }

    /**
     * A set that joins limited groups holds their locks until the transaction has committed.
     *
     * Another request counts a group's members only once this one's additions are committed, so a lock released
     * inside the transaction would let both requests see room for one more member.
     *
     * @return void
     */
    public function test_a_set_holds_the_group_locks_until_the_commit(): void {
        global $CFG;

        $this->resetAfterTest();
        // PHPUnit wraps a test in a transaction, which would stay open past this one's commit.
        $this->preventResetByRollback();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1, 'limitanswers' => 1]);
        $this->set_limit($a, $a->g1->id, 5);
        $this->set_limit($a, $a->g2->id, 5);
        $a = $this->load_setup($a);
        $this->setUser($a->s1);

        $CFG->lock_factory = '\mod_choicegroup_recording_lock_factory';
        \mod_choicegroup_recording_lock_factory::$releases = [];
        try {
            choicegroup_save_user_choices(
                $a->choicegroup,
                $a->s1->id,
                $a->course,
                $a->cm,
                [$a->option[$a->g1->id], $a->option[$a->g2->id]]
            );
        } finally {
            unset($CFG->lock_factory);
        }

        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g2->id, $a->s1->id));
        // Control: both groups were locked, each once.
        $keys = array_column(\mod_choicegroup_recording_lock_factory::$releases, 'key');
        sort($keys);
        $expected = ['group_' . $a->g1->id, 'group_' . $a->g2->id];
        sort($expected);
        $this->assertSame($expected, $keys);
        foreach (\mod_choicegroup_recording_lock_factory::$releases as $release) {
            $this->assertFalse($release['intransaction'], $release['key'] . ' was released before the commit.');
        }
    }

    /**
     * Set the member limit of one option.
     *
     * @param \stdClass $setup The setup
     * @param int $groupid The option's group
     * @param int $limit The limit
     * @return void
     */
    private function set_limit(\stdClass $setup, int $groupid, int $limit): void {
        global $DB;

        $DB->set_field('choicegroup_options', 'maxanswers', $limit, ['id' => $setup->option[$groupid]]);
    }

    /**
     * Groups, answers and responses of two instances read in one request are each instance's own.
     *
     * @return void
     */
    public function test_request_caches_are_keyed_by_instance(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $other = $this->getDataGenerator()->create_module(
            'choicegroup',
            ['course' => $a->course->id, 'groups' => [$a->g3->id]]
        );
        $b = choicegroup_get_choicegroup($other->id);
        $bcm = get_coursemodule_from_instance('choicegroup', $other->id);
        groups_add_member($a->g3->id, $a->s1->id);

        $agroupids = array_map('intval', array_keys(choicegroup_get_groups($a->choicegroup)));
        sort($agroupids);
        $this->assertSame([(int)$a->g1->id, (int)$a->g2->id], $agroupids);
        $this->assertSame([(int)$a->g3->id], array_map('intval', array_keys(choicegroup_get_groups($b))));

        $this->assertFalse(choicegroup_get_user_answer($a->choicegroup, $a->s1->id));
        $this->assertEquals($a->g3->id, choicegroup_get_user_answer($b, $a->s1->id)->id);

        $aresponses = choicegroup_get_response_data($a->choicegroup, $a->cm, 0, false);
        $bresponses = choicegroup_get_response_data($b, $bcm, 0, false);
        $this->assertArrayNotHasKey($a->g3->id, $aresponses);
        $this->assertArrayHasKey($a->g3->id, $bresponses);
    }

    /**
     * One user's answer in two instances of the same request is each instance's own, read without a refresh.
     *
     * @return void
     */
    public function test_the_user_answer_is_cached_per_instance(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $other = $this->getDataGenerator()->create_module(
            'choicegroup',
            ['course' => $a->course->id, 'groups' => [$a->g3->id]]
        );
        $b = choicegroup_get_choicegroup($other->id);
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g3->id, $a->s1->id);
        choicegroup_purge_request_cache();

        // The first read fills the cache for instance A; the second, for B, must not be served A's entry.
        $this->assertEquals($a->g1->id, choicegroup_get_user_answer($a->choicegroup, $a->s1->id)->id);
        $this->assertEquals($a->g3->id, choicegroup_get_user_answer($b, $a->s1->id)->id);
        // Control: the entries survive, one per instance.
        $this->assertEquals($a->g1->id, choicegroup_get_user_answer($a->choicegroup, $a->s1->id)->id);
    }

    /**
     * In multiple mode a set holding an option of another instance is refused whole.
     *
     * @return void
     */
    public function test_a_set_with_a_foreign_option_is_refused(): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1]);
        $other = $this->getDataGenerator()->create_module(
            'choicegroup',
            ['course' => $a->course->id, 'groups' => [$a->g3->id], 'multipleenrollmentspossible' => 1]
        );
        $b = choicegroup_get_choicegroup($other->id);
        $foreign = (int)array_flip($b->option)[$a->g3->id];
        $this->assertNotContains($foreign, array_values($a->option));

        $this->setUser($a->s1);
        try {
            choicegroup_save_user_choices(
                $a->choicegroup,
                $a->s1->id,
                $a->course,
                $a->cm,
                [$a->option[$a->g1->id], $foreign]
            );
            $this->fail('A foreign option was skipped instead of refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidoption', $e->errorcode);
        }
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertFalse(groups_is_member($a->g3->id, $a->s1->id));

        // Control: the set of its own option is saved.
        choicegroup_save_user_choices($a->choicegroup, $a->s1->id, $a->course, $a->cm, [$a->option[$a->g1->id]]);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * The serialised group list keeps integer ids of groups of the course, once each.
     *
     * @return void
     */
    public function test_selected_groups_are_groups_of_the_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $own = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);

        $serialized = ";{$own->id};{$foreign->id};abc;{$own->id}e3; {$own->id} ;";
        $this->assertSame([(int)$own->id], choicegroup_parse_selected_groups($serialized, (int)$course->id));

        // The instance keeps the course's group only.
        $module = $this->getDataGenerator()->create_module(
            'choicegroup',
            ['course' => $course->id, 'groups' => [$own->id, $foreign->id]]
        );
        $options = choicegroup_get_choicegroup($module->id)->option;
        $this->assertSame([(int)$own->id], array_values(array_map('intval', $options)));
    }

    /**
     * An update without the group picker's field keeps the options.
     *
     * @return void
     */
    public function test_an_update_without_the_group_field_keeps_the_options(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $record = $DB->get_record('choicegroup', ['id' => $a->moduleid]);
        $record->instance = $record->id;
        $record->coursemodule = $a->cm->id;
        choicegroup_update_instance($record);
        $this->assertSame(2, $DB->count_records('choicegroup_options', ['choicegroupid' => $a->moduleid]));

        // Control: the field, when present, rewrites the options.
        $record->serializedselectedgroups = (string)$a->g3->id;
        choicegroup_update_instance($record);
        $groupids = $DB->get_fieldset_select('choicegroup_options', 'groupid', 'choicegroupid = ?', [$a->moduleid]);
        $this->assertSame([(int)$a->g3->id], array_map('intval', $groupids));
    }

    /**
     * Members of a group are listed as core's group visibility decides.
     *
     * @return array
     */
    public static function visibility_provider(): array {
        return [
            'all, not a member' => [GROUPS_VISIBILITY_ALL, false, 2],
            'members, a member' => [GROUPS_VISIBILITY_MEMBERS, true, 2],
            'members, not a member' => [GROUPS_VISIBILITY_MEMBERS, false, 0],
            'own, a member' => [GROUPS_VISIBILITY_OWN, true, 1],
            'own, not a member' => [GROUPS_VISIBILITY_OWN, false, 0],
            'none, a member' => [GROUPS_VISIBILITY_NONE, true, 0],
        ];
    }

    /**
     * A student sees the members core would show them.
     *
     * @param int $visibility The group visibility
     * @param bool $member Whether the viewing student is a member
     * @param int $expected How many members they see
     * @return void
     */
    #[DataProvider('visibility_provider')]
    public function test_member_names_follow_group_visibility(int $visibility, bool $member, int $expected): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $viewer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $third = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'visibility' => $visibility]);
        $members = [$other->id => $other];
        if ($member) {
            groups_add_member($group->id, $viewer->id);
            $members[$viewer->id] = $viewer;
        } else {
            $members[$third->id] = $third;
        }

        $this->setUser($viewer);
        $this->assertCount($expected, choicegroup_filter_visible_members($group, $members));

        // A teacher, who holds viewhiddengroups, sees every member.
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $this->assertCount(2, choicegroup_filter_visible_members($group, $members));
    }

    /**
     * The download carries only the identity fields the site shows in this context.
     *
     * @return void
     */
    public function test_the_download_carries_only_the_shown_identity_fields(): void {
        global $CFG;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $this->setAdminUser();
        $context = \context_module::instance($a->cm->id);

        $CFG->showuseridentity = '';
        $users = choicegroup_get_response_data($a->choicegroup, $a->cm, 0, false);
        $data = choicegroup_get_export_data($a->choicegroup, $a->course, $context, $users);
        $this->assertNotContains(get_string('email'), $data['header']);
        $this->assertNotContains($a->s1->email, array_merge(...$data['rows']));

        // Control: once the site shows email, it is exported.
        $CFG->showuseridentity = 'email';
        $data = choicegroup_get_export_data($a->choicegroup, $a->course, $context, $users);
        $this->assertContains(get_string('email'), $data['header']);
        $this->assertContains($a->s1->email, array_merge(...$data['rows']));
    }

    /**
     * A tab or a line break in a cell cannot shift the columns of the text download.
     *
     * @return void
     */
    public function test_a_text_cell_keeps_no_tab_or_line_break(): void {
        $this->assertSame('a b c d e', choicegroup_clean_txt_cell("a\tb\nc\r\nd\re"));
        $this->assertSame('plain', choicegroup_clean_txt_cell('plain'));
    }

    /**
     * A calendar event whose activity is gone has no action, and does not fail.
     *
     * @return void
     */
    public function test_a_calendar_event_without_its_activity_has_no_action(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $factory = new \core_calendar\action_factory();
        $event = new \calendar_event((object)[
            'courseid' => $a->course->id,
            'modulename' => 'choicegroup',
            'instance' => $a->moduleid,
            'eventtype' => 'open',
        ]);

        // Control: the existing activity has its action.
        $this->setUser($a->s1);
        $this->assertNotNull(mod_choicegroup_core_calendar_provide_event_action($event, $factory));

        $orphan = new \calendar_event((object)[
            'courseid' => $a->course->id,
            'modulename' => 'choicegroup',
            'instance' => $a->moduleid + 1000,
            'eventtype' => 'open',
        ]);
        $this->assertNull(mod_choicegroup_core_calendar_provide_event_action($orphan, $factory));
    }

    /**
     * Viewing logs course_module_viewed and marks the activity viewed.
     *
     * @return void
     */
    public function test_viewing_logs_the_view_and_marks_it_viewed(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $a = $this->create_setup(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $this->setUser($a->s1);
        $context = \context_module::instance($a->cm->id);
        $completion = new \completion_info($a->course);
        $this->assertEquals(COMPLETION_NOT_VIEWED, $completion->get_data($a->cm, false, $a->s1->id)->viewed);

        $sink = $this->redirectEvents();
        choicegroup_view($a->choicegroup, $a->course, $a->cm, $context);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_choicegroup\event\course_module_viewed
        ));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertEquals($a->moduleid, $events[0]->objectid);
        $this->assertEquals(COMPLETION_VIEWED, $completion->get_data($a->cm, false, $a->s1->id)->viewed);
    }

    /**
     * The course reset form offers no option the plugin cannot honour.
     *
     * Memberships are core rows without the plugin's component, so the plugin cannot tell them from hand-made ones and
     * has no reset; core's "remove all group members" does that.
     *
     * @return void
     */
    public function test_the_reset_form_offers_nothing(): void {
        $this->assertFalse(function_exists('choicegroup_reset_course_form_definition'));
        $this->assertFalse(function_exists('choicegroup_reset_course_form_defaults'));
        $this->assertFalse(function_exists('choicegroup_reset_userdata'));
    }

    /**
     * Adding the activity is a course-level right of editing teachers and managers, as in mod_choice.
     *
     * @return void
     */
    public function test_addinstance_is_defined_as_in_mod_choice(): void {
        global $CFG;

        $ours = $this->read_capabilities($CFG->dirroot . '/mod/choicegroup/db/access.php')['mod/choicegroup:addinstance'];
        $core = $this->read_capabilities($CFG->dirroot . '/mod/choice/db/access.php')['mod/choice:addinstance'];
        $this->assertEquals(CONTEXT_COURSE, $core['contextlevel']);
        foreach (['contextlevel', 'riskbitmask', 'archetypes', 'clonepermissionsfrom', 'captype'] as $key) {
            $this->assertEquals($core[$key], $ours[$key], $key);
        }

        $info = get_capability_info('mod/choicegroup:addinstance');
        $this->assertEquals(CONTEXT_COURSE, $info->contextlevel);
        $this->assertEquals(RISK_XSS, $info->riskbitmask & RISK_XSS);
        $this->assertArrayNotHasKey('mod/choicegroup:addinstance', get_default_capabilities('teacher'));
        // Control: the archetypes that keep it.
        $this->assertArrayHasKey('mod/choicegroup:addinstance', get_default_capabilities('editingteacher'));
        $this->assertArrayHasKey('mod/choicegroup:addinstance', get_default_capabilities('manager'));
    }

    /**
     * Read the capabilities a plugin's access file declares, from the file and not from the installed table.
     *
     * @param string $file Path of a db/access.php
     * @return array The capabilities array
     */
    private function read_capabilities(string $file): array {
        $capabilities = [];
        include($file);

        return $capabilities;
    }
}
