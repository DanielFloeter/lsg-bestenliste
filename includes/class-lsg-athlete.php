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
