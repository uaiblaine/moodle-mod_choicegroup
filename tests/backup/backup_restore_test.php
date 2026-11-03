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
 * Tests for the backup and restore of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup\backup;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A restored instance keeps its settings and offers the target course's groups.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\backup_choicegroup_activity_structure_step::class)]
#[CoversClass(\restore_choicegroup_activity_structure_step::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Load the backup and restore libraries.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/choicegroup/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create a course with two groups and an instance offering them, with every setting away from its default.
     *
     * @return array The course, the two groups and the instance
     */
    private function create_source(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $g1 = $generator->create_group(['courseid' => $course->id, 'name' => 'Alpha']);
        $g2 = $generator->create_group(['courseid' => $course->id, 'name' => 'Beta']);
        $module = $generator->create_module('choicegroup', [
            'course' => $course->id,
            'groups' => [$g1->id, $g2->id],
            'multipleenrollmentspossible' => 1,
            'minenrollments' => 1,
            'maxenrollments' => 2,
            'defaultgroupdescriptionstate' => 1,
        ]);

        return [$course, $g1, $g2, $module];
    }

    /**
     * A course restored as a new course offers the new course's copies of the groups, with every setting kept.
     *
     * @return void
     */
    public function test_a_restored_course_offers_its_own_groups(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $g1, $g2] = $this->create_source();

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_record('choicegroup', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals(1, $restored->minenrollments);
        $this->assertEquals(2, $restored->maxenrollments);
        $this->assertEquals(1, $restored->defaultgroupdescriptionstate);

        $groupids = $DB->get_fieldset_select('choicegroup_options', 'groupid', 'choicegroupid = ?', [$restored->id]);
        $names = $DB->get_fieldset_select('groups', 'name', 'courseid = ? ORDER BY name', [$newcourseid]);
        $this->assertSame(['Alpha', 'Beta'], $names);
        $this->assertCount(2, $groupids);
        foreach ($groupids as $groupid) {
            $this->assertEquals($newcourseid, $DB->get_field('groups', 'courseid', ['id' => $groupid]));
            $this->assertNotContains((int)$groupid, [(int)$g1->id, (int)$g2->id]);
        }
    }

    /**
     * An activity duplicated within its course, whose backup carries no groups, keeps offering the same groups.
     *
     * @return void
     */
    public function test_a_duplicated_activity_keeps_the_course_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $g1, $g2, $module] = $this->create_source();

        $newcm = \core_courseformat\formatactions::cm($course->id)->duplicate((int) $module->cmid);

        $groupids = $DB->get_fieldset_select('choicegroup_options', 'groupid', 'choicegroupid = ?', [$newcm->instance]);
        $groupids = array_map('intval', $groupids);
        sort($groupids);
        $this->assertSame([(int)$g1->id, (int)$g2->id], $groupids);
        $this->assertEquals(2, $DB->get_field('choicegroup', 'maxenrollments', ['id' => $newcm->instance]));
    }
}
