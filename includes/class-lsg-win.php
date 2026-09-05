<?php
/**
 * Datenzugriff für die Seite „Gesamtsiege" (Plan, Abschnitt 12).
 *
 * ⚠ Hier wird geholt und geschrieben, nicht entschieden. Die Regeln stehen in
 * class-lsg-win-form.php und sind ohne WordPress prüfbar.
 *
 * ⚠ `distance` und `time` sind Freitext und bleiben es (12.1). Keine dieser
 * Funktionen normalisiert etwas – sie schreiben, was das Formular geprüft hat.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Die Filter der Liste auf gültige Werte bringen (12.4).
 *
 * @param array $roh Rohe GET-Werte.
 * @return array{jahr:int,athlet:int,s:string,orderby:string,order:string}
 */
function lsg_bl_win_filter( array $roh ) {
	/*
	 * ⚠ Die Sortierung kommt aus der Query und gehört deshalb in eine
	 * Whitelist – und sie muss auch wirklich ankommen. Genau das ist bei
	 * LSG_BL_Best_Table bis M7 nicht passiert (11.3).
	 */
	$orderby = isset( $roh['orderby'] ) ? (string) $roh['orderby'] : '';
	if ( ! in_array( $orderby, array( 'datum', 'ort', 'athlet' ), true ) ) {
		$orderby = '';
	}

	$order = ( isset( $roh['order'] ) && 'asc' === strtolower( (string) $roh['order'] ) ) ? 'asc' : 'desc';

	return array(
		'jahr'    => isset( $roh['jahr'] ) ? (int) $roh['jahr'] : 0,
		'athlet'  => isset( $roh['athlet'] ) ? (int) $roh['athlet'] : 0,
		's'       => isset( $roh['s'] ) ? trim( (string) $roh['s'] ) : '',
		'orderby' => $orderby,
		'order'   => $order,
	);
}

/**
 * Die Jahre, in denen es Gesamtsiege gibt – für den Jahresfilter.
 *
 * @return int[] absteigend.
 */
function lsg_bl_win_jahre() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_win' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$stempel = $wpdb->get_col( "SELECT DISTINCT `date` FROM {$t} WHERE `date` > 0" );

	$jahre = array();
	foreach ( (array) $stempel as $ts ) {
		$j = lsg_bl_year_from_timestamp( (int) $ts );
		if ( $j > 0 ) {
			$jahre[ $j ] = $j;
		}
	}

	rsort( $jahre );
	return $jahre;
}

/**
 * Die Werte, die in einer Spalte schon vorkommen – für die Vorschlagsliste
 * (Plan 12.1).
 *
 * Nach Häufigkeit sortiert, damit oben steht, was üblich ist: `10 km` vor
 * `10km`, und `Pforzheim nach Basel` ganz unten.
 *
 * ⚠ Das ist eine Zugabe, kein Filter. Wer etwas anderes tippt, bekommt es
 * gespeichert – die Liste vereinheitlicht durch Angewohnheit, nicht durch
 * Verbot.
 *
 * @param string $spalte 'distance' | 'time' | 'town'.
 * @param int    $limit  Höchstens so viele.
 * @return string[]
 */
function lsg_bl_win_vorschlaege( $spalte, $limit = 40 ) {
	global $wpdb;

	if ( ! in_array( $spalte, array( 'distance', 'time', 'town' ), true ) ) {
		return array();
	}

	$t = lsg_bl_table( 'lsg_win' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$werte = $wpdb->get_col(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT {$spalte} FROM {$t}
			  WHERE TRIM({$spalte}) <> ''
			  GROUP BY {$spalte}
			  ORDER BY COUNT(*) DESC, {$spalte} ASC
			  LIMIT %d",
			(int) $limit
		)
	);

	return $werte ? array_map( 'strval', $werte ) : array();
}

/**
 * Eine Zeile, mit dem Athleten daran.
 *
 * @param int $id lsg_win.id.
 * @return array|null
 */
function lsg_bl_win_zeile( $id ) {
	global $wpdb;

	$t_win = lsg_bl_table( 'lsg_win' );
	$t_ath = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT w.id, w.`date`, w.town, w.event, w.distance, w.time, w.athletes_id,
			        a.name, a.firstname, a.born, a.cat, a.active
			   FROM {$t_win} w
			   LEFT JOIN {$t_ath} a ON a.id = w.athletes_id
			  WHERE w.id = %d",
			(int) $id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Die Liste (12.4).
 *
 * @param array $filter Ergebnis von lsg_bl_win_filter().
 * @param int   $seite  1-basiert.
 * @param int   $pro    Zeilen je Seite.
 * @return array{zeilen:array,gesamt:int}
 */
function lsg_bl_win_liste( array $filter, $seite = 1, $pro = 50 ) {
	global $wpdb;

	$t_win = lsg_bl_table( 'lsg_win' );
	$t_ath = lsg_bl_table( 'lsg_athlete' );

	$where  = array( '1=1' );
	$params = array();

	if ( $filter['jahr'] > 0 ) {
		// Jahresgrenzen aus wp_timezone(), nicht YEAR(FROM_UNIXTIME()) – 6.5.4
		// nennt lsg_win ausdrücklich mit.
		list( $von, $bis ) = lsg_bl_jahr_grenzen( $filter['jahr'] );
		$where[]           = '(w.`date` >= %d AND w.`date` < %d)';
		$params[]          = $von;
		$params[]          = $bis;
	}

	if ( $filter['athlet'] > 0 ) {
		$where[]  = 'w.athletes_id = %d';
		$params[] = $filter['athlet'];
	}

	if ( '' !== $filter['s'] ) {
		$like     = '%' . $wpdb->esc_like( $filter['s'] ) . '%';
		$where[]  = '(w.event LIKE %s OR w.town LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}

	$w = implode( ' AND ', $where );

	$sql_count = "SELECT COUNT(*) FROM {$t_win} w LEFT JOIN {$t_ath} a ON a.id = w.athletes_id WHERE {$w}";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$gesamt = (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql_count, $params ) : $sql_count );

	$seite  = max( 1, (int) $seite );
	$pro    = max( 1, (int) $pro );
	$offset = ( $seite - 1 ) * $pro;

	/*
	 * Die Chronik liest sich von heute nach hinten. Sortiert der Mensch
	 * selbst, tritt sein Schlüssel davor; die Vorgabe bleibt als zweiter
	 * stehen, damit gleiche Werte nicht zufällig angeordnet sind.
	 */
	$vorgabe    = 'w.`date` DESC, w.id DESC';
	$richtung   = ( 'asc' === $filter['order'] ) ? 'ASC' : 'DESC';
	$schluessel = array(
		'datum'  => 'w.`date` %1$s',
		'ort'    => 'w.town %1$s',
		'athlet' => 'a.name %1$s, a.firstname %1$s',
	);

	$order_by = $vorgabe;
	if ( '' !== $filter['orderby'] && isset( $schluessel[ $filter['orderby'] ] ) ) {
		$order_by = sprintf( $schluessel[ $filter['orderby'] ], $richtung ) . ', ' . $vorgabe;
	}

	$sql = "SELECT w.id, w.`date`, w.town, w.event, w.distance, w.time, w.athletes_id,
	               a.name, a.firstname, a.born, a.cat, a.active
	          FROM {$t_win} w
	          LEFT JOIN {$t_ath} a ON a.id = w.athletes_id
	         WHERE {$w}
	         ORDER BY {$order_by}
	         LIMIT %d OFFSET %d";

	$args   = $params;
	$args[] = $pro;
	$args[] = $offset;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$zeilen = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

	return array(
		'zeilen' => $zeilen ? $zeilen : array(),
		'gesamt' => $gesamt,
	);
}

/**
 * Steht dieser Sieg schon da? (Dublettensperre, 12.3)
 *
 * Athlet + Datum + Veranstaltung. ⚠ Nicht Athlet + Jahr: `lsg_win` ist keine
 * Jahrestabelle, mehrere Siege im Jahr sind der Normalfall.
 *
 * @param int    $athlet    athletes_id.
 * @param string $datum     JJJJ-MM-TT.
 * @param string $event     Veranstaltungsname.
 * @param int    $ausser_id Diese id nicht als Dublette werten.
 * @return array|null
 */
function lsg_bl_win_dublette( $athlet, $datum, $event, $ausser_id = 0 ) {
	global $wpdb;

	$athlet = (int) $athlet;
	$ts     = lsg_bl_datum_zu_timestamp( $datum );

	if ( $athlet <= 0 || $ts <= 0 || '' === trim( (string) $event ) ) {
		return null;
	}

	/*
	 * ⚠ Der Tag, nicht die Sekunde – und „der Tag" heißt: der Tag, den die
	 * Oberfläche anzeigt.
	 *
	 * Im Bestand stehen Zeitstempel auf 00:00 Ortszeit, während das Plugin
	 * selbst auf 12:00 schreibt (6.5.1) – und `lsg_bl_format_date_iso()`
	 * rechnet beide auf einen Kalendertag herunter. Welchen, ist im Moment
	 * eine offene Frage (9.3, Zeitzonen-Befund vom 2026-09-05); hier wird sie
	 * nicht beantwortet, sondern derselben Funktion überlassen, die auch die
	 * Liste und das Formular benutzen. So sagen Dublettenprüfung und Anzeige
	 * in jedem Fall dasselbe – auch wenn sich die Funktion ändert.
	 *
	 * Die Abfrage holt deshalb grob (±36 Stunden), und entschieden wird in
	 * PHP.
	 */
	$t = lsg_bl_table( 'lsg_win' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$kandidaten = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, `date`, town, event, distance, time, athletes_id
			   FROM {$t}
			  WHERE athletes_id = %d
			    AND `date` >= %d AND `date` <= %d
			    AND LOWER(TRIM(event)) = %s
			    AND id <> %d
			  ORDER BY id ASC",
			$athlet,
			$ts - 36 * HOUR_IN_SECONDS,
			$ts + 36 * HOUR_IN_SECONDS,
			lsg_bl_kleinschreiben( trim( (string) $event ) ),
			(int) $ausser_id
		),
		ARRAY_A
	);

	$tag = trim( (string) $datum );
	foreach ( (array) $kandidaten as $k ) {
		if ( lsg_bl_format_date_iso( (int) $k['date'] ) === $tag ) {
			return $k;
		}
	}

	return null;
}

/**
 * Einen Gesamtsieg anlegen.
 *
 * @param array $w Geprüfte Werte.
 * @return array{ok:bool,id:int,fehler:string}
 */
function lsg_bl_win_anlegen( array $w ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_win' );

	$werte = array(
		'tstamp'      => time(),
		'date'        => lsg_bl_datum_zu_timestamp( $w['datum'] ),
		'town'        => (string) $w['ort'],
		'event'       => (string) $w['event'],
		'distance'    => (string) $w['distanz'],
		'athletes_id' => (int) $w['athlet'],
		'time'        => (string) $w['zeit'],
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->insert( $t, $werte, array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' ) );

	return array(
		'ok'     => (bool) $ok,
		'id'     => $ok ? (int) $wpdb->insert_id : 0,
		'fehler' => $ok ? '' : (string) $wpdb->last_error,
	);
}

/**
 * Einen Gesamtsieg ändern.
 *
 * @param int   $id lsg_win.id.
 * @param array $w  Geprüfte Werte.
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_win_aendern( $id, array $w ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_win' );

	$werte = array(
		'tstamp'      => time(),
		'date'        => lsg_bl_datum_zu_timestamp( $w['datum'] ),
		'town'        => (string) $w['ort'],
		'event'       => (string) $w['event'],
		'distance'    => (string) $w['distanz'],
		'athletes_id' => (int) $w['athlet'],
		'time'        => (string) $w['zeit'],
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->update(
		$t,
		$werte,
		array( 'id' => (int) $id ),
		array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' ),
		array( '%d' )
	);

	return array(
		'ok'     => ( false !== $ok ),
		'fehler' => ( false === $ok ) ? (string) $wpdb->last_error : '',
	);
}

/**
 * Einen Gesamtsieg löschen.
 *
 * ⚠ Keine Referenzprüfung wie bei den Sportlern (11.3): an einer
 * `lsg_win`-Zeile hängt nichts, sie ist selbst das Blatt (12.4).
 *
 * @param int $id lsg_win.id.
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_win_loeschen( $id ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_win' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->delete( $t, array( 'id' => (int) $id ), array( '%d' ) );

	return array(
		'ok'     => ( false !== $ok && $ok > 0 ),
		'fehler' => ( false === $ok ) ? (string) $wpdb->last_error : '',
	);
}
