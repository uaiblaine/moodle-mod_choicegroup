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
 * Tests for the activity manager of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The course overview counts users who answered, not memberships.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(manager::class)]
final class manager_test extends \advanced_testcase {
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
     * A student in two groups of a multiple-enrolment instance is one respondent.
     *
     * @return void
     */
    public function test_a_user_in_two_groups_counts_once(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $s1 = $generator->create_and_enrol($course, 'student');
        $s2 = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student');
        $module = $generator->create_module(
            'choicegroup',
            ['course' => $course->id, 'groups' => [$g1->id, $g2->id], 'multipleenrollmentspossible' => 1]
        );
        groups_add_member($g1->id, $s1->id);
        groups_add_member($g2->id, $s1->id);
        groups_add_member($g2->id, $s2->id);

        $this->setAdminUser();
        $manager = manager::create_from_coursemodule(get_coursemodule_from_id('choicegroup', $module->cmid));

        // Three memberships, two users; the third student has not answered.
        $this->assertSame(2, $manager->count_all_users_answered());
    }
}
