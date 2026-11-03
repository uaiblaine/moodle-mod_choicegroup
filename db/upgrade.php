<?php
// This file is part of the Choice group module for Moodle - http://moodle.org/
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
 * Choicegroup upgrade script.
 *
 * @package    mod_choicegroup
 * @copyright  2013 Université de Lausanne
 * @author     Nicolas Dunand <Nicolas.Dunand@unil.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Extra upgrade steps.
 * @param int $oldversion
 * @return bool
 */
function xmldb_choicegroup_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2012042500) {
        // Remove the no longer needed choicegroup_answers DB table.
        $choicegroupanswers = new xmldb_table('choicegroup_answers');
        $dbman->drop_table($choicegroupanswers);

        // Change the choicegroup_options.text (text) field as choicegroup_options.groupid (int).
        $choicegroupoptions = new xmldb_table('choicegroup_options');
        $fieldtext = new xmldb_field('text', XMLDB_TYPE_TEXT, 'small', null, null, null, null, 'choicegroupid');
        $fieldgroupid = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'choicegroupid');

        $dbman->rename_field($choicegroupoptions, $fieldtext, 'groupid');
        $dbman->change_field_type($choicegroupoptions, $fieldgroupid);

        upgrade_mod_savepoint(true, 2012042500, 'choicegroup');
    }

    if ($oldversion < 2013070900) {
        // Define table choicegroup to be created.
        $table = new xmldb_table('choicegroup');

        // Adding fields to table choicegroup.
        $newfield = $table->add_field('multipleenrollmentspossible', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $dbman->add_field($table, $newfield);

        upgrade_mod_savepoint(true, 2013070900, 'choicegroup');
    }

    if ($oldversion < 2015022301) {
        $table = new xmldb_table('choicegroup');

        // Adding field to table choicegroup.
        $newfield = $table->add_field('sortgroupsby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        if (!$dbman->field_exists($table, $newfield)) {
            $dbman->add_field($table, $newfield);
        }

        upgrade_mod_savepoint(true, 2015022301, 'choicegroup');
    }

    if ($oldversion < 2021071400) {
        // Define field maxenrollments to be added to choicegroup.
        $table = new xmldb_table('choicegroup');
        $field = new xmldb_field('maxenrollments', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'sortgroupsby');

        // Conditionally launch add field maxenrollments.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Choicegroup savepoint reached.
        upgrade_mod_savepoint(true, 2021071400, 'choicegroup');
    }

    if ($oldversion < 2021080500) {
        // Define field onlyactive to be added to choicegroup.
        $table = new xmldb_table('choicegroup');
        $field = new xmldb_field('onlyactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sortgroupsby');

        // Conditionally launch add field onlyactive.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Choicegroup savepoint reached.
        upgrade_mod_savepoint(true, 2021080500, 'choicegroup');
    }

    if ($oldversion < 2024092600) {
        // Define field defaultgroupdescriptionstate to be added to choicegroup.
        $table = new xmldb_table('choicegroup');
        $field = new xmldb_field('defaultgroupdescriptionstate', XMLDB_TYPE_INTEGER, '1', null, null, null, '0', 'onlyactive');

        // Conditionally launch add field defaultgroupdescriptionstate.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Choicegroup savepoint reached.
        upgrade_mod_savepoint(true, 2024092600, 'choicegroup');
    }

    if ($oldversion < 2026060401) {
        // Define field minenrollments to be added to choicegroup.
        $table = new xmldb_table('choicegroup');
        $field = new xmldb_field('minenrollments', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'maxenrollments');

        // Conditionally launch add field minenrollments.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Choicegroup savepoint reached.
        upgrade_mod_savepoint(true, 2026060401, 'choicegroup');
    }

    if ($oldversion < 2026110301) {
        // The card layout of the selection page; existing activities keep the table (selectionlayout 0).
        $table = new xmldb_table('choicegroup');
        $fields = [
            new xmldb_field(
                'selectionlayout',
                XMLDB_TYPE_INTEGER,
                '2',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'defaultgroupdescriptionstate'
            ),
            new xmldb_field('cardsperpage', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '25', 'selectionlayout'),
            new xmldb_field('searchmode', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'cardsperpage'),
            new xmldb_field('filterfreeseats', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'searchmode'),
            new xmldb_field('picturecard', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'filterfreeseats'),
            new xmldb_field('picturemodal', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'picturecard'),
            new xmldb_field('modaldescription', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'picturemodal'),
            new xmldb_field('modalseats', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'modaldescription'),
            new xmldb_field('modalmembers', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'modalseats'),
            new xmldb_field('cardfields', XMLDB_TYPE_TEXT, null, null, null, null, null, 'modalmembers'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Choicegroup savepoint reached.
        upgrade_mod_savepoint(true, 2026110301, 'choicegroup');
    }

    return true;
}
