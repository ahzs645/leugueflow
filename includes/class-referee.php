<?php
/** Assigned-match roster access and final score submission. @package LeagueFlow */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/** Referees never receive general post, user, or roster editing permissions. */
class Referee {
	const ASSIGNMENT_META = '_lf_referee_user_id';
	const SCORE_META = '_lf_referee_scores_enabled';
	const AUDIT_META = '_lf_referee_result';
	const PAGE = 'leagueflow-referee';
	const VIEW_CAP = 'leagueflow_view_assigned_rosters';
	const SCORE_CAP = 'leagueflow_submit_assigned_scores';
	const MANAGE_CAP = 'leagueflow_manage_referees';

	public function register() {
		add_action( 'init', array( __CLASS__, 'ensure_roles' ), 2 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'add_meta_boxes_lf_match', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_lf_match', array( $this, 'save_assignments' ), 20, 2 );
		add_action( 'admin_post_leagueflow_referee_result', array( $this, 'handle_submission' ) );
		add_shortcode( 'leagueflow_referee', array( $this, 'render' ) );
	}

	public static function ensure_roles() {
		add_role( 'leagueflow_referee', __( 'LeagueFlow Referee', 'leagueflow' ), array( 'read' => true, self::VIEW_CAP => true, self::SCORE_CAP => true ) );
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::MANAGE_CAP ) ) {
			$admin->add_cap( self::MANAGE_CAP );
		}
	}

	public function register_menu() {
		if ( current_user_can( self::MANAGE_CAP ) ) {
			add_submenu_page( 'leagueflow', __( 'Referee desk', 'leagueflow' ), __( 'Referee desk', 'leagueflow' ), self::MANAGE_CAP, self::PAGE, array( $this, 'render_admin_page' ) );
		} elseif ( current_user_can( self::VIEW_CAP ) ) {
			add_menu_page( __( 'Referee desk', 'leagueflow' ), __( 'Referee desk', 'leagueflow' ), self::VIEW_CAP, self::PAGE, array( $this, 'render_admin_page' ), 'dashicons-clipboard', 25 );
		}
	}

	public function register_meta_box() {
		if ( current_user_can( self::MANAGE_CAP ) ) {
			add_meta_box( 'leagueflow-referees', __( 'Assigned referees', 'leagueflow' ), array( $this, 'render_assignment_box' ), 'lf_match', 'side' );
		}
	}

	public static function assigned_users( $match_id ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( absint( $match_id ), self::ASSIGNMENT_META, false ) ) ) ) );
	}

	public function render_assignment_box( $post ) {
		wp_nonce_field( 'leagueflow_assign_referees_' . $post->ID, 'leagueflow_referee_nonce' );
		$users = get_users( array( 'capability' => self::VIEW_CAP, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$assigned = self::assigned_users( $post->ID );
		?>
		<p><?php esc_html_e( 'Assign a WordPress user with the LeagueFlow Referee role. They can see only the rosters for their assigned published matches.', 'leagueflow' ); ?></p>
		<label for="lf_referee_user_ids"><?php esc_html_e( 'Referees', 'leagueflow' ); ?></label>
		<select id="lf_referee_user_ids" name="lf_referee_user_ids[]" multiple size="5" style="width:100%" data-leagueflow-searchable data-leagueflow-search-label="<?php esc_attr_e( 'Filter referees', 'leagueflow' ); ?>">
			<?php foreach ( $users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( (int) $user->ID, $assigned, true ) ); ?>><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<p><label><input type="checkbox" name="lf_referee_scores_enabled" value="1" <?php checked( '1' === (string) get_post_meta( $post->ID, self::SCORE_META, true ) ); ?>> <?php esc_html_e( 'Allow assigned referees to submit the final score', 'leagueflow' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Leave unchecked for roster access only. Final scores update standings and advance winners into eligible linked knockout matches. Staff handle corrections and any bracket conflicts.', 'leagueflow' ); ?></p>
		<?php
		$history = get_post_meta( $post->ID, self::AUDIT_META, false );
		if ( $history ) {
			$last = end( $history );
			if ( is_array( $last ) ) {
				$user = get_user_by( 'id', absint( $last['user_id'] ?? 0 ) );
				echo '<p>' . esc_html( sprintf( __( 'Final result %1$s–%2$s submitted by %3$s at %4$s UTC.', 'leagueflow' ), $last['home_score'] ?? '', $last['away_score'] ?? '', $user ? $user->display_name : __( 'Former user', 'leagueflow' ), $last['submitted_at_utc'] ?? '' ) ) . '</p>';
			}
		}
	}

	public function save_assignments( $post_id, $post ) {
		if ( 'lf_match' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( self::MANAGE_CAP ) || ! current_user_can( 'edit_post', $post_id )
			|| ! isset( $_POST['leagueflow_referee_nonce'] ) || ! is_scalar( $_POST['leagueflow_referee_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leagueflow_referee_nonce'] ) ), 'leagueflow_assign_referees_' . $post_id ) ) {
			return;
		}
		$submitted = $_POST['lf_referee_user_ids'] ?? array();
		if ( ! is_array( $submitted ) ) { return; }
		$ids = array();
		foreach ( $submitted as $value ) {
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || ! user_can( absint( $value ), self::VIEW_CAP ) ) { return; }
			$ids[] = absint( $value );
		}
		$lock = db_lock_name( 'result', (string) $post_id );
		if ( ! acquire_db_lock( $lock, 1 ) ) { return; }
		try {
			clean_post_cache( $post_id );
			if ( ! current_user_can( self::MANAGE_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
			foreach ( $ids as $id ) {
				clean_user_cache( $id );
				if ( ! user_can( $id, self::VIEW_CAP ) ) { return; }
			}
			delete_post_meta( $post_id, self::ASSIGNMENT_META );
			foreach ( array_unique( $ids ) as $id ) { add_post_meta( $post_id, self::ASSIGNMENT_META, $id ); }
			update_post_meta( $post_id, self::SCORE_META, isset( $_POST['lf_referee_scores_enabled'] ) && '1' === $_POST['lf_referee_scores_enabled'] ? 1 : 0 );
		} finally { release_db_lock( $lock ); }
	}

	/** Every read and write rechecks the assignment and visibility of both teams. */
	public static function can_access( $match_id, $score = false ) {
		$post = get_post( absint( $match_id ) );
		if ( ! is_user_logged_in() || ! current_user_can( self::VIEW_CAP ) || ! $post instanceof \WP_Post || 'lf_match' !== $post->post_type
			|| 'publish' !== $post->post_status || '' !== $post->post_password
			|| ! in_array( get_current_user_id(), self::assigned_users( $post->ID ), true ) ) { return false; }
		$teams = array( absint( get_post_meta( $post->ID, 'lf_home_team_id', true ) ), absint( get_post_meta( $post->ID, 'lf_away_team_id', true ) ) );
		if ( ! $teams[0] || ! $teams[1] || $teams[0] === $teams[1] ) { return false; }
		foreach ( $teams as $team_id ) {
			$team = get_post( $team_id );
			if ( ! $team instanceof \WP_Post || 'lf_team' !== $team->post_type || 'publish' !== $team->post_status || '' !== $team->post_password ) { return false; }
		}
		if ( $score && ! self::valid_result_context( $post->ID, $teams ) ) { return false; }
		return ! $score || ( current_user_can( self::SCORE_CAP ) && '1' === (string) get_post_meta( $post->ID, self::SCORE_META, true )
			&& in_array( (string) get_post_meta( $post->ID, 'lf_status', true ), array( '', 'scheduled', 'live' ), true ) );
	}

	/** Imported or reassigned participants must still match the fixture context. */
	public static function valid_result_context( $match_id, $teams ) {
		$sport = get_post_primary_term_id( $match_id, 'lf_sport' );
		$level = get_post_primary_term_id( $match_id, 'lf_league_level' );
		if ( ! $sport ) { return false; }
		$sport_term = get_term( $sport, 'lf_sport' );
		if ( ! $sport_term instanceof \WP_Term ) { return false; }
		foreach ( array( 'lf_competition', 'lf_season' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) { return false; }
			foreach ( $terms as $term_id ) {
				$owner = (string) get_term_meta( $term_id, 'lf_sport_slug', true );
				if ( $owner && $owner !== $sport_term->slug ) { return false; }
			}
		}
		$team_levels = array();
		foreach ( $teams as $team_id ) {
			if ( $sport !== get_post_primary_term_id( $team_id, 'lf_sport' ) ) { return false; }
			$team_level = get_post_primary_term_id( $team_id, 'lf_league_level' );
			if ( $level && $team_level && $level !== $team_level ) { return false; }
			if ( $team_level ) { $team_levels[] = $team_level; }
		}
		return count( array_unique( $team_levels ) ) <= 1;
	}

	/** Opaque optimistic-concurrency token; stale forms cannot overwrite a result. */
	public static function state_token( $match_id ) {
		$state = array( get_post_status( $match_id ), get_post_field( 'post_password', $match_id ), self::assigned_users( $match_id ) );
		foreach ( array( 'lf_home_team_id', 'lf_away_team_id', 'lf_home_score', 'lf_away_score', 'lf_status', 'lf_outcome', 'lf_is_knockout', 'lf_winner_team_id', 'lf_match_datetime', 'lf_venue', 'lf_next_match_id', 'lf_next_match_slot', 'lf_round_order', self::SCORE_META ) as $key ) {
			$state[ $key ] = get_post_meta( $match_id, $key, true );
		}
		foreach ( array( 'lf_sport', 'lf_season', 'lf_competition', 'lf_league_level' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $match_id, $taxonomy, array( 'fields' => 'ids' ) );
			$state[ $taxonomy ] = is_wp_error( $terms ) ? array() : $terms;
		}
		return hash_hmac( 'sha256', wp_json_encode( $state ), wp_salt( 'nonce' ) );
	}

	/** Validated service used only after a nonce-protected form submission. */
	public function submit_result( $match_id, $home, $away, $state ) {
		global $wpdb;
		$match_id = absint( $match_id );
		if ( ! self::can_access( $match_id, true ) ) {
			return new \WP_Error( 'forbidden', __( 'This match is not available for score entry. Ask staff to check your assignment and the result status.', 'leagueflow' ) );
		}
		if ( ! Match_Status::is_valid_score( $home ) || ! Match_Status::is_valid_score( $away ) ) {
			return new \WP_Error( 'invalid_score', __( 'Enter both final scores as whole numbers of 0 or more.', 'leagueflow' ) );
		}
		$lock = db_lock_name( 'result', (string) $match_id );
		if ( ! acquire_db_lock( $lock, 1 ) ) {
			return new \WP_Error( 'busy', __( 'Another person is updating this match. Reload and try again.', 'leagueflow' ) );
		}
		$transaction = false;
		$saved_result = false;
		try {
			clean_post_cache( $match_id );
			foreach ( array( 'lf_home_team_id', 'lf_away_team_id' ) as $team_key ) { clean_post_cache( (int) get_post_meta( $match_id, $team_key, true ) ); }
			if ( ! self::can_access( $match_id, true ) ) { return new \WP_Error( 'forbidden', __( 'Your access or this match has changed. Reload the page.', 'leagueflow' ) ); }
			if ( ! is_string( $state ) || ! hash_equals( self::state_token( $match_id ), $state ) ) { return new \WP_Error( 'stale', __( 'This match changed after you opened it. Reload before submitting.', 'leagueflow' ) ); }
			// Both scores, evidence and Finished must succeed together. Fail closed on
			// legacy non-transactional metadata tables instead of risking half a result.
			$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->postmeta ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $table || 'innodb' !== strtolower( (string) $table->Engine ) || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'save_failed', __( 'The result could not be safely saved. Ask staff to review the database configuration.', 'leagueflow' ) );
			}
			$transaction = true;
			Match_Status::defer_transitions( $match_id );
			$before = array( 'status' => get_post_meta( $match_id, 'lf_status', true ), 'home_score' => get_post_meta( $match_id, 'lf_home_score', true ), 'away_score' => get_post_meta( $match_id, 'lf_away_score', true ) );
			update_post_meta( $match_id, 'lf_home_score', (string) (int) $home );
			update_post_meta( $match_id, 'lf_away_score', (string) (int) $away );
			delete_post_meta( $match_id, 'lf_outcome' );
			delete_post_meta( $match_id, 'lf_winner_team_id' );
			if ( (string) (int) $home !== (string) get_post_meta( $match_id, 'lf_home_score', true ) || (string) (int) $away !== (string) get_post_meta( $match_id, 'lf_away_score', true ) || metadata_exists( 'post', $match_id, 'lf_outcome' ) || metadata_exists( 'post', $match_id, 'lf_winner_team_id' ) ) {
				return new \WP_Error( 'save_failed', __( 'The complete result could not be saved. No scores were changed; please try again.', 'leagueflow' ) );
			}
			// Result fields precede the lifecycle transition; observers see complete data.
			update_post_meta( $match_id, 'lf_status', 'finished' );
			if ( 'finished' !== get_post_meta( $match_id, 'lf_status', true ) ) {
				return new \WP_Error( 'save_failed', __( 'The final result could not be saved. Please reload and try again.', 'leagueflow' ) );
			}
			// Bind downstream authority to the exact committed participants, bracket
			// link, assignments and taxonomy as well as the scores. A later edit
			// cannot reuse this submission to authorize a different bracket write.
			$audit_id = add_post_meta( $match_id, self::AUDIT_META, array( 'user_id' => get_current_user_id(), 'submitted_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'home_score' => (string) (int) $home, 'away_score' => (string) (int) $away, 'previous' => $before, 'result_state' => self::state_token( $match_id ) ) );
			if ( ! $audit_id ) { return new \WP_Error( 'save_failed', __( 'The result record could not be saved. No scores were changed; please try again.', 'leagueflow' ) ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new \WP_Error( 'save_failed', __( 'The final result could not be committed. Please reload and try again.', 'leagueflow' ) );
			}
			$transaction = false;
			$saved_result = true;
			return true;
		} finally {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); clean_post_cache( $match_id ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			release_db_lock( $lock );
			Match_Status::flush_transitions( $match_id, $saved_result );
			if ( $saved_result ) {
				do_action( 'leagueflow_referee_result_submitted', $match_id, get_current_user_id() );
				do_action( 'leagueflow_match_result_saved', $match_id );
			}
		}
	}

	public function handle_submission() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the result form to submit a score.', 'leagueflow' ), '', array( 'response' => 405 ) ); }
		$id = isset( $_POST['match_id'] ) && is_scalar( $_POST['match_id'] ) ? absint( $_POST['match_id'] ) : 0;
		check_admin_referer( 'leagueflow_referee_result_' . $id, 'leagueflow_referee_result_nonce' );
		if ( ! isset( $_POST['confirm_final'] ) || '1' !== $_POST['confirm_final'] ) { $result = new \WP_Error( 'confirm', __( 'Confirm that these are the final scores.', 'leagueflow' ) ); }
		else { $result = $this->submit_result( $id, wp_unslash( $_POST['home_score'] ?? '' ), wp_unslash( $_POST['away_score'] ?? '' ), wp_unslash( $_POST['result_state'] ?? '' ) ); }
		$key = 'leagueflow_referee_notice_' . get_current_user_id();
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Final score saved. League standings and eligible linked knockout matches have been updated.', 'leagueflow' );
		if ( ! is_wp_error( $result ) && get_post_meta( $id, '_lf_bracket_sync_notice', true ) ) { $message .= ' ' . __( 'A bracket conflict needs staff review.', 'leagueflow' ); }
		set_transient( $key, array( 'error' => is_wp_error( $result ), 'message' => $message ), 120 );
		$url = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE );
		wp_safe_redirect( add_query_arg( 'lf_referee_match', $id, $url ) );
		exit;
	}

	public function render_admin_page() {
		echo '<div class="wrap">' . $this->render() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by renderer.
	}

	public function render() {
		if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Sign in with your referee account to view assigned matches.', 'leagueflow' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'leagueflow' ) . '</a></p>'; }
		if ( ! current_user_can( self::VIEW_CAP ) && ! current_user_can( self::MANAGE_CAP ) ) { return '<p>' . esc_html__( 'Referee access has not been assigned to this account.', 'leagueflow' ) . '</p>'; }
		if ( ! headers_sent() ) { nocache_headers(); }
		wp_enqueue_style( 'leagueflow-referee', LEAGUEFLOW_URL . 'assets/css/referee.css', array(), LEAGUEFLOW_VERSION );
		ob_start();
		?>
		<section class="leagueflow-referee" aria-label="<?php esc_attr_e( 'Referee desk', 'leagueflow' ); ?>">
			<h1><?php esc_html_e( 'Referee desk', 'leagueflow' ); ?></h1>
			<?php if ( current_user_can( self::MANAGE_CAP ) && ! current_user_can( self::VIEW_CAP ) ) : ?>
				<p><?php esc_html_e( 'Give officials the LeagueFlow Referee role in Users, then assign them in the Assigned referees panel on each match. Enable score entry per match, or leave it off for roster access only. Officials use this screen after signing in; a page can also contain [leagueflow_referee].', 'leagueflow' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Manage users', 'leagueflow' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lf_match' ) ); ?>"><?php esc_html_e( 'Assign matches', 'leagueflow' ); ?></a></p>
			<?php else : ?>
				<?php $this->render_desk(); ?>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	protected function render_desk() {
		$notice_key = 'leagueflow_referee_notice_' . get_current_user_id();
		$notice = get_transient( $notice_key );
		if ( is_array( $notice ) ) { delete_transient( $notice_key ); echo '<p role="status" class="leagueflow-referee__notice">' . esc_html( $notice['message'] ) . '</p>'; }
		$id = isset( $_GET['lf_referee_match'] ) && is_scalar( $_GET['lf_referee_match'] ) ? absint( $_GET['lf_referee_match'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			if ( ! self::can_access( $id ) ) { echo '<p>' . esc_html__( 'This match is not available to your account.', 'leagueflow' ) . '</p>'; return; }
			$this->render_match( $id );
			return;
		}
		$page = isset( $_GET['lf_referee_page'] ) && is_scalar( $_GET['lf_referee_page'] ) ? max( 1, absint( $_GET['lf_referee_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = new \WP_Query( array( 'post_type' => 'lf_match', 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 20, 'paged' => $page, 'orderby' => 'ID', 'order' => 'DESC', 'meta_query' => array( array( 'key' => self::ASSIGNMENT_META, 'value' => get_current_user_id() ) ) ) );
		echo '<p>' . esc_html__( 'Open an assigned match to check both rosters and, when enabled, submit its final result.', 'leagueflow' ) . '</p><ul class="leagueflow-referee__matches">';
		$visible = 0;
		foreach ( $query->posts as $match ) {
			if ( ! self::can_access( $match->ID ) ) { continue; }
			++$visible;
			echo '<li><a href="' . esc_url( add_query_arg( 'lf_referee_match', $match->ID ) ) . '">' . esc_html( get_the_title( $match->ID ) ) . '</a> <span>' . esc_html( format_match_datetime( (string) get_post_meta( $match->ID, 'lf_match_datetime', true ) ) ) . '</span> <span>' . esc_html( Match_Status::statuses()[ get_post_meta( $match->ID, 'lf_status', true ) ] ?? __( 'Scheduled', 'leagueflow' ) ) . '</span></li>';
		}
		echo '</ul>';
		if ( ! $visible ) { echo '<p>' . esc_html__( 'No accessible matches on this page. Ask staff to publish and assign your matches.', 'leagueflow' ) . '</p>'; }
		echo '<nav aria-label="' . esc_attr__( 'Assigned match pages', 'leagueflow' ) . '">';
		if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'lf_referee_page', $page - 1 ) ) . '">' . esc_html__( 'Previous page', 'leagueflow' ) . '</a> '; }
		if ( $page < $query->max_num_pages ) { echo '<a href="' . esc_url( add_query_arg( 'lf_referee_page', $page + 1 ) ) . '">' . esc_html__( 'Next page', 'leagueflow' ) . '</a>'; }
		echo '</nav>';
	}

	protected function render_match( $id ) {
		echo '<p><a href="' . esc_url( remove_query_arg( 'lf_referee_match' ) ) . '">' . esc_html__( 'All assigned matches', 'leagueflow' ) . '</a></p><h2>' . esc_html( get_the_title( $id ) ) . '</h2>';
		echo '<p>' . esc_html( format_match_datetime( (string) get_post_meta( $id, 'lf_match_datetime', true ) ) . ' · ' . get_post_meta( $id, 'lf_venue', true ) ) . '</p>';
		$home = absint( get_post_meta( $id, 'lf_home_team_id', true ) );
		$away = absint( get_post_meta( $id, 'lf_away_team_id', true ) );
		foreach ( array( $home, $away ) as $team_id ) {
			echo '<h3>' . esc_html( get_the_title( $team_id ) ) . '</h3><div class="leagueflow-referee__table"><table><thead><tr><th scope="col">' . esc_html__( 'Player', 'leagueflow' ) . '</th><th scope="col">' . esc_html__( 'Number', 'leagueflow' ) . '</th><th scope="col">' . esc_html__( 'Position', 'leagueflow' ) . '</th><th scope="col">' . esc_html__( 'Registration acknowledgements', 'leagueflow' ) . '</th></tr></thead><tbody>';
			$players = get_team_member_player_ids( $team_id, array( 'publish', 'private' ) );
			foreach ( $players as $player_id ) {
				$detail = get_player_team_detail( $player_id, $team_id );
				$consent = ! Registration_Consent::documents() ? __( 'Not required', 'leagueflow' ) : ( Registration_Consent::is_current( $player_id ) ? __( 'Current', 'leagueflow' ) : __( 'Needs review', 'leagueflow' ) );
				echo '<tr><th scope="row">' . esc_html( get_the_title( $player_id ) . ( ! empty( $detail['is_captain'] ) ? ' (' . __( 'Captain', 'leagueflow' ) . ')' : '' ) ) . '</th><td>' . esc_html( $detail['jersey_number'] ) . '</td><td>' . esc_html( $detail['position'] ) . '</td><td>' . esc_html( $consent ) . '</td></tr>';
			}
			if ( ! $players ) { echo '<tr><td colspan="4">' . esc_html__( 'No players have been assigned to this team.', 'leagueflow' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
		}
		if ( ! self::can_access( $id, true ) ) {
			echo '<p>' . esc_html__( 'Score entry is closed or disabled for this match. Staff can make result corrections.', 'leagueflow' ) . '</p>';
			if ( 'finished' === get_post_meta( $id, 'lf_status', true ) ) { echo '<p><strong>' . esc_html( get_post_meta( $id, 'lf_home_score', true ) . ' – ' . get_post_meta( $id, 'lf_away_score', true ) ) . '</strong></p>'; }
			return;
		}
		?>
		<h3><?php esc_html_e( 'Submit final result', 'leagueflow' ); ?></h3>
		<?php if ( 'volleyball' === get_post_primary_term_slug( $id, 'lf_sport' ) ) : ?><p><?php esc_html_e( 'Enter sets won by each team (for example, 2–1), not individual rally points.', 'leagueflow' ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="leagueflow_referee_result">
			<input type="hidden" name="match_id" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="result_state" value="<?php echo esc_attr( self::state_token( $id ) ); ?>">
			<?php wp_nonce_field( 'leagueflow_referee_result_' . $id, 'leagueflow_referee_result_nonce' ); ?>
			<p><label for="lf_ref_home"><?php echo esc_html( get_the_title( $home ) ); ?></label><input id="lf_ref_home" name="home_score" type="number" min="0" step="1" required value="<?php echo esc_attr( (string) get_post_meta( $id, 'lf_home_score', true ) ); ?>"></p>
			<p><label for="lf_ref_away"><?php echo esc_html( get_the_title( $away ) ); ?></label><input id="lf_ref_away" name="away_score" type="number" min="0" step="1" required value="<?php echo esc_attr( (string) get_post_meta( $id, 'lf_away_score', true ) ); ?>"></p>
			<p><label><input type="checkbox" name="confirm_final" value="1" required> <?php esc_html_e( 'These are the final scores. Staff must make any later corrections.', 'leagueflow' ); ?></label></p>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Save final score', 'leagueflow' ); ?></button>
		</form>
		<?php
	}
}
