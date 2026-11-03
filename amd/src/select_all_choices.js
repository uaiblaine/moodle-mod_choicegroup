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
 * Ticks or unticks all checkboxes when clicking the Select all or Deselect all elements when viewing the response overview.
 *
 * @module      mod_choicegroup/select_all_choices
 * @copyright   2017 Marcus Fabriczy <marcus.fabriczy@blackboard.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Listen to the Select all and Deselect all links: data-select-info="1" ticks every checkbox of the form, "0" unticks.
 */
export const init = () => {
    document.querySelectorAll('.selectallnone a').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const checked = link.dataset.selectInfo === '1';
            document.querySelectorAll('#attemptsform input[type="checkbox"]').forEach((checkbox) => {
                checkbox.checked = checked;
            });
        });
    });
};
