<?php
/**
 * League table template.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var bool                             $show_logos
 * @var array<string, string>            $table_labels
 */

defined( 'ABSPATH' ) || exit;
$show_draws = (bool) array_filter( $rows, static function( $row ) { return ! empty( $row['draws'] ); } );
?>
<div class="leagueflow leagueflow-table-wrap" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'League standings', 'leagueflow' ); ?>">
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'No teams or completed league results match this sport, season and level yet.', 'leagueflow' ); ?></p>
	<?php else : ?>
		<table class="leagueflow-table" aria-label="<?php esc_attr_e( 'League standings', 'leagueflow' ); ?>">
			<thead>
				<tr>
					<th scope="col" aria-label="<?php esc_attr_e( 'Position', 'leagueflow' ); ?>"><?php esc_html_e( '#', 'leagueflow' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Team', 'leagueflow' ); ?></th>
					<th scope="col" aria-label="<?php esc_attr_e( 'Played', 'leagueflow' ); ?>"><?php esc_html_e( 'P', 'leagueflow' ); ?></th>
					<th scope="col" aria-label="<?php esc_attr_e( 'Won', 'leagueflow' ); ?>"><?php esc_html_e( 'W', 'leagueflow' ); ?></th>
					<?php if ( $show_draws ) : ?><th scope="col" aria-label="<?php esc_attr_e( 'Drawn (tied)', 'leagueflow' ); ?>"><?php esc_html_e( 'D', 'leagueflow' ); ?></th><?php endif; ?>
					<th scope="col" aria-label="<?php esc_attr_e( 'Lost', 'leagueflow' ); ?>"><?php esc_html_e( 'L', 'leagueflow' ); ?></th>
					<th scope="col"><?php echo esc_html( $table_labels['for'] ); ?></th>
					<th scope="col"><?php echo esc_html( $table_labels['against'] ); ?></th>
					<th scope="col"><?php echo esc_html( $table_labels['difference'] ); ?></th>
					<th scope="col" aria-label="<?php esc_attr_e( 'Table points', 'leagueflow' ); ?>"><?php esc_html_e( 'Pts', 'leagueflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['position'] ); ?></td>
						<td class="leagueflow-table__team">
							<?php if ( $show_logos && ! empty( $row['logo'] ) ) : ?>
								<span class="leagueflow-table__logo-wrap"><?php echo wp_kses_post( $row['logo'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $row['permalink'] ) ) : ?>
								<a href="<?php echo esc_url( $row['permalink'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( $row['name'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row['played'] ); ?></td>
						<td><?php echo esc_html( (string) $row['wins'] ); ?></td>
						<?php if ( $show_draws ) : ?><td><?php echo esc_html( (string) $row['draws'] ); ?></td><?php endif; ?>
						<td><?php echo esc_html( (string) $row['losses'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_for'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goals_against'] ); ?></td>
						<td><?php echo esc_html( (string) $row['goal_difference'] ); ?></td>
						<td>
							<strong><?php echo esc_html( (string) $row['points'] ); ?></strong>
							<?php if ( ! empty( $row['adjustment'] ) ) : ?>
								<span class="leagueflow-table__adjustment"<?php echo ! empty( $row['adjustment_note'] ) ? ' title="' . esc_attr( (string) $row['adjustment_note'] ) . '"' : ''; ?>><?php echo esc_html( sprintf( '%+d', (int) $row['adjustment'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! array_sum( array_column( $rows, 'played' ) ) ) : ?>
			<p><?php esc_html_e( 'Teams are listed; results will appear after league matches are marked Finished and published.', 'leagueflow' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
