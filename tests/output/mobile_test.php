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
 * Tests for the Moodle App view of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup\output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The app shows the member counts only where showresults lets the student see them.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mobile::class)]
final class mobile_test extends \advanced_testcase {
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
     * Both app templates, the current and the Ionic 5 one.
     *
     * @return array App version codes
     */
    public static function app_versions(): array {
        return [
            'latest' => [44000],
            'ionic5' => [43000],
        ];
    }

    /**
     * The count column is left out when the results are hidden, and shown when they are always visible.
     *
     * @param int $appversioncode The app version
     * @return void
     */
    #[DataProvider('app_versions')]
    public function test_the_count_column_follows_showresults(int $appversioncode): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => [$group->id],
            'showresults' => CHOICEGROUP_SHOWRESULTS_NOT,
        ]);
        $member = $generator->create_and_enrol($course, 'student');
        groups_add_member($group->id, $member->id);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $args = ['cmid' => $module->cmid, 'courseid' => $course->id, 'appversioncode' => $appversioncode];

        $html = mobile::mobile_course_view($args)['templates'][0]['html'];
        $this->assertStringNotContainsString('plugin.mod_choicegroup.members/', $html);

        // Control: with the results always shown the column is there.
        $DB->set_field('choicegroup', 'showresults', CHOICEGROUP_SHOWRESULTS_ALWAYS, ['id' => $module->id]);
        choicegroup_purge_request_cache();
        $html = mobile::mobile_course_view($args)['templates'][0]['html'];
        $this->assertStringContainsString('plugin.mod_choicegroup.members/', $html);
    }
}
