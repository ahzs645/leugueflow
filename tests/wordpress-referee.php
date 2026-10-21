<?php
/** Assigned referee permissions, result concurrency, admin date validation and season form state. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'LEAGUEFLOW_DISPOSABLE_WP' ) || 'http://leagueflow.test' !== get_option( 'home' ) ) {
	throw new RuntimeException( 'Run only on the disposable leagueflow.test site.' );
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'pre_http_request', static function() { return new WP_Error( 'offline', 'Test blocks outbound HTTP.' ); }, PHP_INT_MAX );
$GLOBALS['lfr_checks'] = 0;
function lfr_check( $condition, $message ) {
	++$GLOBALS['lfr_checks'];
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$lfr_posts = array(); $lfr_users = array(); $lfr_seasons = array();
$lfr_settings = get_option( 'leagueflow_settings' );
$lfr_timezone = get_option( 'timezone_string' );
$lfr_current_seasons = get_option( \LeagueFlow\CURRENT_SEASONS_OPTION, null );
$lfr_admin_id = get_user_by( 'login', 'admin' )->ID;
$lfr_old_user = get_current_user_id();
$lfr_old_post = $_POST; $lfr_old_get = $_GET;
$lfr_term = 0;

try {
	wp_set_current_user( $lfr_admin_id );
	\LeagueFlow\Referee::ensure_roles();
	update_option( 'timezone_string', 'America/Vancouver' );
	$settings = \LeagueFlow\defaults(); $settings['registration_documents'] = array();
	update_option( 'leagueflow_settings', $settings );
	$term = wp_insert_term( 'Referee test ' . wp_generate_uuid4(), 'lf_sport' );
	lfr_check( ! is_wp_error( $term ), 'Create referee context.' ); $lfr_term = $term['term_id'];
	$user = function( $role ) use ( &$lfr_users ) {
		$id = wp_insert_user( array( 'user_login' => 'lfref_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password( 10, false ) . '@example.test', 'role' => $role ) );
		lfr_check( ! is_wp_error( $id ), 'Create isolated test user.' ); $lfr_users[] = $id; return $id;
	};
	$ref = $user( 'leagueflow_referee' ); $other = $user( 'leagueflow_referee' ); $subscriber = $user( 'subscriber' );
	$post = function( $type, $name, $meta = array(), $status = 'publish' ) use ( &$lfr_posts, $lfr_term ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $name, 'post_status' => $status ), true );
		lfr_check( ! is_wp_error( $id ), 'Create isolated referee record.' ); $lfr_posts[] = $id;
		wp_set_object_terms( $id, array( (int) $lfr_term ), 'lf_sport' );
		foreach ( $meta as $key => $value ) { update_post_meta( $id, $key, $value ); } return $id;
	};
	$a = $post( 'lf_team', 'Assigned Falcons' ); $b = $post( 'lf_team', 'Assigned Bears' ); $secret = $post( 'lf_team', 'Unassigned Secret Team' );
	$p = $post( 'lf_player', 'Assigned Player', array( 'lf_email' => 'secret-address@example.test', 'lf_age' => 27, 'lf_user_id' => $subscriber ), 'private' );
	\LeagueFlow\assign_player_to_team( $p, $a, array( 'jersey_number' => 12, 'position' => 'Forward' ) );
	$q = $post( 'lf_player', 'Unassigned Secret Player', array( 'lf_email' => 'unassigned@example.test' ), 'private' );
	\LeagueFlow\assign_player_to_team( $q, $secret );
	$m = $post( 'lf_match', 'Assigned Match', array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled', 'lf_match_datetime' => '2026-11-29 18:30' ) );
	$n = $post( 'lf_match', 'Unassigned Match', array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $secret, 'lf_status' => 'scheduled' ) );
	$service = new \LeagueFlow\Referee();
	add_post_meta( $m, \LeagueFlow\Referee::ASSIGNMENT_META, $ref );
	update_post_meta( $m, \LeagueFlow\Referee::SCORE_META, 0 );

	wp_set_current_user( $ref );
	lfr_check( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_users' ) && ! current_user_can( 'edit_post', $m ), 'Referee has no general content or user editing capability.' );
	lfr_check( \LeagueFlow\Referee::can_access( $m ), 'Assigned official can read rosters.' );
	lfr_check( ! \LeagueFlow\Referee::can_access( $n ), 'Other matches are out of scope.' );
	lfr_check( ! \LeagueFlow\Referee::can_access( $m, true ), 'Per-match roster-only assignment cannot submit scores.' );
	$_GET = array( 'lf_referee_match' => $m );
	$html = $service->render();
	lfr_check( false !== strpos( $html, 'Assigned Player' ) && false !== strpos( $html, '12' ), 'Assigned private roster is shown with playing details.' );
	lfr_check( false === strpos( $html, 'secret-address@example.test' ) && false === strpos( $html, 'Unassigned Secret Player' ) && false === strpos( $html, 'unassigned@example.test' ), 'Referee page never exposes contact information or unrelated players.' );
	$_GET = array( 'lf_referee_match' => $n ); $html = $service->render();
	lfr_check( false === strpos( $html, 'Unassigned Secret Team' ) && false === strpos( $html, 'Unassigned Secret Player' ), 'Forged match ID reveals no roster or team details.' );
	$rest = rest_do_request( '/wp/v2/lf_player' );
	lfr_check( 403 === $rest->get_status(), 'Referee cannot browse the full WordPress player REST directory.' );
	$state = \LeagueFlow\Referee::state_token( $m );
	lfr_check( is_wp_error( $service->submit_result( $m, '2', '1', $state ) ), 'Roster-only score write denied.' );
	update_post_meta( $m, \LeagueFlow\Referee::SCORE_META, 1 );
	$state = \LeagueFlow\Referee::state_token( $m );
	lfr_check( \LeagueFlow\Referee::can_access( $m, true ), 'Enabled assigned referee can enter final scores.' );
	foreach ( array( '', '-1', '1.2', '1e3', array( '2' ), true, (string) PHP_INT_MAX . '0' ) as $bad ) {
		lfr_check( is_wp_error( $service->submit_result( $m, $bad, '1', $state ) ), 'Invalid score is rejected.' );
		lfr_check( ! metadata_exists( 'post', $m, 'lf_home_score' ), 'Invalid score attempt makes no writes.' );
	}
	wp_set_current_user( $other );
	lfr_check( is_wp_error( $service->submit_result( $m, '2', '1', $state ) ), 'Unassigned referee denied even with a known token.' );
	wp_set_current_user( 0 );
	lfr_check( is_wp_error( $service->submit_result( $m, '2', '1', $state ) ), 'Anonymous score writes denied.' );
	wp_set_current_user( $ref );
	update_post_meta( $m, 'lf_home_score', '1' );
	$result = $service->submit_result( $m, '2', '1', $state );
	lfr_check( is_wp_error( $result ) && 'stale' === $result->get_error_code() && '1' === get_post_meta( $m, 'lf_home_score', true ), 'Stale form cannot overwrite a newer score.' );
	delete_post_meta( $m, 'lf_home_score' );
	$state = \LeagueFlow\Referee::state_token( $m );
	update_post_meta( $m, 'lf_match_datetime', '2026-12-07 18:30' );
	$result = $service->submit_result( $m, '2', '1', $state );
	lfr_check( is_wp_error( $result ) && 'stale' === $result->get_error_code(), 'Rescheduling invalidates the old score form.' );
	$state = \LeagueFlow\Referee::state_token( $m );
	$reject = static function( $check, $id, $key ) use ( $m ) { return $id === $m && 'lf_away_score' === $key ? false : $check; };
	add_filter( 'add_post_metadata', $reject, 10, 3 );
	$result = $service->submit_result( $m, '2', '1', $state );
	remove_filter( 'add_post_metadata', $reject, 10 );
	lfr_check( is_wp_error( $result ) && 'save_failed' === $result->get_error_code(), 'A refused score write reports failure.' );
	lfr_check( ! metadata_exists( 'post', $m, 'lf_home_score' ) && ! metadata_exists( 'post', $m, 'lf_away_score' ) && 'scheduled' === get_post_meta( $m, 'lf_status', true ) && ! get_post_meta( $m, \LeagueFlow\Referee::AUDIT_META, false ), 'Failed score write rolls back both scores, status and audit.' );
	$reject_audit = static function( $check, $id, $key ) use ( $m ) { return $id === $m && \LeagueFlow\Referee::AUDIT_META === $key ? false : $check; };
	add_filter( 'add_post_metadata', $reject_audit, 10, 3 );
	$result = $service->submit_result( $m, '2', '1', $state );
	remove_filter( 'add_post_metadata', $reject_audit, 10 );
	lfr_check( is_wp_error( $result ) && ! metadata_exists( 'post', $m, 'lf_home_score' ) && 'scheduled' === get_post_meta( $m, 'lf_status', true ), 'Failed audit write rolls back the result before Finished.' );
	$state = \LeagueFlow\Referee::state_token( $m );
	$peer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$lock = \LeagueFlow\db_lock_name( 'result', (string) $m );
	lfr_check( '1' === (string) $peer->get_var( $peer->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ), 'Hold competing score lock from another connection.' );
	$result = $service->submit_result( $m, '2', '1', $state );
	lfr_check( is_wp_error( $result ) && 'busy' === $result->get_error_code(), 'Concurrent score submission fails closed.' );
	$peer->query( $peer->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); $peer->close();
	$observed = array();
	$observer = function( $id, $new ) use ( $m, &$observed ) { if ( $m === $id && 'finished' === $new ) { $observed = array( get_post_meta( $id, 'lf_home_score', true ), get_post_meta( $id, 'lf_away_score', true ), get_post_meta( $id, 'lf_outcome', true ) ); } };
	add_action( 'leagueflow_match_status_changed', $observer, 10, 2 );
	lfr_check( true === $service->submit_result( $m, '0', '0', $state ), 'Explicit 0–0 final result accepted.' );
	remove_action( 'leagueflow_match_status_changed', $observer, 10 );
	lfr_check( 'finished' === get_post_meta( $m, 'lf_status', true ) && array( '0', '0', '' ) === $observed, 'Lifecycle transition sees the complete final result.' );
	$audit = get_post_meta( $m, \LeagueFlow\Referee::AUDIT_META, false );
	lfr_check( 1 === count( $audit ) && $ref === $audit[0]['user_id'] && '0' === $audit[0]['home_score'] && ! empty( $audit[0]['submitted_at_utc'] ), 'Submission retains private referee/time/previous-result evidence.' );
	lfr_check( is_wp_error( $service->submit_result( $m, '5', '0', \LeagueFlow\Referee::state_token( $m ) ) ) && '0' === get_post_meta( $m, 'lf_home_score', true ), 'Final scores cannot be overwritten by referees.' );
	$rows = array_column( ( new \LeagueFlow\Standings_Service() )->get_rows( 0, 0, $lfr_term, 0 ), null, 'team_id' );
	lfr_check( 1 === $rows[$a]['played'] && 1 === $rows[$a]['draws'], 'Referee score contributes to official standings.' );
	foreach ( array( 'cancelled', 'postponed', 'finished' ) as $status ) {
		update_post_meta( $m, 'lf_status', $status );
		lfr_check( ! \LeagueFlow\Referee::can_access( $m, true ), 'Closed status blocks score entry.' );
	}
	update_post_meta( $m, 'lf_status', 'scheduled' );
	wp_set_current_user( $lfr_admin_id ); wp_update_post( array( 'ID' => $a, 'post_password' => 'private' ) ); wp_set_current_user( $ref );
	lfr_check( ! \LeagueFlow\Referee::can_access( $m ), 'Protected referenced team blocks access.' );
	wp_set_current_user( $lfr_admin_id ); wp_update_post( array( 'ID' => $a, 'post_password' => '' ) ); wp_update_post( array( 'ID' => $m, 'post_status' => 'draft' ) ); wp_set_current_user( $ref );
	lfr_check( ! \LeagueFlow\Referee::can_access( $m ), 'Unpublished assigned match is not exposed.' );
	wp_set_current_user( $lfr_admin_id ); wp_update_post( array( 'ID' => $m, 'post_status' => 'publish' ) );
	$_POST = array( 'leagueflow_referee_nonce' => 'invalid', 'lf_referee_user_ids' => array( $other ), 'lf_referee_scores_enabled' => '1' );
	$service->save_assignments( $m, get_post( $m ) );
	lfr_check( array( $ref ) === \LeagueFlow\Referee::assigned_users( $m ), 'Assignment update requires valid nonce.' );
	$_POST['leagueflow_referee_nonce'] = wp_create_nonce( 'leagueflow_assign_referees_' . $m );
	$_POST['lf_referee_user_ids'] = array( $subscriber ); $service->save_assignments( $m, get_post( $m ) );
	lfr_check( array( $ref ) === \LeagueFlow\Referee::assigned_users( $m ), 'Participant cannot be assigned as referee without the capability.' );
	$_POST['lf_referee_user_ids'] = array( $other ); $service->save_assignments( $m, get_post( $m ) );
	lfr_check( array( $other ) === \LeagueFlow\Referee::assigned_users( $m ), 'Staff can reassign officials.' );
	wp_set_current_user( $ref );
	lfr_check( ! \LeagueFlow\Referee::can_access( $m ) && is_wp_error( $service->submit_result( $m, '1', '0', $state ) ), 'Revoked assignment immediately removes access.' );

	// Drive real admin save callbacks for finalise and impossible event/date inputs.
	wp_set_current_user( $lfr_admin_id );
	$admin = new \LeagueFlow\Admin( new \LeagueFlow\Standings_Service(), new \LeagueFlow\Knockout_Service(), leagueflow()->renderer(), new \LeagueFlow\Seeder(), new \LeagueFlow\Sports_Manager(), new \LeagueFlow\Exporter( new \LeagueFlow\Sports_Manager() ), new \LeagueFlow\Field_Availability_Manager(), new \LeagueFlow\Fixture_Generator( new \LeagueFlow\Field_Availability_Manager() ), new \LeagueFlow\Join_Request_Service() );
	$_POST = array( 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'leagueflow_match_state' => \LeagueFlow\Admin::match_form_state( $m ), 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_home_score' => '2', 'lf_away_score' => '1', 'lf_status' => 'scheduled', 'lf_finalize_result' => '1', 'lf_match_datetime' => '2026-12-07T18:30' );
	$admin->save_match_meta( $m, get_post( $m ) );
	lfr_check( 'finished' === get_post_meta( $m, 'lf_status', true ) && '2' === get_post_meta( $m, 'lf_home_score', true ), 'Explicit admin finalise button persists Finished and valid scores.' );
	$_POST['lf_match_datetime'] = '2026-02-30T18:30'; $_POST['lf_home_score'] = '9'; $_POST['leagueflow_match_state'] = \LeagueFlow\Admin::match_form_state( $m );
	$admin->save_match_meta( $m, get_post( $m ) );
	lfr_check( '2026-12-07 18:30' === get_post_meta( $m, 'lf_match_datetime', true ) && '2' === get_post_meta( $m, 'lf_home_score', true ), 'Impossible match date rejects all match-detail writes.' );

	// A staff form opened before a referee result must not overwrite it. Staff
	// and referees also compete for the same lock, including native post saves.
	$_POST = array();
	$admin_match = $post( 'lf_match', 'Admin concurrency match', array( 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_status' => 'scheduled', 'lf_match_datetime' => '2026-12-07 18:30', 'lf_venue' => 'Original court' ) );
	add_post_meta( $admin_match, \LeagueFlow\Referee::ASSIGNMENT_META, $ref );
	update_post_meta( $admin_match, \LeagueFlow\Referee::SCORE_META, 1 );
	$admin_state = \LeagueFlow\Admin::match_form_state( $admin_match );
	wp_set_current_user( $ref );
	lfr_check( true === $service->submit_result( $admin_match, '4', '3', \LeagueFlow\Referee::state_token( $admin_match ) ), 'A referee can save while an older staff form remains open.' );
	$newer_audit = get_post_meta( $admin_match, \LeagueFlow\Referee::AUDIT_META, false );
	wp_set_current_user( $lfr_admin_id );
	$_POST = array( 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'leagueflow_match_state' => $admin_state, 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_home_score' => '9', 'lf_away_score' => '8', 'lf_status' => 'finished', 'lf_match_datetime' => '2026-12-08T19:00', 'lf_venue' => 'Stale court' );
	$admin->save_match_meta( $admin_match, get_post( $admin_match ) );
	lfr_check( '4' === get_post_meta( $admin_match, 'lf_home_score', true ) && '3' === get_post_meta( $admin_match, 'lf_away_score', true ) && 'finished' === get_post_meta( $admin_match, 'lf_status', true ) && $newer_audit === get_post_meta( $admin_match, \LeagueFlow\Referee::AUDIT_META, false ), 'A stale staff form overwrote a newer referee score or its evidence.' );
	lfr_check( 'Original court' === get_post_meta( $admin_match, 'lf_venue', true ) && '2026-12-07 18:30' === get_post_meta( $admin_match, 'lf_match_datetime', true ), 'Rejected stale staff form partially changed match details.' );
	$_POST = array();
	wp_update_post( array( 'ID' => $admin_match, 'post_status' => 'draft' ) );
	$fresh_state = \LeagueFlow\Admin::match_form_state( $admin_match );
	$_POST = array( 'leagueflow_match_nonce' => wp_create_nonce( 'leagueflow_save_match' ), 'leagueflow_match_state' => $fresh_state, 'lf_home_team_id' => $a, 'lf_away_team_id' => $b, 'lf_home_score' => '5', 'lf_away_score' => '3', 'lf_status' => 'finished', 'lf_match_datetime' => '2026-12-08T19:00', 'lf_venue' => 'Reviewed court' );
	$peer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$lock = \LeagueFlow\db_lock_name( 'result', (string) $admin_match );
	try {
		lfr_check( '1' === (string) $peer->get_var( $peer->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ), 'Hold a competing staff/referee result lock from a separate database connection.' );
		$admin->save_match_meta( $admin_match, get_post( $admin_match ) );
		lfr_check( $fresh_state === \LeagueFlow\Admin::match_form_state( $admin_match ) && '4' === get_post_meta( $admin_match, 'lf_home_score', true ) && 'Original court' === get_post_meta( $admin_match, 'lf_venue', true ), 'A staff save wrote details while another connection held the shared result lock.' );
	} finally {
		$peer->query( $peer->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$peer->close();
	}
	// Core publishes the post before invoking save_post_lf_match. A fresh
	// metadata token must survive that ordering and allow the submitted details.
	$published = wp_update_post( array( 'ID' => $admin_match, 'post_status' => 'publish' ), true );
	lfr_check( ! is_wp_error( $published ) && 'publish' === get_post_status( $admin_match ) && 'finished' === get_post_meta( $admin_match, 'lf_status', true ) && '5' === get_post_meta( $admin_match, 'lf_home_score', true ) && '3' === get_post_meta( $admin_match, 'lf_away_score', true ), 'Fresh staff details failed during a native draft-to-published save.' );
	lfr_check( '2026-12-08 19:00' === get_post_meta( $admin_match, 'lf_match_datetime', true ) && 'Reviewed court' === get_post_meta( $admin_match, 'lf_venue', true ), 'Fresh native publication failed to save the reviewed date and venue.' );
	$_POST = array();
	$event = $post( 'lf_calendar_event', 'Test Drop-in', array( 'lf_event_start_datetime' => '2026-12-07 18:00', 'lf_event_end_datetime' => '2026-12-07 20:00' ) );
	$_POST = array( 'leagueflow_calendar_event_nonce' => wp_create_nonce( 'leagueflow_save_calendar_event' ), 'lf_event_start_datetime' => '2026-12-07T18:00', 'lf_event_end_datetime' => '2026-12-07T17:00' );
	$admin->save_calendar_event_meta( $event, get_post( $event ) );
	lfr_check( '2026-12-07 20:00' === get_post_meta( $event, 'lf_event_end_datetime', true ), 'Backwards end time preserves the previous valid event.' );
	$_POST['lf_event_start_datetime'] = '2026-03-08T02:30'; $_POST['lf_event_end_datetime'] = '2026-03-08T04:00';
	$admin->save_calendar_event_meta( $event, get_post( $event ) );
	lfr_check( '2026-12-07 18:00' === get_post_meta( $event, 'lf_event_start_datetime', true ), 'Nonexistent DST time is rejected.' );

	// Term hooks run for imports/renames too. Only a submitted season form can
	// interpret an absent checkbox as a request to clear Current.
	$_POST = array(); $_GET = array();
	$season_sport_a = 'season-regression-a-' . wp_generate_uuid4();
	$season_sport_b = 'season-regression-b-' . wp_generate_uuid4();
	foreach ( array( 'a1' => $season_sport_a, 'a2' => $season_sport_a, 'b1' => $season_sport_b ) as $label => $sport_slug ) {
		$made = wp_insert_term( 'Season form regression ' . $label . ' ' . wp_generate_uuid4(), 'lf_season' );
		lfr_check( ! is_wp_error( $made ), 'Create an isolated season form fixture.' );
		$lfr_seasons[ $label ] = (int) $made['term_id'];
		update_term_meta( $made['term_id'], 'lf_sport_slug', $sport_slug );
	}
	foreach ( array( 'a1', 'b1' ) as $label ) {
		$_POST = array( 'action' => 'editedtag', 'leagueflow_season_current_present' => '1', 'lf_is_current' => '1' );
		wp_update_term( $lfr_seasons[ $label ], 'lf_season', array( 'description' => 'Chosen current season' ) );
	}
	lfr_check( $lfr_seasons['a1'] === \LeagueFlow\get_current_season_id( $season_sport_a ) && $lfr_seasons['b1'] === \LeagueFlow\get_current_season_id( $season_sport_b ), 'Explicit season forms did not select Current independently by sport.' );
	$_POST = array();
	$renamed = wp_update_term( $lfr_seasons['a1'], 'lf_season', array( 'name' => 'Programmatically renamed season ' . wp_generate_uuid4() ) );
	lfr_check( ! is_wp_error( $renamed ) && $lfr_seasons['a1'] === \LeagueFlow\get_current_season_id( $season_sport_a ) && $lfr_seasons['b1'] === \LeagueFlow\get_current_season_id( $season_sport_b ), 'Programmatic season rename cleared Current or affected another sport.' );
	$_POST = array( 'action' => 'inline-save-tax' );
	wp_update_term( $lfr_seasons['a1'], 'lf_season', array( 'description' => 'Quick edit' ) );
	lfr_check( $lfr_seasons['a1'] === \LeagueFlow\get_current_season_id( $season_sport_a ), 'Quick Edit without the checkbox cleared Current.' );
	$_POST = array( 'action' => 'editedtag', 'leagueflow_season_current_present' => '1' );
	wp_update_term( $lfr_seasons['a2'], 'lf_season', array( 'description' => 'Explicitly not current' ) );
	lfr_check( $lfr_seasons['a1'] === \LeagueFlow\get_current_season_id( $season_sport_a ), 'Unchecking a non-current season cleared the actual current season.' );
	wp_update_term( $lfr_seasons['a1'], 'lf_season', array( 'description' => 'Explicitly clear current' ) );
	lfr_check( ! isset( \LeagueFlow\get_current_season_map()[ $season_sport_a ] ) && ! \LeagueFlow\is_current_season( $lfr_seasons['a1'] ) && $lfr_seasons['b1'] === \LeagueFlow\get_current_season_id( $season_sport_b ), 'Explicit unchecked season form did not clear only its own Current assignment.' );
	$_POST['lf_is_current'] = '1';
	wp_update_term( $lfr_seasons['a2'], 'lf_season', array( 'description' => 'Select replacement current' ) );
	lfr_check( $lfr_seasons['a2'] === \LeagueFlow\get_current_season_id( $season_sport_a ) && $lfr_seasons['b1'] === \LeagueFlow\get_current_season_id( $season_sport_b ), 'A checked season form could not select a replacement without changing another sport.' );

	WP_CLI::success( $GLOBALS['lfr_checks'] . ' referee, result-admin and season assertions passed.' );
} finally {
	$_POST = array(); $_GET = array(); wp_set_current_user( $lfr_admin_id );
	foreach ( array_reverse( $lfr_posts ) as $id ) { wp_delete_post( $id, true ); }
	foreach ( $lfr_users as $id ) { wp_delete_user( $id ); }
	foreach ( $lfr_seasons as $id ) { wp_delete_term( $id, 'lf_season' ); }
	if ( $lfr_term ) { wp_delete_term( $lfr_term, 'lf_sport' ); }
	update_option( 'leagueflow_settings', $lfr_settings ); update_option( 'timezone_string', $lfr_timezone );
	if ( null === $lfr_current_seasons ) { delete_option( \LeagueFlow\CURRENT_SEASONS_OPTION ); }
	else { update_option( \LeagueFlow\CURRENT_SEASONS_OPTION, $lfr_current_seasons ); }
	\LeagueFlow\flush_current_season_cache();
	$_POST = $lfr_old_post; $_GET = $lfr_old_get; wp_set_current_user( $lfr_old_user );
}
