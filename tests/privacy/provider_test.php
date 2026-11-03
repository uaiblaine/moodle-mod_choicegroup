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
 * Tests for the privacy provider.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_choicegroup\privacy;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The plugin keeps no personal data of its own: a choice is a core group membership with an empty component.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The plugin counts as compliant.
     *
     * Core's own compliance test sweeps every component but is not in the plugin's testsuite, which is all
     * moodle-plugin-ci runs, so the check is repeated here ({@see \core_privacy\manager::component_is_compliant()}).
     *
     * @return void
     */
    public function test_the_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('mod_choicegroup'));
    }

    /**
     * The null provider's reason is a string of the plugin.
     *
     * @return void
     */
    public function test_the_reason_is_a_string_of_the_plugin(): void {
        $reason = provider::get_reason();

        $this->assertSame('privacy:metadata', $reason);
        $this->assertTrue(get_string_manager()->string_exists($reason, 'mod_choicegroup'));
    }
}
