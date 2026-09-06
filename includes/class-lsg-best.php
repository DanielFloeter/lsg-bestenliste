<?php
/**
 * Datenzugriff der Seiten „Bestenliste" und „Zuordnungen" (Plan 7, 6.5.3).
 *
 * ⚠ Hier steht nur Holen und Schreiben. Was entschieden wird – ob eine
 * Leistung gültig ist, ob sie den Bestand ersetzt, ob zwei Regeln
 * kollidieren – steht in class-lsg-leistung.php und ist ohne Datenbank
 * prüfbar.
 *
 * ⚠ Und hier steht kein Athleten-Anlegen. Weder der Import (6.5.3) noch das
 * Formular (7.2) erzeugen einen Athleten; `lsg_athlete` wird im Untermenü
 * „Sportler" gepflegt (Phase 4).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Athleten für das Dropdown
 * ---------------------------------------------------------------------- */

/**
 * Alle Athleten, nach Aktiv und Ehemalige getrennt (Plan 7.2).
 *
 * Rund 430 Datensätze – zu viele für eine ungeordnete Liste, zu wenige für
 * eine Suche mit Autocomplete. Also ein gewöhnliches Select mit zwei
 * `<optgroup>`.
 *
 * ⚠ Ehemalige werden mitangeboten, nur getrennt. Ein Ergebnis aus 2019 kann
 * zu jemandem gehören, der inzwischen ausgetreten ist; sie zu verstecken
 * würde genau die Nachträge verhindern, für die es diese Seite gibt.
 *
 * @return array{aktiv:array<int,array>,ehemalig:array<int,array>}
 */
function lsg_bl_athleten_gruppiert() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		"SELECT id, name, firstname, born, cat, active
		   FROM {$t}
		  ORDER BY name ASC, firstname ASC",
		ARRAY_A
	);

	$out = array(
		'aktiv'    => array(),
		'ehemalig' => array(),
	);

	foreach ( (array) $rows as $r ) {
		// `active` ist varchar(1) – '1' oder '0'. Nicht (bool) casten: der
		// String '0' ist in PHP falsy, aber '0' === '1' ist der Vergleich,
		// der auch bei einem leeren Feld richtig entscheidet.
		$key                = ( '1' === (string) $r['active'] ) ? 'aktiv' : 'ehemalig';
		$out[ $key ][]      = $r;
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * Die Liste (Plan 7.4)
 * ---------------------------------------------------------------------- */

/**
 * Der SQL-Ausdruck, der Distanzen in die Reihenfolge von
 * lsg_bl_distance_map() bringt.
 *
 * ⚠ Nicht alphabetisch sortieren: `100km` stünde dann vor `10km`, und `HM`
 * zwischen `50km` und `5km`. Die Reihenfolge steht in genau einer Liste
 * (lsg_bl_distance_map()), und dieser Ausdruck wird daraus gebaut – damit
 * beide nicht auseinanderlaufen können.
 *
 * ⚠ Bewusst `CASE` und nicht MySQLs `FIELD()`: `CASE` ist Standard-SQL und
 * läuft auch dort, wo geprüft wird.
 *
 * @return string
 */
function lsg_bl_sql_distanz_rang() {
	global $wpdb;

	$teile = array();
	$rang  = 0;
	foreach ( array_keys( lsg_bl_distance_map() ) as $code ) {
		++$rang;
		$teile[] = $wpdb->prepare( 'WHEN %s THEN %d', $code, $rang );
	}

	// 999 für alles, was nicht in der Liste steht – solche Zeilen gibt es im
	// Bestand nicht mehr, aber sie sollen hinten landen und nicht die
	// Sortierung durcheinanderbringen.
	return 'CASE b.distance ' . implode( ' ', $teile ) . ' ELSE 999 END';
}

/**
 * Der SQL-Ausdruck, der Zeitlauf-Zeilen nach Strecke sortiert.
 *
 * ⚠ Das ist der Grund, warum die Sortierung überhaupt zwei Schlüssel
 * braucht. In `lsg_best.time` steht bei den meisten Distanzen eine Zeit
 * (`01:36:44`) und bei `6h`/`12h`/`24h` eine Strecke (`96,723 km`). Als
 * String sortiert die Zeit richtig – sie ist nullgefüllt –, die Strecke
 * nicht: `112,737` käme vor `96,723`, weil `1` kleiner als `9` ist. Und
 * „besser" heißt bei Zeitläufen größer.
 *
 * Deshalb: dieser Ausdruck liefert für Zeitläufe die NEGATIVE Kilometerzahl
 * (aufsteigend sortiert = weiteste zuerst) und für alle anderen 0. Weil der
 * Distanz-Rang davor schon nach Distanz gruppiert, vergleicht er nie
 * Kilometer gegen Sekunden – innerhalb einer Zeitlauf-Gruppe ordnet er,
 * innerhalb einer Zeit-Gruppe ist er konstant 0 und der String-Vergleich auf
 * `time` entscheidet.
 *
 * @return string
 */
function lsg_bl_sql_strecke_desc() {
	global $wpdb;

	$zeitlaeufe = array();
	foreach ( lsg_bl_distance_map() as $code => $info ) {
		if ( 'distance' === $info['type'] ) {
			$zeitlaeufe[] = $code;
		}
	}
	if ( ! $zeitlaeufe ) {
		return '0';
	}

	$in = implode( ',', array_fill( 0, count( $zeitlaeufe ), '%s' ) );

	return $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"CASE WHEN b.distance IN ({$in})
		      THEN -CAST( REPLACE( REPLACE( REPLACE( b.time, ' km', '' ), ' ', '' ), ',', '.' ) AS DECIMAL(12,3) )
		      ELSE 0 END",
		$zeitlaeufe
	);
}

/**
 * Die Filter der Liste, aus der Query gelesen und auf gültige Werte begrenzt.
 *
 * @param array $roh Rohwerte.
 * @return array{jahr:int,distanz:string,geschlecht:string,s:string,athlet:int}
 */
function lsg_bl_best_filter( array $roh ) {
	$distanz = isset( $roh['distanz'] ) ? (string) $roh['distanz'] : '';
	if ( '' !== $distanz && ! array_key_exists( $distanz, lsg_bl_distance_map() ) ) {
		$distanz = '';
	}

	$geschlecht = isset( $roh['geschlecht'] ) ? strtolower( (string) $roh['geschlecht'] ) : '';
	if ( ! in_array( $geschlecht, array( 'm', 'f' ), true ) ) {
		$geschlecht = '';
	}

	/*
	 * ⚠ Die Sortierung gehört in die Whitelist, nicht direkt in die Abfrage.
	 * `orderby` kommt aus der Query, und ein Spaltenname aus der Query, der
	 * ungeprüft in ein ORDER BY wandert, ist eine Einladung.
	 *
	 * ⚠ Sortiert wird nur nach Sportler, Datum und Ort – und diese drei
	 * sortieren wirklich. Bis 2026-09-05 zeichnete LSG_BL_Best_Table sie als
	 * sortierbar aus, ohne dass hier je ein `orderby` angekommen wäre: die
	 * Spaltenköpfe waren Links, die nichts taten (Abschnitt 8, M7).
	 */
	$orderby = isset( $roh['orderby'] ) ? (string) $roh['orderby'] : '';
	if ( ! in_array( $orderby, array( 'athlet', 'datum', 'ort' ), true ) ) {
		$orderby = '';
	}

	$order = ( isset( $roh['order'] ) && 'desc' === strtolower( (string) $roh['order'] ) ) ? 'desc' : 'asc';

	return array(
		'jahr'       => isset( $roh['jahr'] ) ? (int) $roh['jahr'] : 0,
		'distanz'    => $distanz,
		'geschlecht' => $geschlecht,
		's'          => isset( $roh['s'] ) ? trim( (string) $roh['s'] ) : '',
		'athlet'     => isset( $roh['athlet'] ) ? (int) $roh['athlet'] : 0,
		'orderby'    => $orderby,
		'order'      => $order,
	);
}

/**
 * Bestandszeilen für die Liste, gefiltert, sortiert und seitenweise.
 *
 * @param array $filter Ergebnis von lsg_bl_best_filter().
 * @param int   $seite  1-basiert.
 * @param int   $pro    Zeilen je Seite.
 * @return array{zeilen:array<int,array>,gesamt:int}
 */
function lsg_bl_best_liste( array $filter, $seite = 1, $pro = 50 ) {
	global $wpdb;

	$t_best    = lsg_bl_table( 'lsg_best' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	$where  = array( '1=1' );
	$params = array();

	if ( $filter['jahr'] > 0 ) {
		// Jahresgrenzen aus wp_timezone(), nicht YEAR(FROM_UNIXTIME()) –
		// derselbe Grund wie in 6.5.4.
		list( $von, $bis ) = lsg_bl_jahr_grenzen( $filter['jahr'] );
		$where[]           = '(b.`date` >= %d AND b.`date` < %d)';
		$params[]          = $von;
		$params[]          = $bis;
	}

	if ( '' !== $filter['distanz'] ) {
		$where[]  = 'b.distance = %s';
		$params[] = $filter['distanz'];
	}

	if ( '' !== $filter['geschlecht'] ) {
		$where[]  = 'a.cat = %s';
		$params[] = $filter['geschlecht'];
	}

	if ( $filter['athlet'] > 0 ) {
		$where[]  = 'b.athletes_id = %d';
		$params[] = $filter['athlet'];
	}

	if ( '' !== $filter['s'] ) {
		$like     = '%' . $wpdb->esc_like( $filter['s'] ) . '%';
		$where[]  = '(a.name LIKE %s OR a.firstname LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}

	$w = implode( ' AND ', $where );

	// Zählen und Holen laufen über dasselbe WHERE – zwei Abfragen, aber nur
	// eine Bedingung. Getrennte Bedingungen wären der Weg zu einer
	// Paginierung, die eine andere Menge zählt als sie zeigt.
	$sql_count = "SELECT COUNT(*) FROM {$t_best} b LEFT JOIN {$t_athlete} a ON a.id = b.athletes_id WHERE {$w}";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$gesamt = (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql_count, $params ) : $sql_count );

	$seite  = max( 1, (int) $seite );
	$pro    = max( 1, (int) $pro );
	$offset = ( $seite - 1 ) * $pro;

	$rang    = lsg_bl_sql_distanz_rang();
	$strecke = lsg_bl_sql_strecke_desc();

	/*
	 * Die Vorgabe: neuestes Jahr zuerst, darin die Distanzen in der
	 * Reihenfolge der Map (nicht alphabetisch – sonst stünde `100km` vor
	 * `10km`), darin die beste Leistung oben.
	 *
	 * Sortiert der Mensch selbst, tritt sein Schlüssel davor; die vertraute
	 * Ordnung bleibt als zweiter Schlüssel stehen, damit gleiche Werte nicht
	 * zufällig angeordnet sind. Die Werte kommen aus der Whitelist in
	 * lsg_bl_best_filter(), nicht aus der Query.
	 */
	$vorgabe   = "b.`date` DESC, {$rang} ASC, {$strecke} ASC, b.time ASC, b.id ASC";
	$richtung  = ( 'desc' === $filter['order'] ) ? 'DESC' : 'ASC';
	$schluessel = array(
		'athlet' => 'a.name %1$s, a.firstname %1$s',
		'datum'  => 'b.`date` %1$s',
		'ort'    => 'b.town %1$s',
	);

	$order_by = $vorgabe;
	if ( '' !== $filter['orderby'] && isset( $schluessel[ $filter['orderby'] ] ) ) {
		$order_by = sprintf( $schluessel[ $filter['orderby'] ], $richtung ) . ', ' . $vorgabe;
	}

	$sql = "SELECT b.id, b.distance, b.time, b.town, b.`date`, b.ak, b.athletes_id, b.tstamp,
	               a.name, a.firstname, a.born, a.cat, a.active
	          FROM {$t_best} b
	          LEFT JOIN {$t_athlete} a ON a.id = b.athletes_id
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
 * Eine Bestandszeile samt Athlet.
 *
 * @param int $id lsg_best.id.
 * @return array|null
 */
function lsg_bl_best_zeile( $id ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return null;
	}

	$t_best    = lsg_bl_table( 'lsg_best' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT b.id, b.distance, b.time, b.town, b.`date`, b.ak, b.athletes_id,
			        a.name, a.firstname, a.born, a.cat, a.active
			   FROM {$t_best} b
			   LEFT JOIN {$t_athlete} a ON a.id = b.athletes_id
			  WHERE b.id = %d",
			$id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Die Jahre, in denen überhaupt Bestandszeilen liegen – für den Jahresfilter.
 *
 * @return int[] absteigend.
 */
function lsg_bl_best_jahre() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_best' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$stamps = $wpdb->get_col( "SELECT DISTINCT `date` FROM {$t} WHERE `date` IS NOT NULL AND `date` > 0" );

	// Umgerechnet wird in PHP über wp_timezone(), nicht in SQL – ein
	// YEAR(FROM_UNIXTIME()) rechnet in der Zeitzone des Datenbankservers und
	// schöbe einen Neujahrslauf ins Vorjahr (Plan 6.5.4).
	return lsg_bl_jahre_aus_timestamps( $stamps );
}

/* -------------------------------------------------------------------------
 * Schreiben (Plan 7.3, 7.4, 7.5)
 * ---------------------------------------------------------------------- */

/**
 * Eine Bestandszeile anlegen.
 *
 * @param array $w Werte: athletes_id, distanz, leistung, ort, datum_ts, ak.
 * @return array{ok:bool,id:int,fehler:string}
 */
function lsg_bl_best_anlegen( array $w ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->insert(
		lsg_bl_table( 'lsg_best' ),
		array(
			'tstamp'      => time(),
			'distance'    => (string) $w['distanz'],
			'time'        => (string) $w['leistung'],
			'town'        => (string) $w['ort'],
			'date'        => (int) $w['datum_ts'],
			'athletes_id' => (int) $w['athletes_id'],
			'ak'          => (string) $w['ak'],
		),
		array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
	);

	if ( false === $ok ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'fehler' => $wpdb->last_error ? $wpdb->last_error : __( 'Die Zeile ließ sich nicht anlegen.', 'lsg-bestenliste' ),
		);
	}

	return array(
		'ok'     => true,
		'id'     => (int) $wpdb->insert_id,
		'fehler' => '',
	);
}

/**
 * Eine Bestandszeile ändern.
 *
 * ⚠ `ak` wird immer mitgeschrieben, nie aus der alten Zeile übernommen
 * (Plan 7.4): ändert sich Athlet oder Datum, ändert sich die Altersklasse.
 *
 * @param int   $id Zeile.
 * @param array $w  Werte wie bei lsg_bl_best_anlegen().
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_best_aendern( $id, array $w ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->update(
		lsg_bl_table( 'lsg_best' ),
		array(
			'tstamp'      => time(),
			'distance'    => (string) $w['distanz'],
			'time'        => (string) $w['leistung'],
			'town'        => (string) $w['ort'],
			'date'        => (int) $w['datum_ts'],
			'athletes_id' => (int) $w['athletes_id'],
			'ak'          => (string) $w['ak'],
		),
		array( 'id' => (int) $id ),
		array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' ),
		array( '%d' )
	);

	if ( false === $ok ) {
		return array(
			'ok'     => false,
			'fehler' => $wpdb->last_error ? $wpdb->last_error : __( 'Die Zeile ließ sich nicht ändern.', 'lsg-bestenliste' ),
		);
	}

	return array(
		'ok'     => true,
		'fehler' => '',
	);
}

/**
 * Eine Bestandszeile löschen.
 *
 * ⚠ Der Aufrufer protokolliert VORHER den vollständigen Datensatz (Plan
 * 7.4). Löschen ist die einzige Aktion ohne Wiederherstellung in der
 * Oberfläche; wer versehentlich löscht, muss die Zeile aus dem Log heraus
 * neu tippen können.
 *
 * @param int $id Zeile.
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_best_loeschen( $id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->delete( lsg_bl_table( 'lsg_best' ), array( 'id' => (int) $id ), array( '%d' ) );

	if ( false === $ok ) {
		return array(
			'ok'     => false,
			'fehler' => $wpdb->last_error ? $wpdb->last_error : __( 'Die Zeile ließ sich nicht löschen.', 'lsg-bestenliste' ),
		);
	}

	return array(
		'ok'     => true,
		'fehler' => '',
	);
}

/* -------------------------------------------------------------------------
 * Zuordnungsregeln (Plan 6.5.3)
 * ---------------------------------------------------------------------- */

/**
 * Alle Regeln, mit dem Athleten, auf den sie zeigen.
 *
 * ⚠ Auch die abgeschalteten. Eine Regel abzuschalten ist die vorgesehene
 * Antwort auf eine Kollision (6.5.3), und was unsichtbar ist, schaltet
 * niemand wieder ein.
 *
 * @param string $s Suchtext über Regel- und Athletennamen.
 * @return array<int,array>
 */
function lsg_bl_map_alle( $s = '' ) {
	global $wpdb;

	$t_map     = lsg_bl_table( 'lsg_athlete_map' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	if ( ! lsg_bl_tabelle_da( $t_map ) ) {
		return array();
	}

	$sql = "SELECT m.id, m.athletes_id, m.born, m.vorname, m.nachname, m.modus, m.aktiv, m.notiz,
	               a.name, a.firstname, a.born AS athlet_born, a.cat, a.active
	          FROM {$t_map} m
	          LEFT JOIN {$t_athlete} a ON a.id = m.athletes_id";

	$s = trim( (string) $s );
	if ( '' !== $s ) {
		$like = '%' . $wpdb->esc_like( $s ) . '%';
		$sql .= $wpdb->prepare(
			' WHERE m.vorname LIKE %s OR m.nachname LIKE %s OR a.name LIKE %s OR a.firstname LIKE %s',
			$like,
			$like,
			$like,
			$like
		);
	}

	$sql .= ' ORDER BY a.name ASC, a.firstname ASC, m.id ASC';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return $rows ? $rows : array();
}

/**
 * Eine Regel.
 *
 * @param int $id lsg_athlete_map.id.
 * @return array|null
 */
function lsg_bl_map_zeile( $id ) {
	global $wpdb;

	$id = (int) $id;
	$t  = lsg_bl_table( 'lsg_athlete_map' );
	if ( $id <= 0 || ! lsg_bl_tabelle_da( $t ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, athletes_id, born, vorname, nachname, modus, aktiv, notiz FROM {$t} WHERE id = %d",
			$id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Eine Regel anlegen oder ändern.
 *
 * @param array $w Geprüfte Werte von lsg_bl_map_pruefen().
 * @return array{ok:bool,id:int,fehler:string}
 */
function lsg_bl_map_speichern( array $w ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete_map' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'fehler' => __( 'Die Tabelle der Zuordnungsregeln fehlt noch. Bitte die Plugin-Seite einmal neu laden.', 'lsg-bestenliste' ),
		);
	}

	$daten = array(
		'athletes_id' => (int) $w['athletes_id'],
		// Ein leerer Jahrgang heißt „beliebig" und wird als 0 gespeichert,
		// nicht als NULL: lsg_bl_map_regeln() fragt über born ab – als Menge
		// von Jahrgängen oder als Jahrgangsband der Altersklasse –, und ein
		// NULL trifft in beidem nie.
		'born'        => (int) $w['born'],
		'vorname'     => (string) $w['vorname'],
		'nachname'    => (string) $w['nachname'],
		'modus'       => (string) $w['modus'],
		'aktiv'       => empty( $w['aktiv'] ) ? 0 : 1,
		'notiz'       => (string) $w['notiz'],
	);
	$formate = array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' );

	$id = isset( $w['id'] ) ? (int) $w['id'] : 0;

	if ( $id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->update( $t, $daten, array( 'id' => $id ), $formate, array( '%d' ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $t, $daten, $formate );
		$id = ( false === $ok ) ? 0 : (int) $wpdb->insert_id;
	}

	if ( false === $ok ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'fehler' => $wpdb->last_error ? $wpdb->last_error : __( 'Die Regel ließ sich nicht speichern.', 'lsg-bestenliste' ),
		);
	}

	return array(
		'ok'     => true,
		'id'     => $id,
		'fehler' => '',
	);
}

/**
 * Eine Regel löschen.
 *
 * @param int $id lsg_athlete_map.id.
 * @return bool
 */
function lsg_bl_map_loeschen( $id ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete_map' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	return false !== $wpdb->delete( $t, array( 'id' => (int) $id ), array( '%d' ) );
}

/**
 * Wie oft hat eine Regel in `lsg_import_log` tatsächlich gegriffen?
 *
 * ⚠ Das ist die Zahl, die eine Regel beurteilbar macht. Eine Regel, die in
 * zwei Jahren nie gegriffen hat, ist entweder falsch geschrieben oder
 * überflüssig – beides sieht man nur an dieser Spalte. Gezählt wird über
 * `match_type IN ('regel','regel_ak')` und den Athleten, weil das Log die
 * Regel-ID nicht
 * mitführt: die Zahl ist also eine Obergrenze je Athlet, nicht je Regel.
 * Genau so steht sie auch in der Oberfläche.
 *
 * @return array<int,int> athletes_id => Anzahl.
 */
function lsg_bl_map_treffer() {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_import_log' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		"SELECT athletes_id, COUNT(*) AS n FROM {$t}
		  WHERE match_type IN ('regel','regel_ak') GROUP BY athletes_id",
		ARRAY_A
	);

	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r['athletes_id'] ] = (int) $r['n'];
	}
	return $out;
}
