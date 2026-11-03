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
 * Group choice data generator class
 *
 * @package    mod_choicegroup
 * @category   test
 * @copyright  2025 Luca Bösch <luca.boesch@bfh.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_choicegroup_generator extends testing_module_generator {
    /**
     * Create a new instance of the Group choice activity.
     *
     * The options come from `groups` (an array of group ids, serialised as the settings form does) or from
     * `serializedselectedgroups` itself; a limit per group goes in `group_<id>_limit`, as the form posts it.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;

        if (isset($record->groups)) {
            $record->serializedselectedgroups = implode(';', (array)$record->groups);
            unset($record->groups);
        } else if (!isset($record->serializedselectedgroups)) {
            $record->serializedselectedgroups = implode(';', []);
        }

        return parent::create_instance($record, $options);
    }
}
