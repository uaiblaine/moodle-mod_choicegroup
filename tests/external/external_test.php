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
 * Tests for the web services of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The web services enforce what view.php enforces, and read only what the caller may read.
 *
 * Every call goes through call_external_function(), as a web service request does, so a PHP warning or an
 * exception surfaces as the response's error.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_choicegroup_external::class)]
final class external_test extends \advanced_testcase {
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
     * Create a course with two offered groups, two students and a teacher.
     *
     * @param array $settings Instance settings to override
     * @return \stdClass course, module, choicegroup, g1, g2, s1, s2, teacher, option (group id => option id)
     */
    private function create_setup(array $settings = []): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $module = $generator->create_module(
            'choicegroup',
            array_merge(['course' => $course->id, 'groups' => [$g1->id, $g2->id], 'allowupdate' => 1], $settings)
        );
        $choicegroup = choicegroup_get_choicegroup($module->id);

        return (object)[
            'course' => $course,
            'module' => $module,
            'choicegroup' => $choicegroup,
            'g1' => $g1,
            'g2' => $g2,
            's1' => $generator->create_and_enrol($course, 'student'),
            's2' => $generator->create_and_enrol($course, 'student'),
            'teacher' => $generator->create_and_enrol($course, 'teacher'),
            'option' => array_map('intval', array_flip($choicegroup->option)),
        ];
    }

    /**
     * Call a web service as a request does.
     *
     * @param string $function The function name without the component prefix
     * @param array $args The arguments
     * @return array The response: error (bool), exception (object) or data
     */
    private function call(string $function, array $args): array {
        $_POST['sesskey'] = sesskey();

        return external_api::call_external_function('mod_choicegroup_' . $function, $args);
    }

    /**
     * Another user's choice is read only by a caller who may read the responses.
     *
     * @return void
     */
    public function test_options_of_another_user_need_readresponses(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        groups_add_member($a->g1->id, $a->s2->id);

        $this->setUser($a->s1);
        // Control: the caller's own options are returned.
        $own = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s1->id]);
        $this->assertFalse($own['error']);
        $this->assertFalse($own['data']['options'][0]['checked']);

        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s2->id]);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);

        $this->setUser($a->teacher);
        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s2->id]);
        $this->assertFalse($response['error']);
        $checked = array_column($response['data']['options'], 'checked', 'groupid');
        $this->assertTrue($checked[$a->g1->id]);
    }

    /**
     * The readresponses capability does not reach a user who is not enrolled in the course.
     *
     * @return void
     */
    public function test_options_of_a_user_who_is_not_enrolled_are_refused(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $outsider = $this->getDataGenerator()->create_user();

        $this->setUser($a->teacher);
        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $outsider->id]);
        $this->assertTrue($response['error']);
        $this->assertSame('notenrolledchoose', $response['exception']->errorcode);

        // Control: an enrolled student is read by the same caller.
        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s1->id]);
        $this->assertFalse($response['error']);
    }

    /**
     * Under separate groups a caller without accessallgroups reads only users who share one of their groups.
     *
     * @return void
     */
    public function test_options_under_separate_groups_need_a_shared_group(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $DB->update_record('course', (object)['id' => $a->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $helper = $this->getDataGenerator()->create_and_enrol($a->course, 'teacher');
        groups_add_member($a->g1->id, $helper->id);
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g2->id, $a->s2->id);

        $this->setUser($helper);
        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s2->id]);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);

        // Control: a student of the caller's own group is read.
        $response = $this->call('get_choicegroup_options', ['choicegroupid' => $a->module->id, 'userid' => $a->s1->id]);
        $this->assertFalse($response['error']);
    }

    /**
     * A caller with the capability but no enrolment cannot choose through the web service.
     *
     * @return void
     */
    public function test_submitting_requires_enrolment(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $data = [['name' => 'responses', 'value' => (string)$a->option[$a->g1->id]]];

        $this->setAdminUser();
        $response = $this->call('submit_choicegroup_response', ['choicegroupid' => $a->module->id, 'data' => $data]);
        $this->assertTrue($response['error']);
        $this->assertSame('notenrolledchoose', $response['exception']->errorcode);
        $this->assertFalse(groups_is_member($a->g1->id, get_admin()->id));

        // Control: an enrolled student submits the same data.
        $this->setUser($a->s1);
        $response = $this->call('submit_choicegroup_response', ['choicegroupid' => $a->module->id, 'data' => $data]);
        $this->assertFalse($response['error']);
        $this->assertTrue(groups_is_member($a->g1->id, $a->s1->id));
    }

    /**
     * The minimum number of groups holds for the web service as for the form.
     *
     * @return void
     */
    public function test_submitting_respects_the_minimum(): void {
        $this->resetAfterTest();
        $a = $this->create_setup(['multipleenrollmentspossible' => 1, 'minenrollments' => 2]);
        $this->setUser($a->s1);

        $one = [['name' => 'responses_' . $a->option[$a->g1->id], 'value' => 'true']];
        $response = $this->call('submit_choicegroup_response', ['choicegroupid' => $a->module->id, 'data' => $one]);
        $this->assertTrue($response['error']);
        $this->assertSame('mustchoosemin', $response['exception']->errorcode);
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));

        // Control: two groups are accepted.
        $two = array_merge($one, [['name' => 'responses_' . $a->option[$a->g2->id], 'value' => 'true']]);
        $response = $this->call('submit_choicegroup_response', ['choicegroupid' => $a->module->id, 'data' => $two]);
        $this->assertFalse($response['error']);
        $this->assertTrue(groups_is_member($a->g2->id, $a->s1->id));
    }

    /**
     * A submission or a deletion after closing reports the closing date, not a PHP warning.
     *
     * @return void
     */
    public function test_a_closed_activity_reports_its_closing_date(): void {
        global $DB;

        $this->resetAfterTest();
        $a = $this->create_setup();
        $this->setUser($a->s1);

        // Control: while open, the deletion of an empty answer succeeds.
        $response = $this->call('delete_choicegroup_responses', ['choicegroupid' => $a->module->id]);
        $this->assertFalse($response['error']);

        $DB->set_field('choicegroup', 'timeclose', time() - 60, ['id' => $a->module->id]);
        $data = [['name' => 'responses', 'value' => (string)$a->option[$a->g1->id]]];

        $response = $this->call('submit_choicegroup_response', ['choicegroupid' => $a->module->id, 'data' => $data]);
        $this->assertTrue($response['error']);
        $this->assertSame('expired', $response['exception']->errorcode);

        $response = $this->call('delete_choicegroup_responses', ['choicegroupid' => $a->module->id]);
        $this->assertTrue($response['error']);
        $this->assertSame('expired', $response['exception']->errorcode);
    }

    /**
     * The deletion web service removes the caller's own choice.
     *
     * @return void
     */
    public function test_deleting_removes_the_callers_choice(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        groups_add_member($a->g1->id, $a->s1->id);
        groups_add_member($a->g2->id, $a->s2->id);

        $this->setUser($a->s1);
        $response = $this->call('delete_choicegroup_responses', ['choicegroupid' => $a->module->id]);

        $this->assertFalse($response['error']);
        $this->assertTrue($response['data']['status']);
        $this->assertFalse(groups_is_member($a->g1->id, $a->s1->id));
        $this->assertTrue(groups_is_member($a->g2->id, $a->s2->id));
    }

    /**
     * The view web service logs the view.
     *
     * @return void
     */
    public function test_viewing_logs_the_view(): void {
        $this->resetAfterTest();
        $a = $this->create_setup();
        $this->setUser($a->s1);

        $sink = $this->redirectEvents();
        $response = $this->call('view_choicegroup', ['choicegroupid' => $a->module->id]);
        $events = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_choicegroup\event\course_module_viewed
        );
        $sink->close();

        $this->assertFalse($response['error']);
        $this->assertCount(1, $events);
    }
}
