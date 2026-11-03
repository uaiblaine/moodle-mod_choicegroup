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
 * Shows and hides the group members and group descriptions on the view page.
 *
 * Each toggle is a button whose aria-expanded says whether its regions (aria-controls) are shown; a click flips it,
 * the regions and the button's text together.
 *
 * @module      mod_choicegroup/choicegroupdatadisplay
 * @copyright   2018 Université de Lausanne
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Notification from 'core/notification';

/** @type {Object} The toggles, by the class of their button: the regions they show and the strings of each state. */
const TOGGLES = {
    'choicegroup-memberdisplay': {
        regions: '.choicegroups-membersnames',
        show: 'showgroupmembers',
        hide: 'hidegroupmembers',
    },
    'choicegroup-descriptiondisplay': {
        regions: '.choicegroups-descriptions',
        show: 'showdescription',
        hide: 'hidedescription',
    },
};

/** @type {string} A selector matching every toggle. */
const SELECTOR = Object.keys(TOGGLES).map((name) => `.${name}`).join(', ');

/** @type {boolean} Whether the click listener is in place, so that a second init adds no second one. */
let listening = false;

/**
 * Flip a toggle: its regions, its aria-expanded and its text.
 *
 * @param {HTMLElement} button The toggle
 * @param {Object} toggle Its entry of TOGGLES
 * @returns {Promise}
 */
const flip = async(button, toggle) => {
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    document.querySelectorAll(toggle.regions).forEach((region) => {
        region.classList.toggle('hidden', !expanded);
    });
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    const text = await getString(expanded ? toggle.hide : toggle.show, 'mod_choicegroup');
    // A second click may have flipped the toggle back while the string loaded: the text follows the state it is in now.
    if (button.getAttribute('aria-expanded') === (expanded ? 'true' : 'false')) {
        button.textContent = text;
    }
};

/**
 * Listen to the toggles of the page.
 */
export const init = () => {
    if (listening) {
        return;
    }
    listening = true;

    document.addEventListener('click', (e) => {
        const button = e.target.closest(SELECTOR);
        if (!button) {
            return;
        }

        e.preventDefault();
        const name = Object.keys(TOGGLES).find((key) => button.classList.contains(key));
        flip(button, TOGGLES[name]).catch(Notification.exception);
    });
};
