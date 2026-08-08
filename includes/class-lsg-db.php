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
	$rows = $wpdb->get_col( "SELECT DISTINCT YEAR(FROM_UNIXTIME(`date`)) FROM {$table} WHERE `date` IS NOT NULL AND `date` > 0 ORDER BY 1 DESC" );
	return array_map( 'intval', $rows );
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
	$rows = $wpdb->get_col( "SELECT DISTINCT YEAR(FROM_UNIXTIME(`date`)) FROM {$table} WHERE `date` IS NOT NULL AND `date` > 0 ORDER BY 1 DESC" );
	return array_map( 'intval', $rows );
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
		$where[]  = 'b.ak = %s';
		$params[] = $ak;
	}

	if ( $year > 0 ) {
		$where[]  = 'YEAR(FROM_UNIXTIME(b.date)) = %d';
		$params[] = $year;
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
		$where[]  = 'b.ak = %s';
		$params[] = $ak;
	}
	if ( $year > 0 ) {
		$where[]  = 'YEAR(FROM_UNIXTIME(b.date)) = %d';
		$params[] = $year;
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
		$where[]  = 'YEAR(FROM_UNIXTIME(w.date)) = %d';
		$params[] = $year;
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
