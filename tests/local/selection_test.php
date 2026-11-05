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

namespace mod_choicegroup\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The card layout: search, filters, sorting, and what each card may show to whom.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(selection::class)]
final class selection_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass[] The groups, by name. */
    private array $groups = [];

    /**
     * Load the library under test.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choicegroup/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * A course with groups named after their shift, two of them full at a limit of two.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        foreach (['Morning A', 'Morning B', 'Evening A', 'Evening B', 'Night A'] as $name) {
            $this->groups[$name] = $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => $name]);
        }
    }

    /**
     * Create an instance offering every group, with a limit of two each unless the record says otherwise.
     *
     * @param array $record Instance fields
     * @return \stdClass The instance, as choicegroup_get_choicegroup() returns it, with the course module in ->cm
     */
    private function create_instance(array $record = []): \stdClass {
        $record += [
            'course' => $this->course->id,
            'groups' => array_map(fn($group) => $group->id, array_values($this->groups)),
            'selectionlayout' => selection::LAYOUT_CARDS,
            'showresults' => CHOICEGROUP_SHOWRESULTS_ALWAYS,
            'publish' => CHOICEGROUP_PUBLISH_NAMES,
            'limitanswers' => 1,
        ];
        foreach ($this->groups as $group) {
            $record['group_' . $group->id . '_limit'] = $record['group_' . $group->id . '_limit'] ?? 2;
        }

        $module = $this->getDataGenerator()->create_module('choicegroup', $record);
        $choicegroup = choicegroup_get_choicegroup($module->id);
        $choicegroup->cm = get_coursemodule_from_id('choicegroup', $module->cmid);

        return $choicegroup;
    }

    /**
     * Enrol a student and add them to groups.
     *
     * @param string[] $groupnames Groups to join
     * @return \stdClass The user
     */
    private function create_member(array $groupnames = []): \stdClass {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        foreach ($groupnames as $name) {
            groups_add_member($this->groups[$name]->id, $user->id);
        }

        return $user;
    }

    /**
     * A group custom field, its values set per group name.
     *
     * @param string $shortname Field shortname
     * @param string $type Field type
     * @param array $values Values by group name
     * @param array $configdata Field configuration
     * @return void
     */
    private function create_field(string $shortname, string $type, array $values, array $configdata = []): void {
        /** @var \core_customfield_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $generator->create_category(['component' => 'core_group', 'area' => 'group']);
        $field = $generator->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
            'type' => $type,
            'configdata' => $configdata,
        ]);
        foreach ($values as $name => $value) {
            $generator->add_instance_data($field, (int)$this->groups[$name]->id, $value);
        }
    }

    /**
     * The names on the cards of a query.
     *
     * @param array $page What query() returned
     * @return string[]
     */
    private static function names(array $page): array {
        return array_column($page['cards'], 'name');
    }

    /**
     * The search matches part of the name in any case, and pages through the matches.
     *
     * @return void
     */
    public function test_search_matches_names_and_pages(): void {
        $choicegroup = $this->create_instance(['sortgroupsby' => CHOICEGROUP_SORTGROUPS_NAME]);
        $selection = new selection($choicegroup, $choicegroup->cm, (int)$this->create_member()->id);

        $page = $selection->query('MORNING', [], false, 'name', 0, 25);
        $this->assertSame(2, $page['total']);
        $this->assertSame(['Morning A', 'Morning B'], self::names($page));

        $page = $selection->query('', [], false, 'name', 2, 2);
        $this->assertSame(5, $page['total']);
        $this->assertSame(['Morning A', 'Morning B'], self::names($page));
    }

    /**
     * A filter compares the field value, and a field the activity did not make a filter is ignored.
     *
     * @return void
     */
    public function test_filters_only_from_fields_placed_as_filters(): void {
        $this->create_field('shift', 'select', ['Morning A' => 1, 'Morning B' => 1, 'Evening A' => 2], [
            'options' => "Morning\nEvening",
        ]);
        $this->create_field('campus', 'text', ['Morning A' => 'North', 'Evening A' => 'South']);
        $choicegroup = $this->create_instance([
            'cardfields' => json_encode(['shift' => selection::FIELD_FILTER, 'campus' => selection::FIELD_CARD]),
        ]);
        $selection = new selection($choicegroup, $choicegroup->cm, (int)$this->create_member()->id);

        $filters = $selection->get_filters();
        $this->assertCount(1, $filters);
        $this->assertSame('shift', $filters[0]['shortname']);
        $this->assertSame(
            [['value' => '1', 'label' => 'Morning'], ['value' => '2', 'label' => 'Evening']],
            $filters[0]['options']
        );

        $page = $selection->query('', ['shift' => '2'], false, 'name', 0, 25);
        $this->assertSame(['Evening A'], self::names($page));

        // The campus is on the cards but is not a filter: filtering by it would reveal nothing it should not, but the
        // promise is that only the chosen fields filter.
        $page = $selection->query('', ['campus' => 'North'], false, 'name', 0, 25);
        $this->assertSame(5, $page['total']);
    }

    /**
     * Only groups with a free seat survive the free filter, and the free sort exists only when counts are visible.
     *
     * @return void
     */
    public function test_free_filter_and_free_sort(): void {
        $choicegroup = $this->create_instance(['sortgroupsby' => CHOICEGROUP_SORTGROUPS_NAME]);
        $this->create_member(['Morning A']);
        $this->create_member(['Morning A', 'Evening A']);
        $selection = new selection($choicegroup, $choicegroup->cm, (int)$this->create_member()->id);

        $this->assertTrue($selection->has_free_filter());
        $page = $selection->query('', [], true, 'name', 0, 25);
        $this->assertNotContains('Morning A', self::names($page));
        $this->assertSame(4, $page['total']);
        $this->assertContains('free', $selection->get_sort_keys());
        $this->assertSame('Morning A', self::names($selection->query('', [], false, 'free', 0, 25))[4]);

        // With the results hidden, sorting by free seats would rank the groups by a count the viewer may not see.
        $hidden = $this->create_instance(['showresults' => CHOICEGROUP_SHOWRESULTS_NOT]);
        $selection = new selection($hidden, $hidden->cm, (int)$this->create_member()->id);
        $this->assertNotContains('free', $selection->get_sort_keys());
        $this->assertSame(
            self::names($selection->query('', [], false, 'default', 0, 25)),
            self::names($selection->query('', [], false, 'free', 0, 25))
        );

        $unlimited = $this->create_instance(['limitanswers' => 0]);
        $selection = new selection($unlimited, $unlimited->cm, (int)$this->create_member()->id);
        $this->assertFalse($selection->has_free_filter());
        $this->assertSame(5, $selection->query('', [], true, 'default', 0, 25)['total']);
    }

    /**
     * Seat counts show only when showresults lets the viewer see them; a full group is still called full.
     *
     * @return void
     */
    public function test_seat_counts_follow_showresults(): void {
        $this->create_member(['Morning A']);
        $this->create_member(['Morning A']);
        $visible = $this->create_instance();
        $hidden = $this->create_instance(['showresults' => CHOICEGROUP_SHOWRESULTS_NOT]);
        $viewer = $this->create_member();
        $optionid = (int)array_search($this->groups['Morning A']->id, $visible->option);

        $card = (new selection($visible, $visible->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame('2 of 2 seats taken', $card['seats']['text']);
        $this->assertTrue($card['seats']['full']);
        $this->assertTrue($card['disabled']);
        $this->assertFalse($card['canpick']);

        $optionid = (int)array_search($this->groups['Morning A']->id, $hidden->option);
        $card = (new selection($hidden, $hidden->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame('', $card['seats']['text']);
        $this->assertSame('Full', $card['seats']['status']);
        $this->assertNull($card['details']['members']);

        $optionid = (int)array_search($this->groups['Morning B']->id, $hidden->option);
        $card = (new selection($hidden, $hidden->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame('Seats available', $card['seats']['status']);
        $this->assertFalse($card['disabled']);
    }

    /**
     * Member names need publish = names, and the group's own visibility can withhold them.
     *
     * @return void
     */
    public function test_member_names_follow_publish_and_group_visibility(): void {
        $member = $this->create_member(['Evening A']);
        $viewer = $this->create_member();
        $named = $this->create_instance();
        $anonymous = $this->create_instance(['publish' => CHOICEGROUP_PUBLISH_ANONYMOUS]);
        $optionid = (int)array_search($this->groups['Evening A']->id, $named->option);
        $this->setUser($viewer);

        $card = (new selection($named, $named->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame([['name' => fullname($member)]], $card['details']['members']['names']);
        $this->assertFalse($card['details']['members']['hidden']);

        $optionid = (int)array_search($this->groups['Evening A']->id, $anonymous->option);
        $card = (new selection($anonymous, $anonymous->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertNull($card['details']['members']);

        // Core fixes a group's visibility once it has members, so this group is created hidden.
        $this->groups['Hidden'] = $this->getDataGenerator()->create_group([
            'courseid' => $this->course->id,
            'name' => 'Hidden',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        groups_add_member($this->groups['Hidden']->id, $member->id);
        $hidden = $this->create_instance();
        $optionid = (int)array_search($this->groups['Hidden']->id, $hidden->option);
        $card = (new selection($hidden, $hidden->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame([], $card['details']['members']['names']);
        $this->assertTrue($card['details']['members']['hidden']);
    }

    /**
     * A field value shows only where the activity placed it, and a textarea never becomes a tag.
     *
     * @return void
     */
    public function test_field_values_show_only_where_placed(): void {
        $this->create_field('campus', 'text', ['Night A' => 'North & South']);
        $this->create_field('notes', 'textarea', ['Night A' => ['text' => 'Bring a laptop', 'format' => FORMAT_HTML]]);
        $this->create_field('room', 'text', ['Night A' => 'B12']);
        $choicegroup = $this->create_instance([
            'cardfields' => json_encode([
                'campus' => selection::FIELD_CARD,
                'notes' => selection::FIELD_CARD | selection::FIELD_MODAL,
            ]),
        ]);
        $optionid = (int)array_search($this->groups['Night A']->id, $choicegroup->option);
        $card = (new selection($choicegroup, $choicegroup->cm, (int)$this->create_member()->id))->export_card($optionid);

        // Plain text for a double stash: the ampersand is escaped once, by the template.
        $this->assertSame([['label' => 'Campus', 'value' => 'North & South']], $card['tags']);
        $this->assertCount(1, $card['details']['fields']);
        $this->assertSame('Notes', $card['details']['fields'][0]['label']);
        $this->assertStringContainsString('Bring a laptop', $card['details']['fields'][0]['value']);
    }

    /**
     * Without allowupdate a stored choice locks every card; a closed activity locks them too.
     *
     * @return void
     */
    public function test_cards_lock_when_the_viewer_may_not_choose(): void {
        $viewer = $this->create_member(['Night A']);
        $locked = $this->create_instance(['allowupdate' => 0]);
        $selection = new selection($locked, $locked->cm, (int)$viewer->id);
        $this->assertFalse($selection->can_choose());
        $this->assertSame([(int)array_search($this->groups['Night A']->id, $locked->option)], $selection->get_chosen());
        foreach ($selection->query('', [], false, 'default', 0, 25)['cards'] as $card) {
            $this->assertTrue($card['disabled']);
        }

        $open = $this->create_instance(['allowupdate' => 1]);
        $this->assertTrue((new selection($open, $open->cm, (int)$viewer->id))->can_choose());

        $closed = $this->create_instance([
            'allowupdate' => 1,
            'timerestrict' => 1,
            'timeopen' => time() - 2 * DAYSECS,
            'timeclose' => time() - DAYSECS,
        ]);
        $this->assertFalse((new selection($closed, $closed->cm, (int)$viewer->id))->can_choose());

        $outsider = $this->getDataGenerator()->create_user();
        $this->assertFalse((new selection($open, $open->cm, (int)$outsider->id))->can_choose());
    }

    /**
     * The settings form's checkboxes serialise by shortname, keep only placements the type accepts, and parse back.
     *
     * @return void
     */
    public function test_card_fields_round_trip(): void {
        $this->create_field('shift', 'select', [], ['options' => "Morning\nEvening"]);
        $this->create_field('notes', 'textarea', []);
        $fields = selection::get_site_fields();
        $data = (object)[
            'cf_' . $fields['shift']->get('id') . '_filter' => 1,
            'cf_' . $fields['shift']->get('id') . '_modal' => 1,
            'cf_' . $fields['notes']->get('id') . '_filter' => 1,
        ];

        $json = selection::serialise_card_fields($data);
        $this->assertSame(['shift' => selection::FIELD_FILTER | selection::FIELD_MODAL], selection::parse_card_fields($json));
        $this->assertSame('{}', selection::serialise_card_fields((object)[]));
        $this->assertSame([], selection::parse_card_fields('not json'));
        $this->assertSame(['a' => 1], selection::parse_card_fields('{"a": 9, "b": "x"}'));
    }

    /**
     * The automatic search shows from ten options on; the free filter needs its own setting too.
     *
     * @return void
     */
    public function test_search_mode_and_full_activity(): void {
        $choicegroup = $this->create_instance();
        $viewer = $this->create_member();
        $this->assertFalse((new selection($choicegroup, $choicegroup->cm, (int)$viewer->id))->has_search());

        $choicegroup->searchmode = selection::SEARCH_ALWAYS;
        $choicegroup->filterfreeseats = 0;
        $selection = new selection($choicegroup, $choicegroup->cm, (int)$viewer->id);
        $this->assertTrue($selection->has_search());
        $this->assertFalse($selection->has_free_filter());
        $this->assertFalse($selection->is_all_full());

        $choicegroup->maxanswers = array_map(fn() => 0, $choicegroup->maxanswers);
        $this->assertTrue((new selection($choicegroup, $choicegroup->cm, (int)$viewer->id))->is_all_full());
    }

    /**
     * "After close" waits for the closing date: before the opening date the activity is not closed, and shows nothing.
     *
     * @return void
     */
    public function test_after_close_results_wait_for_the_closing_date(): void {
        $this->create_member(['Morning A']);
        $viewer = $this->create_member();
        $early = $this->create_instance([
            'showresults' => CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE,
            'timerestrict' => 1,
            'timeopen' => time() + DAYSECS,
            'timeclose' => time() + 2 * DAYSECS,
        ]);
        $selection = new selection($early, $early->cm, (int)$viewer->id);
        $this->assertTrue($selection->is_not_open_yet());
        $this->assertNotContains('free', $selection->get_sort_keys());
        $this->assertSame(['total' => 0, 'cards' => []], $selection->query('', [], false, 'default', 0, 25));
        $optionid = (int)array_search($this->groups['Morning A']->id, $early->option);
        $card = $selection->export_card($optionid);
        $this->assertSame('', $card['seats']['text']);
        $this->assertNull($card['details']['members']);

        $closed = $this->create_instance([
            'showresults' => CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE,
            'timerestrict' => 1,
            'timeopen' => time() - 2 * DAYSECS,
            'timeclose' => time() - DAYSECS,
        ]);
        $optionid = (int)array_search($this->groups['Morning A']->id, $closed->option);
        $card = (new selection($closed, $closed->cm, (int)$viewer->id))->export_card($optionid);
        $this->assertSame('1 of 2 seats taken', $card['seats']['text']);
        $this->assertNotNull($card['details']['members']);
    }

    /**
     * "After answer" shows the counts once the viewer has chosen, and the low-seats hint follows the counts.
     *
     * @return void
     */
    public function test_after_answer_results_and_the_low_seats_hint(): void {
        $this->create_member(['Morning A']);
        $chooser = $this->create_member(['Night A']);
        $other = $this->create_member();
        $choicegroup = $this->create_instance(['showresults' => CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER]);
        $optionid = (int)array_search($this->groups['Morning A']->id, $choicegroup->option);

        $card = (new selection($choicegroup, $choicegroup->cm, (int)$chooser->id))->export_card($optionid);
        $this->assertSame('1 of 2 seats taken', $card['seats']['text']);
        $this->assertTrue($card['seats']['low']);

        $card = (new selection($choicegroup, $choicegroup->cm, (int)$other->id))->export_card($optionid);
        $this->assertSame('', $card['seats']['text']);
        $this->assertFalse($card['seats']['low']);
        $this->assertSame('Seats available', $card['seats']['status']);
    }

    /**
     * The grouping filter lists only groupings that hold offered groups, and ignores a grouping it does not offer.
     *
     * @return void
     */
    public function test_grouping_filter(): void {
        $evening = $this->getDataGenerator()->create_grouping(['courseid' => $this->course->id, 'name' => 'Evening']);
        $empty = $this->getDataGenerator()->create_grouping(['courseid' => $this->course->id, 'name' => 'Empty']);
        foreach (['Evening A', 'Evening B'] as $name) {
            groups_assign_grouping($evening->id, $this->groups[$name]->id);
        }
        $viewer = $this->create_member();

        $off = $this->create_instance();
        $selection = new selection($off, $off->cm, (int)$viewer->id);
        $this->assertSame([], $selection->get_groupings());
        $this->assertSame(5, $selection->query('', [], false, 'default', 0, 25, (int)$evening->id)['total']);

        $on = $this->create_instance(['filtergrouping' => 1, 'sortgroupsby' => CHOICEGROUP_SORTGROUPS_NAME]);
        $selection = new selection($on, $on->cm, (int)$viewer->id);
        $this->assertSame([['id' => (int)$evening->id, 'name' => 'Evening']], $selection->get_groupings());
        $page = $selection->query('', [], false, 'name', 0, 25, (int)$evening->id);
        $this->assertSame(['Evening A', 'Evening B'], self::names($page));
        $this->assertSame(5, $selection->query('', [], false, 'default', 0, 25, (int)$empty->id)['total']);
        $this->assertSame('Evening A', $selection->get_option_name((int)array_search($this->groups['Evening A']->id, $on->option)));
    }

    /**
     * An opening date ahead keeps the cards shut even without a closing date, as saving the choice would refuse it.
     *
     * @return void
     */
    public function test_an_opening_date_without_a_closing_date_keeps_the_cards_shut(): void {
        global $DB;

        $viewer = $this->create_member();
        $choicegroup = $this->create_instance(['timerestrict' => 1, 'timeopen' => time() + DAYSECS, 'timeclose' => 0]);
        // The form always sets both dates; a restore or a web service edit can leave the closing date empty.
        $DB->set_field('choicegroup', 'timeclose', 0, ['id' => $choicegroup->id]);
        $choicegroup = choicegroup_get_choicegroup($choicegroup->id);
        $choicegroup->cm = get_coursemodule_from_instance('choicegroup', $choicegroup->id);

        $selection = new selection($choicegroup, $choicegroup->cm, (int)$viewer->id);
        $this->assertTrue($selection->is_not_open_yet());
        $this->assertFalse($selection->can_choose());

        // Control: once the opening date has passed the same viewer may choose.
        $DB->set_field('choicegroup', 'timeopen', time() - DAYSECS, ['id' => $choicegroup->id]);
        $choicegroup = choicegroup_get_choicegroup($choicegroup->id);
        $choicegroup->cm = get_coursemodule_from_instance('choicegroup', $choicegroup->id);
        $selection = new selection($choicegroup, $choicegroup->cm, (int)$viewer->id);
        $this->assertFalse($selection->is_not_open_yet());
        $this->assertTrue($selection->can_choose());
    }
}
