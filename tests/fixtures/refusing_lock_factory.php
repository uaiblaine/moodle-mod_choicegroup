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
 * A lock factory that never grants a lock, for testing the code path of a lock held elsewhere.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Refuses every lock, as a factory would while another request holds it.
 *
 * The real factories are re-entrant within one process (a PostgreSQL advisory lock taken twice by the same session
 * succeeds), so contention cannot be produced in a single PHPUnit process; $CFG->lock_factory selects this class.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_choicegroup_refusing_lock_factory implements \core\lock\lock_factory {
    /**
     * Create the factory.
     *
     * @param string $type The lock type
     */
    public function __construct($type) {
    }

    /**
     * Timeouts are accepted and ignored.
     *
     * @return bool
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * No lock outlives the process, since none is granted.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * The factory can always be used.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Refuse the lock.
     *
     * @param string $resource The resource
     * @param int $timeout The timeout
     * @param int $maxlifetime The maximum lifetime
     * @return bool Always false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        return false;
    }

    /**
     * Nothing to release.
     *
     * @param \core\lock\lock $lock The lock
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        return true;
    }
}
