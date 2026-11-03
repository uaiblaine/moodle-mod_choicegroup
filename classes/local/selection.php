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

namespace mod_choicegroup\local;

use context_course;
use context_module;
use core_group\customfield\group_handler;
use stdClass;

/**
 * The card layout of the selection page: which options match a search, and what each card may show.
 *
 * The page and the get_cards web service both build their cards here, so a rule about what a viewer may see (the seat
 * counts, the member names, the custom field values) is decided in one place.
 *
 * @package    mod_choicegroup
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selection {
    /** @var int The table layout of upstream. */
    public const LAYOUT_TABLE = 0;

    /** @var int Cards with search, filters and "load more". */
    public const LAYOUT_CARDS = 1;

    /** @var int Search shown from {@see self::SEARCH_AUTO_MINIMUM} options on. */
    public const SEARCH_AUTO = 0;

    /** @var int Search always shown. */
    public const SEARCH_ALWAYS = 1;

    /** @var int Search never shown. */
    public const SEARCH_NEVER = 2;

    /** @var int The number of options from which the automatic search shows. */
    public const SEARCH_AUTO_MINIMUM = 10;

    /** @var int Custom field bit: the field is a filter. */
    public const FIELD_FILTER = 1;

    /** @var int Custom field bit: the value is a tag on the card. */
    public const FIELD_CARD = 2;

    /** @var int Custom field bit: the value is listed in the details dialogue. */
    public const FIELD_MODAL = 4;

    /** @var int The largest page a request may ask for. */
    public const MAX_PAGE_SIZE = 100;

    /** @var string[] Field types each placement accepts; a textarea is too long for a filter or a tag. */
    private const FIELD_TYPES = [
        self::FIELD_FILTER => ['checkbox', 'number', 'select', 'text'],
        self::FIELD_CARD => ['checkbox', 'date', 'number', 'select', 'text'],
        self::FIELD_MODAL => ['checkbox', 'date', 'number', 'select', 'text', 'textarea'],
    ];

    /** @var stdClass The instance, as choicegroup_get_choicegroup() returns it. */
    private stdClass $choicegroup;

    /** @var stdClass The course module. */
    private stdClass $cm;

    /** @var int The viewer. */
    private int $userid;

    /** @var context_module The module context. */
    private context_module $context;

    /** @var context_course The course context, where the groups live. */
    private context_course $coursecontext;

    /** @var stdClass[] The groups of the options, by group id. */
    private array $groups;

    /** @var int[] The option ids the viewer is a member of. */
    private array $chosen = [];

    /** @var int[]|null Enrolled member counts by group id, filled on first use. */
    private ?array $counts = null;

    /** @var array|null Custom field data controllers by group id and field shortname, filled on first use. */
    private ?array $fielddata = null;

    /** @var bool Whether the activity accepts choices now. */
    private bool $open;

    /** @var bool Whether the opening date is still ahead; view.php shows nothing of the options then. */
    private bool $notyetopen;

    /** @var bool Whether the viewer may choose or change a choice now. */
    private bool $canchoose;

    /** @var bool Whether the seat counts are visible to the viewer, as showresults has them. */
    private bool $resultsvisible;

    /**
     * Collect the facts every card depends on.
     *
     * @param stdClass $choicegroup The instance, as choicegroup_get_choicegroup() returns it
     * @param stdClass $cm The course module
     * @param int $userid The viewer
     */
    public function __construct(stdClass $choicegroup, stdClass $cm, int $userid) {
        $this->choicegroup = $choicegroup;
        $this->cm = $cm;
        $this->userid = $userid;
        $this->context = context_module::instance($cm->id);
        $this->coursecontext = context_course::instance($cm->course);
        $this->groups = choicegroup_get_groups($choicegroup);

        $answers = choicegroup_get_user_answer($choicegroup, $userid, true, true);
        $groupids = $answers ? array_map(fn($group) => (int)$group->id, $answers) : [];
        foreach ($choicegroup->option ?? [] as $optionid => $groupid) {
            if (in_array((int)$groupid, $groupids, true)) {
                $this->chosen[] = (int)$optionid;
            }
        }

        // The same tests as view.php: with no closing date the activity counts as open, whatever the opening date, and
        // "after close" means past the closing date, not merely "not open" (before the opening date is not closed).
        $now = time();
        $this->notyetopen = $choicegroup->timeclose != 0 && $choicegroup->timeopen > $now;
        $closed = $choicegroup->timeclose != 0 && $now > $choicegroup->timeclose;
        $this->open = !$this->notyetopen && !$closed;
        $this->canchoose = $this->open && !isguestuser($userid) &&
            (!$this->chosen || $choicegroup->allowupdate) &&
            is_enrolled($this->context, $userid, 'mod/choicegroup:choose');

        $showresults = (int)$choicegroup->showresults;
        $this->resultsvisible = $showresults === (int)CHOICEGROUP_SHOWRESULTS_ALWAYS ||
            ($showresults === (int)CHOICEGROUP_SHOWRESULTS_AFTER_ANSWER && $this->chosen) ||
            ($showresults === (int)CHOICEGROUP_SHOWRESULTS_AFTER_CLOSE && $closed);
    }

    /**
     * Read the per-field placements stored in the instance's cardfields column.
     *
     * @param string|null $json The stored JSON
     * @return int[] Placement bits by field shortname
     */
    public static function parse_card_fields(?string $json): array {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        foreach ($decoded as $shortname => $bits) {
            if (is_string($shortname) && is_int($bits)) {
                $fields[$shortname] = $bits & (self::FIELD_FILTER | self::FIELD_CARD | self::FIELD_MODAL);
            }
        }

        return $fields;
    }

    /**
     * Build the cardfields JSON from the settings form's cf_<fieldid>_<placement> checkboxes.
     *
     * Keyed by shortname, which a backup carries to another site, where field ids differ.
     *
     * @param stdClass $data The submitted form data
     * @return string The JSON; an empty object when no field is placed
     */
    public static function serialise_card_fields(stdClass $data): string {
        $fields = [];
        foreach (self::get_site_fields() as $shortname => $field) {
            $bits = 0;
            $suffixes = [self::FIELD_FILTER => 'filter', self::FIELD_CARD => 'card', self::FIELD_MODAL => 'modal'];
            foreach ($suffixes as $bit => $suffix) {
                $name = 'cf_' . $field->get('id') . '_' . $suffix;
                if (!empty($data->$name) && self::field_accepts($field->get('type'), $bit)) {
                    $bits |= $bit;
                }
            }

            if ($bits) {
                $fields[$shortname] = $bits;
            }
        }

        return json_encode((object)$fields);
    }

    /**
     * Whether a field of this type may take this placement.
     *
     * @param string $type The custom field type
     * @param int $placement One of the FIELD_* bits
     * @return bool
     */
    public static function field_accepts(string $type, int $placement): bool {
        return in_array($type, self::FIELD_TYPES[$placement] ?? [], true);
    }

    /**
     * The group custom fields this site defines, by shortname.
     *
     * @return \core_customfield\field_controller[]
     */
    public static function get_site_fields(): array {
        $fields = [];
        foreach (group_handler::create()->get_fields() as $field) {
            $fields[$field->get('shortname')] = $field;
        }

        return $fields;
    }

    /**
     * Whether the viewer may choose or change a choice now.
     *
     * @return bool
     */
    public function can_choose(): bool {
        return $this->canchoose;
    }

    /**
     * Whether the opening date is still ahead: then no option is shown at all, as on view.php.
     *
     * @return bool
     */
    public function is_not_open_yet(): bool {
        return $this->notyetopen;
    }

    /**
     * The option ids the viewer is a member of.
     *
     * @return int[]
     */
    public function get_chosen(): array {
        return $this->chosen;
    }

    /**
     * Whether every option is full and none is the viewer's: then there is nothing left to choose.
     *
     * @return bool
     */
    public function is_all_full(): bool {
        if (empty($this->choicegroup->limitanswers) || $this->chosen) {
            return false;
        }

        foreach (array_keys($this->choicegroup->option ?? []) as $optionid) {
            if ($this->get_free((int)$optionid) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the search box shows, by the instance's search mode and the number of options.
     *
     * @return bool
     */
    public function has_search(): bool {
        $mode = (int)$this->choicegroup->searchmode;
        if ($mode === self::SEARCH_AUTO) {
            return count($this->choicegroup->option ?? []) >= self::SEARCH_AUTO_MINIMUM;
        }

        return $mode === self::SEARCH_ALWAYS;
    }

    /**
     * Whether the "only groups with seats" filter shows: it needs a limit to mean anything.
     *
     * @return bool
     */
    public function has_free_filter(): bool {
        return !empty($this->choicegroup->limitanswers) && !empty($this->choicegroup->filterfreeseats);
    }

    /**
     * The sort orders the viewer may pick; sorting by free seats would reveal counts showresults hides.
     *
     * @return string[]
     */
    public function get_sort_keys(): array {
        $keys = ['default', 'name', 'date'];
        if ($this->resultsvisible && !empty($this->choicegroup->limitanswers)) {
            $keys[] = 'free';
        }

        return $keys;
    }

    /**
     * The filters for the toolbar: one per filter field that at least one option has a value for.
     *
     * @return array[] Each with shortname, label (plain text) and options (value and plain label)
     */
    public function get_filters(): array {
        $filters = [];
        foreach ($this->get_fields(self::FIELD_FILTER) as $shortname => $field) {
            $values = [];
            foreach (array_keys($this->groups) as $groupid) {
                [$key, $label] = $this->get_filter_value($groupid, $shortname);
                if ($key !== '') {
                    $values[$key] = $label;
                }
            }

            if (!$values) {
                continue;
            }

            if ($field->get('type') === 'select') {
                ksort($values, SORT_NUMERIC);
            } else {
                \core_collator::asort($values);
            }

            $options = [];
            foreach ($values as $value => $label) {
                $options[] = ['value' => (string)$value, 'label' => $label];
            }

            $filters[] = [
                'shortname' => $shortname,
                'label' => self::plain($field->get_formatted_name()),
                'options' => $options,
            ];
        }

        return $filters;
    }

    /**
     * The options that match a search, in the asked order, and the cards of one page of them.
     *
     * @param string $query Text the group name must contain, case-insensitively
     * @param string[] $filters Filter values by field shortname; a shortname that is not a filter is ignored
     * @param bool $onlyfree Keep only the options with a free seat
     * @param string $sort One of {@see self::get_sort_keys()}; another value sorts as 'default'
     * @param int $offset The first match to export
     * @param int $limit How many matches to export, at most {@see self::MAX_PAGE_SIZE}
     * @return array With total (the matches) and cards (the exported page)
     */
    public function query(string $query, array $filters, bool $onlyfree, string $sort, int $offset, int $limit): array {
        $filterfields = $this->get_fields(self::FIELD_FILTER);
        $filters = array_filter(
            array_intersect_key($filters, $filterfields),
            fn($value) => (string)$value !== ''
        );
        $query = \core_text::strtolower(trim($query));
        $onlyfree = $onlyfree && $this->has_free_filter();
        if ($this->notyetopen) {
            return ['total' => 0, 'cards' => []];
        }

        $matches = [];
        foreach ($this->choicegroup->option ?? [] as $optionid => $groupid) {
            $group = $this->groups[$groupid] ?? null;
            if (!$group) {
                continue;
            }

            if ($query !== '' && \core_text::strpos(\core_text::strtolower($this->get_name($group)), $query) === false) {
                continue;
            }

            foreach ($filters as $shortname => $value) {
                if ($this->get_filter_value((int)$groupid, $shortname)[0] !== (string)$value) {
                    continue 2;
                }
            }

            if ($onlyfree && $this->get_free((int)$optionid) <= 0) {
                continue;
            }

            $matches[] = (int)$optionid;
        }

        $matches = $this->sort($matches, in_array($sort, $this->get_sort_keys(), true) ? $sort : 'default');

        $limit = max(1, min($limit, self::MAX_PAGE_SIZE));
        $cards = [];
        foreach (array_slice($matches, max(0, $offset), $limit) as $optionid) {
            $cards[] = $this->export_card($optionid);
        }

        return ['total' => count($matches), 'cards' => $cards];
    }

    /**
     * The template context of one card, details dialogue included.
     *
     * @param int $optionid An option of this instance
     * @return array
     */
    public function export_card(int $optionid): array {
        $groupid = (int)$this->choicegroup->option[$optionid];
        $group = $this->groups[$groupid];
        $multiple = !empty($this->choicegroup->multipleenrollmentspossible);
        $haslimit = !empty($this->choicegroup->limitanswers);
        $count = $this->get_counts()[$groupid] ?? 0;
        $max = (int)($this->choicegroup->maxanswers[$optionid] ?? 0);
        $free = max(0, $max - $count);
        $full = $haslimit && $free <= 0;
        $checked = in_array($optionid, $this->chosen, true);
        $name = $this->get_name($group);

        $seats = [
            'counts' => $this->resultsvisible,
            'haslimit' => $haslimit,
            'full' => $full,
            // A hint at the count, so it follows the count's visibility; "full" is always shown.
            'low' => $this->resultsvisible && $haslimit && !$full && $free <= 3,
            'percent' => $haslimit ? ($max > 0 ? min(100, (int)round($count * 100 / $max)) : 100) : 0,
            'text' => '',
            'status' => '',
        ];
        if ($this->resultsvisible && $haslimit) {
            $seats['text'] = get_string('cardseatstaken', 'choicegroup', (object)['count' => $count, 'max' => $max]);
            $seats['status'] = $full ? get_string('cardfull', 'choicegroup') : get_string('cardseatsfree', 'choicegroup', $free);
        } else if ($this->resultsvisible) {
            $seats['text'] = get_string('cardmembers', 'choicegroup', $count);
        } else if ($haslimit) {
            $seats['status'] = $full ? get_string('cardfull', 'choicegroup') : get_string('cardseatsavailable', 'choicegroup');
        }
        $seats['has'] = $seats['text'] !== '' || $seats['status'] !== '';

        $pictureurl = empty($group->hidepicture) ? get_group_picture_url($group, $this->cm->course, true) : null;
        $description = $this->get_description($group);

        $tags = [];
        $details = [];
        foreach ($this->get_fields(self::FIELD_CARD | self::FIELD_MODAL) as $shortname => $field) {
            $data = $this->get_field_data()[$groupid][$shortname] ?? null;
            $exported = $data ? $data->export_value() : null;
            if ($exported === null || $exported === '') {
                continue;
            }

            $bits = $this->get_card_fields()[$shortname];
            $label = self::plain($field->get_formatted_name());
            if (($bits & self::FIELD_CARD) && self::field_accepts($field->get('type'), self::FIELD_CARD)) {
                $tags[] = ['label' => $label, 'value' => self::plain($exported)];
            }

            if (($bits & self::FIELD_MODAL) && self::field_accepts($field->get('type'), self::FIELD_MODAL)) {
                // The value comes from the field type's own export, which formats it for display.
                $details[] = ['label' => $label, 'value' => $exported];
            }
        }

        $members = $this->get_member_names($group, $count);

        return [
            'optionid' => $optionid,
            'inputid' => 'choiceid_' . $optionid,
            'inputname' => $multiple ? 'answers[]' : 'answer',
            'multiple' => $multiple,
            'name' => $name,
            'checked' => $checked,
            'disabled' => !$this->canchoose || ($full && !$checked),
            'canpick' => $this->canchoose && !$full && !$checked,
            'pictureurl' => ($pictureurl && !empty($this->choicegroup->picturecard)) ? $pictureurl->out(false) : '',
            'excerpt' => ($description !== '' &&
                (int)$this->choicegroup->defaultgroupdescriptionstate === CHOICEGROUP_GROUPDESCRIPTIONSTATE_VISIBLE)
                ? shorten_text(self::plain($description), 160) : '',
            'tags' => $tags,
            'hastags' => (bool)$tags,
            'seats' => $seats,
            // Its keys differ from the card's: inside a section a key the frame lacks resolves to the card's value.
            'details' => [
                'picture' => ($pictureurl && !empty($this->choicegroup->picturemodal)) ? $pictureurl->out(false) : '',
                'description' => !empty($this->choicegroup->modaldescription) ? $description : '',
                'seatsinfo' => !empty($this->choicegroup->modalseats) && $seats['has'] ? $seats : false,
                'fields' => $details,
                'hasfields' => (bool)$details,
                'members' => $members,
            ],
        ];
    }

    /**
     * The member list of the details dialogue, or null when it is not shown.
     *
     * Names need the publish setting at names, visible results and the group's own visibility; the setting of the
     * dialogue only ever narrows that.
     *
     * @param stdClass $group The group
     * @param int $count Its enrolled member count
     * @return array|null With names (plain text), count, and hidden when the group's visibility withholds them
     */
    private function get_member_names(stdClass $group, int $count): ?array {
        if (
            empty($this->choicegroup->modalmembers) || !$this->resultsvisible ||
            (int)$this->choicegroup->publish !== (int)CHOICEGROUP_PUBLISH_NAMES || $count === 0
        ) {
            return null;
        }

        $userfields = 'u.id' . \core_user\fields::for_name()->get_sql('u')->selects;
        $members = get_enrolled_users(
            $this->coursecontext,
            '',
            $group->id,
            $userfields,
            'u.lastname, u.firstname',
            0,
            0,
            !empty($this->choicegroup->onlyactive)
        );

        $names = [];
        foreach (choicegroup_filter_visible_members($group, $members) as $member) {
            $names[] = ['name' => fullname($member)];
        }

        return ['names' => $names, 'count' => $count, 'hidden' => !$names];
    }

    /**
     * Order option ids.
     *
     * @param int[] $optionids Option ids in the activity's own order
     * @param string $sort One of {@see self::get_sort_keys()}
     * @return int[]
     */
    private function sort(array $optionids, string $sort): array {
        if ($sort === 'default') {
            return $optionids;
        }

        $keys = [];
        foreach ($optionids as $optionid) {
            $group = $this->groups[$this->choicegroup->option[$optionid]];
            $keys[$optionid] = match ($sort) {
                'name' => $this->get_name($group),
                'date' => (int)$group->timecreated,
                'free' => -$this->get_free($optionid),
            };
        }

        if ($sort === 'name') {
            \core_collator::asort($keys);
        } else {
            asort($keys);
        }

        return array_keys($keys);
    }

    /**
     * The free seats of an option; without a limit there is always room.
     *
     * @param int $optionid An option of this instance
     * @return int
     */
    private function get_free(int $optionid): int {
        if (empty($this->choicegroup->limitanswers)) {
            return PHP_INT_MAX;
        }

        $groupid = (int)$this->choicegroup->option[$optionid];
        return (int)($this->choicegroup->maxanswers[$optionid] ?? 0) - ($this->get_counts()[$groupid] ?? 0);
    }

    /**
     * Enrolled member counts of every option group, in one query.
     *
     * The same members get_enrolled_users() lists for a group (active only when onlyactive is set), which is what
     * the table layout counts.
     *
     * @return int[] By group id
     */
    private function get_counts(): array {
        global $DB;

        if ($this->counts !== null) {
            return $this->counts;
        }

        $this->counts = [];
        if (!$this->groups) {
            return $this->counts;
        }

        [$enrolsql, $enrolparams] = get_enrolled_sql($this->coursecontext, '', 0, !empty($this->choicegroup->onlyactive));
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($this->groups), SQL_PARAMS_NAMED, 'cggrp');
        $sql = "SELECT gm.groupid, COUNT(gm.userid) AS members
                  FROM {groups_members} gm
                  JOIN ($enrolsql) eu ON eu.id = gm.userid
                 WHERE gm.groupid $insql
              GROUP BY gm.groupid";
        foreach ($DB->get_records_sql_menu($sql, $enrolparams + $inparams) as $groupid => $members) {
            $this->counts[(int)$groupid] = (int)$members;
        }

        return $this->counts;
    }

    /**
     * The site's group custom fields the instance places somewhere among the given placements.
     *
     * @param int $placements FIELD_* bits
     * @return \core_customfield\field_controller[] By shortname
     */
    private function get_fields(int $placements): array {
        $fields = [];
        $sitefields = self::get_site_fields();
        foreach ($this->get_card_fields() as $shortname => $bits) {
            $field = $sitefields[$shortname] ?? null;
            if (!$field) {
                continue;
            }

            foreach ([self::FIELD_FILTER, self::FIELD_CARD, self::FIELD_MODAL] as $placement) {
                if (($placements & $placement) && ($bits & $placement) && self::field_accepts($field->get('type'), $placement)) {
                    $fields[$shortname] = $field;
                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * The instance's field placements.
     *
     * @return int[] By shortname
     */
    private function get_card_fields(): array {
        return self::parse_card_fields($this->choicegroup->cardfields ?? null);
    }

    /**
     * The custom field data of every option group, read in one batch.
     *
     * The values are read whatever group_handler::can_view() says, which hides them from students: the teacher chose
     * to show these fields in this activity, and only the chosen ones are ever exported.
     *
     * @return array Data controllers by group id and shortname
     */
    private function get_field_data(): array {
        if ($this->fielddata !== null) {
            return $this->fielddata;
        }

        $this->fielddata = [];
        if (!$this->groups || !$this->get_card_fields()) {
            return $this->fielddata;
        }

        foreach (group_handler::create()->get_instances_data(array_keys($this->groups), true) as $groupid => $datas) {
            foreach ($datas as $data) {
                $this->fielddata[(int)$groupid][$data->get_field()->get('shortname')] = $data;
            }
        }

        return $this->fielddata;
    }

    /**
     * A group's value of a filter field, as the key the filter compares and its plain label.
     *
     * @param int $groupid A group of an option
     * @param string $shortname A field shortname
     * @return string[] Key and label; an empty key when the group has no value
     */
    private function get_filter_value(int $groupid, string $shortname): array {
        $data = $this->get_field_data()[$groupid][$shortname] ?? null;
        if (!$data) {
            return ['', ''];
        }

        $value = $data->get_value();
        $exported = $data->export_value();
        if ($value === null || $value === '' || $exported === null || $exported === '') {
            return ['', ''];
        }

        return [(string)$value, self::plain($exported)];
    }

    /**
     * The group name as plain text, for a double-stash template variable and for the search.
     *
     * @param stdClass $group The group
     * @return string
     */
    private function get_name(stdClass $group): string {
        return format_string($group->name, true, ['context' => $this->coursecontext, 'escape' => false]);
    }

    /**
     * The group description as HTML, with its embedded files served.
     *
     * @param stdClass $group The group
     * @return string Empty when the group has none
     */
    private function get_description(stdClass $group): string {
        if (trim(strip_tags((string)$group->description, '<img>')) === '') {
            return '';
        }

        $text = file_rewrite_pluginfile_urls(
            $group->description,
            'pluginfile.php',
            $this->coursecontext->id,
            'group',
            'description',
            $group->id
        );

        return format_text($text, $group->descriptionformat, ['context' => $this->coursecontext]);
    }

    /**
     * Formatted HTML as plain text, entities decoded: the spelling a double-stash variable wants.
     *
     * @param string $html Formatted HTML
     * @return string
     */
    private static function plain(string $html): string {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
