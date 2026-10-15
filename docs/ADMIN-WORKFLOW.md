# LeagueFlow admin workflow

This document describes the intended administrator journey and tracks every
finding from the 2026-10-14 read-only admin coherence audit (local Intramurals
copy, LeagueFlow 1.0.3, canonical `7483fc0`). Work is delivered in reviewed
batches on `codex/leagueflow-admin-workflow`.

Status columns are separate on purpose:

- **Implemented**: changed in maintained source (batch number).
- **Tested**: automated evidence. `ADM-xx` = `tests/wordpress-admin.php` on a
  disposable WordPress (6.5 and 7.1.3); `unit` = standalone PHP harness;
  `fixture-browser` = local headless Chromium against the disposable site
  (layout only, not the live site).
- **Applied**: installed on the local Intramurals copy through the reviewed
  WordPress Manager development update and retested there. Empty until that
  happens.

## Intended journey

1. **Setup**: enable sports; League Levels are one global list shared by every
   sport; competitions and seasons each belong to exactly one sport.
2. **Rosters and requests**: teams, players, join requests and placements.
3. **Generate fixtures** for one sport and one division (level).
4. **Schedule** the generated drafts from venue availability, or by hand.
5. **Review and publish** matches; undated matches are visibly *Unscheduled*.
6. **Score** finished matches with explicit scores or a forfeit result.
7. **Standings and brackets** are computed from finished matches.

## Behaviour changed in batch 1 (foundations)

- **Lists keep undated items.** Matches and Calendar Events sort by their own
  date through a `LEFT JOIN`, so rows without a date are never dropped (the
  default `meta_key` sort used an `INNER JOIN`). Default order: *Unscheduled*
  first (they need action), then newest date. Explicit column sorts put empty
  values last in both directions. The same join is used for every LeagueFlow
  meta column sort (short name, jersey number, status…). A new **Unscheduled**
  view lists items with no date, and the date cell says "Unscheduled".
  Existing records are not modified; the 190 undated trashed matches on the
  Intramurals copy simply become visible in Trash.
- **Status views stay in context.** Inside a sport (or level) the
  All/Published/Draft/Private/Trash counts are recomputed for that context and
  their links keep `lf_sport`/`lf_league_level`.
- **Sort indicator.** The Date / Time column carries the initial descending
  sort marker; the publish Date column no longer claims it.
- **Scores.** A match saved as Finished and *played as normal* needs both
  scores. Scores are whole numbers of 0 or more, with no sport-specific cap; the only
  limit is the largest integer PHP stores exactly (`PHP_INT_MAX`), and larger
  values are refused with that limit named. A blank score is never turned into
  0; an explicit 0–0 is kept; forfeits may finish without a score. Invalid
  input is refused before any LeagueFlow metadata is written (core still saves
  the title/status of the post itself, as for the other validation errors).
- **Competition/season sport.** The add form requires a sport (server-side
  check on `add-tag`); a blank edit keeps the stored sport; Quick Edit and
  programmatic updates no longer reset the sport to the first enabled one.
  With a single enabled sport it is preselected.
- **Demo data** is development-only (`WP_ENVIRONMENT_TYPE` local/development,
  `LEAGUEFLOW_ALLOW_DEMO_DATA`, or the `leagueflow_allow_demo_data` filter).
  Settings shows a read-only preview. It is strictly create-only and only for
  an empty league: any existing league record (any status except WordPress's
  own auto-drafts), or any page/term/record using a demo slug, blocks the run
  before a write, **including objects an earlier demo run created** (the
  `_leagueflow_demo_seed` marker is never treated as permission to overwrite;
  remove earlier demo data by hand to create it again). A run requires a
  confirmation box and the preview token; the token fingerprints the existing
  IDs, modification times and league-record counts, so a changed site is
  "stale". If something with a demo slug appears after the preview, creation
  stops with a collision error listing what it had created; the colliding
  record is never adopted, assigned or edited. League levels are reused
  without renaming; only newly created competitions/seasons get a sport.
- **Unambiguous selects.** Selects that always have a value (schedule mode,
  league level, next-match slot, event type/status) no longer show a blank
  first option captioned like a real one.
- **Phones.** Player memberships become labelled cards below 783px; scroll
  regions no longer widen their parent (Player edit was 784px wide at 390/320,
  sport Overview 338–343px at 320). Placements stacks each request with a
  visible "Team" label and a full-width Assign button below 601px. On LeagueFlow
  edit screens below 851px the record's own details come before the taxonomy
  and Publish panels (Publish is at the end of the form on phones).
- **Searchable long selects.** `assets/js/admin.js` (no dependencies) adds a
  filter box to Team Managers, Linked User and Add-to-team. Typing hides
  non-matching options (selected ones stay), a polite live region announces the
  count, and Enter never submits the post form. Without JavaScript the plain
  select still works.
- **Accessible names.** Membership No./Position inputs and Placements team
  selects have contextual names; Overview count rows use row headers; the
  level breakdown is a focusable, labelled scroll region.
- **Asset cache keys.** `admin.css`, `editor.css`, `blocks.js` and the new
  `admin.js` use plugin version + content hash like the frontend assets.

## Behaviour changed in batches 2–3 (navigation, context, requests)

- **One LeagueFlow menu.** Overview, Teams, Players, Requests, Matches,
  Generate fixtures, Venues & availability, Calendar Events, Standings,
  Knockout Brackets, Competitions, Seasons, League Levels (all sports), Sports,
  Utilities, Settings — in workflow order. The per-sport top-level menus and
  their 17 entries each (including the Add links) are gone. WordPress supports
  one submenu level, so the sport is a context on the screen, not a menu.
- **Sport context bar** above every LeagueFlow screen: breadcrumb (LeagueFlow ›
  sport › level › screen) and an "All sports / Soccer / …" switcher that keeps
  the current screen and resets paging and sport-specific filters. Edit/add
  screens show "Back to Teams (Soccer)" for the record's own sport; core Add
  New links carry `lf_sport` (server-side `admin_url` filter, LeagueFlow paths
  only). The League Levels screen states that levels are shared by all sports
  and that counts include every sport.
- **Old deep links keep working.** `admin.php?page=leagueflow-sport-{sport}`,
  `…-fixtures`, `…-standings`, `…-brackets`, `…-fields` and
  `leagueflow-placements` stay registered as hidden aliases (same
  capabilities) that redirect to the unified screen with `lf_sport` (and keep
  competition/season/level/request filters). `edit.php?…&lf_sport=` links were
  already native.
- **Per-sport tools ask for a sport.** Generate fixtures and Knockout Brackets
  no longer silently default to the first enabled sport; without a sport they
  show a "choose a sport" page. Standings without a sport show every sport
  grouped by level; with a sport, the table plus a note when levels are mixed.
- **Players in a sport** show only that sport's team, number, position and
  captaincy, its level, and "(also Basketball…)" for other sports (C-06).
- **Overview** has a *Needs attention* queue (join requests, players needing
  placement, requests with problems, undated matches, draft matches, missing
  venue availability), in-context work links (including Add links), and either
  a per-sport summary or the sport's counts and level breakdown.
- **Names and empty states.** "Generate fixtures", "Venues & availability",
  Calendar Events described as non-match items; list and term empty states say
  what to do next (C-10/C-11); the Utilities placeholder row is gone (C-14).
- **Lists and forms.** Secondary Matches/Teams/Players columns are hidden by
  default (Screen Options shows them); the Fixture column is wider. The core
  League Level box is removed from Team/Match/Event edit and the core Sport box
  from Match edit, because the details box already sets them (U-06).
- **Requests inbox** (`admin.php?page=leagueflow-requests`, capability
  `leagueflow_manage_placements`, pending count in the menu): tabs *All /
  Join a team / Needs placement*, statuses *Pending / Approved / Declined /
  Dismissed / All*, sport filter, player search, "only requests that need
  attention", paging, and a detail panel with the player's current team in the
  sport, the note, flags and history. Actions, each in its own form with an
  action- and record-bound nonce, and re-validated before any write:
  *Approve and add to team*, *Assign to team* (eligible published or private
  teams in the requested sport and level), *Close: already on the team* (no
  roster change), *Move to Needs placement* (target trashed/missing/draft/
  wrong sport or level; keeps the original team), *Decline* and *Dismiss*
  (reason required; never remove anyone from a roster; nothing is deleted).
  A request without a player can only be dismissed — no player is invented.
  Flags: player missing, team missing/trashed/not published, sport or level
  mismatch, already on the requested team, already on another team in the
  sport. Optional e-mails reuse the existing placement wording (on by default
  for approve/assign, off for decline).
- **Reconciliation.** When a roster gains a membership (portal, admin player
  or team save, request approval), the same player's *pending* requests that
  the new membership satisfies are closed as approved with a "Closed by a
  roster change" history entry. It never runs on activation, upgrade or page
  view, never touches other sports/levels, and is idempotent. Existing stale
  requests are only flagged until someone chooses a reviewed action.
- **Request records** can no longer be created empty by hand
  (`create_posts` → `do_not_allow`); the classic edit screen shows a read-only
  summary that links to the inbox review; list rows get a *Review* action.
- **Counts follow core visibility.** Context status counts use
  `perm => readable`, so an Author/Contributor sees counts for exactly the
  rows core lists for them (other users' private records are not counted);
  core's *Mine* view is kept and counted in context.
- **Scores** have no sport cap: any whole number up to PHP's integer maximum.

## Changed after review of batches 1–3

- **Demo data (review A + sport terms).** Seeding is strictly create-only.
  It no longer calls the sport-term synchroniser: missing enabled sport terms
  are created, existing ones (and any name or description an editor gave
  them) are reused untouched. Note: LeagueFlow's existing `init` hook still
  re-synchronises enabled sport names from the sport definitions on every
  request, as it did before this branch; that pre-existing behaviour is not
  changed here.
- **Requests (reviews F1–F6).**
  - *F1* The generic membership setters (`set_player_team_ids`,
    `assign_player_to_team`, the one-time migration, demo data, portal
    rollbacks) never reconcile requests. Only explicit, authorized roster
    entry points call `reconcile_roster_additions()`: the player edit screen,
    a staff or captain roster add in the portal, a portal join-request
    approval, captain team creation (after every rollback point) and an inbox
    approval/assignment.
  - *F2* A missing or unrecognised stored status is shown as *Needs status
    review*, never as pending: the only actions are *Reopen as pending*
    (reason required; the old value is kept in `lf_previous_request_status`
    and the history) or *Dismiss*. Reconciliation requires an exact `pending`
    status and the same sport, level (when stored) and team (named requests).
  - *F3* *Close: already on the team* for a placement request needs exactly one
    membership that fits the requested sport and level. Otherwise the request
    is flagged *membership conflict* and only Decline/Dismiss are offered. A
    trashed, missing or wrong-sport/level named target is never treated as
    satisfied because membership meta still contains its ID.
  - *F4* Teams in any roster status (published, private, draft, pending,
    scheduled) are valid targets for staff who can edit them; drafts are marked
    "(draft, not public yet)" and flagged as a warning, not as nonexistent.
    Assignable teams are filtered per team with `edit_post`, so another user's
    private team title is never offered.
  - *F5* The shared service checks `leagueflow_manage_placements` and
    `edit_post` on the request before every action (not only the screen
    handler), plus `edit_post` on the team and player for roster actions.
    Captains keep their existing owned-team review in the portal and get no
    inbox access.
  - *F6* One shared service instance (`Join_Request_Service::shared()`;
    a second instance cannot register a duplicate hook). Every action runs
    under a per-request database lock (`GET_LOCK`), re-reads the stored state,
    and checks the state token the reviewer saw. Double submissions, stale
    forms and a concurrent reviewer are refused without writes; history is
    appended once per decision.

## Changed after the final review (G1–G4, H1–H5)

Implemented in maintained source and tested on disposable sites only; not yet
applied to the Intramurals copy.

- **G1 (draw policy).** No change needed: nothing infers a no-draw rule from
  a sport's description. The admin standings show the D column only when a
  row in that table has a draw; points always use the effective rules, which
  equal the global settings until a sport or competition override is saved.
- **G2 (division context).** The Requests inbox has a *Level* filter
  (`lf_league_level`) that is kept on the type tabs, status counts, search,
  paging, the detail panel, every action's return address and the Back link.
  Requests and players have no competition or season of their own, so neither
  screen offers an always-empty competition/season filter; both say so and
  point to the team lists.
- **G3 (request record state).** A request in the Trash, an auto-draft or any
  status other than published/private/pending/draft/scheduled is flagged
  *cannot be reviewed*, offers no actions, and is refused inside the locked
  re-check for every path (staff, captain, reconciliation).
- **G4 (decision layout).** The primary decision (Approve, Assign or *Close:
  already on the team*) stays visible. Decline, Dismiss, *Move to Needs
  placement* and *Reopen* sit in a native `<details>` disclosure (keyboard and
  no-JS friendly), each its own form with an action- and record-bound nonce
  and a required reason; it starts open when there is no primary decision.
- **H1 (locks fail closed).** All LeagueFlow named locks go through
  `acquire_db_lock()`: only an explicit `1` from `GET_LOCK` counts. A timeout,
  an error or an unavailable lock service (`NULL`) changes nothing and shows
  a "busy or lock unavailable, nothing changed" message. This covers request
  review, reconciliation (the request stays pending), fixture generation and
  mapping.
- **H2 (HTTP freshness).** The inbox handler requires a well-formed state token
  (16 hex characters) on every POST; an omitted, empty or malformed token is
  sent to a fresh review without writes. The service checks the token whenever
  the `state` key is passed (even empty). Programmatic callers that omit the
  key keep every other check. The deprecated Placements form no longer writes:
  after its capability and nonce checks it opens a fresh review.
- **H3 (locked re-checks).** After taking the lock and clearing the caches the
  service re-reads the request record and its post state, re-checks the
  caller's authority (staff capability and `edit_post`, or the captain's
  managed-team check), then the business state and token. An unknown status
  with a missing player can only be dismissed; dismissing keeps the raw stored
  status in `lf_previous_request_status` and names it in the history.
- **H4 (one review path).** The portal captain review now calls
  `Join_Request_Service::apply_team_manager_decision()`: the same lock, fresh
  re-read and state token as the inbox. Captains keep their owned-team
  authority (passed as a callback) and get no inbox access; placement requests
  stay staff-only. The portal form carries the state token; a stale or
  stateless form shows *That request changed or was reviewed by someone else*
  and changes nothing. History and the decision e-mail happen once, only for
  the winning decision. Older portal requests without a stored sport keep the
  managed team's sport, as before.
- **H5 (mapping and generation writes).**
  - Mapping apply takes a site-wide mapping lock, clears the records' term
    caches, recomputes the plan (capability, sport context, records, existing
    assignments) and compares the user-bound preview token, then consumes the
    preview once (`delete_transient` must succeed). Of two conflicting
    previews only the first applies; the second is stale and writes nothing.
  - Fixture generation records every refused match insert (`failed_pairings`)
    and every match saved without part of its scope (`incomplete_ids`, kept
    for review, never deleted). An early stop keeps the earlier levels' work.
    The result always has the same keys, including `scheduled`,
    `unscheduled`, `partial` and `partial_error`; the notice reports each
    count. Generated matches also store their scope (`_lf_fixture_scope`), so
    the duplicate check still sees a match whose terms could not be saved and
    a fresh preview offers only the pairing that failed.
  - WordPress core boundary: `wp_set_object_terms()` does not check its
    relationship INSERT, so a refused write can still return term IDs. Mapping
    and generation therefore read each assignment back
    (`object_term_saved()`) before reporting it as applied. Writes are not
    all-or-nothing: successful ones are kept and failures are listed for a
    fresh preview.

## Behaviour changed in batch 4 (seasons, setup check, mapping)

- **Current season per sport (C-07).** Marking a season current stores it for
  that season's sport only (`leagueflow_current_seasons`). Other sports keep
  theirs. The Seasons list has a *Current* column. Legacy data keeps working:
  a season flagged with the old site-wide `lf_is_current` meta is still used
  exactly as before while no sport has its own current season; once a sport
  has one, that sport's legacy flag is superseded and another sport's legacy
  flag no longer leaks into it. A season without a sport keeps the old
  site-wide flag. Deleting a season removes it from the map; caches are reset
  after every change.
- **All / Current / one season (N-02).** `resolve_season_context()` accepts
  `all` (every season) and `current`. Admin season filters default to *All
  seasons*, offer *Current season (name)* explicitly, and never turn All into
  the current season; Knockout Brackets passes `all` to the renderer. Public
  blocks and shortcodes are unchanged: an empty season still means "current
  season if one applies, else all", now using the block's own sport.
- **Setup check (C-16, C-09, C-11).** New screen *LeagueFlow › Setup check*
  (`edit_others_posts`). Read-only per sport: shared levels, competitions,
  seasons, current season, teams, teams without a level, teams/matches without
  a competition or season, matches, venue availability rules. Opening it never
  writes.
- **Explicit mapping (C-16).** On a sport's Setup check, staff can give a
  competition or season to teams or matches that have **none**. LeagueFlow
  never guesses: the person picks the sport, type, target (owned by that
  sport) and each record (nothing preselected). A preview lists every change;
  records that already have one, belong to another sport, are trashed or not
  editable are conflicts that block the whole apply. Applying needs the
  confirmation box, the action nonce and the stored single-use preview, and
  re-validates the records' current state (title, status, sport, modified
  time); anything changed is refused as stale before the first write.
  Existing assignments are never replaced. Nothing runs on activation,
  upgrade or page view.
- **List filters.** Teams and Matches lists can be filtered by competition and
  season, including *No competition* / *No season* (the Setup check links to
  those views). Choices name their sport when *All sports* is selected.
- **Overview.** A sport's Overview shows its current season; the attention
  queue counts teams and matches without a season and links to Setup check.

## Behaviour changed in batch 5 (fixtures, scheduling, scoring)

- **Generate fixtures (U-05, C-09).** The page is a server-side preview: teams
  are grouped by level (division) and fixtures are only made within a level,
  matching the manual Add Match rule. Teams without a level are named and left
  out. With *All levels* and several levels in scope, a person must pick one
  level or explicitly choose *Generate each level separately*. Each level
  shows new pairings and pairings that already exist (same sport, level,
  competition and season; knockout matches ignored); duplicates are never
  recreated. Generating re-runs the preview under a per-sport lock and
  requires the preview token, so a double click, a stale page (a team added,
  another run finished) or a forged competition/season from another sport
  creates nothing. Created drafts are tagged with the run and the result
  notice links to exactly those drafts (`lf_batch`). Without venue
  availability, the scheduling option is replaced by the prerequisite and a
  link; with it, the result reports how many fixtures got a date and how many
  stay *Unscheduled*. Generated drafts have no slug until first publication,
  so their address comes from the then-current title (P-01); published slugs
  stay stable.
- **Scheduling assistant (C-09, C-13).** With no availability rules the
  assistant form is replaced by an explanation and links (availability form,
  Unscheduled matches). With *All sports*, competition and season choices name
  their sport, and a competition or season from another sport is refused
  before anything is scheduled. Manual scheduling and the existing overwrite
  capability are unchanged.
- **Knockout targets.** *Advance winner to* lists only later-round knockout
  matches that the existing `can_advance_to()` validation accepts (same sport,
  level, competition and season, no loop), labelled with round, title and
  date. An existing link that is no longer valid stays visible and marked.
  Saving still uses the same validation.
- **Scoring by sport and competition (C-08).** *Settings › Scoring by sport
  and competition* shows the scoring each sport (and customised competition)
  uses and where each value comes from. Every scope inherits the global
  settings until someone saves an override (`leagueflow_rule_overrides`);
  unticking it inherits again. A competition override applies only to its
  own sport's tables. With no overrides the standings are byte-for-byte the
  global results. No league policy is invented and nothing is migrated.
  Tie-breaker and forfeit labels are sport-neutral.
- **Standings (U-05, C-08).** A sport's admin Standings shows one table per
  level by default (or the chosen level), states the table points in use, and
  shows the D (draw) column only when a team in that table has a draw. Public
  blocks are unchanged.
- **Team save (C-15).** Re-synchronising a team's sport/level onto its players
  now loads only that team's members (database pre-filter on the stored
  membership meta, confirmed per player; private, draft and legacy string
  IDs included) instead of every player on the site. The public roster uses
  the same lookup with the same statuses.

## Changed in the final UI batch (team editor review, 2026-10-14)

Source changes after proposal `90cfa6a8`; tested on disposable sites only and
**not installed** on the local Intramurals copy yet.

- **Team sport (T-01).** Team Details now starts with a required **Sport**
  selector listing only enabled sports that have a sport term. A sport chosen
  in the list context (`lf_sport`) is preselected; *All sports* preselects
  nothing. Once a team has a sport it is shown read-only, because player
  memberships, requests and matches are checked against it; a submitted
  value (stale or forged form) never changes it. Older teams keep every
  existing sport term (including several sports or a sport that is no longer
  enabled, which is labelled "(not enabled)"); an older team without a sport
  gets the selector so it can be fixed. A first publication without a valid
  sport is held as a draft; an already published sportless team keeps its
  status. Every free-form sport path is gone: the `lf_sport` taxonomy is now
  registered with `show_ui => false`, which removes the generic term screen,
  the Sports tag box (Team, Player, Match), the Quick Edit "Sports" field and
  the block-editor Sports panel (Calendar Events are the only LeagueFlow type
  edited in the block editor). The list-table Sport column, REST exposure and
  every existing term assignment are unchanged. Player League Level stays a
  tag box (players without a team have no other level control).
- **League Level duplicates (U-06 follow-up).** Quick Edit no longer offers a
  free-form League Levels field for Teams, Matches or Calendar Events (it
  saved without the details box, skipping the team-to-player level sync), and
  the Calendar Event block editor no longer shows its League Levels sidebar
  panel (`removeEditorPanel`, editor-only inline script). Players keep both.
- **Calendar event sport (T-01).** Calendar Event Details has the same
  enabled-sport selector; an event may move to another enabled sport, a blank
  or disabled choice keeps its current sport, and a current sport that was
  later disabled stays listed and selected.
- **Player sports (T-01).** Player Details shows the player's sports
  read-only, explaining that they come from team memberships.
- **No silent default sport (T-02).** `assign_default_sport_if_missing()`
  only assigns an explicit, enabled sport from the screen context (or an
  older form's hidden field). It no longer falls back to the first enabled
  sport or to an invented Soccer. Sports_Manager itself still treats Soccer
  as enabled when the enabled list is empty (its existing default); when no
  enabled sport has a term the selector is replaced by a setup notice linking
  administrators to LeagueFlow > Sports. While initial sport setup is not
  finished, administrators see a link to confirm it.
- **Sport term authority (T-06).** `lf_sport` term create/rename/delete now
  needs `manage_options`, the capability of the existing LeagueFlow > Sports
  screen that owns sport terms. Assigning an existing sport keeps WordPress's
  default (`edit_posts`). League Levels, Competitions and Seasons keep
  `manage_categories` (unchanged; LeagueFlow's menu uses that capability).
  No role is added or removed.
- **Settings labels (T-03).** Every Settings checkbox has an `id`, a row
  title linked with `label_for`, and a label naming its effect (the three
  display toggles used to say only "Enabled"; the uninstall toggle now lists
  what is deleted). The responsive panel grid is unchanged.
- **Team Roster (T-04).** The read-only roster now says roster changes are
  made on each player record, gives every row a "Manage player" link with an
  accessible name (only when the user may edit that player), offers "Add a
  new player to this team" (`post-new.php?post_type=lf_player&lf_sport=…&lf_add_to_team=…`)
  and an "All <sport> players" list link. `lf_add_to_team` is handled by the
  server: on a new player only, it preselects (never saves) that team in
  "Add to team" if it is an existing, non-trashed team the user can edit; the
  normal save validation still applies. The roster table sits in a labelled,
  keyboard-scrollable region for 320/390.
- **Sidebar help (T-05).** The Team Details note no longer claims taxonomy
  boxes for all assignments; it says the Competitions and Seasons sidebar
  boxes add the team to existing ones for its sport, and that new ones with a
  sport are created under LeagueFlow > Competitions or Seasons. (Those two tag
  boxes are unchanged and can still type a new name; that is recorded as a
  remaining limit.)
- **Other Utilities removed (C-14).** The "Other Utilities" card was removed
  in batch 2: its "Player roster exporter — Available" row only repeated the
  real Player Roster Exporter card above it, and its "Additional import,
  audit, and cleanup tools — Ready for future utilities" row had no
  implementation behind it. The real exporter, its form, nonce and
  published-only default are unchanged; no placeholder replaces the card.
- **Remaining limits of this batch.** A team's sport cannot be changed in
  wp-admin by design (add a new team instead). The Competitions and Seasons
  tag boxes can still type a new name, which creates a term without a sport.
  Sports_Manager still treats Soccer as enabled when the enabled list is
  empty. All of this is source plus disposable-site evidence; nothing here is
  installed on the Intramurals copy yet.

## Finding checklist

Implemented = in maintained source on `codex/leagueflow-admin-workflow`.
Tested = automated, on disposable `leagueflow.test` sites (WordPress 6.5 and
7.1.3) unless marked otherwise. Applied = installed on the local Intramurals
copy through the reviewed update **and** retested there; empty means not yet.

| ID | Finding | Implemented | Tested | Applied |
|---|---|---|---|---|
| C-01 | Undated matches/events hidden by the default and date sorts; Trash showed 0 of 190 | batch 1 | ADM-01 (old code fails), fixture-browser | |
| N-05 | Sort arrow on the publish Date column | batch 1 | ADM-01 header check | |
| C-04 | Sport lists show all-sport status counts; links drop the sport | batch 1; readable-only counts after review B | ADM-02, ADM-07 (Author) | |
| N-01 | Finished + blank score silently became 0–0; negative/decimal accepted | batch 1; no invented cap after review C | ADM-03 | |
| N-03 | Blank "Select a sport" saved as the first enabled sport (also via Quick Edit) | batch 1 | ADM-04 | |
| R-01 | One-click demo seeding could overwrite same-slug pages and real terms | batch 1; strict create-only after review A; sport terms reused untouched | ADM-06 (edits survive rerun; post-preview collision; renamed sport kept), mutation M13 | |
| B-02 | Duplicate first-option captions | batch 1 | ADM-05 | |
| N-04 | Membership No./Position inputs unnamed | batch 1 | ADM-05, fixture-browser | |
| D-02 | Placements team selects unnamed | batch 1 (inbox Assign select labelled) | ADM-05, Expect audit (fixture) | |
| D-01 | Placements Assign control escapes its table at 320 (root's fresh sweep found no page overflow) | batch 1 | fixture-browser | |
| C-18 | Player edit 784px wide at 390/320 | batch 1 | fixture-browser (784 → 390/320) | |
| C-19 | Sport Overview level table overflows at 320 | batch 1 | ADM-05 markup, fixture-browser | |
| U-03 | Team Managers (274) / Linked User (272) unsearchable | batch 1 (filter box) | ADM-05 markup; fixture-browser | |
| U-06 | Details far down on phones; duplicated level/sport boxes | batches 1–2 | fixture-browser; ADM-08 | |
| S-01 | admin.css/editor.css/blocks.js cached by plugin version only | batch 1 | unit `tests/asset-versions.php` (old fails) | |
| B-01 | Edit screens and Add New lose the sport context | batch 2 | ADM-08; fixture-browser | |
| C-05 | Each sport's "League Levels" is the global list with cross-sport counts | batch 2 | ADM-08 | |
| C-06 | Multi-sport players show another sport's team/level | batch 2 | ADM-08 | |
| N-06 | Global Matches list has no menu entry | batch 2 | ADM-08 | |
| C-11 | Empty states without next steps; Overview quick links incomplete | batches 2 and 4 (Setup check, attention item) | ADM-08, ADM-11 | |
| C-14 | Utilities placeholder row ("Other Utilities": duplicate exporter row and an unimplemented "future utilities" row) | batch 2; documented in final UI batch | ADM-08 | |
| C-10 | Empty term screens say "No tags found." | batch 2 | ADM-08 | |
| C-02 | Roster adds leave matching join requests Pending | batch 3; explicit entry points after F1/F2 | ADM-09 (F1, F2, F6), POR-04 portal roster add; mutations M06, M09 | |
| C-03 | Requests target trashed/missing teams; no flag or reviewed repair | batch 3; F3/F4 | ADM-09; mutations M10, M16 | |
| U-01/U-02 | Named-team requests cannot be approved/declined; no status filter | batch 3; F2/F5/F6 | ADM-09; mutations M07, M08, M15 | |
| C-12 | Placements global-only; orphan request cannot be dismissed | batch 3 | ADM-09 | |
| C-07 | One global current season set from per-sport screens | batch 4 | ADM-10; mutation M02 | |
| N-02 | Brackets "All seasons" resolves to the current season | batch 4 | ADM-10 (brackets render); mutation M01 | |
| C-16 | Teams/matches without season/competition: validation and explicit mapping | batch 4 | ADM-11; mutation M04 | |
| C-09 | No availability rules but scheduling enabled without a prerequisite | batch 5 | ADM-12, ADM-14; mutation M12 | |
| U-05 | Fixture generation/standings mix levels by default | batch 5 | ADM-12, ADM-13; mutations M03, M14 | |
| C-08 | Global soccer-worded scoring; D column for sports without draws | batch 5 | ADM-13; mutation M05 | |
| C-13 | Availability/scheduling competition list mixes sports | batch 5 | ADM-14 | |
| C-15 | `save_team_meta()` loads every player per team save | batch 5 | ADM-14; mutation M11 | |
| P-01 | Published slugs stay stable (policy) | no change: drafts get their slug at first publish | ADM-12 (generated draft has no slug yet) | |
| P-02 | Export default "Active published records only" (policy) | unchanged by design | — | |
| G1 | No sport-description draw inference | review: already data-driven | ADM-13 | |
| G2 | Requests keep division context; no fake season/competition filter for requests/players | final review | ADM-09 G2 | |
| G3 | Trashed/auto-draft request records rejected inside locked checks | final review | ADM-09 G3 | |
| G4 | Primary decision visible; secondary decisions in native `<details>` | final review | ADM-09 G4 | |
| H1 | Request/generation locks accepted `NULL` as success | final review (fail closed) | ADM-09 H1, ADM-11 H5, ADM-12 H5 (NULL injection and held lock) | |
| H2 | Omitted HTTP state token skipped the freshness check | final review | ADM-09 H2 (omitted/empty/forged/stale player/stale team; legacy form) | |
| H3 | Authority/record state not re-checked after the lock | final review | ADM-09 H3 (trashed, status and capability changed while waiting) | |
| H4 | Portal captain review outside the request lock | final review | ADM-09 H4; POR-04 (stateless, stale, staff-first, locked re-read); HTTP-06 (rendered token, removed token, stale page) | |
| H5 | Mapping not serialized; term/insert errors ignored; incomplete partial result | final review | ADM-11 H5, ADM-12 H5 (conflicting previews, held/NULL lock, injected term and insert failures, early stop) | |
| T-01 | Free-form sport entry (tag boxes, Quick Edit, block-editor panel, generic term screen); no safe team/event sport control | final UI batch | ADM-15 (selector, preselect, disabled/invented/blank refused, read-only, legacy multi/disabled/sportless kept, publish held as draft, nonce/capability, show_ui/Quick Edit/editor panel); mutations U-M2/M3/M4/M9/M10; fixture-browser (1440/390/320, publish blocked without sport, event sidebar) | |
| T-02 | Default sport silently first enabled sport / Soccer | final UI batch | ADM-15 (player without context, disabled context); mutation U-M1 | |
| T-03 | Settings checkboxes without id/label_for; three toggles said only "Enabled" | final UI batch | ADM-15 (all five checkboxes); mutation U-M6 | |
| T-04 | Team Roster read-only dead end | final UI batch | ADM-15 (manage/add/list links, region, preselect only if editable, no write); mutations U-M7/U-M8 | |
| T-05 | Team sidebar competition/season help inaccurate | final UI batch (text) | ADM-15 render; review | |
| T-06 | Sport terms editable by anyone with manage_categories / created by edit_posts tag box | final UI batch | ADM-15 (Editor vs Administrator caps); mutation U-M5 | |
| U-06b | Quick Edit and Calendar Event block-editor League Levels duplicated the details box | final UI batch | ADM-15 (Quick Edit filter, editor script per type); fixture-browser event sidebar | |

Earlier usability IDs from the first admin audit map onto these rows: U-04
(Matches density) → U-06/batch 2 columns; U-07 (menu sprawl) → B-01/C-05;
U-08 (orphan request) → C-12; U-09 (Players/Teams lists) → C-06; U-10
(Overviews, empty states) → C-11; U-11 (sport-agnostic rules) → C-08.

Players have no competition or season of their own (they belong to teams), so
no player list adds a competition or season filter; the sport context filters
players by their sport terms only.

## Testing

`tests/wordpress-admin.php` groups: ADM-01 undated lists, ADM-02 context
views, ADM-03 scores, ADM-04 term sport, ADM-05 selects/labels, ADM-06 demo
data, ADM-07 count visibility (Author), ADM-08 navigation/context, ADM-09
requests inbox (nonce missing/forged/other action/other request/other user,
forged ID, unauthorized role, cross sport/level, trash/missing/draft targets,
double submission, explicit-entry reconciliation, unknown statuses, ambiguous
memberships, service-level authority, stale state token, a second database
connection holding the request lock, history counts), ADM-10 current seasons
and All/Current semantics (including the Brackets render), ADM-11 Setup check,
list filters and the previewed single-use mapping, ADM-12 fixture generation
(level scope, duplicates, stale and repeated submissions, forged scope,
batch link, scheduling prerequisite), ADM-13 scoring overrides and per-level
standings, ADM-14 scheduling scope, knockout targets and the team-member
lookup, ADM-15 Team/Event sport controls, sport term authority, Settings
labels and Team Roster links. `tests/wordpress-portal.php` POR-04 also checks portal review history
and that an authorized portal roster add closes the matching request once.

The final-review cases inject faults without touching core: a `query` filter
turns `GET_LOCK` into `SELECT NULL` (unavailable lock service) or runs a
change "while waiting" just before the lock query; a second MariaDB
connection holds a lock (a concurrent process); a `query` filter makes one
term-relationship INSERT fail; `wp_insert_post_empty_content` refuses one
match insert. `tests/wordpress-portal-http.php` HTTP-06 reads the state token
from the rendered portal form and submits it over real HTTP.

A dev-only mutation runner re-introduced 16 old behaviours one at a time
(each F1–F6 rule, the seeder sync, per-sport seasons, Brackets "all",
mapping token, duplicates, level scope, overrides, prerequisites, member
lookup); the suite failed on every one.

- `php tests/asset-versions.php`, `php tests/admin-assets.php` (standalone).
- `LEAGUEFLOW_DISPOSABLE_WP=1 wp eval-file tests/wordpress-admin.php` on a
  disposable `http://leagueflow.test` site only. It refuses any other home URL,
  blocks outbound HTTP and mail, cleans up its own `lfa ` fixtures, and runs the
  successful demo-seeding case inside a rolled-back transaction. Never run it on
  the Intramurals database.
- Phone layout was measured on the disposable site with local headless Chromium
  (fixture evidence). Live acceptance on the Intramurals copy happens after the
  reviewed update is installed.
