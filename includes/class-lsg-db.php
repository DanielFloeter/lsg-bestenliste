<?php
/**
 * Datenzugriffsschicht: alle Datenbankabfragen für die drei Blöcke.
 * Tabellennamen kommen aus lsg_bl_table() (siehe lsg-bestenliste.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alle Jahre, für die es Einträge in lsg_best gibt (für das Bestenliste-Jahr-Dropdown),
 * absteigend sortiert (neuestes Jahr zuerst).
 *
 * @return int[]
 */
function lsg_bl_get_best_years() {
	global $wpdb;
	$table = lsg_bl_table( 'lsg_best' );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$stamps = $wpdb->get_col( "SELECT DISTINCT `date` FROM {$table} WHERE `date` IS NOT NULL AND `date` > 0" );
	return lsg_bl_jahre_aus_timestamps( $stamps );
}

/**
 * Timestamps → absteigend sortierte Liste eindeutiger Jahre.
 *
 * Die beiden Jahres-Dropdowns lasen früher SELECT DISTINCT
 * YEAR(FROM_UNIXTIME(`date`)) – das rechnet mit der MySQL-Session-Zeitzone
 * und kann einen Neujahrslauf ins Vorjahr schieben. Hier wird stattdessen
 * nur der Timestamp gelesen und in PHP über lsg_bl_year_from_timestamp()
 * (also date_i18n(), also die richtige Zeitzone) in ein Jahr umgerechnet
 * (Plan 6.5.4).
 *
 * @param array $stamps Rohe Timestamps aus der Datenbank.
 * @return int[]
 */
function lsg_bl_jahre_aus_timestamps( $stamps ) {
	$jahre = array();
	foreach ( (array) $stamps as $ts ) {
		$j = lsg_bl_year_from_timestamp( $ts );
		if ( $j > 0 ) {
			$jahre[ $j ] = true;
		}
	}
	$jahre = array_keys( $jahre );
	rsort( $jahre, SORT_NUMERIC );
	return $jahre;
}

/**
 * Alle Jahre, für die es Einträge in lsg_win gibt (für das Gesamtsiege-Jahr-Dropdown).
 *
 * @return int[]
 */
function lsg_bl_get_win_years() {
	global $wpdb;
	$table = lsg_bl_table( 'lsg_win' );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$stamps = $wpdb->get_col( "SELECT DISTINCT `date` FROM {$table} WHERE `date` IS NOT NULL AND `date` > 0" );
	return lsg_bl_jahre_aus_timestamps( $stamps );
}

/**
 * Rohzeilen aus lsg_best (inkl. Athletenname) für eine bestimmte Distanz,
 * gefiltert nach Geschlecht, Altersklasse und optional Jahr. Kein Limit,
 * keine Sortierung (das übernimmt der Aufrufer je nach Distanztyp).
 *
 * @param string $distance Distanzcode, z.B. '5km'.
 * @param string $gender   'm', 'f' oder 'alle' (dann kein Geschlechtsfilter).
 * @param string $ak       Altersklassen-Code oder 'alle'.
 * @param int    $year     Jahr, oder 0 für alle Jahre (Ewige Bestenliste).
 * @return array<int,array>
 */
function lsg_bl_get_best_rows( $distance, $gender, $ak, $year = 0 ) {
	global $wpdb;
	$t_best    = lsg_bl_table( 'lsg_best' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	$where  = array( 'b.distance = %s' );
	$params = array( $distance );

	if ( 'alle' !== $gender ) {
		$where[]  = 'b.ak LIKE %s';
		$params[] = lsg_bl_gender_ak_pattern( $gender );
	}

	if ( $ak && 'alle' !== $ak ) {
		// $ak ist der Altersklassen-Code ohne Geschlechts-Präfix (z.B. '45',
		// 'hk'), daher hier auf den Teil ab dem 2. Zeichen von b.ak matchen.
		$where[]  = 'SUBSTRING(b.ak, 2) = %s';
		$params[] = $ak;
	}

	if ( $year > 0 ) {
		// Zeitspanne statt YEAR(FROM_UNIXTIME()): zeitzonenfest und
		// indexfaehig (siehe lsg_bl_jahr_grenzen(), Plan 6.5.4).
		list( $von, $bis ) = lsg_bl_jahr_grenzen( $year );
		$where[]  = 'b.date >= %d';
		$params[] = $von;
		$where[]  = 'b.date < %d';
		$params[] = $bis;
	}

	$where_sql = implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql = $wpdb->prepare(
		"SELECT b.id, b.distance, b.time, b.town, b.date, b.ak, b.athletes_id, a.name, a.firstname, a.cat
		 FROM {$t_best} b
		 INNER JOIN {$t_athlete} a ON a.id = b.athletes_id
		 WHERE {$where_sql}",
		$params
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! $rows ) {
		return array();
	}

	foreach ( $rows as &$row ) {
		$row['_perf'] = lsg_bl_parse_performance( $row['distance'], $row['time'] );
	}
	return $rows;
}

/**
 * Liste der Distanzen, für die es in lsg_best (gefiltert nach Jahr/Geschlecht/AK)
 * mindestens einen Eintrag gibt, in kanonischer Reihenfolge.
 *
 * @return string[]
 */
function lsg_bl_get_distances_present( $gender, $ak, $year = 0 ) {
	global $wpdb;
	$t_best = lsg_bl_table( 'lsg_best' );

	$where  = array();
	$params = array();

	if ( 'alle' !== $gender ) {
		$where[]  = 'b.ak LIKE %s';
		$params[] = lsg_bl_gender_ak_pattern( $gender );
	}
	if ( $ak && 'alle' !== $ak ) {
		// $ak ist der Altersklassen-Code ohne Geschlechts-Präfix (z.B. '45',
		// 'hk'), daher hier auf den Teil ab dem 2. Zeichen von b.ak matchen.
		$where[]  = 'SUBSTRING(b.ak, 2) = %s';
		$params[] = $ak;
	}
	if ( $year > 0 ) {
		// Zeitspanne statt YEAR(FROM_UNIXTIME()): zeitzonenfest und
		// indexfaehig (siehe lsg_bl_jahr_grenzen(), Plan 6.5.4).
		list( $von, $bis ) = lsg_bl_jahr_grenzen( $year );
		$where[]  = 'b.date >= %d';
		$params[] = $von;
		$where[]  = 'b.date < %d';
		$params[] = $bis;
	}
	$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( "SELECT DISTINCT b.distance FROM {$t_best} b {$where_sql}", $params );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT DISTINCT b.distance FROM {$t_best} b {$where_sql}";
	}
	$present = $wpdb->get_col( $sql );

	$map     = lsg_bl_distance_map();
	$ordered = array();
	foreach ( array_keys( $map ) as $d ) {
		if ( in_array( $d, $present, true ) ) {
			$ordered[] = $d;
		}
	}
	// Unbekannte/zukünftige Distanzwerte hinten anhängen.
	foreach ( $present as $d ) {
		if ( ! in_array( $d, $ordered, true ) ) {
			$ordered[] = $d;
		}
	}
	return $ordered;
}

/**
 * Alle verfügbaren Distanzcodes über die komplette Tabelle lsg_best (für
 * das Distanz-Dropdown der Blöcke, unabhängig vom aktuell gewählten Jahr).
 *
 * @return string[]
 */
function lsg_bl_get_all_distances() {
	global $wpdb;
	$table   = lsg_bl_table( 'lsg_best' );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$present = $wpdb->get_col( "SELECT DISTINCT distance FROM {$table}" );

	$map     = lsg_bl_distance_map();
	$ordered = array();
	foreach ( array_keys( $map ) as $d ) {
		if ( in_array( $d, $present, true ) ) {
			$ordered[] = $d;
		}
	}
	foreach ( $present as $d ) {
		if ( ! in_array( $d, $ordered, true ) ) {
			$ordered[] = $d;
		}
	}
	return $ordered;
}

/**
 * Einzelsieg-Einträge aus lsg_win für ein Jahr, chronologisch sortiert.
 *
 * @return array<int,array>
 */
function lsg_bl_get_win_rows( $year ) {
	global $wpdb;
	$t_win     = lsg_bl_table( 'lsg_win' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	$where  = array();
	$params = array();
	if ( $year > 0 ) {
		// Dieselbe Umstellung wie bei lsg_best: lsg_win hat heute keinen Lauf
		// am 1. Januar, aber die Falle bliebe sonst offen (Plan 6.5.4).
		list( $von, $bis ) = lsg_bl_jahr_grenzen( $year );
		$where[]  = 'w.date >= %d';
		$params[] = $von;
		$where[]  = 'w.date < %d';
		$params[] = $bis;
	}

	$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

	$sql = "SELECT w.id, w.date, w.town, w.event, w.distance, w.time, a.name, a.firstname, a.cat
			FROM {$t_win} w
			INNER JOIN {$t_athlete} a ON a.id = w.athletes_id
			{$where_sql}
			ORDER BY w.date ASC, w.id ASC";

	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $params );
	}

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return $rows ? $rows : array();
}
