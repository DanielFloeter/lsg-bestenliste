<?php
/**
 * Datenmodell der Import-Erweiterung: die drei neuen Tabellen und ihre
 * Versionierung.
 *
 * ⚠ Diese Tabellen entstehen NICHT durch die Aktivierung.
 * lsg_bl_activate() hängt an register_activation_hook() und läuft auf einer
 * Installation, auf der das Plugin bereits aktiv ist, kein zweites Mal. Die
 * drei neuen Tabellen kämen dort also nie an – und der Fehler zeigte sich
 * erst beim ersten Import als „Table doesn't exist". Deshalb die
 * Schema-Version und der Upgrade-Lauf auf admin_init (Plan 6.8).
 *
 * ⚠ lsg_bl_install_schema() enthält NUR die drei neuen Tabellen. Die vier
 * Bestandstabellen bleiben in lsg_bl_activate(): ihre Definitionen schreiben
 * int(10) UNSIGNED, year(4), varchar(1). Liefe das ab jetzt bei jedem
 * Versionssprung durch dbDelta(), bekämen vier Tabellen mit 6 000 Zeilen
 * Vereinsgeschichte bei jedem Durchlauf überflüssige ALTER TABLEs.
 *
 * ⚠ dbDelta()-Regeln, die hier eingehalten sind und eingehalten bleiben
 * müssen:
 *   - Keine Anzeigebreiten: `int UNSIGNED`, nicht `int(10) UNSIGNED`, und
 *     `year`, nicht `year(4)`. MySQL 8.0.19+ normalisiert sie weg, MariaDB
 *     10.11 nicht – dbDelta() vergleicht Strings und hielte die Tabelle
 *     sonst bei JEDEM Aufruf für geändert. Einzige Ausnahme: tinyint(1),
 *     das MySQL als eigenen Typ führt.
 *   - Zwei Leerzeichen nach PRIMARY KEY, ein KEY je Zeile, Feldtypen klein.
 *   - Tabellennamen über lsg_bl_table(), Kollation über
 *     $wpdb->get_charset_collate().
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema-Version. Hochzählen, sobald sich eine der drei Tabellen ändert.
 */
if ( ! defined( 'LSG_BL_DB_VERSION' ) ) {
	define( 'LSG_BL_DB_VERSION', 2 );
}

/**
 * Die drei neuen Tabellen anlegen bzw. nachziehen.
 *
 * dbDelta() ist idempotent – ein überflüssiger Durchlauf kostet nichts.
 *
 * @return void
 */
function lsg_bl_install_schema() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$t_map = lsg_bl_table( 'lsg_athlete_map' );
	$t_run = lsg_bl_table( 'lsg_import_run' );
	$t_log = lsg_bl_table( 'lsg_import_log' );

	// Mapping 2 von 2: Zuordnungsregeln. Leeres Feld = beliebig,
	// modus 'egal' = feldunabhängiger Vergleich (Plan 6.5.3).
	// born bekommt bewusst kein DEFAULT – der Jahrgang ist Pflicht, ein
	// Vorgabewert lädt nur dazu ein, ihn wegzulassen.
	$sql = "CREATE TABLE {$t_map} (
  id int UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp int UNSIGNED NOT NULL DEFAULT 0,
  athletes_id int UNSIGNED NOT NULL,
  born year NOT NULL,
  vorname varchar(30) NOT NULL DEFAULT '',
  nachname varchar(30) NOT NULL DEFAULT '',
  modus varchar(8) NOT NULL DEFAULT 'feld',
  aktiv tinyint(1) NOT NULL DEFAULT 1,
  notiz varchar(255) NOT NULL DEFAULT '',
  user_id bigint UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY lookup (born, aktiv),
  KEY athlete (athletes_id)
) {$charset_collate};";

	// Der Vorgang: ein Datensatz je Klick auf „Übernehmen" – und je
	// Formularaktion auf der Seite „Bestenliste" (adapter = 'manuell').
	$sql .= "CREATE TABLE {$t_run} (
  id int UNSIGNED NOT NULL AUTO_INCREMENT,
  tstamp int UNSIGNED NOT NULL DEFAULT 0,
  user_id bigint UNSIGNED NOT NULL DEFAULT 0,
  adapter varchar(32) NOT NULL DEFAULT '',
  source_url varchar(255) NOT NULL DEFAULT '',
  event_id varchar(32) NOT NULL DEFAULT '',
  event_name varchar(120) NOT NULL DEFAULT '',
  event_date int UNSIGNED DEFAULT NULL,
  datum_quelle varchar(16) NOT NULL DEFAULT '',
  jahr smallint UNSIGNED NOT NULL DEFAULT 0,
  contest_id varchar(32) NOT NULL DEFAULT '',
  contest_name varchar(120) NOT NULL DEFAULT '',
  list_id varchar(64) NOT NULL DEFAULT '',
  list_name varchar(120) NOT NULL DEFAULT '',
  distance varchar(15) NOT NULL DEFAULT '',
  town varchar(30) NOT NULL DEFAULT '',
  zeit_typ varchar(8) NOT NULL DEFAULT '',
  cnt_gelesen int UNSIGNED NOT NULL DEFAULT 0,
  cnt_lsg int UNSIGNED NOT NULL DEFAULT 0,
  cnt_zugeordnet int UNSIGNED NOT NULL DEFAULT 0,
  cnt_angelegt int UNSIGNED NOT NULL DEFAULT 0,
  cnt_aktualisiert int UNSIGNED NOT NULL DEFAULT 0,
  cnt_uebersprungen int UNSIGNED NOT NULL DEFAULT 0,
  cnt_fehler int UNSIGNED NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT '',
  note text NULL,
  PRIMARY KEY  (id),
  KEY zeit (tstamp),
  KEY event (event_id, contest_id)
) {$charset_collate};";

	// Die Zeilen: ein Datensatz je Ergebnis, auch für nicht geschriebene.
	// Die Rohfelder wirken redundant, sind es aber nicht: das Log soll auch
	// dann noch verständlich sein, wenn die Quelle offline ist, der Athlet
	// umbenannt oder eine Zuordnungsregel korrigiert wurde.
	// roh_jahrgang ist NULL, nicht 0000: „die Quelle nannte keinen
	// Jahrgang" muss von „Jahrgang 0" unterscheidbar bleiben.
	// roh_platz, gesamtsieg und die Aktion win_insert werden jetzt schon
	// angelegt, obwohl 6.5.5 noch nicht schreibt – eine leere Spalte kostet
	// nichts, eine ALTER TABLE auf Produktivdaten kostet Nerven.
	$sql .= "CREATE TABLE {$t_log} (
  id int UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id int UNSIGNED NOT NULL,
  tstamp int UNSIGNED NOT NULL DEFAULT 0,
  athletes_id int UNSIGNED NOT NULL DEFAULT 0,
  best_id int UNSIGNED NOT NULL DEFAULT 0,
  match_type varchar(16) NOT NULL DEFAULT '',
  aktion varchar(20) NOT NULL DEFAULT '',
  distance varchar(15) NOT NULL DEFAULT '',
  ak varchar(10) NOT NULL DEFAULT '',
  time_neu varchar(15) NOT NULL DEFAULT '',
  time_alt varchar(15) NOT NULL DEFAULT '',
  roh_teilnehmer varchar(120) NOT NULL DEFAULT '',
  roh_name varchar(30) NOT NULL DEFAULT '',
  roh_vorname varchar(30) NOT NULL DEFAULT '',
  roh_verein varchar(60) NOT NULL DEFAULT '',
  roh_jahrgang year NULL DEFAULT NULL,
  roh_zeit varchar(20) NOT NULL DEFAULT '',
  roh_startnr varchar(16) NOT NULL DEFAULT '',
  roh_platz varchar(8) NOT NULL DEFAULT '',
  gesamtsieg tinyint(1) NOT NULL DEFAULT 0,
  meldung varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY run (run_id),
  KEY athlet (athletes_id, distance),
  KEY aktion (aktion),
  KEY suche (roh_name, roh_vorname)
) {$charset_collate};";

	dbDelta( $sql );
}

/**
 * Wertebereich der Spalte lsg_import_log.aktion – bewusst auch die
 * Nicht-Aktionen (Plan 6.8).
 *
 * @return array<string,string> Code => Klartext.
 */
function lsg_bl_log_aktionen() {
	return array(
		'insert'          => __( 'angelegt', 'lsg-bestenliste' ),
		'update'          => __( 'aktualisiert', 'lsg-bestenliste' ),
		'skip_langsamer'  => __( 'übersprungen – Bestand war besser', 'lsg-bestenliste' ),
		'skip_gleich'     => __( 'übersprungen – Zeit war schon da', 'lsg-bestenliste' ),
		'skip_abgewaehlt' => __( 'übersprungen – abgewählt', 'lsg-bestenliste' ),
		'skip_offen'      => __( 'übersprungen – kein Athlet zugeordnet', 'lsg-bestenliste' ),
		'konflikt'        => __( 'Konflikt – Bestand hatte sich geändert', 'lsg-bestenliste' ),
		'fehler'          => __( 'Fehler', 'lsg-bestenliste' ),
		'delete'          => __( 'gelöscht', 'lsg-bestenliste' ),
		'win_insert'      => __( 'Gesamtsieg eingetragen', 'lsg-bestenliste' ),
	);
}

/**
 * Wertebereich der Spalte lsg_import_log.match_type.
 *
 * @return array<string,string>
 */
function lsg_bl_match_types() {
	return array(
		'exakt'        => __( 'Name und Jahrgang exakt', 'lsg-bestenliste' ),
		'regel'        => __( 'über eine Zuordnungsregel', 'lsg-bestenliste' ),
		'normalisiert' => __( 'Name normalisiert', 'lsg-bestenliste' ),
		'manuell'      => __( 'von Hand gewählt', 'lsg-bestenliste' ),
		'mehrdeutig'   => __( 'mehrdeutig – nicht zugeordnet', 'lsg-bestenliste' ),
		'offen'        => __( 'offen – nicht zugeordnet', 'lsg-bestenliste' ),
	);
}

/**
 * Die drei Startdatensätze für lsg_athlete_map – aber nur, wenn die Tabelle
 * leer ist und der jeweilige Athlet auch wirklich der gemeinte ist.
 *
 * ⚠ Jede athletes_id wird vor dem Schreiben gegen Name und Jahrgang in
 * lsg_athlete gegengelesen. Ein früherer Entwurf nannte für Gudrun
 * Schlippe-Schrieber die 337 – das ist Österle, Hans-Jörg, 1967, und damit
 * eine völlig andere Person. Eine Regel, die auf den Falschen zeigt,
 * schreibt Zeiten still einem Fremden gut, und in der Bestenliste sieht man
 * dem Eintrag nichts an (Plan 6.5.3).
 *
 * @return array<int,array> Meldungen je Regel: array{ok:bool,text:string}
 */
function lsg_bl_seed_athlete_map() {
	global $wpdb;

	$t_map     = lsg_bl_table( 'lsg_athlete_map' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$vorhanden = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_map}" );
	if ( $vorhanden > 0 ) {
		return array();
	}

	$regeln = array(
		array(
			'athletes_id' => 171,
			'born'        => 1961,
			'vorname'     => 'wolfram',
			'nachname'    => 'pfeiffer',
			'modus'       => 'feld',
			'notiz'       => 'Schreibweise des Nachnamens weicht in den Listen ab',
			'erwartet'    => 'pfeiffer',
		),
		array(
			'athletes_id' => 183,
			'born'        => 1943,
			'vorname'     => 'harry',
			'nachname'    => '',
			'modus'       => 'feld',
			'notiz'       => 'Nachname variiert; Vorname + Jahrgang sind im Verein eindeutig',
			'erwartet'    => 'van wees',
		),
		array(
			'athletes_id' => 377,
			'born'        => 1955,
			'vorname'     => 'gudrun',
			'nachname'    => '',
			'modus'       => 'egal',
			'notiz'       => 'Vor- und Nachname in der Quelle vertauscht',
			'erwartet'    => 'schlippe schrieber',
		),
	);

	$meldungen = array();

	foreach ( $regeln as $r ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$athlet = $wpdb->get_row(
			$wpdb->prepare( "SELECT name, firstname, born FROM {$t_athlete} WHERE id = %d", $r['athletes_id'] ),
			ARRAY_A
		);

		if ( ! $athlet ) {
			$meldungen[] = array(
				'ok'   => false,
				'text' => sprintf(
					/* translators: %d: Athleten-ID */
					__( 'Zuordnungsregel übersprungen: In lsg_athlete gibt es keinen Datensatz #%d.', 'lsg-bestenliste' ),
					$r['athletes_id']
				),
			);
			continue;
		}

		$name_ok = ( false !== strpos( lsg_bl_text_normalisieren( $athlet['name'] ), $r['erwartet'] ) );
		$jahr_ok = ( (int) $athlet['born'] === (int) $r['born'] );

		if ( ! $name_ok || ! $jahr_ok ) {
			$meldungen[] = array(
				'ok'   => false,
				'text' => sprintf(
					/* translators: 1: Athleten-ID, 2: Name laut Datenbank, 3: Jahrgang, 4: erwarteter Name */
					__( 'Zuordnungsregel übersprungen: #%1$d ist „%2$s" (%3$d) – erwartet wurde „%4$s". Bitte in „Zuordnungen" von Hand anlegen.', 'lsg-bestenliste' ),
					$r['athletes_id'],
					$athlet['name'] . ', ' . $athlet['firstname'],
					(int) $athlet['born'],
					$r['erwartet']
				),
			);
			continue;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$t_map,
			array(
				'tstamp'      => time(),
				'athletes_id' => $r['athletes_id'],
				'born'        => $r['born'],
				'vorname'     => $r['vorname'],
				'nachname'    => $r['nachname'],
				'modus'       => $r['modus'],
				'aktiv'       => 1,
				'notiz'       => $r['notiz'],
				'user_id'     => 0,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		$meldungen[] = array(
			'ok'   => true,
			'text' => sprintf(
				/* translators: 1: Name, 2: Jahrgang */
				__( 'Zuordnungsregel angelegt für %1$s (%2$d).', 'lsg-bestenliste' ),
				$athlet['name'] . ', ' . $athlet['firstname'],
				(int) $athlet['born']
			),
		);
	}

	return $meldungen;
}

/**
 * Upgrade-Lauf. Hängt an admin_init, weil der Activation-Hook auf einer
 * bereits aktiven Installation nicht noch einmal läuft.
 *
 * @return void
 */
function lsg_bl_maybe_upgrade_schema() {
	if ( (int) get_option( 'lsg_bl_db_version' ) === LSG_BL_DB_VERSION ) {
		return;
	}

	lsg_bl_install_schema();

	$meldungen = lsg_bl_seed_athlete_map();
	if ( $meldungen ) {
		// Nachvollziehbar machen, was beim Anlegen der Regeln passiert ist –
		// besonders die übersprungenen.
		update_option( 'lsg_bl_seed_meldungen', $meldungen, false );
	}

	update_option( 'lsg_bl_db_version', LSG_BL_DB_VERSION );
}
add_action( 'admin_init', 'lsg_bl_maybe_upgrade_schema' );
