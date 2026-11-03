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

namespace mod_choicegroup\external;

use mod_choicegroup\local\selection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The get_cards web service: who may call it and what a page holds.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_cards::class)]
final class get_cards_test extends \advanced_testcase {
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
     * A course with three groups offered by a card-layout instance.
     *
     * @return array The course, the module and the groups
     */
    private function create_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $groups = [];
        foreach (['Alpha & Co', 'Beta', 'Gamma'] as $name) {
            $groups[] = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => $name]);
        }

        $module = $this->getDataGenerator()->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => array_map(fn($group) => $group->id, $groups),
            'selectionlayout' => selection::LAYOUT_CARDS,
            'sortgroupsby' => CHOICEGROUP_SORTGROUPS_NAME,
        ]);

        return [$course, $module, $groups];
    }

    /**
     * Call the service through the external API, as the browser does.
     *
     * @param array $args The arguments
     * @return array The response: error, and data or exception
     */
    private static function call(array $args): array {
        $_POST['sesskey'] = sesskey();
        return \core_external\external_api::call_external_function('mod_choicegroup_get_cards', $args, true);
    }

    /**
     * A student gets one page of rendered cards, the search applied and the total counted.
     *
     * @return void
     */
    public function test_student_gets_a_rendered_page(): void {
        $this->resetAfterTest();
        [$course, $module] = $this->create_fixture();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $response = self::call(['cmid' => $module->cmid, 'sort' => 'name', 'limit' => 2]);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame(3, $response['data']['total']);
        $this->assertCount(2, $response['data']['cards']);
        $this->assertStringContainsString('Alpha &amp; Co', $response['data']['cards'][0]['html']);
        $this->assertStringNotContainsString('&amp;amp;', $response['data']['cards'][0]['html']);

        $response = self::call(['cmid' => $module->cmid, 'query' => 'gam', 'offset' => 0]);
        $this->assertSame(1, $response['data']['total']);
        $this->assertStringContainsString('Gamma', $response['data']['cards'][0]['html']);
    }

    /**
     * A user who cannot see the activity, and a guest, are refused.
     *
     * @return void
     */
    public function test_outsiders_and_guests_are_refused(): void {
        $this->resetAfterTest();
        [, $module] = $this->create_fixture();

        $this->setUser($this->getDataGenerator()->create_user());
        $response = self::call(['cmid' => $module->cmid]);
        $this->assertTrue($response['error']);

        $this->setGuestUser();
        $response = self::call(['cmid' => $module->cmid]);
        $this->assertTrue($response['error']);
    }

    /**
     * An enrolled user without the choose capability and without readresponses is refused.
     *
     * @return void
     */
    public function test_enrolled_user_without_capabilities_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $module] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertFalse(self::call(['cmid' => $module->cmid])['error']);

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/choicegroup:choose', CAP_PROHIBIT, $roleid, \context_module::instance($module->cmid));
        $response = self::call(['cmid' => $module->cmid]);
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
    }

    /**
     * A teacher who cannot choose still loads the cards through readresponses; nothing loads before the opening date.
     *
     * @return void
     */
    public function test_readresponses_suffices_and_nothing_before_opening(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $module] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('mod/choicegroup:choose', CAP_PROHIBIT, $roleid, \context_module::instance($module->cmid));
        $this->setUser($teacher);

        $response = self::call(['cmid' => $module->cmid]);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame(3, $response['data']['total']);

        $DB->update_record('choicegroup', (object)[
            'id' => $module->id,
            'timeopen' => time() + DAYSECS,
            'timeclose' => time() + 2 * DAYSECS,
        ]);
        choicegroup_purge_request_cache();
        $response = self::call(['cmid' => $module->cmid]);
        $this->assertFalse($response['error'], json_encode($response));
        $this->assertSame(0, $response['data']['total']);
        $this->assertSame([], $response['data']['cards']);
    }
}
