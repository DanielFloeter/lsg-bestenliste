<?php
/**
 * Datenzugriff für P3 und P4: Athleten, Zuordnungsregeln, Bestandszeilen.
 *
 * ⚠ Diese Datei liest $wpdb. Die Entscheidungen selbst – welcher Athlet zu
 * einer Zeile gehört, welchen Status sie bekommt – stehen in
 * class-lsg-pipeline.php und sind ohne WordPress prüfbar. Hier wird nur
 * geholt und geschrieben.
 *
 * ⚠ Der Import legt NIEMALS einen Athleten an. `lsg_athlete` wird an anderer
 * Stelle gepflegt, die Zuordnungsregeln im Untermenü „Zuordnungen". Ein
 * Tippfehler in einer Ergebnisliste kann so keinen Doppel-Athleten erzeugen,
 * und ein Import bleibt das, was er ist: das Übernehmen von Zeiten für
 * bekannte Personen (Plan 6.5.3).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Athleten mehrerer Jahrgänge auf einmal holen.
 *
 * Eine Abfrage statt einer je Zeile: bei elf LSG-Zeilen sind das elf
 * Abfragen weniger, und der Fall „zwei Läufer desselben Jahrgangs" braucht
 * ohnehin alle Kandidaten dieses Jahrgangs.
 *
 * @param int[] $jahrgaenge Jahrgänge.
 * @return array<int,array> Zeilen aus lsg_athlete.
 */
function lsg_bl_athleten_nach_jahrgang( array $jahrgaenge ) {
	global $wpdb;

	$jahrgaenge = array_values( array_unique( array_filter( array_map( 'intval', $jahrgaenge ) ) ) );
	if ( ! $jahrgaenge ) {
		return array();
	}

	$t   = lsg_bl_table( 'lsg_athlete' );
	$in  = implode( ',', array_fill( 0, count( $jahrgaenge ), '%d' ) );
	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id, name, firstname, born, cat, active FROM {$t} WHERE born IN ({$in})",
		$jahrgaenge
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return $rows ? $rows : array();
}

/**
 * Einen Athleten holen.
 *
 * @param int $id lsg_athlete.id.
 * @return array|null
 */
function lsg_bl_athlet( $id ) {
	global $wpdb;
	$t = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, name, firstname, born, cat, active FROM {$t} WHERE id = %d", (int) $id ),
		ARRAY_A
	);
	return $row ? $row : null;
}

/**
 * Aktive Zuordnungsregeln mehrerer Jahrgänge (Mapping 2 von 2, Plan 6.5.3).
 *
 * @param int[] $jahrgaenge Jahrgänge.
 * @return array<int,array> Zeilen aus lsg_athlete_map.
 */
function lsg_bl_map_regeln( array $jahrgaenge ) {
	global $wpdb;

	$jahrgaenge = array_values( array_unique( array_filter( array_map( 'intval', $jahrgaenge ) ) ) );
	if ( ! $jahrgaenge ) {
		return array();
	}

	$t = lsg_bl_table( 'lsg_athlete_map' );
	if ( ! lsg_bl_tabelle_da( $t ) ) {
		return array();
	}

	$in  = implode( ',', array_fill( 0, count( $jahrgaenge ), '%d' ) );
	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id, athletes_id, born, vorname, nachname, modus, aktiv, notiz
		   FROM {$t} WHERE aktiv = 1 AND born IN ({$in}) ORDER BY id ASC",
		$jahrgaenge
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return $rows ? $rows : array();
}

/**
 * Gibt es diese Tabelle?
 *
 * Die drei neuen Tabellen entstehen über die Schema-Version auf `admin_init`.
 * Läuft etwas davor – ein Cron, ein REST-Aufruf –, ist die Tabelle vielleicht
 * noch nicht da, und ein „Table doesn't exist" wäre die unfreundlichste
 * denkbare Fehlermeldung.
 *
 * @param string $tabelle Voller Tabellenname.
 * @return bool
 */
function lsg_bl_tabelle_da( $tabelle ) {
	global $wpdb;

	static $bekannt = array();
	if ( isset( $bekannt[ $tabelle ] ) ) {
		return $bekannt[ $tabelle ];
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$da = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabelle ) );

	$bekannt[ $tabelle ] = ( $da === $tabelle );
	return $bekannt[ $tabelle ];
}

/**
 * Die Altersklassen-Codes, die `lsg_ak` kennt.
 *
 * ⚠ `lsg_ak` ist eine Anzeigeliste, keine Prüfinstanz (Plan 6.5.3). Der
 * berechnete Code wird IMMER geschrieben; fehlt er hier, ist das ein Hinweis
 * auf eine Lücke in den Stammdaten – bis sie geschlossen ist, lässt sich im
 * Frontend nicht danach filtern.
 *
 * @return string[] Codes in Kleinschreibung.
 */
function lsg_bl_ak_codes() {
	global $wpdb;

	static $codes = null;
	if ( null !== $codes ) {
		return $codes;
	}

	$t = lsg_bl_table( 'lsg_ak' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_col( "SELECT DISTINCT ak FROM {$t}" );

	$codes = array();
	foreach ( (array) $rows as $r ) {
		$codes[] = strtolower( trim( (string) $r ) );
	}
	return $codes;
}

/**
 * Bestandszeilen eines Athleten auf einer Distanz in einem Kalenderjahr.
 *
 * Die Jahresgrenzen kommen aus lsg_bl_jahr_grenzen(), also aus
 * wp_timezone() – nicht aus `YEAR(FROM_UNIXTIME())` und nicht aus
 * `mktime()`. Beides wäre zeitzonenabhängig und schöbe einen Neujahrslauf
 * ins Vorjahr (Plan 6.5.4).
 *
 * @param int    $athletes_id lsg_athlete.id.
 * @param string $distanz     Distanzcode.
 * @param int    $jahr        Kalenderjahr aus dem Veranstaltungsdatum.
 * @return array<int,array> id, time, town, date.
 */
function lsg_bl_best_zeilen( $athletes_id, $distanz, $jahr ) {
	global $wpdb;

	$athletes_id = (int) $athletes_id;
	if ( $athletes_id <= 0 || '' === (string) $distanz || (int) $jahr <= 0 ) {
		return array();
	}

	list( $von, $bis ) = lsg_bl_jahr_grenzen( (int) $jahr );

	$t = lsg_bl_table( 'lsg_best' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, time, town, `date`, ak
			   FROM {$t}
			  WHERE athletes_id = %d AND distance = %s AND `date` >= %d AND `date` < %d
			  ORDER BY id ASC",
			$athletes_id,
			(string) $distanz,
			$von,
			$bis
		),
		ARRAY_A
	);

	return $rows ? $rows : array();
}

/**
 * Athleten-Anzeigename, wie ihn die Oberfläche zeigt.
 *
 * @param array|null $athlet Zeile aus lsg_athlete.
 * @return string
 */
function lsg_bl_athlet_label( $athlet ) {
	if ( ! $athlet ) {
		return '';
	}
	$label = trim( $athlet['name'] . ', ' . $athlet['firstname'], ', ' );
	if ( ! empty( $athlet['born'] ) ) {
		$label .= ' (' . (int) $athlet['born'] . ')';
	}
	return $label;
}

/* -------------------------------------------------------------------------
 * Sportler-Pflege (Plan 11) – Lesen, Schreiben, Referenzen zählen
 * ---------------------------------------------------------------------- */

/**
 * Die Filter der Liste auf gültige Werte bringen (11.3).
 *
 * @param array $roh Rohe GET-Werte.
 * @return array{status:string,geschlecht:string,s:string,orderby:string,order:string}
 */
function lsg_bl_athlet_filter( array $roh ) {
	$status = isset( $roh['status'] ) ? (string) $roh['status'] : 'aktiv';
	if ( ! in_array( $status, array( 'aktiv', 'ehemalig', 'alle' ), true ) ) {
		$status = 'aktiv';
	}

	$geschlecht = isset( $roh['geschlecht'] ) ? strtolower( (string) $roh['geschlecht'] ) : '';
	if ( ! in_array( $geschlecht, array( 'm', 'f' ), true ) ) {
		$geschlecht = '';
	}

	$orderby = isset( $roh['orderby'] ) ? (string) $roh['orderby'] : 'name';
	if ( ! in_array( $orderby, array( 'name', 'born', 'ergebnisse' ), true ) ) {
		$orderby = 'name';
	}

	$order = isset( $roh['order'] ) && 'desc' === strtolower( (string) $roh['order'] ) ? 'desc' : 'asc';

	return array(
		'status'     => $status,
		'geschlecht' => $geschlecht,
		's'          => isset( $roh['s'] ) ? trim( (string) $roh['s'] ) : '',
		'orderby'    => $orderby,
		'order'      => $order,
	);
}

/**
 * Wie oft ein Athlet in den drei Tabellen vorkommt, für alle Athleten auf
 * einmal.
 *
 * ⚠ Drei `GROUP BY`-Abfragen statt einer Unterabfrage je Zeile. Der
 * Unterschied ist nicht Geschmack: `lsg_best` hat keinen Index auf
 * `athletes_id` (das Schema kennt nur den Primärschlüssel), eine Unterabfrage
 * je Athlet liefe also 427-mal über 5 900 Zeilen. So ist es ein Durchlauf je
 * Tabelle.
 *
 * @return array{best:array<int,int>,win:array<int,int>,map:array<int,int>}
 */
function lsg_bl_athlet_referenzen_alle() {
	global $wpdb;

	$out = array(
		'best' => array(),
		'win'  => array(),
		'map'  => array(),
	);

	$quellen = array(
		'best' => lsg_bl_table( 'lsg_best' ),
		'win'  => lsg_bl_table( 'lsg_win' ),
		'map'  => lsg_bl_table( 'lsg_athlete_map' ),
	);

	foreach ( $quellen as $schluessel => $tabelle ) {
		if ( ! lsg_bl_tabelle_da( $tabelle ) ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT athletes_id, COUNT(*) AS n FROM {$tabelle} GROUP BY athletes_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $r ) {
			$out[ $schluessel ][ (int) $r['athletes_id'] ] = (int) $r['n'];
		}
	}

	return $out;
}

/**
 * Woran ein einzelner Athlet hängt (11.3).
 *
 * @param int $id lsg_athlete.id.
 * @return array{best:int,win:int,map:int,gesamt:int}
 */
function lsg_bl_athlet_referenzen( $id ) {
	global $wpdb;

	$id  = (int) $id;
	$out = array(
		'best' => 0,
		'win'  => 0,
		'map'  => 0,
	);

	if ( $id <= 0 ) {
		$out['gesamt'] = 0;
		return $out;
	}

	$quellen = array(
		'best' => lsg_bl_table( 'lsg_best' ),
		'win'  => lsg_bl_table( 'lsg_win' ),
		'map'  => lsg_bl_table( 'lsg_athlete_map' ),
	);

	foreach ( $quellen as $schluessel => $tabelle ) {
		if ( ! lsg_bl_tabelle_da( $tabelle ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out[ $schluessel ] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tabelle} WHERE athletes_id = %d", $id )
		);
	}

	$out['gesamt'] = $out['best'] + $out['win'] + $out['map'];
	return $out;
}

/**
 * Die Liste der Sportler (11.3).
 *
 * ⚠ Gefiltert und sortiert wird in PHP, nicht in SQL. Der Grund ist der
 * Bestand: 427 Zeilen passen in einen Rutsch in den Speicher, die Zählspalten
 * kommen ohnehin aus drei eigenen Abfragen (siehe oben), und nach einer
 * Zählspalte lässt sich in SQL nur mit genau der Unterabfrage sortieren, die
 * dort vermieden wird. Bei einer Mitgliederzahl in anderer Größenordnung wäre
 * das die falsche Entscheidung – bei dieser ist es die einfachere.
 *
 * @param array $filter Ergebnis von lsg_bl_athlet_filter().
 * @param int   $seite  1-basiert.
 * @param int   $pro    Zeilen je Seite.
 * @return array{zeilen:array,gesamt:int}
 */
function lsg_bl_athlet_liste( array $filter, $seite = 1, $pro = 100 ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$alle = $wpdb->get_results(
		"SELECT id, name, firstname, born, cat, active FROM {$t}",
		ARRAY_A
	);
	$alle = $alle ? $alle : array();

	$ref = lsg_bl_athlet_referenzen_alle();

	$suche = lsg_bl_kleinschreiben( (string) $filter['s'] );

	$zeilen = array();
	foreach ( $alle as $a ) {
		$aktiv = ( '1' === (string) $a['active'] );

		if ( 'aktiv' === $filter['status'] && ! $aktiv ) {
			continue;
		}
		if ( 'ehemalig' === $filter['status'] && $aktiv ) {
			continue;
		}
		if ( '' !== $filter['geschlecht'] && strtolower( (string) $a['cat'] ) !== $filter['geschlecht'] ) {
			continue;
		}
		if ( '' !== $suche ) {
			$heu = lsg_bl_kleinschreiben( $a['name'] . ' ' . $a['firstname'] );
			if ( false === strpos( $heu, $suche ) ) {
				continue;
			}
		}

		$id           = (int) $a['id'];
		$a['id']      = $id;
		$a['born']    = (int) $a['born'];
		$a['n_best']  = isset( $ref['best'][ $id ] ) ? $ref['best'][ $id ] : 0;
		$a['n_win']   = isset( $ref['win'][ $id ] ) ? $ref['win'][ $id ] : 0;
		$a['n_map']   = isset( $ref['map'][ $id ] ) ? $ref['map'][ $id ] : 0;
		$a['n_summe'] = $a['n_best'] + $a['n_win'] + $a['n_map'];

		$zeilen[] = $a;
	}

	$richtung = ( 'desc' === $filter['order'] ) ? -1 : 1;
	$feld     = $filter['orderby'];

	usort(
		$zeilen,
		function ( $x, $y ) use ( $feld, $richtung ) {
			if ( 'born' === $feld ) {
				$v = $x['born'] - $y['born'];
			} elseif ( 'ergebnisse' === $feld ) {
				$v = $x['n_best'] - $y['n_best'];
			} else {
				$v = strcoll( lsg_bl_kleinschreiben( $x['name'] ), lsg_bl_kleinschreiben( $y['name'] ) );
			}

			if ( 0 === $v ) {
				// Zweiter Schlüssel immer der Name, damit die Reihenfolge bei
				// gleichen Werten nicht bei jedem Aufruf anders aussieht.
				$v = strcoll(
					lsg_bl_kleinschreiben( $x['name'] . ' ' . $x['firstname'] ),
					lsg_bl_kleinschreiben( $y['name'] . ' ' . $y['firstname'] )
				);
				return $v;
			}

			return $v * $richtung;
		}
	);

	$gesamt = count( $zeilen );
	$seite  = max( 1, (int) $seite );
	$pro    = max( 1, (int) $pro );

	return array(
		'zeilen' => array_slice( $zeilen, ( $seite - 1 ) * $pro, $pro ),
		'gesamt' => $gesamt,
	);
}

/**
 * Gibt es diesen Sportler schon? (Dublettensperre, 11.2)
 *
 * ⚠ Verglichen wird über `LOWER(TRIM(...))`, also mit derselben
 * Normalisierung wie lsg_bl_athlet_schluessel(). Wer die eine ändert, ändert
 * die andere.
 *
 * @param string $name      Nachname.
 * @param string $firstname Vorname.
 * @param int    $born      Jahrgang.
 * @param int    $ausser_id Diese id nicht als Dublette werten (beim Bearbeiten).
 * @return array|null Die vorhandene Zeile, oder null.
 */
function lsg_bl_athlet_dublette( $name, $firstname, $born, $ausser_id = 0 ) {
	global $wpdb;

	$born = (int) $born;
	if ( $born <= 0 || '' === trim( (string) $name ) ) {
		return null;
	}

	$t = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, name, firstname, born, cat, active
			   FROM {$t}
			  WHERE LOWER(TRIM(name)) = %s AND LOWER(TRIM(firstname)) = %s AND born = %d AND id <> %d
			  ORDER BY id ASC
			  LIMIT 1",
			lsg_bl_kleinschreiben( trim( (string) $name ) ),
			lsg_bl_kleinschreiben( trim( (string) $firstname ) ),
			$born,
			(int) $ausser_id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Einen Sportler anlegen.
 *
 * @param array $w Geprüfte Werte: name, firstname, born, cat, active.
 * @return array{ok:bool,id:int,fehler:string}
 */
function lsg_bl_athlet_anlegen( array $w ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete' );

	$werte = array(
		'tstamp'    => time(),
		'name'      => (string) $w['name'],
		'firstname' => (string) $w['firstname'],
		'born'      => (int) $w['born'],
		'cat'       => (string) $w['cat'],
		'active'    => (string) $w['active'],
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->insert( $t, $werte, array( '%d', '%s', '%s', '%d', '%s', '%s' ) );

	return array(
		'ok'     => (bool) $ok,
		'id'     => $ok ? (int) $wpdb->insert_id : 0,
		'fehler' => $ok ? '' : (string) $wpdb->last_error,
	);
}

/**
 * Einen Sportler ändern.
 *
 * @param int   $id lsg_athlete.id.
 * @param array $w  Geprüfte Werte.
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_athlet_aendern( $id, array $w ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete' );

	$werte = array(
		'tstamp'    => time(),
		'name'      => (string) $w['name'],
		'firstname' => (string) $w['firstname'],
		'born'      => (int) $w['born'],
		'cat'       => (string) $w['cat'],
		'active'    => (string) $w['active'],
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->update(
		$t,
		$werte,
		array( 'id' => (int) $id ),
		array( '%d', '%s', '%s', '%d', '%s', '%s' ),
		array( '%d' )
	);

	return array(
		'ok'     => ( false !== $ok ),
		'fehler' => ( false === $ok ) ? (string) $wpdb->last_error : '',
	);
}

/**
 * Einen Sportler löschen.
 *
 * ⚠ Diese Funktion prüft NICHT, ob etwas daranhängt. Das tut der Handler
 * (11.3), und zwar unmittelbar vor dem Löschen – ein Ergebnis, das in der
 * Zwischenzeit angelegt wurde, soll die Zeile noch retten.
 *
 * @param int $id lsg_athlete.id.
 * @return array{ok:bool,fehler:string}
 */
function lsg_bl_athlet_loeschen( $id ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$ok = $wpdb->delete( $t, array( 'id' => (int) $id ), array( '%d' ) );

	return array(
		'ok'     => ( false !== $ok && $ok > 0 ),
		'fehler' => ( false === $ok ) ? (string) $wpdb->last_error : '',
	);
}

/**
 * Alle Bestandszeilen eines Athleten, mit dem Veranstaltungsjahr.
 *
 * Das Jahr kommt aus lsg_bl_year_from_timestamp(), also aus der
 * WordPress-Zeitzone – dieselbe Rechnung wie überall sonst (6.5.4).
 *
 * @param int $athletes_id lsg_athlete.id.
 * @return array<int,array{id:int,jahr:int,distance:string,time:string,ak:string,town:string,date:int}>
 */
function lsg_bl_athlet_best_alle( $athletes_id ) {
	global $wpdb;

	$athletes_id = (int) $athletes_id;
	if ( $athletes_id <= 0 ) {
		return array();
	}

	$t = lsg_bl_table( 'lsg_best' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, distance, time, town, `date`, ak
			   FROM {$t} WHERE athletes_id = %d ORDER BY `date` DESC, id ASC",
			$athletes_id
		),
		ARRAY_A
	);

	$out = array();
	foreach ( (array) $rows as $r ) {
		$r['id']   = (int) $r['id'];
		$r['date'] = (int) $r['date'];
		$r['jahr'] = lsg_bl_year_from_timestamp( $r['date'] );
		$out[]     = $r;
	}

	return $out;
}

/**
 * Die nachgerechneten Altersklassen schreiben (11.2).
 *
 * ⚠ Geschrieben wird ausschließlich `ak`. Zeit, Ort, Datum und `tstamp` der
 * Ergebniszeile bleiben, wie sie sind – geändert hat sich der Athlet, nicht
 * sein Ergebnis.
 *
 * @param array $aenderungen Ergebnis von lsg_bl_athlet_ak_abweichungen().
 * @return int Anzahl der geschriebenen Zeilen.
 */
function lsg_bl_athlet_ak_schreiben( array $aenderungen ) {
	global $wpdb;

	$t = lsg_bl_table( 'lsg_best' );
	$n = 0;

	foreach ( $aenderungen as $a ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->update(
			$t,
			array( 'ak' => (string) $a['ak_neu'] ),
			array( 'id' => (int) $a['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false !== $ok ) {
			++$n;
		}
	}

	return $n;
}
