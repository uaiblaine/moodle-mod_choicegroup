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
 * Tests for the choice events of mod_choicegroup.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup\event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A choice event names the instance as its object, the affected user and the group.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(choice_updated::class)]
#[CoversClass(choice_removed::class)]
#[CoversClass(course_module_viewed::class)]
final class events_test extends \advanced_testcase {
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
     * Choosing, switching and a teacher's removal each log the affected user and the group.
     *
     * @return void
     */
    public function test_choice_events_name_the_affected_user_and_the_group(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $module = $generator->create_module(
            'choicegroup',
            ['course' => $course->id, 'groups' => [$g1->id, $g2->id], 'allowupdate' => 1]
        );
        $choicegroup = choicegroup_get_choicegroup($module->id);
        $cm = get_coursemodule_from_instance('choicegroup', $module->id);
        $option = array_map('intval', array_flip($choicegroup->option));
        $this->setUser($student);

        $sink = $this->redirectEvents();
        choicegroup_save_user_choices($choicegroup, $student->id, $course, $cm, [$option[$g1->id]]);
        choicegroup_save_user_choices($choicegroup, $student->id, $course, $cm, [$option[$g2->id]]);
        $events = $this->choice_events($sink);
        $sink->clear();

        $this->assertCount(3, $events);
        [$joined1, $joined2, $left1] = $events;
        $this->assertInstanceOf(choice_updated::class, $joined1);
        $this->assertInstanceOf(choice_updated::class, $joined2);
        $this->assertInstanceOf(choice_removed::class, $left1);
        $this->assertSame('choicegroup', $joined1->objecttable);
        $this->assertEquals($module->id, $joined1->objectid);
        $this->assertEquals($student->id, $joined1->relateduserid);
        $this->assertEquals($g1->id, $joined1->other['groupid']);
        $this->assertEquals($g2->id, $joined2->other['groupid']);
        $this->assertEquals($g1->id, $left1->other['groupid']);
        $this->assertStringContainsString("'{$student->id}'", $left1->get_description());
        $this->assertStringContainsString("'{$g1->id}'", $left1->get_description());

        // A teacher's removal is logged as the teacher's action on the student.
        $this->setAdminUser();
        $rowid = $DB->get_field('groups_members', 'id', ['groupid' => $g2->id, 'userid' => $student->id]);
        choicegroup_delete_responses([$rowid], $choicegroup, $cm, $course);
        $events = $this->choice_events($sink);
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(choice_removed::class, $events[0]);
        $this->assertEquals(get_admin()->id, $events[0]->userid);
        $this->assertEquals($student->id, $events[0]->relateduserid);
        $this->assertEquals($g2->id, $events[0]->other['groupid']);
    }

    /**
     * Keep the plugin's choice events of a sink, without core's group membership events.
     *
     * @param \core\test\phpunit\event_sink $sink The sink
     * @return array The choice events, in order
     */
    private function choice_events(\core\test\phpunit\event_sink $sink): array {
        return array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof choice_updated || $event instanceof choice_removed
        ));
    }

    /**
     * Each choice event with one of the two required values missing.
     *
     * @return array Event class, the field left out, and the message that names it
     */
    public static function missing_field_provider(): array {
        $cases = [];
        foreach ([choice_updated::class, choice_removed::class] as $class) {
            $short = substr(strrchr($class, '\\'), 1);
            $cases[$short . ' without the user'] = [$class, 'relateduserid', 'relateduserid'];
            $cases[$short . ' without the group'] = [$class, 'groupid', 'groupid'];
        }

        return $cases;
    }

    /**
     * A choice event without the affected user or the group is refused, each field on its own.
     *
     * @param string $class The event class
     * @param string $missing The field left out
     * @param string $message A word the refusal names
     * @return void
     */
    #[DataProvider('missing_field_provider')]
    public function test_a_choice_event_needs_the_user_and_the_group(string $class, string $missing, string $message): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('choicegroup', ['course' => $course->id]);
        $data = [
            'context' => \context_module::instance($module->cmid),
            'objectid' => $module->id,
            'relateduserid' => 2,
            'other' => ['groupid' => 5],
        ];

        // Control: the complete event is created.
        $event = $class::create($data);
        $this->assertSame(5, $event->other['groupid']);

        if ($missing === 'groupid') {
            $data['other'] = [];
        } else {
            unset($data[$missing]);
        }
        try {
            $class::create($data);
            $this->fail('An event without ' . $missing . ' was created.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * The events map their ids on restore.
     *
     * @return void
     */
    public function test_events_map_their_ids_on_restore(): void {
        $this->assertSame(['db' => 'choicegroup', 'restore' => 'choicegroup'], choice_updated::get_objectid_mapping());
        $this->assertSame(['db' => 'choicegroup', 'restore' => 'choicegroup'], choice_removed::get_objectid_mapping());
        $this->assertSame(['db' => 'choicegroup', 'restore' => 'choicegroup'], course_module_viewed::get_objectid_mapping());
        $this->assertSame(['groupid' => ['db' => 'groups', 'restore' => 'group']], choice_updated::get_other_mapping());
        $this->assertSame(['groupid' => ['db' => 'groups', 'restore' => 'group']], choice_removed::get_other_mapping());
    }
}
