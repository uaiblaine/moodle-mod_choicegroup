# Group choice (mod_choicegroup)

An activity module that lets students put themselves into one of a course's groups. The teacher
picks which groups are offered and, optionally, the maximum (and minimum) number of members per
group, an opening and a closing date, and whether a choice may be changed later.

Students can see the members of each group before choosing (when the teacher shows them and the
group's visibility allows it). Choosing a group adds the student to that course group; changing
the choice removes them from the previous group and adds them to the new one. With multiple
enrolments allowed, a student may join several of the offered groups.

The module is based on core's Choice activity and behaves much like it.

This repository is a fork of [ndunand/moodle-mod_choicegroup](https://github.com/ndunand/moodle-mod_choicegroup)
maintained for **Moodle 5.3** (`$plugin->supported = [503, 503]`) on the `MOODLE_503_STABLE`
branch. It carries security and correctness fixes over upstream; see `CHANGELOG.md`.

## Requirements

- Moodle 5.3 (`$plugin->requires = 2026100500`).
- No other plugin. The course must have groups for the activity to offer.

## Installation

1. Copy the plugin into `mod/choicegroup` (on 5.3, `public/mod/choicegroup`), or install the zip
   from *Site administration > Plugins > Install plugins*.
2. Visit *Site administration > Notifications* to finish the installation.

No post-install step and no external service.

## Use

1. Create the groups in the course.
2. Add a *Group choice* activity and select the groups students may choose from; set the limits,
   dates and display options.

### The selection page

Under *Selection page* in the activity settings, *Show the groups as* picks the layout:

- **Cards with search and filters** (the default for new activities): one card per group, with the
  group picture, the seats and the custom field values the activity chooses to show. The cards load
  a page at a time (10, 25, 50 or 100) with "Load more"; a search by name (automatic from 10 groups,
  always or never), one filter per chosen group custom field, an "only groups with free seats"
  filter and a sort order narrow them on the server. A *Details* button on each card opens a
  dialogue with the description, the seats, the chosen field values and the member list, and offers
  to choose the group.
- **A table**: the layout of earlier versions, which existing activities keep until a teacher
  switches.

Group custom fields (*Site administration > Courses > Groups > Group custom fields*) are shown to
students only in the activities whose teacher picks them, per field, as a filter, a tag on the
card or a line in the details. The seat counts and member names follow *Publish results*,
*Privacy of results* and each group's own visibility: the card settings can hide them, never show
more.

## Data the plugin keeps

| Table | What it keeps | How long |
|---|---|---|
| `choicegroup` | One row per activity: name, intro, the display, limit, date and completion settings, and the selection page settings (layout, page size, search, filters, and which group custom fields show where, by field shortname). No user data. | Until the activity is deleted. |
| `choicegroup_options` | One row per offered group: the activity, the course group id and that group's member limit. No user data. | Until the option or the activity is deleted. |

A student's choice is not stored by the plugin: it **is** the student's membership of the course
group (core table `groups_members`), written through core's group API with an empty component, so
those rows belong to core's group subsystem under the Privacy API. Consequences:

- The plugin's privacy provider is a null provider: core_group exports and deletes the
  memberships.
- Deleting the activity, or resetting the course, leaves the memberships in place; use core's
  "Remove all group members" in the course reset form to clear them.
- Course backups carry the choices only when they include groups and group members.

Events (choice made, choice removed, report viewed, module viewed) go to the site's log stores
with the acting user and, for choice changes, the affected user.

## Capabilities

| Capability | Default roles | Purpose |
|---|---|---|
| `mod/choicegroup:addinstance` | editing teacher, manager | Add a group choice activity to a course. |
| `mod/choicegroup:choose` | student, teacher, editing teacher | Choose a group (only users enrolled in the course). |
| `mod/choicegroup:readresponses` | teacher, editing teacher, manager | See who chose which group. |
| `mod/choicegroup:deleteresponses` | teacher, editing teacher, manager | Remove a student's choice (their group membership). |
| `mod/choicegroup:downloadresponses` | teacher, editing teacher, manager | Download the responses (ODS, Excel, text). |

## Settings

*Site administration > Plugins > Activity modules > Group choice*:

- `choicegroup/sortgroupsby`: default order of the groups on the activity page (creation date or
  name).
- `choicegroup/defaultgroupdescriptionstate`: whether group descriptions start shown or hidden.

## Web services

`mod_choicegroup_get_cards` (AJAX, read) returns one page of rendered cards after a search, filters
and a sort. It needs `mod/choicegroup:choose` or `mod/choicegroup:readresponses` in the activity and
refuses guests.

## Moodle App

The activity works in the Moodle App through the plugin's mobile handler (`db/mobile.php`) and its
web services (`mod_choicegroup_get_choicegroup_options`, `mod_choicegroup_submit_choicegroup_response`,
`mod_choicegroup_delete_choicegroup_responses`, `mod_choicegroup_view_choicegroup`).

## Licence

GNU GPL v3 or later; see `LICENSE`. Original work © Université de Lausanne and contributors.

## Credits

- Nicolas Dunand and the Université de Lausanne, original authors and upstream maintainers.
- André Lausch: German translation. Luiggi Sansonetti: French translation.
- Upstream links: [plugins directory entry](https://moodle.org/plugins/mod_choicegroup),
  [forum discussion](https://moodle.org/mod/forum/discuss.php?d=174424).
