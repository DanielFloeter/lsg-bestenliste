<?php
/**
 * Rendering für Block 3 "Ewige Bestenliste": Top-20 (10km: Top-30) je Distanz,
 * über alle Jahre, gefiltert nach Geschlecht / Altersklasse / Distanz.
 * Entspricht https://www.lsg-ka.de/ewige-bestenliste.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lsg_bl_resolve_ewige_args( $attributes = array() ) {
	$default_gender   = isset( $attributes['defaultGender'] ) ? $attributes['defaultGender'] : 'm';
	$default_ak       = isset( $attributes['defaultAk'] ) ? $attributes['defaultAk'] : 'alle';
	$default_distance = isset( $attributes['defaultDistance'] ) ? $attributes['defaultDistance'] : 'alle';

	$gender   = isset( $_GET['lsg_ew_gender'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_ew_gender'] ) ) : $default_gender; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$ak       = isset( $_GET['lsg_ew_ak'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_ew_ak'] ) ) : $default_ak; // phpcs:ignore
	$distance = isset( $_GET['lsg_ew_distance'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_ew_distance'] ) ) : $default_distance; // phpcs:ignore

	$gender = in_array( $gender, array( 'm', 'f', 'alle' ), true ) ? $gender : 'm';

	$valid_ak = lsg_bl_ak_list_for_gender( $gender );
	if ( 'alle' !== $ak && ! in_array( $ak, $valid_ak, true ) ) {
		$ak = 'alle';
	}

	$valid_distances = lsg_bl_get_all_distances();
	if ( 'alle' !== $distance && ! in_array( $distance, $valid_distances, true ) ) {
		$distance = 'alle';
	}

	return array(
		'gender'   => $gender,
		'ak'       => $ak,
		'distance' => $distance,
	);
}

function lsg_bl_render_ewige_results( $args ) {
	$gender = $args['gender'];
	$ak     = $args['ak'];

	if ( 'alle' === $args['distance'] ) {
		$distances = lsg_bl_get_distances_present( $gender, $ak, 0 );
	} else {
		$distances = array( $args['distance'] );
	}

	if ( empty( $distances ) ) {
		return '<p class="lsg-empty">Keine Ergebnisse für diese Auswahl.</p>';
	}

	$show_heading     = ( count( $distances ) > 1 ) || ( 'alle' === $args['distance'] );
	$highlight_gender = ( 'alle' === $gender ); // Bei gemischter Ausgabe Frauen farblich hervorheben.
	$html             = '';
	$any              = false;

	foreach ( $distances as $distance ) {
		$rows = lsg_bl_get_best_rows( $distance, $gender, $ak, 0 );
		if ( empty( $rows ) ) {
			continue;
		}
		$rows  = lsg_bl_sort_rows_by_performance( $rows );
		$rows  = lsg_bl_dedupe_rows_by_athlete( $rows ); // Jeder Athlet nur mit seiner besten Zeit pro Distanz.
		$limit = lsg_bl_eternal_limit( $distance );
		$rows  = array_slice( $rows, 0, $limit );

		$any   = true;
		$html .= '<div class="lsg-distance-block">' . lsg_bl_render_result_table( $rows, $show_heading, $distance, false, $highlight_gender ) . '</div>';
	}

	if ( ! $any ) {
		return '<p class="lsg-empty">Keine Ergebnisse für diese Auswahl.</p>';
	}

	return $html;
}

function lsg_bl_render_ewige_filters( $args, $instance_id ) {
	$valid_ak        = lsg_bl_ak_list_for_gender( $args['gender'] );
	$valid_distances = lsg_bl_get_all_distances();

	ob_start();
	?>
	<form class="lsg-filters" data-lsg-endpoint="ewige-bestenliste" data-lsg-target="#<?php echo esc_attr( $instance_id ); ?>-results" method="get">
		<label class="lsg-field">
			<span>Geschlecht</span>
			<select name="lsg_ew_gender">
				<option value="alle" <?php selected( $args['gender'], 'alle' ); ?>>Alle</option>
				<option value="m" <?php selected( $args['gender'], 'm' ); ?>>Männer</option>
				<option value="f" <?php selected( $args['gender'], 'f' ); ?>>Frauen</option>
			</select>
		</label>
		<label class="lsg-field">
			<span>Altersklasse</span>
			<select name="lsg_ew_ak">
				<option value="alle" <?php selected( $args['ak'], 'alle' ); ?>>Alle</option>
				<?php foreach ( $valid_ak as $ak ) : ?>
					<option value="<?php echo esc_attr( $ak ); ?>" <?php selected( $args['ak'], $ak ); ?>><?php echo esc_html( $ak ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="lsg-field">
			<span>Distanz</span>
			<select name="lsg_ew_distance">
				<option value="alle" <?php selected( $args['distance'], 'alle' ); ?>>Alle</option>
				<?php foreach ( $valid_distances as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $args['distance'], $d ); ?>><?php echo esc_html( lsg_bl_distance_label( $d ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<noscript><button type="submit">Anzeigen</button></noscript>
	</form>
	<?php
	return ob_get_clean();
}

function lsg_bl_render_ewige_block( $attributes = array() ) {
	$args        = lsg_bl_resolve_ewige_args( $attributes );
	$instance_id = 'lsg-ewige-' . substr( md5( wp_json_encode( $attributes ) . wp_rand() ), 0, 8 );

	$html  = '<div class="lsg-block lsg-ewige-bestenliste" data-lsg-block="ewige-bestenliste">';
	$html .= '<h2 class="lsg-title">Ewige Bestenliste</h2>';
	$html .= lsg_bl_render_ewige_filters( $args, $instance_id );
	$html .= '<div id="' . esc_attr( $instance_id ) . '-results" class="lsg-results">';
	$html .= lsg_bl_render_ewige_results( $args );
	$html .= '</div>';
	$html .= '<p class="lsg-contact">Fragen zu Einträgen an: bestenliste(at)lsg-ka.de</p>';
	$html .= '</div>';

	return $html;
}
