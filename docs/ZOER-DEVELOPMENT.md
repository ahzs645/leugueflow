# LeagueFlow development and interface audit in Zoer

Clone `https://github.com/ahzs645/leugueflow.git` in **Repos**. For an existing
local installation, open **WordPress → local site → Plugins → Leagueflow →
Development**, select that repository, and save a link to the installed
`leagueflow` folder. This development mirror retains the upstream release updater
at `amirrad98/intramurals`; linking a development repository does not change the
public update feed.

## Build and local update

1. Choose **Inspect repository and prepare build**, then **Start development
   computer**. The attached computer owns the shared repository files.
2. Save the detected PHP lint/package command with **Save settings and build
   now**. Add `__pycache__` to **Additional ignored folders** for Python tests.
3. Link **Plugin folder in repository** to `dist/leagueflow`. The checked-in
   `.distignore` excludes scripts, tests, release notes, audit documentation,
   caches and dependencies from Zoer's generic staging helper. Keep its inventory
   aligned with `ROOT_FILES` and `RUNTIME` in `scripts/build.py`.
4. Edit the attached computer's source through Repos Files, Terminal or Chat.
   Wait for a successful build of the current source, then **Review local update**.
   The review should contain only the runtime distribution. An exact match needs
   no replacement. Otherwise inspect the file changes and **Update local site**.
5. Open **Preview site** and **WordPress admin** to verify behavior. Code rollback
   restores previous files; it does not undo saved posts or league records.

PHP/Composer installation and build-on-save belong to the development computer.
The WordPress site uses DDEV's own PHP/database. A clean Git tree, a successful
build and a browser test are separate results. `python3 scripts/build.py` remains
the canonical deterministic release builder; Zoer staging is for local development.

## Use a separate Claude audit chat

Choose the intended computer in **Chats → Computer for new chats** and select
its live Claude model/effort. Add explicit composer references for this repository,
the local WordPress site, and WordPress Manager. A reference is context, not a
repository mount or a production deployment grant.

An authenticated CLI can still fail at inference if its OAuth refresh expires.
After one bounded retry, complete the supported Claude sign-in on that computer;
do not copy another computer's credentials or change models silently.

For browser and authorized local editing, the named chat needs Full access. In
the chat, use `chat_prepare_repository` to create a committed-source working copy
when the repository is not mounted. Retain `copyId`, `sourceCommit`, `baselineCommit`
and its returned path. Edits there do not trigger the shared repository's watcher.
Stage only intended files and use `chat_request_repository_publish` to prepare a
reviewed patch import. Once that patch reaches the original Zoer repository, the
attached computer rebuilds it; review the WordPress update separately.

Commit the original repository's intended setup changes locally before preparing
the audit copy. Import requires a clean original worktree at that exact source
commit. If its HEAD changes, prepare a fresh copy and reconcile the patch. The
native tool budget is per turn and shared by subagents; save coverage and continue
in bounded batches rather than waiting for a cooldown. Keep an attempt log, but
supersede old blocked rows in the current coverage matrix after a successful retest.

Use `chat_browser_prepare` with the selected provider or explicitly attached saved
profile. `browser_session_snapshot` supplies DOM and visual evidence;
`browser_session_resize` supports desktop 1440×900, mobile 390×844 and custom
sizes. This is viewport testing, not physical-device certification. For local
admin access, `create_wordpress_admin_link` produces a short-lived handoff; open
it promptly in the audit browser and keep its secret URL out of reports. Protected
Zoer deployments require the dashboard's session-bound admin handoff.

Use `list_wordpress_files`, `read_wordpress_file` and bounded read-only
`run_wordpress_cli` for site/source inventory. Direct, hash-checked WordPress file
edits are a separate path and can diverge from maintained repository source.
Do not use shell HTTP to replace a missing native tool or evade an approval.

## Audit coverage and evidence

Build a coverage table from the actual WordPress Pages inventory, frontend links,
active theme templates, plugin blocks, and registered admin menus. Give each row
a URL, viewport, authentication state, exercised interactions, result, screenshot
or receipt, and any blocker. A source-only or inaccessible row stays untested.

Cover all published pages and their navigation, public team/match detail routes,
sport variants, empty results and unavailable selections. Test desktop and mobile
navigation, keyboard focus, readable labels, horizontal overflow and form errors.
For the calendar, exercise Month/List/Week/Day, date navigation, Today, search,
filters and match/event details. Check tables, rosters and brackets with populated
and empty selections.

Verify all nine blocks in both Gutenberg and their rendered frontend output:

- `leagueflow/knockout-bracket`
- `leagueflow/league-table`
- `leagueflow/match-calendar`
- `leagueflow/match-card`
- `leagueflow/match-list`
- `leagueflow/portal`
- `leagueflow/sport-standings`
- `leagueflow/team-list`
- `leagueflow/team-roster`

Inspect global LeagueFlow Overview, Sports, League Levels, Fixtures, Field
Availability, Placements, Utilities and Settings. For each enabled sport, cover its
overview, teams, players, join requests, matches, availability, events, levels,
competitions, seasons, fixtures, standings and brackets. Inspect WordPress Pages,
the Site Editor and plugin status too. Use disposable audit content and a backup
for write tests; preserve actual participant records and scores. Fixture seeding,
uninstall and upgrade/security integration tests belong on disposable installations.

Keep anonymous, administrator and participant-role tests distinct. Administrator
login does not certify student/captain/referee permissions. Do not include private
participant records, login handoffs or credentials in audit artifacts.

Record missing capability precisely: tool name/schema, permission mode, target,
observed error, reproduction and impact. Distinguish missing tools from expired
authentication, a stopped resource, provider limitations, capacity and approvals.
An empty console log from a provider that cannot capture console events is not
evidence of no errors. Fix repository code, run the existing checks, import the
reviewed patch, build, update the local plugin, and retest affected coverage before
publishing anything.

## Automated calendar regressions

Run `npm ci && npm run test:calendar` for 27 source-level DOM checks across
America/Vancouver, UTC and Asia/Tokyo. They exercise site wall-clock placement,
calendar export instants, date labels, keyboard containment and focus restoration.
The same checks gate releases in CI. They complement the real WordPress browser
audit; jsdom supplies no actual rendering. Node manifests, test files and installed
dependencies are excluded from both development and canonical runtime packages.

The site's configured timezone controls labels and calendar export instants.
Confirm that configuration separately before publishing real schedules; changing
it is not part of a code-only local plugin update.

The isolated admin filter regression runs with `php tests/admin-filters.php`. It
checks that join requests filter by sport/level metadata while team lists retain
their taxonomy filters. CI runs it on PHP 8.1–8.4; the stubbed query fixture does
not replace the local WordPress list-table acceptance check.
