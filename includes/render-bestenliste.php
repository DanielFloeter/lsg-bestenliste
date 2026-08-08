<?php
/**
 * Rendering für Block 1 "Bestenliste": Ergebnisse eines gewählten Jahres,
 * gefiltert nach Geschlecht / Altersklasse / Distanz, eine Tabelle pro Distanz.
 * Entspricht https://www.lsg-ka.de/bestenliste.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Liest & validiert die aktuellen Filterwerte aus GET-Parametern (Fallback
 * ohne JS) bzw. übergebenen Attributen (Editor-Vorschau / Block-Defaults).
 */
function lsg_bl_resolve_bestenliste_args( $attributes = array() ) {
	$years = lsg_bl_get_best_years();
	$current_year = ! empty( $years ) ? max( $years ) : (int) date_i18n( 'Y' );

	$default_year     = isset( $attributes['defaultYear'] ) ? (int) $attributes['defaultYear'] : $current_year;
	$default_gender   = isset( $attributes['defaultGender'] ) ? $attributes['defaultGender'] : 'm';
	$default_ak       = isset( $attributes['defaultAk'] ) ? $attributes['defaultAk'] : 'alle';
	$default_distance = isset( $attributes['defaultDistance'] ) ? $attributes['defaultDistance'] : 'alle';

	$year     = isset( $_GET['lsg_year'] ) ? (int) $_GET['lsg_year'] : $default_year; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$gender   = isset( $_GET['lsg_gender'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_gender'] ) ) : $default_gender; // phpcs:ignore
	$ak       = isset( $_GET['lsg_ak'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_ak'] ) ) : $default_ak; // phpcs:ignore
	$distance = isset( $_GET['lsg_distance'] ) ? sanitize_text_field( wp_unslash( $_GET['lsg_distance'] ) ) : $default_distance; // phpcs:ignore

	$gender = in_array( $gender, array( 'm', 'f', 'alle' ), true ) ? $gender : 'm';
	if ( ! in_array( $year, $years, true ) ) {
		$year = $current_year;
	}

	$valid_ak = lsg_bl_ak_list_for_gender( $gender );
	if ( 'alle' !== $ak && ! in_array( $ak, $valid_ak, true ) ) {
		$ak = 'alle';
	}

	$valid_distances = lsg_bl_get_all_distances();
	if ( 'alle' !== $distance && ! in_array( $distance, $valid_distances, true ) ) {
		$distance = 'alle';
	}

	return array(
		'year'     => $year,
		'gender'   => $gender,
		'ak'       => $ak,
		'distance' => $distance,
		'years'    => $years,
	);
}

/**
 * Baut die HTML-Tabelle für eine einzelne Distanz.
 *
 * @param bool $reverse_order    Wenn true, wird die Reihenfolge nach der
 *                                Leistungssortierung umgedreht (schlechteste
 *                                Leistung zuerst statt beste).
 * @param bool $highlight_gender Wenn true, werden Frauen-Zeilen (athlete.cat = 'f')
 *                                mit der Klasse "lsg-row-frau" markiert (rote Schrift).
 */
function lsg_bl_render_result_table( $rows, $show_heading = true, $distance = '', $reverse_order = false, $highlight_gender = false ) {
	if ( empty( $rows ) ) {
		return '';
	}
	$rows = lsg_bl_sort_rows_by_performance( $rows );
	if ( $reverse_order ) {
		$rows = array_reverse( $rows );
	}

	ob_start();
	if ( $show_heading && $distance ) {
		echo '<h3 class="lsg-distance-heading">' . esc_html( lsg_bl_distance_label( $distance ) ) . '</h3>';
	}
	?>
	<table class="lsg-table">
		<thead>
			<tr>
				<th class="lsg-col-rank">#</th>
				<th class="lsg-col-time">Zeit</th>
				<th class="lsg-col-name">Name</th>
				<th class="lsg-col-town">Ort</th>
				<th class="lsg-col-date">Datum</th>
				<th class="lsg-col-ak">AK</th>
			</tr>
		</thead>
		<tbody>
			<?php
			$rank = 0;
			foreach ( $rows as $row ) :
				++$rank;
				$name    = lsg_bl_athlete_display_name( $row['name'], $row['firstname'] );
				$is_frau = $highlight_gender && isset( $row['cat'] ) && ( 'f' === strtolower( trim( (string) $row['cat'] ) ) );
				?>
				<tr class="<?php echo $is_frau ? 'lsg-row-frau' : ''; ?>">
					<td class="lsg-col-rank"><?php echo (int) $rank; ?>.</td>
					<td class="lsg-col-time"><?php echo lsg_bl_cell( $row['_perf']['display'] ); ?></td>
					<td class="lsg-col-name"><?php echo lsg_bl_cell( $name ); ?></td>
					<td class="lsg-col-town"><?php echo lsg_bl_cell( $row['town'] ); ?></td>
					<td class="lsg-col-date"><?php echo lsg_bl_cell( lsg_bl_format_date( $row['date'] ) ); ?></td>
					<td class="lsg-col-ak"><?php echo lsg_bl_cell( lsg_bl_ak_strip_gender( $row['ak'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return ob_get_clean();
}

/**
 * Nur die Ergebnisse (ohne Filterformular) – wird sowohl beim initialen
 * Rendern als auch für den REST-Partial-Refresh verwendet.
 */
function lsg_bl_render_bestenliste_results( $args ) {
	$year   = $args['year'];
	$gender = $args['gender'];
	$ak     = $args['ak'];

	if ( 'alle' === $args['distance'] ) {
		$distances = lsg_bl_get_distances_present( $gender, $ak, $year );
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
		$rows = lsg_bl_get_best_rows( $distance, $gender, $ak, $year );
		if ( empty( $rows ) ) {
			continue;
		}
		$any   = true;
		$html .= '<div class="lsg-distance-block">' . lsg_bl_render_result_table( $rows, $show_heading, $distance, false, $highlight_gender ) . '</div>';
	}

	if ( ! $any ) {
		return '<p class="lsg-empty">Keine Ergebnisse für diese Auswahl.</p>';
	}

	return $html;
}

/**
 * Filterformular (Jahr / Geschlecht / Altersklasse / Distanz).
 */
function lsg_bl_render_bestenliste_filters( $args, $instance_id ) {
	$years           = ! empty( $args['years'] ) ? $args['years'] : array( (int) date_i18n( 'Y' ) );
	$valid_ak        = lsg_bl_ak_list_for_gender( $args['gender'] );
	$valid_distances = lsg_bl_get_all_distances();

	ob_start();
	?>
	<form class="lsg-filters" data-lsg-endpoint="bestenliste" data-lsg-target="#<?php echo esc_attr( $instance_id ); ?>-results" method="get">
		<label class="lsg-field">
			<span>Jahr</span>
			<select name="lsg_year">
				<?php foreach ( $years as $y ) : ?>
					<option value="<?php echo (int) $y; ?>" <?php selected( $args['year'], $y ); ?>><?php echo (int) $y; ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="lsg-field">
			<span>Geschlecht</span>
			<select name="lsg_gender">
				<option value="alle" <?php selected( $args['gender'], 'alle' ); ?>>Alle</option>
				<option value="m" <?php selected( $args['gender'], 'm' ); ?>>Männer</option>
				<option value="f" <?php selected( $args['gender'], 'f' ); ?>>Frauen</option>
			</select>
		</label>
		<label class="lsg-field">
			<span>Altersklasse</span>
			<select name="lsg_ak">
				<option value="alle" <?php selected( $args['ak'], 'alle' ); ?>>Alle</option>
				<?php foreach ( $valid_ak as $ak ) : ?>
					<option value="<?php echo esc_attr( $ak ); ?>" <?php selected( $args['ak'], $ak ); ?>><?php echo esc_html( $ak ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="lsg-field">
			<span>Distanz</span>
			<select name="lsg_distance">
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

/**
 * Komplettes Block-Markup (Filter + Ergebnis-Container).
 */
function lsg_bl_render_bestenliste_block( $attributes = array() ) {
	$args        = lsg_bl_resolve_bestenliste_args( $attributes );
	$instance_id = 'lsg-bestenliste-' . substr( md5( wp_json_encode( $attributes ) . wp_rand() ), 0, 8 );

	$html  = '<div class="lsg-block lsg-bestenliste" data-lsg-block="bestenliste">';
	$html .= lsg_bl_render_bestenliste_filters( $args, $instance_id );
	$html .= '<div id="' . esc_attr( $instance_id ) . '-results" class="lsg-results">';
	$html .= lsg_bl_render_bestenliste_results( $args );
	$html .= '</div>';
	$html .= '<p class="lsg-contact">Fragen zu Einträgen an: bestenliste(at)lsg-ka.de</p>';
	$html .= '</div>';

	return $html;
}
