# LeagueFlow Architecture Plan

## Goals

- Keep the plugin native to WordPress admin and Gutenberg.
- Support multiple sports through one shared content model instead of separate plugins or tables per sport.
- Derive standings from match data instead of storing a second source of truth.
- Reuse one rendering layer for shortcodes, blocks, and public templates.
- Keep extension points obvious for future features like manual overrides, extra knockout rounds, or richer match events.

## Structural Decisions

### Content Model

- `lf_team` stores club identity and public team pages.
- `lf_player` stores a participant identity with team memberships across sports. Membership helpers keep the primary legacy team and per-team details consistent.
- `lf_match` stores fixtures, results, statuses, and knockout metadata.
- `lf_calendar_event` stores non-match activities such as drop-ins, clinics and venue closures.
- `lf_join_request` stores team/placement requests; its service owns decisions, locks and history.
- `lf_sport` is the shared taxonomy that modularizes teams, players, and matches without fragmenting the plugin into separate codebases.
- `lf_competition` and `lf_season` are taxonomies used to filter teams and matches.
- `lf_competition` and `lf_season` also store `lf_sport_slug` term meta so context filters and blocks stay aligned with the right sport.

### Services

- `Standings_Service` calculates played, wins, draws, losses, goals for, goals against, goal difference, and points from finished matches.
- `Knockout_Service` groups knockout fixtures into rounds and advances winners into linked fixtures.
- `Renderer` centralizes frontend output for blocks, shortcodes, and templates.
- `Sports_Manager` owns built-in sport definitions, enabled sport modules, menu icons, sport-specific match fields, and the legacy soccer-to-multi-sport migration.
- `Field_Availability_Manager` stores site field availability windows, generates open match slots, avoids venue and team conflicts, and links auto-scheduled matches back to the availability window used.
- `Seeder` generates demo data and sample pages.
- `Registration_Consent` validates current personal acknowledgements and appends private evidence snapshots to the player record.
- `Referee` provides assigned-match roster access and optional final-score submission through a dedicated role. Result writes use a shared match lock, stale-state checks and a verified transaction.

### Admin

- `Admin` builds the menu, metaboxes, list-table enhancements, sport filters, settings page integration, demo data action, and validation notices.
- `Admin_Guide` provides staff instructions for seasons, leagues, results, drop-ins, referees and documents.
- `Settings` owns the points system, slugs, display defaults, tie-breakers, and uninstall cleanup option.
- Sport-specific top-level menus are generated at runtime for each enabled sport. The plugin still keeps one shared Teams, Players, and Matches data model underneath.

### Frontend

- Dynamic blocks use `register_block_type_from_metadata()` and the shared renderer.
- Shortcodes mirror the block outputs for classic editor compatibility.
- Lightweight templates support public team pages and match archive/single views.

## Key Flows

### Standings Update Flow

1. Staff explicitly finalize a result, or an assigned and enabled referee submits it.
2. Scores, outcome and match context are validated and saved before the `finished` transition.
3. `Standings_Service` derives tables from published, finished non-knockout matches in the selected context. Missing dates do not hide valid results. Each match uses its competition's points rules.
4. Staff diagnostics explain excluded incomplete, unfinished, unpublished, knockout or invalid-team results.

### Knockout Advancement Flow

1. Admin marks a knockout match as `finished`.
2. `Knockout_Service` determines the winner from override or score.
3. Under the target result lock, permissions, graph context and current state are checked again. A valid winner can fill only an unstarted target with a compatible slot. Feeder provenance prevents overwriting another feeder or a manual assignment.

### Field Availability Scheduling Flow

1. Admin defines recurring or one-off field availability windows.
2. Admin runs the scheduling assistant for a match, selected matches, or a filtered season/competition scope.
3. `Field_Availability_Manager` builds candidate slots, removes slots with venue/calendar conflicts, and avoids overlapping team assignments.
4. Empty match `lf_match_datetime` and `lf_venue` values are filled, while manual values are preserved unless overwrite is selected.
5. Matches keep `lf_field_availability_id`, `lf_schedule_source`, and `lf_schedule_generated_at` meta for auditability.

### Editor Flow

1. Editor inserts a LeagueFlow block.
2. The block inspector provides native controls for sport, team, competition, season, or match selection.
3. Preview uses server-side rendering so editor output stays aligned with frontend output.

### Calendar Range Flow

1. The block renderer supplies public matches/events overlapping its initial date range.
2. Calendar navigation and filters request that range from the bounded calendar REST endpoint. Month/week/day views complete pages serially; list view uses explicit load-more paging.
3. Requests are cancelled or ignored when superseded. Loading, incomplete and failed requests are presented distinctly from a verified empty range.
4. Event and saved match durations are considered for overlap, including entries that began before the selected range. Public editor preview follows the same visibility policy.

### Sport Setup Flow

1. Admin opens `LeagueFlow > Sports`.
2. Enabled sports are stored in `leagueflow_enabled_sports`.
3. `Sports_Manager` ensures the matching `lf_sport` terms exist and marks initial setup complete.
4. Enabled sports receive their own admin menu entries with sport-specific icons and sport-specific dashboards.

## Extension Notes

- A future release can add manual standings overrides through a dedicated admin page without changing the derived table pipeline.
- Match event structures can move to richer arrays or custom tables later if line-by-line text becomes limiting.
- New sports can be introduced by extending `Sports_Manager::get_definitions()` and adding sport-specific labels, match fields, and an icon without redesigning the rest of the plugin.
- Competition rule overrides already exist in term metadata. A future migration should scope team point adjustments to season/competition and record who applied each adjustment.
