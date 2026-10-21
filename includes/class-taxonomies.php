<?php
/**
 * Taxonomy registrations.
 *
 * @package LeagueFlow
 */

namespace LeagueFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy registration.
 */
class Taxonomies {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_action( 'init', array( $this, 'register_term_meta' ) );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_levels', 30 );
		add_action( 'init', 'LeagueFlow\\ensure_default_league_level_assignments', 35 );

		add_action( 'lf_season_add_form_fields', array( $this, 'render_season_current_add_field' ) );
		add_action( 'lf_season_edit_form_fields', array( $this, 'render_season_current_edit_field' ) );
		// After the sport field (priority 10) so the season's sport is already saved.
		add_action( 'created_lf_season', array( $this, 'save_season_current_field' ), 20 );
		add_action( 'edited_lf_season', array( $this, 'save_season_current_field' ), 20 );
		add_action( 'delete_lf_season', 'LeagueFlow\\clear_current_season' );
		add_filter( 'manage_edit-lf_season_columns', array( $this, 'add_season_current_column' ) );
		add_filter( 'manage_lf_season_custom_column', array( $this, 'render_season_current_column' ), 10, 3 );
	}

	/**
	 * Add a "Current" column to the Seasons list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_season_current_column( $columns ) {
		$columns['lf_current'] = __( 'Current', 'leagueflow' );

		return $columns;
	}

	/**
	 * Show whether a season is its sport's current season.
	 *
	 * @param string $content Column content.
	 * @param string $column Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public function render_season_current_column( $content, $column, $term_id ) {
		if ( 'lf_current' !== $column ) {
			return $content;
		}

		if ( ! is_current_season( $term_id ) ) {
			return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not current', 'leagueflow' ) . '</span>';
		}

		return '' === get_term_sport_slug( $term_id )
			? esc_html__( 'Current (all sports, legacy)', 'leagueflow' )
			: esc_html__( 'Current for its sport', 'leagueflow' );
	}

	/**
	 * Register season term meta.
	 *
	 * @return void
	 */
	public function register_term_meta() {
		register_term_meta(
			'lf_season',
			'lf_is_current',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function() {
					return current_user_can( 'manage_categories' );
				},
			)
		);
	}

	/**
	 * Render the "current season" checkbox on the add-season screen.
	 *
	 * @return void
	 */
	public function render_season_current_add_field() {
		?>
		<div class="form-field">
			<label>
				<input type="hidden" name="leagueflow_season_current_present" value="1" />
				<input type="checkbox" name="lf_is_current" value="1" />
				<?php esc_html_e( 'Make this the current season for its sport', 'leagueflow' ); ?>
			</label>
			<p><?php esc_html_e( 'Each sport has its own current season; setting one never changes another sport. Blocks and shortcodes without a season use the current season of their sport. Admin screens show all seasons unless you pick Current.', 'leagueflow' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the "current season" checkbox on the edit-season screen.
	 *
	 * @param \WP_Term $term Season term.
	 * @return void
	 */
	public function render_season_current_edit_field( $term ) {
		$is_current = is_current_season( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Current season', 'leagueflow' ); ?></th>
			<td>
				<label>
					<input type="hidden" name="leagueflow_season_current_present" value="1" />
					<input type="checkbox" name="lf_is_current" value="1" <?php checked( $is_current ); ?> />
					<?php esc_html_e( 'Make this the current season for its sport', 'leagueflow' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Each sport has one current season. Setting this replaces the current season of this sport only; other sports keep theirs. A season without a sport uses the older site-wide setting.', 'leagueflow' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the "current season" flag, enforcing a single current season.
	 *
	 * @param int $term_id Season term ID.
	 * @return void
	 */
	public function save_season_current_field( $term_id ) {
		// The taxonomy term screens carry WordPress's own nonce, verified by core before these hooks fire.
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// REST, imports and programmatic renames do not submit the season form.
		// An absent checkbox only means "clear" when that form was actually shown.
		if ( ! isset( $_POST['leagueflow_season_current_present'] ) && ! isset( $_POST['lf_is_current'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			flush_current_season_cache();
			return;
		}
		// Quick Edit does not post this field; leave the current season alone there.
		if ( ! isset( $_POST['lf_is_current'] ) && ( isset( $_POST['action'] ) && 'inline-save-tax' === $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			flush_current_season_cache();
			return;
		}

		if ( ! empty( $_POST['lf_is_current'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			set_current_season( $term_id );
		} elseif ( is_current_season( $term_id ) || get_term_meta( $term_id, 'lf_is_current', true ) ) {
			clear_current_season( $term_id );
		}

		flush_current_season_cache();
	}

	/**
	 * Register sport, league level, competition, and season taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		register_taxonomy(
			'lf_sport',
			array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Sports', 'leagueflow' ),
					'singular_name' => __( 'Sport', 'leagueflow' ),
					'search_items'  => __( 'Search Sports', 'leagueflow' ),
					'all_items'     => __( 'All Sports', 'leagueflow' ),
					'edit_item'     => __( 'Edit Sport', 'leagueflow' ),
					'update_item'   => __( 'Update Sport', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Sport', 'leagueflow' ),
					'new_item_name' => __( 'New Sport Name', 'leagueflow' ),
					'menu_name'     => __( 'Sports', 'leagueflow' ),
					'not_found'     => __( 'No sports yet. Enable sports in LeagueFlow > Sports.', 'leagueflow' ),
				),
				// Sport terms are owned by LeagueFlow > Sports (manage_options), which
				// creates and names them. Creating, renaming or deleting one elsewhere
				// (the generic term screen, a tag box) needs that same authority;
				// assigning an existing sport keeps WordPress's default (edit_posts).
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				),
				'public'            => false,
				// No generic term screen, tag box, Quick Edit field or block-editor
				// panel: Team and Calendar Event details choose an enabled sport,
				// matches and players take theirs from teams. The list column stays.
				'show_ui'           => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			'lf_league_level',
			array( 'lf_team', 'lf_player', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'League Levels', 'leagueflow' ),
					'singular_name' => __( 'League Level', 'leagueflow' ),
					'search_items'  => __( 'Search League Levels', 'leagueflow' ),
					'all_items'     => __( 'All League Levels', 'leagueflow' ),
					'edit_item'     => __( 'Edit League Level', 'leagueflow' ),
					'update_item'   => __( 'Update League Level', 'leagueflow' ),
					'add_new_item'  => __( 'Add New League Level', 'leagueflow' ),
					'new_item_name' => __( 'New League Level Name', 'leagueflow' ),
					'menu_name'     => __( 'League Levels', 'leagueflow' ),
					'not_found'     => __( 'No league levels yet. Levels (divisions) are shared by every sport; add one with the form on this page.', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => 'league-level' ),
			)
		);

		register_taxonomy(
			'lf_competition',
			array( 'lf_team', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Competitions', 'leagueflow' ),
					'singular_name' => __( 'Competition', 'leagueflow' ),
					'search_items'  => __( 'Search Competitions', 'leagueflow' ),
					'all_items'     => __( 'All Competitions', 'leagueflow' ),
					'edit_item'     => __( 'Edit Competition', 'leagueflow' ),
					'update_item'   => __( 'Update Competition', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Competition', 'leagueflow' ),
					'new_item_name' => __( 'New Competition Name', 'leagueflow' ),
					'menu_name'     => __( 'Competitions', 'leagueflow' ),
					'not_found'     => __( 'No competitions for this sport yet. Add one with the form on this page and choose its sport.', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => (string) get_setting( 'competition_slug', 'competition' ) ),
			)
		);

		register_taxonomy(
			'lf_season',
			array( 'lf_team', 'lf_match', 'lf_calendar_event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Seasons', 'leagueflow' ),
					'singular_name' => __( 'Season', 'leagueflow' ),
					'search_items'  => __( 'Search Seasons', 'leagueflow' ),
					'all_items'     => __( 'All Seasons', 'leagueflow' ),
					'edit_item'     => __( 'Edit Season', 'leagueflow' ),
					'update_item'   => __( 'Update Season', 'leagueflow' ),
					'add_new_item'  => __( 'Add New Season', 'leagueflow' ),
					'new_item_name' => __( 'New Season Name', 'leagueflow' ),
					'menu_name'     => __( 'Seasons', 'leagueflow' ),
					'not_found'     => __( 'No seasons for this sport yet. Add one with the form on this page and choose its sport.', 'leagueflow' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array( 'slug' => (string) get_setting( 'season_slug', 'season' ) ),
			)
		);
	}
}
