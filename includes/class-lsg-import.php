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

	$trichter              = lsg_bl_trichter_leer();
	$trichter['gelesen']   = isset( $p1['gelesen'] ) ? (int) $p1['gelesen'] : count( $zeilen );
	$trichter['verworfen'] = isset( $p1['verworfen'] ) ? (int) $p1['verworfen'] : 0;
	$trichter['lsg']       = count( $p2['lsg'] );

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
		'ort'           => isset( $args['ort'] ) ? (string) $args['ort'] : '',
		'zeit_typ'      => isset( $p1['zeit_typ'] ) ? (string) $p1['zeit_typ'] : '',
		'quelle_url'    => $adapter->quelleUrl( $ref, (string) $args['contest_id'], $ref->list_id ? $ref->list_id : null ),
		'trichter'      => $trichter,
		'warnungen'     => isset( $p1['warnungen'] ) ? (array) $p1['warnungen'] : array(),
		'abgelehnt'     => $p2['abgelehnt'],
		'nahe'          => $p2['nahe'],
		// ⚠ Nur die Zeilen, die P2 passiert haben. Die Nicht-LSG-Ergebnisse
		// werden nicht gehalten – auch nicht im Transient (Plan 6.8).
		'zeilen'        => array_map(
			function ( $e ) {
				return $e->to_array();
			},
			$p2['lsg']
		),
	);

	$token = lsg_bl_parse_token_neu();
	set_transient( 'lsg_bl_parse_' . $token, $daten, LSG_BL_CACHE_PARSE );

	return array(
		'token' => $token,
		'daten' => $daten,
	);
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
