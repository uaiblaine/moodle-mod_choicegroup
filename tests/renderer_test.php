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
 * Tests for the renderer of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The options form lists members as core's visibility allows, escaped, and the results render in every layout.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_choicegroup_renderer::class)]
final class renderer_test extends \advanced_testcase {
    /**
     * Load the library under test.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choicegroup/lib.php');
        require_once($CFG->dirroot . '/mod/choicegroup/renderer.php');
        parent::setUpBeforeClass();
    }

    /**
     * Render the options form of an instance as a student would see it.
     *
     * @param \stdClass $module The instance from the generator
     * @param \stdClass $viewer The viewing user
     * @return string The HTML
     */
    private function render_options(\stdClass $module, \stdClass $viewer): string {
        global $PAGE;

        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_id('choicegroup', $module->cmid);
        $PAGE->set_url('/mod/choicegroup/view.php', ['id' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $GLOBALS['choicegroupgroups'] = choicegroup_get_groups($choicegroup);
        $allresponses = choicegroup_get_response_data($choicegroup, $cm, 0, false);
        $options = choicegroup_prepare_options($choicegroup, $viewer, $cm, $allresponses);

        return $PAGE->get_renderer('mod_choicegroup')->display_options(
            $options,
            $cm->id,
            $choicegroup->display,
            CHOICEGROUP_PUBLISH_NAMES,
            false,
            CHOICEGROUP_SHOWRESULTS_ALWAYS,
            false,
            true,
            false,
            0,
            0,
            CHOICEGROUP_GROUPDESCRIPTIONSTATE_VISIBLE
        );
    }

    /**
     * Member names are escaped and hidden where core hides the group's membership; descriptions keep their format.
     *
     * @return void
     */
    public function test_member_names_are_escaped_and_follow_visibility(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $open = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Open group',
            'description' => '<i>plain</i>',
            'descriptionformat' => FORMAT_PLAIN,
        ]);
        $hidden = $generator->create_group([
            'courseid' => $course->id,
            'name' => 'Hidden group',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        $viewer = $generator->create_and_enrol($course, 'student');
        $visible = $generator->create_and_enrol($course, 'student', ['firstname' => 'Tom & Jerry', 'lastname' => 'Visible']);
        $secret = $generator->create_and_enrol($course, 'student', ['firstname' => 'Secret', 'lastname' => 'Member']);
        groups_add_member($open->id, $visible->id);
        groups_add_member($hidden->id, $secret->id);
        $module = $generator->create_module('choicegroup', ['course' => $course->id, 'groups' => [$open->id, $hidden->id]]);

        $this->setUser($viewer);
        $html = $this->render_options($module, $viewer);

        // Control: the open group's member is listed, escaped (core strips tags from names on creation, so an
        // ampersand is what reaches the output raw).
        $this->assertStringContainsString('Tom &amp; Jerry Visible', $html);
        $this->assertStringNotContainsString('Tom & Jerry', $html);
        $this->assertStringNotContainsString('Secret Member', $html);
        // A plain-text description is shown as text.
        $this->assertStringContainsString('&lt;i&gt;plain&lt;/i&gt;', $html);

        // A member of the hidden group sees its members.
        groups_add_member($hidden->id, $viewer->id);
        choicegroup_purge_request_cache();
        $this->assertStringContainsString('Secret Member', $this->render_options($module, $viewer));
    }

    /**
     * Anonymous results in the vertical layout render, through the horizontal display.
     *
     * @return void
     */
    public function test_anonymous_vertical_results_render(): void {
        global $PAGE;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        groups_add_member($group->id, $student->id);
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => [$group->id],
            'publish' => CHOICEGROUP_PUBLISH_ANONYMOUS,
            'display' => CHOICEGROUP_DISPLAY_VERTICAL,
        ]);
        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_id('choicegroup', $module->cmid);
        $context = \context_module::instance($cm->id);
        $this->setAdminUser();
        $PAGE->set_url('/mod/choicegroup/report.php', ['id' => $cm->id]);
        $PAGE->set_context($context);

        $allresponses = choicegroup_get_response_data($choicegroup, $cm, 0, false);
        $results = prepare_choicegroup_show_results($choicegroup, $course, $cm, $allresponses);
        $html = $PAGE->get_renderer('mod_choicegroup')->display_result($results, false);

        $this->assertStringContainsString('results anonymous', $html);
    }

    /**
     * The card page renders the first page only, and carries the viewer's choices beyond it as hidden inputs.
     *
     * @return void
     */
    public function test_card_page_keeps_choices_beyond_the_first_page(): void {
        global $PAGE;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $groups = [];
        foreach (range(1, 12) as $i) {
            $groups[] = $generator->create_group(['courseid' => $course->id, 'name' => sprintf('Group %02d', $i)]);
        }
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => array_map(fn($group) => $group->id, $groups),
            'selectionlayout' => \mod_choicegroup\local\selection::LAYOUT_CARDS,
            'cardsperpage' => 10,
            'multipleenrollmentspossible' => 1,
            'allowupdate' => 1,
            'sortgroupsby' => CHOICEGROUP_SORTGROUPS_NAME,
        ]);
        $student = $generator->create_and_enrol($course, 'student');
        groups_add_member($groups[0]->id, $student->id);
        groups_add_member($groups[11]->id, $student->id);
        $this->setUser($student);

        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_id('choicegroup', $module->cmid);
        $PAGE->set_url('/mod/choicegroup/view.php', ['id' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $html = $PAGE->get_renderer('mod_choicegroup')->display_selection_cards($choicegroup, $cm);

        $this->assertSame(10, substr_count($html, 'data-region="card"'));
        $this->assertStringContainsString('Group 01', $html);
        $this->assertStringNotContainsString('Group 12', $html);
        $lastoption = (int)array_search($groups[11]->id, $choicegroup->option);
        $firstoption = (int)array_search($groups[0]->id, $choicegroup->option);
        $this->assertStringContainsString('<input type="hidden" name="answers[]" value="' . $lastoption . '">', $html);
        $this->assertStringNotContainsString('<input type="hidden" name="answers[]" value="' . $firstoption . '">', $html);
        $this->assertMatchesRegularExpression('/id="choiceid_' . $firstoption . '"[^>]* checked/', $html);
        $this->assertStringContainsString('Showing 10 of 12 groups', $html);
        $this->assertStringContainsString('2 selected', $html);
        $this->assertStringContainsString('data-region="chips"', $html);
        // Under PHPUnit no footer prints the AMD calls: read them from the requirements manager.
        $amd = implode("\n", (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires));
        $this->assertStringContainsString('mod_choicegroup/selection', $amd);
    }

    /**
     * The results show to a reader of the responses who may not download them, in the instance's own context.
     *
     * @return void
     */
    public function test_results_follow_readresponses_in_the_instance_context(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student', ['firstname' => 'Listed', 'lastname' => 'Member']);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        groups_add_member($group->id, $student->id);
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => [$group->id],
            'publish' => CHOICEGROUP_PUBLISH_NAMES,
        ]);
        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_id('choicegroup', $module->cmid);
        $context = \context_module::instance($cm->id);
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('mod/choicegroup:downloadresponses', CAP_PROHIBIT, $teacherrole, $context->id);
        $this->setUser($teacher);
        $PAGE->set_url('/mod/choicegroup/report.php', ['id' => $cm->id]);
        $PAGE->set_context($context);
        // A global $context left by another page must not decide.
        $GLOBALS['context'] = \context_system::instance();

        $allresponses = choicegroup_get_response_data($choicegroup, $cm, 0, false);
        $results = prepare_choicegroup_show_results($choicegroup, $course, $cm, $allresponses);
        $renderer = $PAGE->get_renderer('mod_choicegroup');
        $this->assertStringContainsString('Listed Member', (string)$renderer->display_publish_name_vertical($results));
        $this->assertStringContainsString('results anonymous', (string)$renderer->display_publish_anonymous_horizontal($results));

        // Control: without readresponses nothing shows.
        assign_capability('mod/choicegroup:readresponses', CAP_PROHIBIT, $teacherrole, $context->id);
        $this->assertSame('', (string)$renderer->display_publish_name_vertical($results));
        $this->assertSame('', (string)$renderer->display_publish_anonymous_horizontal($results));
    }
}
