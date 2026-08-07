<?php
/**
 * REST-API-Endpunkte für die Filter-Interaktion ohne Seiten-Reload
 * (progressive enhancement: ohne JS greift der normale GET-Formular-Reload,
 * render.php liest dann dieselben Parameter direkt aus $_GET).
 *
 * Wichtig: Die Parameternamen hier müssen exakt zu den "name"-Attributen der
 * <select>-Felder in den Filterformularen (render-*.php) passen, da das
 * Frontend-Script (frontend.js) einfach alle Formularfeld-Namen 1:1 als
 * Query-Parameter an die REST-Route weiterreicht. Die Präfixe (lsg_, lsg_win_,
 * lsg_ew_) verhindern Kollisionen, falls mehrere Blöcke auf einer Seite stehen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'lsg/v1',
			'/bestenliste',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'lsg_bl_rest_bestenliste',
				'args'                => array(
					'lsg_year'     => array( 'type' => 'integer' ),
					'lsg_gender'   => array( 'type' => 'string' ),
					'lsg_ak'       => array( 'type' => 'string' ),
					'lsg_distance' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'lsg/v1',
			'/gesamtsiege',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'lsg_bl_rest_gesamtsiege',
				'args'                => array(
					'lsg_win_year' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'lsg/v1',
			'/ewige-bestenliste',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'lsg_bl_rest_ewige_bestenliste',
				'args'                => array(
					'lsg_ew_gender'   => array( 'type' => 'string' ),
					'lsg_ew_ak'       => array( 'type' => 'string' ),
					'lsg_ew_distance' => array( 'type' => 'string' ),
				),
			)
		);
	}
);

/**
 * Validiert Geschlecht/Altersklasse/Distanz aus Request-Parametern nach
 * denselben Regeln wie die serverseitigen Blöcke, ohne auf $_GET zurückzugreifen.
 */
function lsg_bl_validate_common( $gender_raw, $ak_raw, $distance_raw ) {
	$gender = ( 'f' === $gender_raw ) ? 'f' : 'm';

	$valid_ak = lsg_bl_ak_list_for_gender( $gender );
	$ak       = ( $ak_raw && in_array( $ak_raw, $valid_ak, true ) ) ? $ak_raw : 'alle';

	$valid_distances = lsg_bl_get_all_distances();
	$distance        = ( $distance_raw && in_array( $distance_raw, $valid_distances, true ) ) ? $distance_raw : 'alle';

	return array(
		'gender'   => $gender,
		'ak'       => $ak,
		'distance' => $distance,
	);
}

function lsg_bl_rest_bestenliste( WP_REST_Request $request ) {
	$common = lsg_bl_validate_common( $request->get_param( 'lsg_gender' ), $request->get_param( 'lsg_ak' ), $request->get_param( 'lsg_distance' ) );

	$years        = lsg_bl_get_best_years();
	$current_year = ! empty( $years ) ? max( $years ) : (int) date_i18n( 'Y' );
	$year         = (int) $request->get_param( 'lsg_year' );
	if ( ! in_array( $year, $years, true ) ) {
		$year = $current_year;
	}

	$args = array_merge( $common, array( 'year' => $year, 'years' => $years ) );

	return rest_ensure_response(
		array(
			'html' => lsg_bl_render_bestenliste_results( $args ),
		)
	);
}

function lsg_bl_rest_gesamtsiege( WP_REST_Request $request ) {
	$years        = lsg_bl_get_win_years();
	$current_year = ! empty( $years ) ? max( $years ) : (int) date_i18n( 'Y' );
	$year         = (int) $request->get_param( 'lsg_win_year' );
	if ( ! in_array( $year, $years, true ) ) {
		$year = $current_year;
	}

	return rest_ensure_response(
		array(
			'html'  => lsg_bl_render_gesamtsiege_results( array( 'year' => $year ) ),
			'title' => 'Gesamtsiege ' . $year,
		)
	);
}

function lsg_bl_rest_ewige_bestenliste( WP_REST_Request $request ) {
	$common = lsg_bl_validate_common( $request->get_param( 'lsg_ew_gender' ), $request->get_param( 'lsg_ew_ak' ), $request->get_param( 'lsg_ew_distance' ) );

	return rest_ensure_response(
		array(
			'html' => lsg_bl_render_ewige_results( $common ),
		)
	);
}
