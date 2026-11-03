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
 * A lock factory that grants every lock and records whether a transaction was open when each was released.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Grants every lock, and records each release with the transaction state at that moment.
 *
 * A lock released while the transaction that wrote under it is still open lets another request count before the
 * commit; $CFG->lock_factory selects this class to show when the release happens.
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_choicegroup_recording_lock_factory implements \core\lock\lock_factory {
    /** @var array Each release: the resource key and whether a transaction was open, in order */
    public static $releases = [];

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
     * No lock outlives the process.
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
     * Grant the lock.
     *
     * @param string $resource The resource
     * @param int $timeout The timeout
     * @param int $maxlifetime The maximum lifetime
     * @return \core\lock\lock
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        return new \core\lock\lock($resource, $this);
    }

    /**
     * Record the release.
     *
     * @param \core\lock\lock $lock The lock
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        global $DB;

        self::$releases[] = ['key' => $lock->get_key(), 'intransaction' => $DB->is_transaction_started()];

        return true;
    }
}
