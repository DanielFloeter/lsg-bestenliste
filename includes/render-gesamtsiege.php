<?php
/**
 * Rendering für Block 2 "Gesamtsiege": chronologische Liste der Einzelsiege
 * eines gewählten Jahres (Tabelle lsg_win).
 * Entspricht https://www.lsg-ka.de/gesamtsiege.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lsg_bl_resolve_gesamtsiege_args( $attributes = array() ) {
	$years        = lsg_bl_get_win_years();
	$current_year = ! empty( $years ) ? max( $years ) : (int) date_i18n( 'Y' );

	$default_year = isset( $attributes['defaultYear'] ) ? (int) $attributes['defaultYear'] : $current_year;
	$year         = isset( $_GET['lsg_win_year'] ) ? (int) $_GET['lsg_win_year'] : $default_year; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! in_array( $year, $years, true ) ) {
		$year = $current_year;
	}

	return array(
		'year'  => $year,
		'years' => $years,
	);
}

function lsg_bl_render_gesamtsiege_results( $args ) {
	$rows = lsg_bl_get_win_rows( $args['year'] );

	if ( empty( $rows ) ) {
		return '<p class="lsg-empty">Keine Siege für dieses Jahr erfasst.</p>';
	}

	ob_start();
	?>
	<table class="lsg-table lsg-table-wins">
		<thead>
			<tr>
				<th class="lsg-col-date">Datum</th>
				<th class="lsg-col-town">Ort</th>
				<th class="lsg-col-event">Veranstaltung</th>
				<th class="lsg-col-distance">Distanz</th>
				<th class="lsg-col-name">Name</th>
				<th class="lsg-col-time">Zeit</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$name    = lsg_bl_athlete_display_name( $row['name'], $row['firstname'] );
				$is_frau = ( 'f' === strtolower( trim( (string) $row['cat'] ) ) );
				?>
				<tr class="<?php echo $is_frau ? 'lsg-row-frau' : ''; ?>">
					<td class="lsg-col-date"><?php echo lsg_bl_cell( lsg_bl_format_date( $row['date'] ) ); ?></td>
					<td class="lsg-col-town"><?php echo lsg_bl_cell( $row['town'] ); ?></td>
					<td class="lsg-col-event"><?php echo lsg_bl_cell( $row['event'] ); ?></td>
					<td class="lsg-col-distance"><?php echo lsg_bl_cell( lsg_bl_distance_label( $row['distance'] ) ); ?></td>
					<td class="lsg-col-name"><?php echo lsg_bl_cell( $name ); ?></td>
					<td class="lsg-col-time"><?php echo lsg_bl_cell( $row['time'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return ob_get_clean();
}

function lsg_bl_render_gesamtsiege_filters( $args, $instance_id ) {
	$years = ! empty( $args['years'] ) ? $args['years'] : array( (int) date_i18n( 'Y' ) );
	ob_start();
	?>
	<form class="lsg-filters" data-lsg-endpoint="gesamtsiege" data-lsg-target="#<?php echo esc_attr( $instance_id ); ?>-results" method="get">
		<label class="lsg-field">
			<span>Jahr</span>
			<select name="lsg_win_year">
				<?php foreach ( $years as $y ) : ?>
					<option value="<?php echo (int) $y; ?>" <?php selected( $args['year'], $y ); ?>><?php echo (int) $y; ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<noscript><button type="submit">Anzeigen</button></noscript>
	</form>
	<?php
	return ob_get_clean();
}

function lsg_bl_render_gesamtsiege_block( $attributes = array() ) {
	$args        = lsg_bl_resolve_gesamtsiege_args( $attributes );
	$instance_id = 'lsg-gesamtsiege-' . substr( md5( wp_json_encode( $attributes ) . wp_rand() ), 0, 8 );

	$html  = '<div class="lsg-block lsg-gesamtsiege" data-lsg-block="gesamtsiege">';
	$html .= '<h2 class="lsg-title">Gesamtsiege ' . (int) $args['year'] . '</h2>';
	$html .= lsg_bl_render_gesamtsiege_filters( $args, $instance_id );
	$html .= '<div id="' . esc_attr( $instance_id ) . '-results" class="lsg-results">';
	$html .= lsg_bl_render_gesamtsiege_results( $args );
	$html .= '</div>';
	$html .= '<p class="lsg-contact">Fragen zu Einträgen an: bestenliste(at)lsg-ka.de</p>';
	$html .= '</div>';

	return $html;
}
