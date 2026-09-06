<?php
/**
 * Der Import-Vorgang: Discovery, Caches, Parse-Orchestrierung.
 *
 * ⚠ Diese Datei braucht WordPress (Transients, Optionen, Benutzer). Sie ist
 * die EINE Implementierung des Ablaufs: die Formular-Handler auf der
 * Admin-Seite und später die REST-Routen (6.10) rufen dieselben Funktionen –
 * die REST-Schicht ist ein zweiter Eingang, keine zweite Logik.
 *
 * Gecacht wird nur INNERHALB eines Vorgangs, damit das Durchklicken der
 * Auswahl keine Requests erzeugt (Plan 5.2):
 *
 *   Discovery (Wettbewerbe, Listen, Datum)   15 Minuten
 *   Parse-Ergebnis                            1 Stunde
 *
 * Beides sind Transients mit kurzer Lebensdauer, keine Datenhaltung. Der
 * race-result-`key` ist vom Cache ausgenommen – er rotiert (4.2).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lebensdauer des Discovery-Caches. */
define( 'LSG_BL_CACHE_DISCOVERY', 15 * MINUTE_IN_SECONDS );

/** Lebensdauer des Parse-Transients. */
define( 'LSG_BL_CACHE_PARSE', HOUR_IN_SECONDS );

/* -------------------------------------------------------------------------
 * Adapter
 * ---------------------------------------------------------------------- */

/**
 * Eine Adapter-Instanz mit dem echten HTTP-Getter.
 *
 * @param string $adapter_cls Klassenname.
 * @return LSG_BL_Ergebnis_Quelle
 */
function lsg_bl_adapter_instanz( $adapter_cls ) {
	return new $adapter_cls( 'lsg_bl_http_get' );
}

/* -------------------------------------------------------------------------
 * Vereins-Aliasse (Plan 6.5.2)
 * ---------------------------------------------------------------------- */

/**
 * Zusätzlich als LSG geltende Vereinsschreibweisen, normalisiert.
 *
 * @return string[]
 */
function lsg_bl_verein_aliasse() {
	$roh = get_option( 'lsg_bl_verein_alias', array() );
	if ( ! is_array( $roh ) ) {
		return array();
	}
	$out = array();
	foreach ( $roh as $a ) {
		$n = lsg_bl_verein_normalisieren( $a );
		if ( '' !== $n ) {
			$out[ $n ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * Eine Schreibweise als Vereins-Alias aufnehmen.
 *
 * @param string $verein Rohe Schreibweise aus der Quelle.
 * @return bool True, wenn sie neu war.
 */
function lsg_bl_verein_alias_hinzufuegen( $verein ) {
	$n = lsg_bl_verein_normalisieren( $verein );
	if ( '' === $n ) {
		return false;
	}

	$liste = lsg_bl_verein_aliasse();
	if ( in_array( $n, $liste, true ) ) {
		return false;
	}

	$liste[] = $n;
	update_option( 'lsg_bl_verein_alias', $liste, false );
	return true;
}

/**
 * Einen Alias wieder entfernen.
 *
 * @param string $verein Rohe oder normalisierte Schreibweise.
 * @return bool
 */
function lsg_bl_verein_alias_entfernen( $verein ) {
	$n     = lsg_bl_verein_normalisieren( $verein );
	$liste = lsg_bl_verein_aliasse();
	$neu   = array_values( array_diff( $liste, array( $n ) ) );

	if ( count( $neu ) === count( $liste ) ) {
		return false;
	}

	update_option( 'lsg_bl_verein_alias', $neu, false );
	return true;
}

/* -------------------------------------------------------------------------
 * Discovery – Schritt 1 und 2, gecacht
 * ---------------------------------------------------------------------- */

/**
 * Cache-Schlüssel der Discovery.
 *
 * @param string $adapter_cls Adapter.
 * @param string $event_id    Event-ID.
 * @return string
 */
function lsg_bl_discovery_key( $adapter_cls, $event_id ) {
	return 'lsg_bl_disc_' . md5( $adapter_cls . '|' . $event_id );
}

/**
 * Alles, was die Oberfläche über ein Event wissen muss – in einem Rutsch und
 * für 15 Minuten gecacht.
 *
 * ⚠ Was NICHT im Cache landet: der race-result-`key` und der Datenserver.
 * Beide rotieren bzw. wechseln und werden bei jedem Datenabruf frisch aus
 * `config` geholt (Plan 4.2, 6.4). Gecacht werden nur die abgeleiteten
 * Wettbewerbs- und Listennamen.
 *
 * @param string $adapter_cls Adapter-Klasse.
 * @param string $url         Eingegebene URL.
 * @param bool   $neu_laden   Cache verwerfen und frisch holen.
 * @return array{
 *   adapter:string, adapter_cls:string, adapter_label:string,
 *   event_id:string, event_name:string, url:string,
 *   contest_id:string, list_id:string,
 *   contests:array<int,array{id:string,name:string}>,
 *   lists:array<int,array>,
 *   datum:array{datum:string,quelle:string,hinweis:string}
 * }
 * @throws LSG_BL_Quelle_Exception Wenn die Quelle nicht auswertbar ist.
 */
function lsg_bl_discovery( $adapter_cls, $url, $neu_laden = false ) {
	$adapter = lsg_bl_adapter_instanz( $adapter_cls );

	// Für den Cache-Schlüssel wird die Event-ID gebraucht – die steht in der
	// URL und kostet keinen Request.
	$event_id = '';
	if ( method_exists( $adapter_cls, 'event_id_aus_url' ) ) {
		$event_id = (string) call_user_func( array( $adapter_cls, 'event_id_aus_url' ), $url );
	}

	$key   = lsg_bl_discovery_key( $adapter_cls, $event_id );
	$cache = $neu_laden ? false : get_transient( $key );

	if ( is_array( $cache ) && isset( $cache['contests'] ) ) {
		// Die Vorauswahl kommt aus der aktuellen URL, nicht aus dem Cache:
		// dieselbe Veranstaltung kann mit unterschiedlichem Fragment
		// eingegeben werden.
		$cache['url'] = (string) $url;
		$vorauswahl   = lsg_bl_discovery_vorauswahl( $adapter_cls, $url );
		$cache['contest_id'] = $vorauswahl['contest'];
		$cache['list_id']    = $vorauswahl['list'];
		return $cache;
	}

	if ( ! lsg_bl_rate_limit_ok() ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Zu viele Abrufe in kurzer Zeit. Bitte ein paar Minuten warten – die Quelle soll nicht belastet werden.', 'lsg-bestenliste' )
		);
	}

	$ref = $adapter->eventLesen( $url );

	$contests = array();
	foreach ( $adapter->wettbewerbe( $ref ) as $w ) {
		$contests[] = array(
			'id'   => (string) $w->id,
			'name' => (string) $w->name,
		);
	}

	// Die Listen aller Wettbewerbe auf einmal, damit ein Wechsel des
	// Wettbewerbs keinen weiteren Request auslöst.
	$lists = array();
	foreach ( $contests as $c ) {
		foreach ( $adapter->listen( $ref, $c['id'] ) as $l ) {
			$lists[] = array(
				'contest'       => $c['id'],
				'id'            => (string) $l->id,
				'name'          => (string) $l->name,
				'ref'           => (string) $l->ref,
				'live'          => (bool) $l->live,
				'gesamtwertung' => (bool) $l->gesamtwertung,
			);
		}
	}

	$daten = array(
		'adapter'       => (string) call_user_func( array( $adapter_cls, 'key' ) ),
		'adapter_cls'   => $adapter_cls,
		'adapter_label' => (string) call_user_func( array( $adapter_cls, 'label' ) ),
		'event_id'      => (string) $ref->event_id,
		'event_name'    => (string) $ref->event_name,
		'url'           => (string) $url,
		'contest_id'    => (string) $ref->contest_id,
		'list_id'       => (string) $ref->list_id,
		'contests'      => $contests,
		'lists'         => $lists,
		'datum'         => $adapter->datum( $ref ),
	);

	set_transient( $key, $daten, LSG_BL_CACHE_DISCOVERY );

	return $daten;
}

/**
 * Vorauswahl aus der URL lesen, ohne Request.
 *
 * @param string $adapter_cls Adapter.
 * @param string $url         URL.
 * @return array{contest:string,list:string}
 */
function lsg_bl_discovery_vorauswahl( $adapter_cls, $url ) {
	// Bei runtix steht die Vorauswahl im Pfad (/sts/10050/3152/21/total),
	// bei race result im Fragment (#2_B45FAB). Der Pfad-Weg hat Vorrang,
	// weil er der allgemeinere ist.
	if ( method_exists( $adapter_cls, 'vorauswahl_aus_url' ) ) {
		$v = (array) call_user_func( array( $adapter_cls, 'vorauswahl_aus_url' ), $url );
		return array(
			'contest' => isset( $v['contest'] ) ? (string) $v['contest'] : '',
			'list'    => isset( $v['list'] ) ? (string) $v['list'] : '',
		);
	}
	if ( method_exists( $adapter_cls, 'fragment_lesen' ) ) {
		$f = (array) call_user_func( array( $adapter_cls, 'fragment_lesen' ), $url );
		return array(
			'contest' => isset( $f['contest'] ) ? (string) $f['contest'] : '',
			'list'    => isset( $f['list'] ) ? (string) $f['list'] : '',
		);
	}
	return array(
		'contest' => '',
		'list'    => '',
	);
}

/**
 * Discovery-Cache eines Events verwerfen („Neu laden"-Link, Plan 6.4).
 *
 * @param string $adapter_cls Adapter.
 * @param string $event_id    Event-ID.
 * @return void
 */
function lsg_bl_discovery_verwerfen( $adapter_cls, $event_id ) {
	delete_transient( lsg_bl_discovery_key( $adapter_cls, $event_id ) );
}

/**
 * Die runtix-Veranstaltungsübersicht eines Jahres, gecacht (Plan 4.2).
 *
 * Warum überhaupt gecacht: die Übersicht ist die maßgebliche Datumsquelle
 * für JEDE runtix-Veranstaltung dieses Jahres. Wer an einem Abend fünf
 * Läufe importiert, holte sie sonst fünfmal – dieselben 157 Zeilen.
 *
 * ⚠ Hier wird nichts Rotierendes gecacht: die Übersicht enthält IDs, Daten
 * und Namen, alles langlebig. Die 15 Minuten sind trotzdem knapp gewählt,
 * damit ein am selben Tag nachgetragener Lauf nicht stundenlang fehlt.
 *
 * @param string        $jahr Jahreszahl.
 * @param callable|null $get  HTTP-Getter; null = lsg_bl_http_get.
 * @return array<string,array{datum:string,name:string}> Schlüssel = Event-ID.
 */
function lsg_bl_runtix_jahr_cache( $jahr, $get = null ) {
	$jahr = (string) (int) $jahr;
	if ( '0' === $jahr ) {
		return array();
	}

	$key   = 'lsg_bl_rtx_jahr_' . $jahr;
	$cache = get_transient( $key );
	if ( is_array( $cache ) ) {
		return $cache;
	}

	if ( ! lsg_bl_rate_limit_ok() ) {
		// Kein Fehler: das Datum ist eine Zugabe, kein Pflichtfeld. Die
		// Oberfläche verlangt es dann eben von Hand.
		return array();
	}

	$get = ( null !== $get ) ? $get : 'lsg_bl_http_get';

	try {
		$url   = LSG_BL_Runtix_Adapter::url_bauen( '10020', $jahr );
		$html  = call_user_func( $get, $url, 'LSG_BL_Runtix_Adapter' );
		$liste = LSG_BL_Runtix_Adapter::parse_jahr( $html );
	} catch ( LSG_BL_Quelle_Exception $e ) {
		return array();
	}

	// Auch ein leeres Ergebnis wird gecacht – sonst wird bei jedem
	// Seitenaufruf erneut vergeblich abgerufen.
	set_transient( $key, $liste, LSG_BL_CACHE_DISCOVERY );

	return $liste;
}

/*
 * Die Lesehelfer auf den Discovery-Daten (lsg_bl_contest_name(),
 * lsg_bl_contest_listen(), lsg_bl_contest_liste(), lsg_bl_import_vorbelegung())
 * stehen in class-lsg-pipeline.php: sie rechnen nur auf dem uebergebenen
 * Array und brauchen kein WordPress – also sind sie ohne WordPress pruefbar.
 */

/* -------------------------------------------------------------------------
 * Parsen – P1 und P2
 * ---------------------------------------------------------------------- */

/**
 * Den Parse-Vorgang ausführen: einmal abrufen, P1 normalisieren, P2 filtern.
 *
 * Geschrieben wird hier nichts. Das Zwischenergebnis landet im
 * Parse-Transient, und der Token dafür ist an die `user_id` gebunden
 * (Plan 6.10).
 *
 * @param array $args adapter_cls, url, contest_id, list_id, distanz, datum, ort.
 * @return array{token:string,daten:array}
 * @throws LSG_BL_Quelle_Exception Bei jedem Abruf- oder Parse-Fehler.
 */
function lsg_bl_parsen( array $args ) {
	$adapter_cls = isset( $args['adapter_cls'] ) ? $args['adapter_cls'] : '';
	if ( ! $adapter_cls || ! class_exists( $adapter_cls ) ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Kein Adapter für diese Adresse.', 'lsg-bestenliste' )
		);
	}

	$distanz = isset( $args['distanz'] ) ? (string) $args['distanz'] : '';
	$datum   = isset( $args['datum'] ) ? (string) $args['datum'] : '';

	// Ohne gültige Distanz UND vollständiges Datum wird nicht geparst. Das
	// ist nicht nur eine Sperre in der Oberfläche – der Handler prüft es
	// selbst, weil ein Formularwert nichts garantiert.
	$fehlt = lsg_bl_import_was_fehlt( $distanz, $datum );
	if ( '' !== $fehlt ) {
		throw new LSG_BL_Quelle_Exception( $fehlt );
	}
	if ( ! in_array( $distanz, lsg_bl_import_distanzen(), true ) ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Diese Distanz lässt sich nicht importieren. Zeitläufe werden über „Bestenliste" von Hand erfasst.', 'lsg-bestenliste' )
		);
	}
	if ( 0 === lsg_bl_datum_zu_timestamp( $datum ) ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Das Veranstaltungsdatum ist kein gültiges Datum.', 'lsg-bestenliste' )
		);
	}

	if ( ! lsg_bl_rate_limit_ok() ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Zu viele Abrufe in kurzer Zeit. Bitte ein paar Minuten warten – die Quelle soll nicht belastet werden.', 'lsg-bestenliste' )
		);
	}

	$disc    = lsg_bl_discovery( $adapter_cls, $args['url'] );
	$adapter = lsg_bl_adapter_instanz( $adapter_cls );

	// Den Event-Kontext aus der Discovery aufbauen, damit der Adapter die
	// Wettbewerbs- und Listennamen nicht erneut holen muss. Der `key` und
	// der Server sind hier absichtlich leer – laden() holt beide frisch.
	$ref               = new LSG_BL_Event_Ref( $disc['adapter'], $disc['event_id'], $disc['url'] );
	$ref->event_name   = $disc['event_name'];
	$ref->contest_id   = (string) $args['contest_id'];
	$ref->list_id      = isset( $args['list_id'] ) ? (string) $args['list_id'] : '';

	// P1: der Adapter liest und normalisiert.
	$zeilen = $adapter->laden( $ref, (string) $args['contest_id'], $ref->list_id ? $ref->list_id : null );

	$p1 = isset( $ref->meta['p1'] ) ? (array) $ref->meta['p1'] : array();

	// P2: auf LSG Karlsruhe filtern.
	$p2 = lsg_bl_p2_filtern( $zeilen, lsg_bl_verein_aliasse() );

	// P3 + P4: zuordnen und gegen den Bestand abgleichen.
	$jahr    = (int) substr( $datum, 0, 4 );
	$geprueft = lsg_bl_p3_p4( $p2['lsg'], $distanz, $jahr );

	$trichter              = lsg_bl_trichter_leer();
	$trichter['gelesen']   = isset( $p1['gelesen'] ) ? (int) $p1['gelesen'] : count( $zeilen );
	$trichter['verworfen'] = isset( $p1['verworfen'] ) ? (int) $p1['verworfen'] : 0;
	$trichter['lsg']       = count( $p2['lsg'] );

	foreach ( array( 'zugeordnet', 'offen', 'neu', 'schneller', 'langsamer', 'gleich' ) as $k ) {
		$trichter[ $k ] = 0;
	}
	foreach ( $geprueft as $z ) {
		// P3: zugeordnet oder nicht. `offen` und `mehrdeutig` landen beide in
		// derselben Stufe – für den Trichter ist die Unterscheidung eine
		// Begründung, keine Zahl.
		if ( $z['athletes_id'] > 0 ) {
			++$trichter['zugeordnet'];
		} else {
			++$trichter['offen'];
			continue;
		}
		// P4: nur die vier Status, die eine zugeordnete Zeile haben kann.
		if ( array_key_exists( $z['status'], $trichter ) ) {
			++$trichter[ $z['status'] ];
		}
	}

	$liste = lsg_bl_contest_liste( $disc, $args['contest_id'], $ref->list_id );

	$daten = array(
		'user_id'       => get_current_user_id(),
		'erzeugt'       => time(),
		'fingerprint'   => lsg_bl_import_fingerprint(
			array(
				'adapter'    => $disc['adapter'],
				'event_id'   => $disc['event_id'],
				'contest_id' => (string) $args['contest_id'],
				'list_id'    => $ref->list_id,
				'distanz'    => $distanz,
				'datum'      => $datum,
			)
		),
		'adapter'       => $disc['adapter'],
		'adapter_cls'   => $adapter_cls,
		'adapter_label' => $disc['adapter_label'],
		'url'           => $disc['url'],
		'event_id'      => $disc['event_id'],
		'event_name'    => $disc['event_name'],
		'contest_id'    => (string) $args['contest_id'],
		'contest_name'  => lsg_bl_contest_name( $disc, $args['contest_id'] ),
		'list_id'       => $ref->list_id,
		'list_name'     => $liste ? (string) $liste['name'] : '',
		'gesamtwertung' => $liste ? (bool) $liste['gesamtwertung'] : false,
		'distanz'       => $distanz,
		'datum'         => $datum,
		// Woher der Wert stammt, wird mitgeführt und in lsg_import_run
		// protokolliert – damit später nachvollziehbar ist, wie sicher er war
		// (Plan 6.5.1). Hat der Mensch das Feld angefasst, ist die Herkunft
		// „manuell", egal was die Quelle vorgeschlagen hatte.
		'datum_quelle'  => lsg_bl_datum_quelle_bestimmen( $disc, $datum ),
		'ort'           => isset( $args['ort'] ) ? (string) $args['ort'] : '',
		'zeit_typ'      => isset( $p1['zeit_typ'] ) ? (string) $p1['zeit_typ'] : '',
		'quelle_url'    => $adapter->quelleUrl( $ref, (string) $args['contest_id'], $ref->list_id ? $ref->list_id : null ),
		'trichter'      => $trichter,
		'warnungen'     => isset( $p1['warnungen'] ) ? (array) $p1['warnungen'] : array(),
		'jahr'          => $jahr,
		'abgelehnt'     => $p2['abgelehnt'],
		'nahe'          => $p2['nahe'],
		// ⚠ Nur die Zeilen, die P2 passiert haben. Die Nicht-LSG-Ergebnisse
		// werden nicht gehalten – auch nicht im Transient (Plan 6.8).
		'zeilen'        => $geprueft,
	);

	$token = lsg_bl_parse_token_neu();
	set_transient( 'lsg_bl_parse_' . $token, $daten, LSG_BL_CACHE_PARSE );

	return array(
		'token' => $token,
		'daten' => $daten,
	);
}

/* -------------------------------------------------------------------------
 * P3 und P4 – verkettet, mit Datenbank
 * ---------------------------------------------------------------------- */

/**
 * P3 und P4 über alle Zeilen laufen lassen.
 *
 * Die Entscheidungen selbst stehen in class-lsg-pipeline.php; hier werden nur
 * die Kandidaten geholt und die Stufen verkettet.
 *
 * @param LSG_BL_Ergebnis[] $zeilen  Zeilen nach P2.
 * @param string            $distanz Gewählter Distanzcode.
 * @param int               $jahr    Kalenderjahr aus dem Veranstaltungsdatum.
 * @return array<int,array> Zeilen als Array, um P3- und P4-Felder ergänzt.
 */
function lsg_bl_p3_p4( array $zeilen, $distanz, $jahr ) {
	// Alle gebrauchten Jahrgänge in einer Abfrage – nicht eine je Zeile.
	//
	// ⚠ Nennt eine Liste gar keinen Jahrgang – bei race result und runtix
	// inzwischen der Regelfall –, tritt das Jahrgangsband der Altersklasse an
	// seine Stelle. Ohne diese Bänder bliebe $jahrgaenge leer, es würde kein
	// einziger Kandidat geladen, und jede Zeile fiele auf `offen`.
	$jahrgaenge = array();
	$baender    = array();
	foreach ( $zeilen as $e ) {
		if ( $e->jahrgang > 0 ) {
			$jahrgaenge[] = (int) $e->jahrgang;
			continue;
		}
		$band = lsg_bl_jahrgangsband_aus_klasse( $e->quelle_klasse, $jahr );
		if ( $band ) {
			$baender[] = $band;
		}
	}

	$athleten = lsg_bl_athleten_nach_jahrgang( $jahrgaenge, $baender );
	$regeln   = lsg_bl_map_regeln( $jahrgaenge, $baender );
	$ak_codes = lsg_bl_ak_codes();

	$out = array();

	foreach ( $zeilen as $e ) {
		$z = $e->to_array();

		// Das Jahrgangsband der Altersklasse – nur, wenn die Quelle keinen
		// Jahrgang nennt. `jahrgang` selbst bleibt 0 und damit `roh_jahrgang`
		// im Protokoll NULL: die Quelle hat keinen Jahrgang genannt, und
		// dieser Unterschied darf nicht verlorengehen (Plan 6.5.1).
		$band                 = ( (int) $z['jahrgang'] > 0 )
			? array()
			: lsg_bl_jahrgangsband_aus_klasse( $z['quelle_klasse'], $jahr );
		$z['jahrgang_von']    = $band ? (int) $band[0] : 0;
		$z['jahrgang_bis']    = $band ? (int) $band[1] : 0;
		$z['jahrgang_aus_ak'] = (bool) $band;
		$z['jahrgang_band']   = lsg_bl_jahrgangsband_text( $band );

		/* ---- P3 ---- */
		$p3 = lsg_bl_p3_zuordnen( $z, $athleten, $regeln );

		$z['athletes_id']   = (int) $p3['athletes_id'];
		$z['match_type']    = $p3['match_type'];
		$z['match_meldung'] = $p3['meldung'];
		$z['match_regeln']  = $p3['regeln'];
		$z['athlet_label']  = '';
		$z['ak']            = '';
		$z['ak_fehlt']      = false;
		$z['ak_abweichung'] = '';
		$z['geschlecht_abweichung'] = false;
		$z['aehnliche']     = array();
		$z['time_alt']      = '';
		$z['best_id']       = 0;
		$z['doppelt']       = array();
		$z['zusatz']        = '';

		if ( 0 === $z['athletes_id'] ) {
			$z['status'] = ( 'mehrdeutig' === $p3['match_type'] ) ? 'mehrdeutig' : 'offen';
			// Reine Lesehilfe unter der Zeile – kein Auswahlfeld.
			$z['aehnliche'] = lsg_bl_p3_aehnliche( $z, lsg_bl_athleten_aehnlich_kandidaten( $z ) );
			$out[]          = $z;
			continue;
		}

		$athlet            = lsg_bl_athlet( $z['athletes_id'] );
		$z['athlet_label'] = lsg_bl_athlet_label( $athlet );

		// Die Altersklasse wird selbst gerechnet, nicht aus der Quelle
		// übernommen: die Portale benutzen eigene Klassenschemata, und der
		// Bestand muss in sich konsistent bleiben (Plan 6.5.3).
		$z['ak'] = lsg_bl_ak_berechnen(
			$athlet ? $athlet['born'] : 0,
			$jahr,
			$athlet ? $athlet['cat'] : 'm'
		);
		if ( '' !== $z['ak'] && ! in_array( strtolower( $z['ak'] ), $ak_codes, true ) ) {
			// ⚠ Kein Vorbehalt, kein Bestätigungsschritt: der Code wird
			// geschrieben. `lsg_ak` ist die Anzeigeliste des AK-Dropdowns im
			// Frontend, nicht die Instanz, die über die Richtigkeit eines
			// Ergebnisses entscheidet (Plan 6.5.3).
			$z['ak_fehlt'] = true;
		}

		// Nennt die Quelle Jahrgang UND Altersklasse, müssen beide zueinander
		// passen. Tun sie es nicht, stimmt eines von beidem nicht – ein
		// vertippter Jahrgang in der Meldeliste oder eine Fehlzuordnung.
		// Dieselbe Art Hinweis wie die Geschlechtsabweichung: markieren,
		// nicht ablehnen (Plan 6.5.1). Bei Zeilen ohne Jahrgang war das Band
		// das Zuordnungskriterium – da kann es per Konstruktion nicht abweichen.
		if ( $athlet && (int) $z['jahrgang'] > 0 ) {
			$ak_band = lsg_bl_jahrgangsband_aus_klasse( $z['quelle_klasse'], $jahr );
			if ( $ak_band
				&& ( (int) $athlet['born'] < (int) $ak_band[0] || (int) $athlet['born'] > (int) $ak_band[1] )
			) {
				$z['ak_abweichung'] = lsg_bl_jahrgangsband_text( $ak_band );
			}
		}

		// Weicht das Geschlecht der Quelle vom zugeordneten Athleten ab, ist
		// das ein starker Hinweis auf eine Fehlzuordnung – „die Quelle sagt W,
		// der zugeordnete Sportler ist m" trifft man selten zufällig. Die
		// Zeile wird deshalb nicht abgelehnt, aber markiert (Plan 6.5.1).
		if ( $athlet && '' !== $z['geschlecht'] ) {
			$cat = ( 'f' === strtolower( (string) $athlet['cat'] ) ) ? 'f' : 'm';
			if ( $cat !== $z['geschlecht'] ) {
				$z['geschlecht_abweichung'] = true;
			}
		}

		/* ---- P4 ---- */
		$bestand = lsg_bl_best_zeilen( $z['athletes_id'], $distanz, $jahr );
		$p4      = lsg_bl_p4_status( $distanz, $z['zeit'], $bestand );

		$z['status']   = $p4['status'];
		$z['best_id']  = $p4['best_id'];
		$z['time_alt'] = $p4['time_alt'];
		$z['doppelt']  = $p4['doppelt'];
		$z['zusatz']   = $p4['zusatz'];

		$out[] = $z;
	}

	// Zwei Zeilen desselben Athleten im selben Import: die bessere gewinnt.
	return lsg_bl_p4_dubletten_im_import( $out, $distanz );
}

/**
 * Kandidaten für die Ähnlichkeitsliste einer nicht zuordenbaren Zeile.
 *
 * Gesucht wird über den normalisierten Nachnamen und über den Jahrgang –
 * beides in einer Abfrage, damit die Lesehilfe nicht teurer wird als die
 * Zuordnung selbst.
 *
 * @param array $zeile nachname, vorname, jahrgang.
 * @return array<int,array>
 */
function lsg_bl_athleten_aehnlich_kandidaten( array $zeile ) {
	global $wpdb;

	$nachname = trim( (string) $zeile['nachname'] );
	$jahrgang = (int) $zeile['jahrgang'];

	if ( '' === $nachname && $jahrgang <= 0 ) {
		return array();
	}

	$t      = lsg_bl_table( 'lsg_athlete' );
	$where  = array();
	$params = array();

	if ( '' !== $nachname ) {
		// Nur die ersten Zeichen: „Weber" soll „Weiber" und „Webber" mit
		// einfangen, ohne die halbe Tabelle zu lesen.
		$where[]  = 'name LIKE %s';
		$params[] = $wpdb->esc_like( mb_substr( $nachname, 0, 3 ) ) . '%';
	}
	if ( $jahrgang > 0 ) {
		$where[]  = 'born = %d';
		$params[] = $jahrgang;
	}

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id, name, firstname, born, cat FROM {$t} WHERE " . implode( ' OR ', $where ) . ' LIMIT 200',
		$params
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return $rows ? $rows : array();
}

/* -------------------------------------------------------------------------
 * Übernehmen – der einzige Schreibvorgang des Imports
 * ---------------------------------------------------------------------- */

/**
 * Die angehakten Zeilen übernehmen.
 *
 * Was passiert (Plan 6.7):
 *
 *   neu         → INSERT in lsg_best
 *   schneller   → UPDATE der gefundenen Zeile; die alte Zeit steht danach
 *                 im Log (time_alt)
 *   langsamer   → nichts schreiben, protokolliert als skip_langsamer
 *   gleich      → nichts schreiben, protokolliert als skip_gleich
 *   offen /
 *   mehrdeutig  → hat keine Checkbox, wird nie geschrieben
 *
 * ⚠ Eine angehakte `langsamer`-Zeile schreibt hier NICHTS – anders als im
 * Formular aus 7.3. Im Import stehen vierzig Zeilen zur Auswahl, und ein
 * versehentlich gesetzter Haken darf keine Bestzeit verschlechtern.
 *
 * ⚠ Der Client bestimmt nur, WELCHE Zeilen übernommen werden – als Indizes,
 * nicht als Daten. Athlet, Zeit, Distanz, Datum und Status kommen
 * ausschließlich aus dem Parse-Transient, den der Server selbst geschrieben
 * hat. Sonst wäre die Übernahme mit einer Capability, die jeder angemeldete
 * Benutzer hat, ein freier Schreibzugriff auf `lsg_best` (Plan 6.10).
 *
 * @param string $token   Parse-Token.
 * @param int[]  $auswahl Zeilenindizes der angehakten Zeilen.
 * @return array{run_id:int,angelegt:int,aktualisiert:int,uebersprungen:int,konflikte:int,fehler:int,ergebnisse:array}
 * @throws LSG_BL_Quelle_Exception Wenn der Token nicht (mehr) gilt.
 */
function lsg_bl_uebernehmen( $token, array $auswahl ) {
	global $wpdb;

	$daten = lsg_bl_parse_holen( $token );
	if ( ! $daten ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Die Vorschau ist abgelaufen oder gehört zu einem anderen Benutzer. Bitte erneut parsen.', 'lsg-bestenliste' )
		);
	}

	if ( ! empty( $daten['uebernommen'] ) ) {
		// Ein Reload der Übernahme darf nicht noch einmal schreiben.
		throw new LSG_BL_Quelle_Exception(
			__( 'Dieser Vorgang ist bereits übernommen. Für einen weiteren Import bitte erneut parsen.', 'lsg-bestenliste' )
		);
	}

	$t_best = lsg_bl_table( 'lsg_best' );
	$jahr   = (int) $daten['jahr'];
	$distanz = (string) $daten['distanz'];
	$datum_ts = lsg_bl_datum_zu_timestamp( $daten['datum'] );

	$auswahl = array_flip( array_map( 'intval', $auswahl ) );

	/*
	 * Reihenfolge: innerhalb einer Gruppe aus Athlet und Distanz zuerst die
	 * beste Zeit. Sonst hinge das Ergebnis daran, in welcher Reihenfolge die
	 * Zeilen in der Liste stehen – und ein Import mit Staffel plus Einzellauf
	 * schriebe mal die eine, mal die andere Zeit.
	 */
	$reihenfolge = array_keys( $daten['zeilen'] );
	usort(
		$reihenfolge,
		function ( $a, $b ) use ( $daten, $distanz ) {
			$za = $daten['zeilen'][ $a ];
			$zb = $daten['zeilen'][ $b ];
			if ( (int) $za['athletes_id'] !== (int) $zb['athletes_id'] ) {
				return $a - $b;   // Gruppen in Listenreihenfolge
			}
			$pa = lsg_bl_parse_performance( $distanz, $za['zeit'] );
			$pb = lsg_bl_parse_performance( $distanz, $zb['zeit'] );
			if ( $pa['sort'] === $pb['sort'] ) {
				return $a - $b;
			}
			return lsg_bl_perf_besser( $pa, $pb ) ? -1 : 1;
		}
	);

	$bilanz = array(
		'angelegt'      => 0,
		'aktualisiert'  => 0,
		'uebersprungen' => 0,
		'konflikte'     => 0,
		'fehler'        => 0,
	);
	$log_zeilen = array();
	$ergebnisse = array();
	$eigene     = array();   // Athlet|Distanz|Jahr, die wir selbst geschrieben haben

	// Alle Schreibvorgänge eines Klicks in einer Transaktion, damit ein Fehler
	// in der Mitte keinen halben Import hinterlässt. Voraussetzung InnoDB –
	// bei MyISAM greift das nicht, dann ist das Log der Rettungsanker.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'START TRANSACTION' );

	foreach ( $reihenfolge as $i ) {
		$z          = $daten['zeilen'][ $i ];
		$angehakt   = isset( $auswahl[ $i ] );
		$aid        = (int) $z['athletes_id'];
		$status_alt = (string) $z['status'];

		$eintrag = array(
			'index'   => $i,
			'name'    => trim( $z['nachname'] . ', ' . $z['vorname'], ', ' ),
			'aktion'  => '',
			'meldung' => '',
			'best_id' => 0,
		);

		/* ---- ohne Zuordnung: nie schreiben, aber immer protokollieren ---- */
		if ( 0 === $aid || ! lsg_bl_zeile_waehlbar( $status_alt ) ) {
			$eintrag['aktion']  = 'skip_offen';
			$eintrag['meldung'] = (string) $z['match_meldung'];
			++$bilanz['uebersprungen'];
			$log_zeilen[] = lsg_bl_log_zeile( $z, 'skip_offen', 0, '', $eintrag['meldung'] );
			$ergebnisse[] = $eintrag;
			continue;
		}

		/* ---- nicht angehakt ---- */
		if ( ! $angehakt ) {
			/*
			 * Warum nichts geschrieben wurde, sagt der Status genauer als
			 * „nicht angehakt": `langsamer` und `gleich` sind gar nicht erst
			 * vorausgewählt (Plan 6.6) – da hat niemand aktiv abgewählt, und
			 * ein `skip_abgewaehlt` im Log läse sich wie eine Entscheidung,
			 * die niemand getroffen hat.
			 *
			 * `skip_abgewaehlt` bleibt damit dem Fall vorbehalten, um den es
			 * dem Plan geht: etwas, das geschrieben WORDEN WÄRE, und jemand
			 * hat den Haken bewusst entfernt.
			 */
			if ( 'langsamer' === $status_alt ) {
				$aktion  = 'skip_langsamer';
				$meldung = __( 'Nicht vorausgewählt – der Bestand ist besser.', 'lsg-bestenliste' );
			} elseif ( 'gleich' === $status_alt ) {
				$aktion  = 'skip_gleich';
				$meldung = __( 'Nicht vorausgewählt – diese Zeit steht bereits so in der Datenbank.', 'lsg-bestenliste' );
			} else {
				$aktion  = 'skip_abgewaehlt';
				$meldung = __( 'Haken entfernt – gesehen und bewusst nicht übernommen.', 'lsg-bestenliste' );
			}

			$eintrag['aktion']  = $aktion;
			$eintrag['meldung'] = $meldung;
			++$bilanz['uebersprungen'];
			$log_zeilen[] = lsg_bl_log_zeile( $z, $aktion, (int) $z['best_id'], (string) $z['time_alt'], $meldung );
			$ergebnisse[] = $eintrag;
			continue;
		}

		/*
		 * Der Statusvergleich wird unmittelbar vor dem Schreiben wiederholt:
		 * zwischen Parsen und Übernehmen liegt eine Benutzerentscheidung, in
		 * der eine zweite Person denselben Import gemacht haben kann.
		 *
		 * ⚠ „Abweichung" heißt: von AUSSEN geändert. Innerhalb eines Vorgangs
		 * ändert sich der Status planmäßig – hakt jemand zwei Zeilen desselben
		 * Athleten an, steht die zweite nach dem Schreiben der ersten
		 * zwangsläufig auf `langsamer` oder `gleich`. Das ist kein Konflikt,
		 * sondern das erwartete Ergebnis. Verglichen wird deshalb gegen den
		 * Stand zu Beginn PLUS die eigenen Schreibvorgänge (Plan 6.7).
		 */
		$key      = $aid . '|' . $distanz . '|' . $jahr;
		$bestand  = lsg_bl_best_zeilen( $aid, $distanz, $jahr );
		$p4_jetzt = lsg_bl_p4_status( $distanz, $z['zeit'], $bestand );

		if ( $p4_jetzt['status'] !== $status_alt && ! isset( $eigene[ $key ] ) ) {
			$eintrag['aktion']  = 'konflikt';
			$eintrag['meldung'] = sprintf(
				/* translators: 1: Status beim Parsen, 2: Status jetzt */
				__( 'Der Bestand hat sich seit dem Parsen geändert (%1$s → %2$s). Die Zeile wurde nicht geschrieben.', 'lsg-bestenliste' ),
				$status_alt,
				$p4_jetzt['status']
			);
			++$bilanz['konflikte'];
			$log_zeilen[] = lsg_bl_log_zeile( $z, 'konflikt', $p4_jetzt['best_id'], $p4_jetzt['time_alt'], $eintrag['meldung'] );
			$ergebnisse[] = $eintrag;
			continue;
		}

		$status = $p4_jetzt['status'];

		/* ---- langsamer / gleich: nichts schreiben ---- */
		if ( 'langsamer' === $status || 'gleich' === $status ) {
			$aktion             = ( 'langsamer' === $status ) ? 'skip_langsamer' : 'skip_gleich';
			$eintrag['aktion']  = $aktion;
			$eintrag['meldung'] = ( 'langsamer' === $status )
				? __( 'Der Bestand bleibt. Korrektur unter „Bestenliste".', 'lsg-bestenliste' )
				: __( 'Diese Zeit steht bereits so in der Datenbank.', 'lsg-bestenliste' );
			++$bilanz['uebersprungen'];
			$log_zeilen[] = lsg_bl_log_zeile( $z, $aktion, $p4_jetzt['best_id'], $p4_jetzt['time_alt'], $eintrag['meldung'] );
			$ergebnisse[] = $eintrag;
			continue;
		}

		/* ---- schreiben ---- */
		$werte = array(
			'tstamp'      => time(),
			'distance'    => $distanz,
			'time'        => (string) $z['zeit'],
			'town'        => mb_substr( (string) $daten['ort'], 0, 30 ),
			'date'        => $datum_ts,
			'athletes_id' => $aid,
			'ak'          => (string) $z['ak'],
		);
		$formate = array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' );

		if ( 'neu' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert( $t_best, $werte, $formate );
			if ( false === $ok ) {
				$eintrag['aktion']  = 'fehler';
				$eintrag['meldung'] = $wpdb->last_error ? $wpdb->last_error : __( 'Die Zeile ließ sich nicht anlegen.', 'lsg-bestenliste' );
				++$bilanz['fehler'];
				$log_zeilen[] = lsg_bl_log_zeile( $z, 'fehler', 0, '', $eintrag['meldung'] );
				$ergebnisse[] = $eintrag;
				continue;
			}
			$best_id            = (int) $wpdb->insert_id;
			$eintrag['aktion']  = 'insert';
			$eintrag['best_id'] = $best_id;
			$eintrag['meldung'] = __( 'angelegt', 'lsg-bestenliste' );
			++$bilanz['angelegt'];
			$eigene[ $key ] = true;
			$log_zeilen[]   = lsg_bl_log_zeile( $z, 'insert', $best_id, '', $eintrag['meldung'] );
			$ergebnisse[]   = $eintrag;
			continue;
		}

		// 'schneller'
		$best_id = (int) $p4_jetzt['best_id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->update(
			$t_best,
			array(
				'tstamp' => time(),
				'time'   => (string) $z['zeit'],
				'town'   => mb_substr( (string) $daten['ort'], 0, 30 ),
				'date'   => $datum_ts,
				'ak'     => (string) $z['ak'],
			),
			array( 'id' => $best_id ),
			array( '%d', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			$eintrag['aktion']  = 'fehler';
			$eintrag['meldung'] = $wpdb->last_error ? $wpdb->last_error : __( 'Die Zeile ließ sich nicht aktualisieren.', 'lsg-bestenliste' );
			++$bilanz['fehler'];
			$log_zeilen[] = lsg_bl_log_zeile( $z, 'fehler', $best_id, $p4_jetzt['time_alt'], $eintrag['meldung'] );
			$ergebnisse[] = $eintrag;
			continue;
		}

		$eintrag['aktion']  = 'update';
		$eintrag['best_id'] = $best_id;
		$eintrag['meldung'] = sprintf(
			/* translators: 1: alte Zeit, 2: neue Zeit */
			__( 'aktualisiert (%1$s → %2$s)', 'lsg-bestenliste' ),
			$p4_jetzt['time_alt'],
			$z['zeit']
		);
		++$bilanz['aktualisiert'];
		$eigene[ $key ] = true;
		$log_zeilen[]   = lsg_bl_log_zeile( $z, 'update', $best_id, $p4_jetzt['time_alt'], $eintrag['meldung'] );
		$ergebnisse[]   = $eintrag;
	}

	if ( $bilanz['fehler'] > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ROLLBACK' );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );
	}

	$run_id = lsg_bl_log_schreiben( $daten, $bilanz, $log_zeilen );

	// Zeilen in Listenreihenfolge zurückgeben, nicht in Schreibreihenfolge.
	usort(
		$ergebnisse,
		function ( $a, $b ) {
			return $a['index'] - $b['index'];
		}
	);

	/*
	 * Der Vorgang bleibt im Transient stehen, aber mit seinem Resultat: nach
	 * dem Übernehmen bleibt die Tabelle sichtbar, jede Zeile bekommt ihr
	 * Ergebnis angeheftet (Plan 6.6). Das `uebernommen`-Feld ist zugleich die
	 * Sperre gegen einen zweiten Klick auf denselben Token – ein Reload darf
	 * nicht noch einmal schreiben.
	 */
	$daten['uebernommen'] = array_merge(
		$bilanz,
		array(
			'run_id'     => $run_id,
			'zeitpunkt'  => time(),
			'ergebnisse' => $ergebnisse,
		)
	);
	set_transient( 'lsg_bl_parse_' . $token, $daten, LSG_BL_CACHE_PARSE );

	return array_merge(
		$bilanz,
		array(
			'run_id'     => $run_id,
			'ergebnisse' => $ergebnisse,
		)
	);
}

/**
 * Herkunft des Veranstaltungsdatums bestimmen.
 *
 * @param array  $disc  Discovery-Daten.
 * @param string $datum 'JJJJ-MM-TT', wie er ins Formular ging.
 * @return string liste|ausschreibung|api|name|jahr|manuell
 */
function lsg_bl_datum_quelle_bestimmen( array $disc, $datum ) {
	$vorschlag = isset( $disc['datum']['datum'] ) ? (string) $disc['datum']['datum'] : '';
	$quelle    = isset( $disc['datum']['quelle'] ) ? (string) $disc['datum']['quelle'] : '';

	if ( '' !== $vorschlag && $vorschlag === (string) $datum && '' !== $quelle ) {
		return $quelle;
	}
	return 'manuell';
}

/**
 * Einen neuen Parse-Token erzeugen.
 *
 * @return string
 */
function lsg_bl_parse_token_neu() {
	return wp_generate_password( 24, false, false );
}

/**
 * Das Parse-Ergebnis zu einem Token holen – nur für den Benutzer, der es
 * erzeugt hat.
 *
 * ⚠ Der Token ist an die `user_id` gebunden. Ein Transient-Schlüssel, den ein
 * zweiter Benutzer erraten oder mitlesen kann, macht aus der Übernahme einen
 * freien Schreibzugriff auf `lsg_best` (Plan 6.10).
 *
 * @param string $token Token.
 * @return array|null
 */
function lsg_bl_parse_holen( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( '' === $token ) {
		return null;
	}

	$daten = get_transient( 'lsg_bl_parse_' . $token );
	if ( ! is_array( $daten ) || ! isset( $daten['zeilen'] ) ) {
		return null;
	}
	if ( (int) $daten['user_id'] !== get_current_user_id() ) {
		return null;
	}

	return $daten;
}

/**
 * Ein Parse-Ergebnis verwerfen.
 *
 * @param string $token Token.
 * @return void
 */
function lsg_bl_parse_verwerfen( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( '' !== $token ) {
		delete_transient( 'lsg_bl_parse_' . $token );
	}
}

/**
 * Passt das gespeicherte Parse-Ergebnis noch zu den Feldern über der Tabelle?
 *
 * @param array $daten Parse-Ergebnis.
 * @param array $args  Aktuelle Werte (adapter, event_id, contest_id, list_id,
 *                     distanz, datum).
 * @return bool
 */
function lsg_bl_parse_passt( array $daten, array $args ) {
	if ( empty( $daten['fingerprint'] ) ) {
		return false;
	}
	return hash_equals( (string) $daten['fingerprint'], lsg_bl_import_fingerprint( $args ) );
}
