<?php
/**
 * Das Import-Log: schreiben und lesen.
 *
 * Alles, was die Übernahme tut *und bewusst nicht tut*, wird protokolliert.
 * Das Log ist die Antwort auf „warum steht bei X diese Zeit" – Monate später,
 * wenn niemand mehr weiß, welche Liste importiert wurde (Plan 6.8).
 *
 * Zwei Tabellen: `lsg_import_run` hält den Vorgang (einen Datensatz je Klick),
 * `lsg_import_log` die Einzelzeilen. In einer Tabelle würde man die
 * Vorgangs-Metadaten auf jeder der vierzig Zeilen wiederholen.
 *
 * ⚠ Die Rohfelder wirken redundant, sind es aber nicht: das Log soll auch dann
 * noch verständlich sein, wenn die Quelle offline ist, der Athlet umbenannt
 * oder eine Zuordnungsregel korrigiert wurde. Ein Log, das nur IDs enthält,
 * ist genau dann wertlos, wenn man es braucht.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formatliste für $wpdb->insert() aus den Feldnamen ableiten.
 *
 * ⚠ $wpdb->insert() liest die Formate POSITIONSWEISE (array_shift), nicht
 * nach Feldnamen. Eine von Hand gepflegte Liste steht damit sofort falsch da,
 * sobald jemand ein Feld einfügt – und der Fehler ist still: aus einer
 * Jahreszahl wird ein String, aus einer ID eine 0. Deshalb wird die Liste aus
 * den tatsächlichen Schlüsseln der Daten gebaut.
 *
 * NULL-Werte sind davon unberührt: $wpdb schreibt sie als SQL-NULL,
 * unabhängig vom Format. Genau das braucht `roh_jahrgang` – „die Quelle
 * nannte keinen Jahrgang" muss von „Jahrgang 0" unterscheidbar bleiben
 * (Plan 6.8).
 *
 * @param array               $daten  Zu schreibende Werte.
 * @param array<string,string> $typen Feldname => '%d' | '%s'.
 * @return string[]
 */
function lsg_bl_formate( array $daten, array $typen ) {
	$out = array();
	foreach ( array_keys( $daten ) as $feld ) {
		$out[] = isset( $typen[ $feld ] ) ? $typen[ $feld ] : '%s';
	}
	return $out;
}

/**
 * Feldtypen von lsg_import_run.
 *
 * @return array<string,string>
 */
function lsg_bl_run_feldtypen() {
	return array(
		'tstamp'            => '%d',
		'user_id'           => '%d',
		'event_date'        => '%d',
		'jahr'              => '%d',
		'cnt_gelesen'       => '%d',
		'cnt_lsg'           => '%d',
		'cnt_zugeordnet'    => '%d',
		'cnt_angelegt'      => '%d',
		'cnt_aktualisiert'  => '%d',
		'cnt_uebersprungen' => '%d',
		'cnt_fehler'        => '%d',
	);
}

/**
 * Feldtypen von lsg_import_log.
 *
 * @return array<string,string>
 */
function lsg_bl_log_feldtypen() {
	return array(
		'run_id'       => '%d',
		'tstamp'       => '%d',
		'athletes_id'  => '%d',
		'best_id'      => '%d',
		'roh_jahrgang' => '%d',
		'gesamtsieg'   => '%d',
	);
}

/**
 * Eine Log-Zeile aus einer Ergebniszeile bauen.
 *
 * @param array  $z        Zeile aus dem Parse-Transient.
 * @param string $aktion   Wert aus lsg_bl_log_aktionen().
 * @param int    $best_id  Betroffene Zeile in lsg_best, 0 wenn keine.
 * @param string $time_alt Überschriebene Zeit, leer bei INSERT.
 * @param string $meldung  Klartext, wie in der Oberfläche.
 * @return array
 */
function lsg_bl_log_zeile( array $z, $aktion, $best_id = 0, $time_alt = '', $meldung = '' ) {
	return array(
		'athletes_id'    => (int) $z['athletes_id'],
		'best_id'        => (int) $best_id,
		'match_type'     => (string) $z['match_type'],
		'aktion'         => (string) $aktion,
		'ak'             => (string) $z['ak'],
		'time_neu'       => (string) $z['zeit'],
		'time_alt'       => (string) $time_alt,
		'roh_teilnehmer' => (string) $z['teilnehmer'],
		'roh_name'       => (string) $z['nachname'],
		'roh_vorname'    => (string) $z['vorname'],
		'roh_verein'     => (string) $z['verein'],
		'roh_jahrgang'   => ( (int) $z['jahrgang'] > 0 ) ? (int) $z['jahrgang'] : null,
		'roh_zeit'       => (string) $z['roh_zeit'],
		'roh_startnr'    => (string) $z['startnummer'],
		'roh_platz'      => (string) $z['platz'],
		'gesamtsieg'     => 0,
		'meldung'        => (string) $meldung,
	);
}

/**
 * Einen Vorgang samt seiner Zeilen schreiben.
 *
 * @param array $daten      Parse-Ergebnis aus dem Transient.
 * @param array $bilanz     angelegt, aktualisiert, uebersprungen, konflikte, fehler.
 * @param array $log_zeilen Zeilen von lsg_bl_log_zeile().
 * @return int run_id, oder 0 wenn das Log nicht geschrieben werden konnte.
 */
function lsg_bl_log_schreiben( array $daten, array $bilanz, array $log_zeilen ) {
	global $wpdb;

	$t_run = lsg_bl_table( 'lsg_import_run' );
	$t_log = lsg_bl_table( 'lsg_import_log' );

	if ( ! lsg_bl_tabelle_da( $t_run ) || ! lsg_bl_tabelle_da( $t_log ) ) {
		return 0;
	}

	$trichter = isset( $daten['trichter'] ) ? (array) $daten['trichter'] : array();

	$notiz = array();
	foreach ( (array) $daten['zeilen'] as $z ) {
		if ( ! empty( $z['doppelt'] ) ) {
			$notiz[] = sprintf(
				'Doppelzeile im Bestand: ids #%s',
				implode( ', #', $z['doppelt'] )
			);
		}
	}
	$notiz = array_values( array_unique( $notiz ) );

	$status = 'uebernommen';
	if ( $bilanz['fehler'] > 0 ) {
		$status = 'fehler';
	} elseif ( $bilanz['konflikte'] > 0 ) {
		$status = 'teilfehler';
	}

	$werte = array(
		'tstamp'            => time(),
		'user_id'           => get_current_user_id(),
		'adapter'           => (string) $daten['adapter'],
		'source_url'        => mb_substr( (string) $daten['quelle_url'], 0, 255 ),
		'event_id'          => mb_substr( (string) $daten['event_id'], 0, 32 ),
		'event_name'        => mb_substr( (string) $daten['event_name'], 0, 120 ),
		'event_date'        => lsg_bl_datum_zu_timestamp( $daten['datum'] ),
		'datum_quelle'      => mb_substr( (string) ( isset( $daten['datum_quelle'] ) ? $daten['datum_quelle'] : 'manuell' ), 0, 16 ),
		'jahr'              => (int) $daten['jahr'],
		'contest_id'        => mb_substr( (string) $daten['contest_id'], 0, 32 ),
		'contest_name'      => mb_substr( (string) $daten['contest_name'], 0, 120 ),
		'list_id'           => mb_substr( (string) $daten['list_id'], 0, 64 ),
		'list_name'         => mb_substr( (string) $daten['list_name'], 0, 120 ),
		'distance'          => (string) $daten['distanz'],
		'town'              => mb_substr( (string) $daten['ort'], 0, 30 ),
		'zeit_typ'          => (string) $daten['zeit_typ'],
		'cnt_gelesen'       => isset( $trichter['gelesen'] ) ? (int) $trichter['gelesen'] : 0,
		'cnt_lsg'           => isset( $trichter['lsg'] ) ? (int) $trichter['lsg'] : 0,
		'cnt_zugeordnet'    => isset( $trichter['zugeordnet'] ) ? (int) $trichter['zugeordnet'] : 0,
		'cnt_angelegt'      => (int) $bilanz['angelegt'],
		'cnt_aktualisiert'  => (int) $bilanz['aktualisiert'],
		'cnt_uebersprungen' => (int) $bilanz['uebersprungen'],
		'cnt_fehler'        => (int) $bilanz['fehler'] + (int) $bilanz['konflikte'],
		'status'            => $status,
		'note'              => $notiz ? implode( "\n", $notiz ) : null,
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->insert( $t_run, $werte, lsg_bl_formate( $werte, lsg_bl_run_feldtypen() ) );

	if ( false === $ok ) {
		return 0;
	}

	$run_id = (int) $wpdb->insert_id;
	$jetzt  = time();

	foreach ( $log_zeilen as $z ) {
		$z['run_id']   = $run_id;
		$z['tstamp']   = $jetzt;
		$z['distance'] = (string) $daten['distanz'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $t_log, $z, lsg_bl_formate( $z, lsg_bl_log_feldtypen() ) );
	}

	return $run_id;
}

/* -------------------------------------------------------------------------
 * Lesen
 * ---------------------------------------------------------------------- */

/**
 * Vorgänge, neueste zuerst.
 *
 * @param array $args limit, offset, adapter, jahr, user_id.
 * @return array{zeilen:array,gesamt:int}
 */
function lsg_bl_log_vorgaenge( array $args = array() ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_import_run' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array(
			'zeilen' => array(),
			'gesamt' => 0,
		);
	}

	$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;
	$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

	$where  = array( '1=1' );
	$params = array();

	if ( ! empty( $args['adapter'] ) ) {
		$where[]  = 'adapter = %s';
		$params[] = (string) $args['adapter'];
	}
	if ( ! empty( $args['jahr'] ) ) {
		$where[]  = 'jahr = %d';
		$params[] = (int) $args['jahr'];
	}
	if ( ! empty( $args['user_id'] ) ) {
		$where[]  = 'user_id = %d';
		$params[] = (int) $args['user_id'];
	}

	$where_sql = implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";
	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = $wpdb->prepare( $count_sql, $params );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$gesamt = (int) $wpdb->get_var( $count_sql );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = $wpdb->prepare(
		"SELECT * FROM {$t} WHERE {$where_sql} ORDER BY tstamp DESC, id DESC LIMIT %d OFFSET %d",
		array_merge( $params, array( $limit, $offset ) )
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$zeilen = $wpdb->get_results( $sql, ARRAY_A );

	return array(
		'zeilen' => $zeilen ? $zeilen : array(),
		'gesamt' => $gesamt,
	);
}

/**
 * Einen Vorgang holen.
 *
 * @param int $run_id lsg_import_run.id.
 * @return array|null
 */
function lsg_bl_log_vorgang( $run_id ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_import_run' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $run_id ),
		ARRAY_A
	);
	return $row ? $row : null;
}

/**
 * Log-Zeilen, gefiltert.
 *
 * Das Suchfeld greift über die Rohschreibweisen der Quelle UND über den
 * Athletennamen (Join) – „warum steht bei X diese Zeit" beantwortet sich
 * sonst nicht, wenn die Quelle den Namen anders geschrieben hat als die
 * Datenbank (Plan 6.8).
 *
 * @param array $args run_id, suche, aktion, distance, jahr, limit, offset.
 * @return array{zeilen:array,gesamt:int}
 */
function lsg_bl_log_zeilen( array $args = array() ) {
	global $wpdb;

	$t_log     = lsg_bl_table( 'lsg_import_log' );
	$t_run     = lsg_bl_table( 'lsg_import_run' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	if ( ! lsg_bl_tabelle_da( $t_log ) ) {
		return array(
			'zeilen' => array(),
			'gesamt' => 0,
		);
	}

	$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
	$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

	$where  = array( '1=1' );
	$params = array();

	if ( ! empty( $args['run_id'] ) ) {
		$where[]  = 'l.run_id = %d';
		$params[] = (int) $args['run_id'];
	}
	if ( ! empty( $args['aktion'] ) ) {
		$where[]  = 'l.aktion = %s';
		$params[] = (string) $args['aktion'];
	}
	if ( ! empty( $args['distance'] ) ) {
		$where[]  = 'l.distance = %s';
		$params[] = (string) $args['distance'];
	}
	if ( ! empty( $args['jahr'] ) ) {
		$where[]  = 'r.jahr = %d';
		$params[] = (int) $args['jahr'];
	}
	if ( ! empty( $args['adapter'] ) ) {
		$where[]  = 'r.adapter = %s';
		$params[] = (string) $args['adapter'];
	}
	if ( ! empty( $args['suche'] ) ) {
		$like     = '%' . $wpdb->esc_like( (string) $args['suche'] ) . '%';
		$where[]  = '( l.roh_name LIKE %s OR l.roh_vorname LIKE %s OR l.roh_teilnehmer LIKE %s OR l.roh_verein LIKE %s OR a.name LIKE %s OR a.firstname LIKE %s )';
		$params   = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
	}

	$where_sql = implode( ' AND ', $where );
	$join      = "FROM {$t_log} l
		LEFT JOIN {$t_run} r ON r.id = l.run_id
		LEFT JOIN {$t_athlete} a ON a.id = l.athletes_id
		WHERE {$where_sql}";

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_sql = "SELECT COUNT(*) {$join}";
	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = $wpdb->prepare( $count_sql, $params );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$gesamt = (int) $wpdb->get_var( $count_sql );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = $wpdb->prepare(
		"SELECT l.*, a.name AS athlet_name, a.firstname AS athlet_firstname, a.born AS athlet_born,
		        r.event_name, r.contest_name, r.adapter, r.jahr, r.user_id
		 {$join}
		 ORDER BY l.id ASC LIMIT %d OFFSET %d",
		array_merge( $params, array( $limit, $offset ) )
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$zeilen = $wpdb->get_results( $sql, ARRAY_A );

	return array(
		'zeilen' => $zeilen ? $zeilen : array(),
		'gesamt' => $gesamt,
	);
}

/**
 * Die Distanzen, zu denen es Log-Zeilen gibt – für das Filter-Dropdown.
 *
 * @return string[]
 */
function lsg_bl_log_distanzen() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_import_log' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$vorhanden = $wpdb->get_col( "SELECT DISTINCT distance FROM {$t} WHERE distance <> ''" );

	// In der kanonischen Reihenfolge, nicht alphabetisch – sonst stünde
	// 100km vor 10km.
	$out = array();
	foreach ( array_keys( lsg_bl_distance_map() ) as $d ) {
		if ( in_array( $d, (array) $vorhanden, true ) ) {
			$out[] = $d;
		}
	}
	return $out;
}

/**
 * Die Jahre, zu denen es Vorgänge gibt – für das Filter-Dropdown.
 *
 * @return int[]
 */
function lsg_bl_log_jahre() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_import_run' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$jahre = $wpdb->get_col( "SELECT DISTINCT jahr FROM {$t} WHERE jahr > 0 ORDER BY jahr DESC" );
	return array_map( 'intval', (array) $jahre );
}
