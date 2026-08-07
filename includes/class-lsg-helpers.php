<?php
/**
 * Hilfsfunktionen: Distanz-Kategorien, Zeit/Distanz-Parsing & Sortierung,
 * Altersklassen/Geschlecht, Formatierung von Datum und Namen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kanonische Reihenfolge & Typ der Distanzen wie in lsg_best.distance /
 * lsg_win.distance verwendet. 'time'  => Ergebnis ist eine Zeit, kleiner ist
 * besser. 'distance' => Ergebnis ist eine gelaufene Strecke (bei Zeitläufen
 * wie 6h/12h/24h), größer ist besser.
 *
 * @return array<string,array{label:string,type:string}>
 */
function lsg_bl_distance_map() {
	return array(
		'5km'      => array( 'label' => '5 km', 'type' => 'time' ),
		'10km'     => array( 'label' => '10 km', 'type' => 'time' ),
		'15km'     => array( 'label' => '15 km', 'type' => 'time' ),
		'20km'     => array( 'label' => '20 km', 'type' => 'time' ),
		'25km'     => array( 'label' => '25 km', 'type' => 'time' ),
		'HM'       => array( 'label' => 'Halbmarathon', 'type' => 'time' ),
		'Marathon' => array( 'label' => 'Marathon', 'type' => 'time' ),
		'50km'     => array( 'label' => '50 km', 'type' => 'time' ),
		'100km'    => array( 'label' => '100 km', 'type' => 'time' ),
		'6h'       => array( 'label' => '6 Stunden', 'type' => 'distance' ),
		'12h'      => array( 'label' => '12 Stunden', 'type' => 'distance' ),
		'24h'      => array( 'label' => '24 Stunden', 'type' => 'distance' ),
	);
}

/**
 * Label für eine Distanz, mit Fallback auf den Rohwert falls unbekannt.
 */
function lsg_bl_distance_label( $distance ) {
	$map = lsg_bl_distance_map();
	if ( isset( $map[ $distance ] ) ) {
		return $map[ $distance ]['label'];
	}
	return $distance;
}

/**
 * Typ einer Distanz ('time' oder 'distance'), mit heuristischem Fallback für
 * unbekannte/zukünftige Distanzwerte.
 */
function lsg_bl_distance_type( $distance ) {
	$map = lsg_bl_distance_map();
	if ( isset( $map[ $distance ] ) ) {
		return $map[ $distance ]['type'];
	}
	// Heuristik: enthält die Distanzbezeichnung ein 'h' (Stunden) und keine km-Zahl -> Zeitlauf.
	if ( preg_match( '/\d+\s*h$/i', trim( $distance ) ) ) {
		return 'distance';
	}
	return 'time';
}

/**
 * Maximale Anzahl Zeilen pro Distanz in der Ewigen Bestenliste.
 */
function lsg_bl_eternal_limit( $distance ) {
	if ( '10km' === $distance ) {
		return 30;
	}
	return 20;
}

/**
 * Parst einen Ergebniswert (Spalte "time" in lsg_best/lsg_win) in einen
 * sortierbaren numerischen Wert. Unterstützt sowohl Zeiten (HH:MM:SS, auch
 * mit Anhängseln wie " h") als auch Streckenangaben (z.B. "80,475 km").
 *
 * @return array{sort:float,better:string,display:string}
 */
function lsg_bl_parse_performance( $distance, $raw ) {
	$raw  = trim( (string) $raw );
	$type = lsg_bl_distance_type( $distance );

	// Zeitformat HH:MM:SS (ggf. mit Anhängsel wie " h" oder führenden Leerzeichen).
	if ( preg_match( '/(\d{1,3}):([0-5]\d):([0-5]\d)/', $raw, $m ) ) {
		$seconds = ( (int) $m[1] ) * 3600 + ( (int) $m[2] ) * 60 + (int) $m[3];
		return array(
			'sort'    => (float) $seconds,
			'better'  => 'lower',
			'display' => $raw,
		);
	}

	// Streckenangabe, z.B. "80,475 km" oder "228 km".
	if ( preg_match( '/([\d]+(?:[.,]\d+)?)\s*km/i', $raw, $m ) ) {
		$num = (float) str_replace( ',', '.', $m[1] );
		return array(
			'sort'    => $num,
			'better'  => 'higher',
			'display' => $raw,
		);
	}

	// Fallback: irgendeine Zahl im String.
	if ( preg_match( '/([\d]+(?:[.,]\d+)?)/', $raw, $m ) ) {
		$num = (float) str_replace( ',', '.', $m[1] );
		return array(
			'sort'    => 'distance' === $type ? $num : -$num,
			'better'  => 'distance' === $type ? 'higher' : 'lower',
			'display' => $raw,
		);
	}

	return array(
		'sort'    => PHP_INT_MAX,
		'better'  => 'lower',
		'display' => $raw,
	);
}

/**
 * Sortiert ein Array von Ergebnis-Zeilen (jede Zeile braucht einen Key
 * '_perf' mit dem Ergebnis von lsg_bl_parse_performance()) nach Leistung.
 */
function lsg_bl_sort_rows_by_performance( array $rows ) {
	usort(
		$rows,
		function ( $a, $b ) {
			$dir = ( 'higher' === $a['_perf']['better'] ) ? -1 : 1;
			if ( $a['_perf']['sort'] === $b['_perf']['sort'] ) {
				return 0;
			}
			return ( $a['_perf']['sort'] < $b['_perf']['sort'] ) ? $dir : -$dir;
		}
	);
	return $rows;
}

/**
 * Geschlecht ('m'|'f') aus einem Altersklassen-Code ableiten (z.B. 'm45' => 'm',
 * 'whk' => 'f'). Fällt auf 'm' zurück, falls der Code nicht erkannt wird.
 */
function lsg_bl_gender_from_ak( $ak ) {
	$ak = strtolower( trim( (string) $ak ) );
	return ( 0 === strpos( $ak, 'w' ) ) ? 'f' : 'm';
}

/**
 * SQL LIKE-Pattern für ein Geschlecht ('m' => 'm%', 'f' => 'w%'), passend zu
 * den Altersklassen-Codes in lsg_best.ak.
 */
function lsg_bl_gender_ak_pattern( $gender ) {
	return ( 'f' === $gender ) ? 'w%' : 'm%';
}

/**
 * Sortierschlüssel für Altersklassen: Hauptklasse (hk) zuerst, danach
 * aufsteigend nach Alterszahl.
 */
function lsg_bl_ak_sort_key( $ak ) {
	$ak = strtolower( trim( (string) $ak ) );
	if ( preg_match( '/hk$/', $ak ) ) {
		return -1;
	}
	if ( preg_match( '/(\d+)/', $ak, $m ) ) {
		return (int) $m[1];
	}
	return 999;
}

/**
 * Liste der verfügbaren Altersklassen für ein Geschlecht, aus der Tabelle
 * lsg_ak, sortiert (hk, dann aufsteigend).
 *
 * @return string[]
 */
function lsg_bl_ak_list_for_gender( $gender ) {
	global $wpdb;
	$table   = lsg_bl_table( 'lsg_ak' );
	$pattern = lsg_bl_gender_ak_pattern( $gender );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ak FROM {$table} WHERE ak LIKE %s", $pattern ) ); // phpcs:ignore
	usort(
		$rows,
		function ( $a, $b ) {
			return lsg_bl_ak_sort_key( $a ) <=> lsg_bl_ak_sort_key( $b );
		}
	);
	return $rows;
}

/**
 * Formatiert einen Unix-Timestamp als TT.MM.JJJJ. Leere/ungültige Werte
 * ergeben einen leeren String.
 */
function lsg_bl_format_date( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return '';
	}
	return date_i18n( 'd.m.Y', $timestamp );
}

/**
 * Jahr aus einem Unix-Timestamp.
 */
function lsg_bl_year_from_timestamp( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return 0;
	}
	return (int) date_i18n( 'Y', $timestamp );
}

/**
 * Anzeigename eines Athleten: "Nachname Vorname" (wie in den Originaldaten).
 */
function lsg_bl_athlete_display_name( $name, $firstname ) {
	return trim( $name . ' ' . $firstname );
}

/**
 * Escaped Ausgabe-Helfer für Tabellenzellen (leerer Wert => "–").
 */
function lsg_bl_cell( $value ) {
	$value = trim( (string) $value );
	return ( '' === $value ) ? '&#8211;' : esc_html( $value );
}
