# Claude instructions for `mod_choicegroup`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. **Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding
style, CI gates, lang-string rules, the `mdl` environment, git rules) — do not
repeat them here. This file keeps only what is true for this plugin, as rules
with a one-line reason each; keep it under ~250 lines, because every session
and every subagent pays for it on every cache write. Measurements, incidents
and the long version of a gotcha go to `docs/` (export-ignored) with a pointer
from the rule.

Plugin context: a Moodle **activity module** ("Group choice") that lets students add themselves to
one (or several) of the course groups a teacher offers, with per-group member limits and an
open/close window. It owns two tables of settings (`choicegroup`, `choicegroup_options`); a
student's choice is a core `groups_members` row written with an empty component, so core_group
owns the personal data and the privacy provider is a null provider. No dependency on other
plugins. Supports Moodle **5.3 only** (`$plugin->requires = 2026100500`,
`$plugin->supported = [503, 503]`).
CI is the moodle-an-hochschulen reusable workflow (through `uaiblaine/moodle-workflows`), one job,
`ci-503`, in `.github/workflows/ci.yml` — **update it when `supported` changes**. Development
happens on m503; this repo is mounted into m503 and m503b at `mod/choicegroup` (see
`~/dev/moodle-dev/plugins.conf`).

This is a **fork** of `ndunand/moodle-mod_choicegroup` (remote `upstream`, single branch
`master`). Default branch here: `MOODLE_503_STABLE`. Sync with `git merge upstream/master`,
keeping our `.github/workflows/ci.yml`, `version.php` range and fork fixes. Keep the diff
upstream-friendly: fix defects where they live, leave upstream's structure, names and comments
alone unless a comment is wrong or fails a gate (fork-mode comment audit).

## Agent orchestration budget (fleet rule, repeated here on purpose)

Section 6 of `~/dev/CLAUDE.md` (`moodle-dev/CLAUDE.fleet.md`) is the authority and says why.
This short copy reaches sessions that do not load that file: cloud sessions and checkouts
outside `~/dev`. Every subagent gets the model and effort of its role from its agent
definition, and none runs on the session model.

| Role | model | effort | agent |
|---|---|---|---|
| Mechanical sweeps, greps, renames, counts, log reading | `haiku` | `medium` | `fleet-sweeper` |
| Checklists against evidence (handoff counts, spec lines against a sweep log, lang lockstep) | `haiku` | `high` | `fleet-checker` |
| Readers, measurers, graders | `sonnet` | `medium` | `fleet-reader` |
| Refuters and verifiers of a blocking finding | `sonnet` | `high` | `fleet-verifier` |
| Well-scoped implementation (established cause, settled design, written recipe) | `sonnet` | `medium` | `fleet-fixer` |
| Non-trivial implementation (open design, several files, long tasks) | `opus` | `high` | `fleet-implementer` |
| Consolidators, critics, estimators, ADR and documentation drafters | `opus` | `high` | `fleet-synthesist` |

- Launch the `Agent` tool with `subagent_type: "fleet-*"`; it has no `effort` parameter, so
  the role's effort comes from that definition (`mdl claude-setup` installs them). Where they
  are not installed, pass `model`. Aliases only; never `fable`; `xhigh` only for a long-horizon
  implementer whose prompt says why; never `xhigh`/`max` on Sonnet or Haiku.
- No long command inside a subagent: `mdl ci --matrix`, `mdl mutate` and Behat run from the
  main session in a background Bash command; a subagent runs the fast gate its prompt names and
  reports the command with its counts.
- Workflows only on the user's opt-in, every `agent()` with `agentType: 'fleet-*'`, under 10
  agents. Advisor off by default.

## Commands

```sh
mdl ci moodle-mod_choicegroup --matrix --behat   # tier-3 gate (5.3 x PHP x DB)
mdl phpunit m503 mod_choicegroup                 # targeted tests
mdl behat m503 @mod_choicegroup                  # Behat smoke tests
mdl grunt m503 mod/choicegroup                   # rebuild amd/build (commit with src)
mdl purge m503                                   # after PHP changes that affect output
```

In a Claude Code cloud session none of `mdl`, the stacks or `~/dev/CLAUDE.md` exist. The cloud
environment's setup script (`moodle-dev/cloud/setup.sh`) installs `moodle-plugin-ci` and the `mpci`
runner instead: `mpci` runs every gate on the highest branch in `ci.yml`, `mpci --branch <branch>`
another one, `mpci --reuse --only phpunit --filter <test>` iterates. It cannot run Behat, PHP 8.4 or
MariaDB, so the owner runs the gate the change's tier asks (fleet `CLAUDE.md` section 4; the
matrix, `mdl ci moodle-mod_choicegroup --matrix --behat`, for a tier-3 change) before merging: push a
branch, open the pull request, leave the merge to them. If `which mpci` prints nothing, the session
was resumed on a container that skipped the setup script: attach `moodle-dev` and run
`bash cloud/setup.sh` from it. See `moodle-dev/cloud/README.md`.

## Code layout

```
lib.php                 module callbacks + the choice logic (submit, delete, response data)
view.php                activity page; POST handling for choosing / removing
report.php              responses report, delete and ODS/XLS/TXT downloads
renderer.php            html_writer output of the options form and the results
mod_form.php            settings form; the group picker is hand-built HTML + amd/src/choicegroupsetting.js
classes/external.php    the four web services (also used by the Moodle App)
classes/output/mobile.php + templates/mobile_* + mobile/js/latest/   Moodle App handler
classes/manager.php, classes/courseformat/overview.php   5.x course overview
backup/moodle2/         backup/restore; options map through the `group` mapping
```

## Versions

`$plugin->version` follows upstream's date numbers (upstream is already past 2026062401, above
the 5.3 namespace `20261005XX`); a fork bump takes the current date (`YYYYMMDD00`). On a
`version.php` conflict in an upstream sync keep the higher number. Upgrade steps added by the fork
need an idempotent body, because an upstream step dated below the fork's version is skipped on a
fork site.

## Architecture gotchas

- A choice is a core group membership, not a plugin row: removing a choice is
  `groups_remove_member()`, and the plugin cannot tell its memberships from hand-made ones (no
  component stamp). Never stamp them without adding a full core_group privacy cover
  (`tests/marketplace_readiness_test.php` enforces it).
- `choicegroup_options.id` (the posted `answer`) is not a group id; every write must resolve it
  within this instance (`choicegroupid`) before touching a group.
- The web path (view.php) and the web services must enforce the same rules (option belongs to the
  instance, allowupdate, dates, limits, enrolment); keep them in one lib.php helper.
- Request-level caches must be keyed by instance (and user): completion, the course overview and
  WS batches evaluate several instances in one request.

## Testing notes

- PHPUnit covers the choice rules, web services, backup/restore, events and the privacy
  compliance; Behat (`tests/behat`) is the smoke layer, all `@javascript`.
- The group picker in the settings form is driven in Behat as the field `availablegroups`; any
  change to its markup updates the features in the same commit.

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it matches no existing shape,
re-examine the approach.
