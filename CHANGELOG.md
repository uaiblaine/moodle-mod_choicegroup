# Changelog

All notable changes of this fork are documented in this file. Upstream
([ndunand/moodle-mod_choicegroup](https://github.com/ndunand/moodle-mod_choicegroup)) keeps no
changelog; its history is in its git log.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

Fork of upstream `master` at db93774 (version 2026062401, release 1.53-dev).

### Added

- Moodle 5.3 support: `MOODLE_503_STABLE` branch, `$plugin->supported = [503, 503]`,
  `$plugin->requires = 2026100500`.
- CI through the moodle-an-hochschulen reusable workflow (`uaiblaine/moodle-workflows`), one job
  for 5.3 with the full PHP x database matrix.
- `LICENSE` and `COPYING.txt` (GNU GPL v3), `.gitattributes` release hygiene, `phpcs.xml`.
- `tests/marketplace_readiness_test.php` (plugins directory rules).
- PHPUnit tests for the choice rules, web services, backup and restore, events, renderer and
  privacy compliance.
- `lang/pt_br` (kept out of the release zip).
- README section on the data the plugin keeps, its capabilities, settings and Moodle App support.
- A card layout of the selection page (*Selection page* settings, the default for new activities):
  clickable cards with the native group picture and the seats, search by name, filters by the group
  custom fields the activity picks, an "only groups with free seats" filter, sorting, "Load more"
  and a details dialogue that can choose the group. Search, filters and paging run on the server
  through the new `mod_choicegroup_get_cards` web service. Existing activities keep the table.
- Card layout, second round: on a phone the filters fold behind a "Filters" button that counts the
  active ones; with several choices the save bar lists the chosen groups as chips that remove them
  and counts against the maximum; an optional filter by grouping (*Offer a filter by grouping*);
  singular strings for one group found and one member.

### Changed

- Version 2026110302 (eleven `choicegroup` columns for the selection page settings).
- The submit web service refuses a change of choice with `updatenotallowed` (it threw
  `missingrequiredcapability`).
- Group choice strings in sentence case.
- The upgrade step 2012042500 is its own block with its own savepoint (it was nested in
  2013070900 without one).
- JavaScript validation messages use `core/notification` instead of `alert()`.
- The mobile templates load the view web service from an `ion-text` element, which the template
  linter accepts.

### Security

- A posted option is resolved within the activity and its group must belong to the course; before,
  any option id of any activity added the user to that group.
- Deleting responses removes only memberships of the activity's option groups (and, in separate
  groups mode, only those the teacher may see); before, any `groups_members` row id was accepted.
- The web page and the web services share one set of rules: enrolment, open and close dates,
  "allow choice update", the option groups of the activity, and the minimum and maximum number of
  choices. Removing a choice is checked the same way.
- `mod_choicegroup_get_choicegroup_options` returns another user's choice only to users with
  `mod/choicegroup:readresponses`, only for a user enrolled in the course and, in separate groups
  mode, sharing a group with the caller.
- In multiple-choice mode a set of choices is saved as a whole: a full group no longer leaves the
  earlier additions and removals of the same submission in place.
- The settings form accepts only groups of the course.
- Member limits are checked under a lock, so two students can no longer take the last seat at once.
- Member names follow core group visibility, and user and group names are escaped.
- Downloads carry only the identity fields the site shows (`showuseridentity`); the text export
  strips tabs and line breaks from cells.

### Fixed

- Saving several choices redirects with "Your choice has been saved", as a single choice does: the page
  showed the selection from before the save, and a reload posted the set again.

- Backup carries `minenrollments`, `maxenrollments` and `defaultgroupdescriptionstate`; restore
  maps option groups through the backup's group mapping first.
- `mod/choicegroup:addinstance` is defined like core Choice's (course context, editing teacher and
  manager, `RISK_XSS`, cloned from `moodle/course:manageactivities`).
- The choice events name the `choicegroup` table, the affected user (`relateduserid`) and the group
  (`other['groupid']`), and map for restore; `choice_updated` fires once per group joined.
- `choicegroup_view()` triggers the view event and marks the activity viewed, for the page and the
  view web service.
- Request caches are keyed by activity, so completion and the course overview no longer mix two
  activities of one request.
- The course reset form no longer offers a "remove responses" option that did nothing.
- An undefined variable in the web services (closed activity message) and in the course overview
  count; a call to a missing renderer method for vertical anonymous results.
- A closing date of integer 0 no longer counts as closed.
- An update of the activity without the group field (bulk completion edit) keeps its options.
- Option and group names in the settings form are escaped.
- `border-collapse` on the choice table no longer needs `!important`.
- Lint warnings in the AMD modules and a missing licence header in `choicegroupdatadisplay.js`.
