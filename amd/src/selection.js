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
 * The card layout of the selection page: search, filters, "load more", the counter and the details dialogue.
 *
 * The choices live in a set rather than in the inputs, because a search or a filter removes cards from the page; on
 * submit, a choice whose card is not on the page goes as a hidden input.
 *
 * @module     mod_choicegroup/selection
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import ModalSaveCancel from 'core/modal_save_cancel';
import Notification from 'core/notification';
import {getString} from 'core/str';

const SELECTORS = {
    cards: '[data-region="cards"]',
    card: '[data-region="card"]',
    input: '[data-region="card-input"]',
    name: '[data-region="card-name"]',
    details: '[data-region="card-details"]',
    search: '[data-region="search"]',
    filter: '[data-region="filter"]',
    sort: '[data-region="sort"]',
    grouping: '[data-region="grouping"]',
    toolbar: '[data-region="toolbar"]',
    filterCount: '[data-region="filter-count"]',
    chips: '[data-region="chips"]',
    toggle: '[data-action="toggle-filters"]',
    onlyfree: '[data-region="onlyfree"]',
    found: '[data-region="found"]',
    shown: '[data-region="shown"]',
    empty: '[data-region="empty"]',
    kept: '[data-region="kept"]',
    counter: '[data-region="counter"]',
    message: '[data-region="limit-message"]',
    save: '[data-region="save"]',
    form: '[data-region="selection-form"]',
    more: '[data-action="more"]',
    clear: '[data-action="clear"]',
    detailsButton: '[data-action="details"]',
};

/**
 * Wait for typing to pause before searching.
 *
 * @param {Function} callback What to run
 * @param {number} delay Milliseconds
 * @returns {Function}
 */
const debounce = (callback, delay) => {
    let timer = null;
    return () => {
        clearTimeout(timer);
        timer = setTimeout(callback, delay);
    };
};

/**
 * Start the page.
 *
 * @param {string} rootid The id of the selection region
 * @param {Object} config The page's facts
 * @param {number} config.cmid Course module id
 * @param {boolean} config.multiple Several choices allowed
 * @param {number} config.min Least choices (0 for none)
 * @param {number} config.max Most choices (0 for none)
 * @param {number} config.perpage Cards per load
 * @param {number} config.total Matches of the first load
 * @param {number[]} config.chosen The viewer's stored choices
 * @param {Object[]} config.chosennames The stored choices' optionid and name, for the chips of several choices
 * @param {boolean} config.canchoose The viewer may choose now
 * @param {boolean} config.remote The web service may be called (not for guests)
 */
export const init = (rootid, config) => {
    const root = document.getElementById(rootid);
    if (!root) {
        return;
    }

    const cards = root.querySelector(SELECTORS.cards);
    const form = root.querySelector(SELECTORS.form);
    const selected = new Set(config.chosen.map(Number));
    const names = new Map(config.chosennames.map((choice) => [Number(choice.optionid), choice.name]));
    let total = config.total;
    let request = 0;

    const getCardInput = (optionid) => cards.querySelector(`${SELECTORS.input}[value="${optionid}"]`);

    /**
     * Mark the cards whose name the two-line clamp cuts, so the full-name popover shows on them.
     *
     * @param {Element} scope The cards to look at
     */
    const markTruncated = (scope) => {
        scope.querySelectorAll(SELECTORS.card).forEach((card) => {
            const name = card.querySelector(SELECTORS.name);
            card.classList.toggle('is-truncated', name.scrollHeight > name.clientHeight + 1);
        });
    };

    /**
     * Show the choices on the inputs, and lock the unchosen ones once the maximum is reached.
     */
    const syncInputs = () => {
        const atmax = config.multiple && config.max > 0 && selected.size >= config.max;
        cards.querySelectorAll(SELECTORS.input).forEach((input) => {
            const id = Number(input.value);
            input.checked = selected.has(id);
            input.disabled = input.dataset.locked === '1' || !config.canchoose || (atmax && !input.checked);
        });
    };

    /**
     * List the choices as chips, each with a button that removes it; the cards outside the page included.
     */
    const renderChips = async() => {
        const list = root.querySelector(SELECTORS.chips);
        if (!list) {
            return;
        }

        const items = await Promise.all([...selected].map(async(id) => {
            const name = names.get(id) || '';
            const item = document.createElement('li');
            const label = document.createElement('span');
            const remove = document.createElement('button');
            item.className = 'mod_choicegroup-chip';
            label.textContent = name;
            remove.type = 'button';
            remove.className = 'mod_choicegroup-chip-remove';
            remove.dataset.action = 'remove-chip';
            remove.dataset.optionid = id;
            remove.setAttribute('aria-label', await getString('cardremovechip', 'mod_choicegroup', name));
            remove.textContent = '×';
            item.append(label, remove);
            return item;
        }));
        list.replaceChildren(...items);
        list.hidden = items.length === 0;
    };

    /**
     * Update the counter and the limit message, and block the save while the selection breaks a limit.
     */
    const updateCounter = async() => {
        const save = root.querySelector(SELECTORS.save);
        if (!config.multiple || !save) {
            return;
        }

        const count = selected.size;
        const exact = config.min > 0 && config.min === config.max;
        let key = '';
        let value = 0;
        if (config.min > 0 && count < config.min) {
            [key, value] = [exact ? 'enrollmentlimit_exact' : 'enrollmentlimit_min', config.min];
        } else if (config.max > 0 && count > config.max) {
            [key, value] = [exact ? 'enrollmentlimit_toomany_exact' : 'enrollmentlimit_toomany', config.max];
        }

        save.disabled = key !== '';
        const message = root.querySelector(SELECTORS.message);
        root.querySelector(SELECTORS.counter).textContent = config.max > 0
            ? await getString('cardselectedmax', 'mod_choicegroup', {count, max: config.max})
            : await getString('cardselected', 'mod_choicegroup', count);
        await renderChips();
        message.hidden = key === '';
        message.textContent = key ? await getString(key, 'mod_choicegroup', value) : '';
    };

    /**
     * Read the toolbar.
     *
     * @returns {Object} The web service arguments without offset and limit
     */
    const readToolbar = () => {
        const search = root.querySelector(SELECTORS.search);
        const sort = root.querySelector(SELECTORS.sort);
        const onlyfree = root.querySelector(SELECTORS.onlyfree);
        const grouping = root.querySelector(SELECTORS.grouping);
        const filters = [];
        root.querySelectorAll(SELECTORS.filter).forEach((select) => {
            if (select.value !== '') {
                filters.push({shortname: select.dataset.shortname, value: select.value});
            }
        });

        return {
            query: search ? search.value.trim() : '',
            filters: filters,
            onlyfree: onlyfree ? onlyfree.checked : false,
            sort: sort ? sort.value : 'default',
            grouping: grouping ? Number(grouping.value) : 0,
        };
    };

    /**
     * Update the counts under the cards and the toolbar.
     */
    const updateCounts = async() => {
        const shown = cards.querySelectorAll(SELECTORS.card).length;
        const toolbar = readToolbar();
        const found = root.querySelector(SELECTORS.found);
        if (found) {
            found.textContent = total === 1
                ? await getString('cardfoundone', 'mod_choicegroup')
                : await getString('cardfound', 'mod_choicegroup', total);
        }

        root.querySelector(SELECTORS.shown).textContent = await getString('cardshown', 'mod_choicegroup', {shown, total});
        root.querySelector(SELECTORS.more).hidden = shown >= total;
        root.querySelector(SELECTORS.empty).hidden = total > 0;
        const active = toolbar.filters.length + (toolbar.onlyfree ? 1 : 0) + (toolbar.grouping ? 1 : 0);
        const clear = root.querySelector(SELECTORS.clear);
        if (clear) {
            clear.hidden = toolbar.query === '' && active === 0;
        }
        const count = root.querySelector(SELECTORS.filterCount);
        if (count) {
            count.hidden = active === 0;
            count.textContent = active ? await getString('cardfilterscount', 'mod_choicegroup', active) : '';
        }
    };

    /**
     * Load cards from the server.
     *
     * @param {boolean} append Add to the cards shown instead of replacing them
     */
    const load = async(append) => {
        const ticket = ++request;
        const args = Object.assign(readToolbar(), {
            cmid: config.cmid,
            offset: append ? cards.querySelectorAll(SELECTORS.card).length : 0,
            limit: config.perpage,
        });

        root.setAttribute('aria-busy', 'true');
        try {
            const response = await Ajax.call([{methodname: 'mod_choicegroup_get_cards', args}])[0];
            if (ticket !== request) {
                // A newer search answered first: this one is stale.
                return;
            }

            if (!append) {
                cards.replaceChildren();
            }
            // The server renders the cards from its own template, so the HTML is trusted.
            cards.insertAdjacentHTML('beforeend', response.cards.map((card) => card.html).join(''));
            total = response.total;
            syncInputs();
            markTruncated(cards);
            await updateCounts();
        } catch (error) {
            Notification.exception(error);
        } finally {
            if (ticket === request) {
                root.removeAttribute('aria-busy');
            }
        }
    };

    /**
     * Open a card's details; with a free seat the dialogue offers to choose the group.
     *
     * @param {Element} card The card
     */
    const openDetails = async(card) => {
        const input = card.querySelector(SELECTORS.input);
        const title = card.querySelector(SELECTORS.name).innerHTML;
        const body = card.querySelector(SELECTORS.details).innerHTML;
        const canpick = config.canchoose && !input.disabled && !input.checked;
        if (!canpick) {
            await Modal.create({title, body, show: true, removeOnClose: true});
            return;
        }

        const modal = await ModalSaveCancel.create({
            title,
            body,
            buttons: {save: await getString('cardpick', 'mod_choicegroup')},
            show: true,
            removeOnClose: true,
        });
        modal.getRoot().on(ModalEvents.save, () => {
            input.click();
            input.focus();
        });
    };

    cards.addEventListener('change', (e) => {
        const input = e.target.closest(SELECTORS.input);
        if (!input) {
            return;
        }

        const id = Number(input.value);
        if (!config.multiple) {
            selected.clear();
        }
        if (input.checked) {
            selected.add(id);
            names.set(id, input.closest(SELECTORS.card).querySelector(SELECTORS.name).textContent);
        } else {
            selected.delete(id);
        }
        syncInputs();
        updateCounter();
    });

    root.addEventListener('click', (e) => {
        const details = e.target.closest(SELECTORS.detailsButton);
        if (details) {
            openDetails(details.closest(SELECTORS.card));
            return;
        }

        const chip = e.target.closest('[data-action="remove-chip"]');
        if (chip) {
            selected.delete(Number(chip.dataset.optionid));
            syncInputs();
            updateCounter();
            return;
        }

        const toggle = e.target.closest(SELECTORS.toggle);
        if (toggle) {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            root.querySelector(SELECTORS.toolbar).classList.toggle('is-filters-open', open);
            return;
        }

        if (e.target.closest(SELECTORS.more)) {
            load(true);
            return;
        }

        if (e.target.closest(SELECTORS.clear)) {
            const search = root.querySelector(SELECTORS.search);
            const onlyfree = root.querySelector(SELECTORS.onlyfree);
            if (search) {
                search.value = '';
            }
            if (onlyfree) {
                onlyfree.checked = false;
            }
            root.querySelectorAll(SELECTORS.filter).forEach((select) => {
                select.value = '';
            });
            const grouping = root.querySelector(SELECTORS.grouping);
            if (grouping) {
                grouping.value = '0';
            }
            load(false);
        }
    });

    if (config.remote) {
        const search = root.querySelector(SELECTORS.search);
        if (search) {
            search.addEventListener('input', debounce(() => load(false), 300));
        }
        root.querySelectorAll(`${SELECTORS.filter}, ${SELECTORS.sort}, ${SELECTORS.onlyfree}, ${SELECTORS.grouping}`)
            .forEach((control) => {
                control.addEventListener('change', () => load(false));
            });
    } else {
        // Guests see the first page only: the web service refuses them.
        const controls = [SELECTORS.search, SELECTORS.filter, SELECTORS.sort, SELECTORS.onlyfree, SELECTORS.grouping];
        root.querySelectorAll(controls.join(', ')).forEach((control) => {
            control.disabled = true;
        });
        root.querySelector(SELECTORS.more).hidden = true;
    }

    form.addEventListener('submit', () => {
        const kept = root.querySelector(SELECTORS.kept);
        kept.replaceChildren();
        selected.forEach((id) => {
            if (!getCardInput(id)) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = config.multiple ? 'answers[]' : 'answer';
                hidden.value = id;
                kept.append(hidden);
            }
        });
    });

    window.addEventListener('resize', debounce(() => markTruncated(cards), 200));
    syncInputs();
    markTruncated(cards);
    updateCounter();
};
