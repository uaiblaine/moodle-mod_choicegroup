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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_choicegroup\local\selection;

/**
 * One page of cards of the selection page, after a search, filters and a sort.
 *
 * @package    mod_choicegroup
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cards extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'query' => new external_value(PARAM_TEXT, 'Text the group name must contain', VALUE_DEFAULT, ''),
            'filters' => new external_multiple_structure(
                new external_single_structure([
                    'shortname' => new external_value(PARAM_ALPHANUMEXT, 'Group custom field shortname'),
                    'value' => new external_value(PARAM_RAW_TRIMMED, 'The value the field must have'),
                ]),
                'Filter values',
                VALUE_DEFAULT,
                []
            ),
            'onlyfree' => new external_value(PARAM_BOOL, 'Only groups with a free seat', VALUE_DEFAULT, false),
            'sort' => new external_value(PARAM_ALPHA, 'default, name, date or free', VALUE_DEFAULT, 'default'),
            'offset' => new external_value(PARAM_INT, 'The first match to return', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'How many matches to return', VALUE_DEFAULT, 25),
        ]);
    }

    /**
     * Returns one page of cards, rendered.
     *
     * @param int $cmid Course module id
     * @param string $query Text the group name must contain
     * @param array $filters Filter values, each with shortname and value
     * @param bool $onlyfree Only groups with a free seat
     * @param string $sort The sort order
     * @param int $offset The first match to return
     * @param int $limit How many matches to return
     * @return array With total and cards (optionid and html)
     * @throws \moodle_exception When the module is unknown or the viewer may not see it
     */
    public static function execute(
        int $cmid,
        string $query = '',
        array $filters = [],
        bool $onlyfree = false,
        string $sort = 'default',
        int $offset = 0,
        int $limit = 25
    ): array {
        global $CFG, $PAGE, $USER;
        require_once($CFG->dirroot . '/mod/choicegroup/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'query' => $query,
            'filters' => $filters,
            'onlyfree' => $onlyfree,
            'sort' => $sort,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $cm = get_coursemodule_from_id('choicegroup', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        if (isguestuser()) {
            throw new \require_login_exception('Guests cannot load the cards');
        }

        // The page itself needs no capability beyond seeing the activity; the cards go to those who choose and to
        // those who read the responses (teachers looking at the page).
        if (!has_any_capability(['mod/choicegroup:choose', 'mod/choicegroup:readresponses'], $context)) {
            throw new \required_capability_exception($context, 'mod/choicegroup:choose', 'nopermissions', '');
        }

        $choicegroup = choicegroup_get_choicegroup($cm->instance);
        $selection = new selection($choicegroup, $cm, (int)$USER->id);

        $values = [];
        foreach ($params['filters'] as $filter) {
            $values[$filter['shortname']] = $filter['value'];
        }

        $page = $selection->query(
            $params['query'],
            $values,
            $params['onlyfree'],
            $params['sort'],
            max(0, $params['offset']),
            $params['limit']
        );

        $output = $PAGE->get_renderer('mod_choicegroup');
        $cards = [];
        foreach ($page['cards'] as $card) {
            $cards[] = [
                'optionid' => $card['optionid'],
                'html' => $output->render_from_template('mod_choicegroup/card', $card),
            ];
        }

        return ['total' => $page['total'], 'cards' => $cards];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'The number of matches'),
            'cards' => new external_multiple_structure(
                new external_single_structure([
                    'optionid' => new external_value(PARAM_INT, 'The option id'),
                    'html' => new external_value(PARAM_RAW, 'The rendered card'),
                ])
            ),
        ]);
    }
}
