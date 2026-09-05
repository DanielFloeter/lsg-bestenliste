<?php
/**
 * REST-Schicht des Ergebnis-Imports (Plan 6.10).
 *
 * ⚠ Der zweite EINGANG, nicht die zweite Logik. Jede Route ruft dieselben
 * Funktionen wie der Formular-Handler auf `admin-post.php`:
 * lsg_bl_discovery(), lsg_bl_parsen(), lsg_bl_uebernehmen() – und für den
 * Seitenzustand lsg_bl_import_ansicht(). Wer hier eine Entscheidung trifft,
 * die dort nicht getroffen wird, hat zwei Oberflächen mit zwei Meinungen.
 *
 * ⚠ Alle Routen prüfen `current_user_can( LSG_BL_CAP )`, nie `__return_true`
 * wie die Frontend-Routen. Hier werden fremde Adressen serverseitig
 * abgerufen; ohne Prüfung wäre das ein offener SSRF-Proxy für nicht
 * angemeldete Besucher (Plan 6.10).
 *
 * ⚠ `/uebernehmen` nimmt Zeilen-INDIZES, keine Daten. Athlet, Zeit, Distanz,
 * Datum und Status kommen ausschließlich aus dem Parse-Transient, den der
 * Server selbst geschrieben hat, und der Token ist an die `user_id` gebunden.
 * Alles andere wäre mit einer Capability, die jeder angemeldete Benutzer hat,
 * ein freier Schreibzugriff auf `lsg_best`.
 *
 * ZWEI ABWEICHUNGEN von der Tabelle in 6.10, beide bewusst und beide in
 * plan.md protokolliert:
 *
 * 1. Jede Antwort trägt zusätzlich `html` – die Fragmente, die die Seite an
 *    ihre Behälter hängt. Die dokumentierten JSON-Felder bleiben, aber die
 *    Vorschau wird nicht ein zweites Mal in JavaScript gebaut. Der Renderer
 *    steht in page-import.php und wird von beiden Wegen benutzt, genau wie
 *    die Logik.
 * 2. Die Routen bekommen `url`, nicht `eventId`. Die Discovery hängt an der
 *    Adresse: ist der 15-Minuten-Cache abgelaufen, muss sie neu abgerufen
 *    werden, und mit einer blossen Event-ID liesse sich die Adresse nicht
 *    zurückbauen.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Die Renderer der Admin-Seite nachladen.
 *
 * ⚠ `includes/admin/page-import.php` hängt in lsg-bestenliste.php an
 * `is_admin()`, und ein REST-Request ist kein Admin-Request. Die Datei enthält
 * neben dem Menü und den Formular-Handlern die Ansichtsberechnung und die
 * Render-Funktionen – beides braucht diese Schicht. Nachgeladen wird erst im
 * Handler, also nur, wenn wirklich eine Import-Route aufgerufen wurde: ein
 * Seitenaufruf im Frontend soll davon nichts mitbekommen.
 *
 * @return void
 */
function lsg_bl_import_rest_renderer_laden() {
	if ( ! function_exists( 'lsg_bl_import_ansicht' ) ) {
		require_once LSG_BL_PATH . 'includes/admin/page-import.php';
	}
}

/**
 * Darf der aufrufende Benutzer importieren?
 *
 * @return bool
 */
function lsg_bl_import_rest_erlaubt() {
	return current_user_can( LSG_BL_CAP );
}

/**
 * Die fünf Routen registrieren.
 *
 * @return void
 */
function lsg_bl_import_rest_routen() {
	$args_quelle = array(
		'url'     => array( 'type' => 'string' ),
		'adapter' => array( 'type' => 'string' ),
	);

	$args_auswahl = array_merge(
		$args_quelle,
		array(
			'contest' => array( 'type' => 'string' ),
			'list'    => array( 'type' => 'string' ),
			'distanz' => array( 'type' => 'string' ),
			'datum'   => array( 'type' => 'string' ),
			'ort'     => array( 'type' => 'string' ),
			'token'   => array( 'type' => 'string' ),
		)
	);

	register_rest_route(
		'lsg/v1',
		'/import/erkennen',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'lsg_bl_import_rest_erlaubt',
			'callback'            => 'lsg_bl_import_rest_erkennen',
			'args'                => $args_quelle,
		)
	);

	register_rest_route(
		'lsg/v1',
		'/import/wettbewerbe',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'lsg_bl_import_rest_erlaubt',
			'callback'            => 'lsg_bl_import_rest_wettbewerbe',
			'args'                => $args_quelle,
		)
	);

	register_rest_route(
		'lsg/v1',
		'/import/listen',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'lsg_bl_import_rest_erlaubt',
			'callback'            => 'lsg_bl_import_rest_listen',
			'args'                => $args_auswahl,
		)
	);

	register_rest_route(
		'lsg/v1',
		'/import/parsen',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'lsg_bl_import_rest_erlaubt',
			'callback'            => 'lsg_bl_import_rest_parsen',
			'args'                => $args_auswahl,
		)
	);

	register_rest_route(
		'lsg/v1',
		'/import/uebernehmen',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'lsg_bl_import_rest_erlaubt',
			'callback'            => 'lsg_bl_import_rest_uebernehmen',
			'args'                => array_merge(
				$args_auswahl,
				array(
					'zeilen' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				)
			),
		)
	);
}
add_action( 'rest_api_init', 'lsg_bl_import_rest_routen' );

/* -------------------------------------------------------------------------
 * Gemeinsames
 * ---------------------------------------------------------------------- */

/**
 * Die Request-Parameter in das Roh-Array der Ansichtsberechnung übersetzen.
 *
 * ⚠ Ein Feld, das der Client nicht schickt, darf hier NICHT als leerer String
 * ankommen: lsg_bl_import_ansicht() unterscheidet „nicht angefasst" (wird
 * vorbelegt) von „bewusst geleert" (bleibt leer), und zwar an `isset()`.
 *
 * @param WP_REST_Request $req    Request.
 * @param string[]        $felder Zu lesende Felder.
 * @return array
 */
function lsg_bl_import_rest_roh( WP_REST_Request $req, array $felder ) {
	$roh = array();
	foreach ( $felder as $feld ) {
		$wert = $req->get_param( $feld );
		if ( null === $wert ) {
			continue;
		}
		$roh[ $feld ] = (string) $wert;
	}

	// Derselbe Textfeld-Fallback wie im Formular-Handler: ohne Datepicker
	// kommt TT.MM.JJJJ an (Plan 6.5.1, 6.9).
	if ( isset( $roh['datum'] ) ) {
		$roh['datum'] = lsg_bl_datum_eingabe_lesen( $roh['datum'] );
	}

	return $roh;
}

/**
 * Die Antwort zusammensetzen: Zustand, Formularwerte und die Fragmente.
 *
 * Alle vier Behälter kommen in jeder Antwort mit. Das ist kein Ballast,
 * sondern der Grund, warum es hier keine zweite Zustandslogik gibt: die Seite
 * hängt aus, was der Server gerechnet hat, statt selbst zu entscheiden,
 * welcher Teil sich geändert haben könnte. Gerendert wird aus dem
 * Discovery-Cache – ein Fragment kostet keinen Abruf bei der Quelle.
 *
 * @param array $a        Ergebnis von lsg_bl_import_ansicht().
 * @param array $hinweise Zusätzliche Meldungen: array( typ, text ).
 * @return array
 */
function lsg_bl_import_rest_ansicht_antwort( array $a, array $hinweise = array() ) {
	$hinweise = array_merge( $hinweise, $a['hinweise'] );

	ob_start();
	echo '<div id="lsg-bl-notices">';
	foreach ( $hinweise as $h ) {
		lsg_bl_admin_notice( $h[0], $h[1] );
	}
	if ( '' !== $a['fehler'] ) {
		lsg_bl_admin_notice( 'error', $a['fehler'] );
	}
	echo '</div>';
	$html_notices = ob_get_clean();

	ob_start();
	lsg_bl_import_erkannt_zeile( $a['w'], $a['adapter_cls'], $a['disc'] );
	$html_erkannt = ob_get_clean();

	ob_start();
	lsg_bl_import_auswahl_block( $a );
	$html_auswahl = ob_get_clean();

	ob_start();
	lsg_bl_import_vorschau_block( $a );
	$html_vorschau = ob_get_clean();

	ob_start();
	lsg_bl_import_zustand_anzeigen( $a['zustand'] );
	$html_zustand = ob_get_clean();

	return array(
		'zustand' => $a['zustand'],
		'werte'   => $a['w'],
		'html'    => array(
			'notices'  => $html_notices,
			'erkannt'  => $html_erkannt,
			'auswahl'  => $html_auswahl,
			'vorschau' => $html_vorschau,
			'zustand'  => $html_zustand,
		),
	);
}

/**
 * Einen Quellenfehler als WP_Error zurückgeben.
 *
 * Status 200 wäre bequemer für das Skript, aber falsch: „die Quelle antwortet
 * nicht" ist kein erfolgreicher Abruf, und ein Fehler, der wie ein Erfolg
 * aussieht, wird irgendwann wie einer behandelt.
 *
 * @param LSG_BL_Quelle_Exception $e Ausnahme.
 * @return WP_Error
 */
function lsg_bl_import_rest_fehler( $e ) {
	return new WP_Error(
		'lsg_bl_quelle',
		$e->getMessage(),
		array( 'status' => 502 )
	);
}

/* -------------------------------------------------------------------------
 * Die Routen
 * ---------------------------------------------------------------------- */

/**
 * Schritt 1: Adresse prüfen, Adapter bestimmen, Wettbewerbe holen.
 *
 * ⚠ Wie der Formular-Schritt `pruefen` setzt die Route die Auswahl zurück:
 * eine neue Adresse hat mit dem alten Wettbewerb nichts zu tun. Deshalb geht
 * nur `url` und `adapter` in die Ansicht – alles andere wird neu vorbelegt.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function lsg_bl_import_rest_erkennen( WP_REST_Request $req ) {
	lsg_bl_import_rest_renderer_laden();

	$roh = lsg_bl_import_rest_roh( $req, array( 'url', 'adapter' ) );
	$a   = lsg_bl_import_ansicht( $roh );

	$antwort = lsg_bl_import_rest_ansicht_antwort( $a );

	$disc                = $a['disc'];
	$antwort['adapter']  = $disc ? $disc['adapter'] : '';
	$antwort['label']    = $disc ? $disc['adapter_label'] : '';
	$antwort['eventId']  = $disc ? $disc['event_id'] : '';
	$antwort['eventName'] = $disc ? $disc['event_name'] : '';
	$antwort['contestId'] = $disc ? $a['w']['contest'] : '';
	$antwort['listId']   = $disc ? $a['w']['list'] : '';

	return rest_ensure_response( $antwort );
}

/**
 * Die Wettbewerbe eines Events.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function lsg_bl_import_rest_wettbewerbe( WP_REST_Request $req ) {
	lsg_bl_import_rest_renderer_laden();

	$roh = lsg_bl_import_rest_roh( $req, array( 'url', 'adapter' ) );
	$a   = lsg_bl_import_ansicht( $roh );

	$antwort              = lsg_bl_import_rest_ansicht_antwort( $a );
	$antwort['contests']  = $a['disc'] ? $a['disc']['contests'] : array();

	return rest_ensure_response( $antwort );
}

/**
 * Die Ergebnislisten eines Wettbewerbs – und der neue Stand der Auswahl.
 *
 * Diese Route trägt mehr als ihren Namen: sie ist zugleich der Weg, auf dem
 * ein Wechsel von Wettbewerb, Liste, Distanz oder Datum die Vorschläge und
 * Hinweise nachzieht, die ohne JavaScript der Knopf „Auswahl übernehmen"
 * holt. Ein Abruf bei der Quelle entsteht dabei nicht: die Discovery liegt
 * im Transient (Plan 5.2).
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function lsg_bl_import_rest_listen( WP_REST_Request $req ) {
	lsg_bl_import_rest_renderer_laden();

	$roh = lsg_bl_import_rest_roh(
		$req,
		array( 'url', 'adapter', 'contest', 'list', 'distanz', 'datum', 'ort', 'token' )
	);
	$a   = lsg_bl_import_ansicht( $roh );

	$antwort          = lsg_bl_import_rest_ansicht_antwort( $a );
	$antwort['lists'] = ( $a['disc'] && '' !== $a['w']['contest'] )
		? lsg_bl_contest_listen( $a['disc'], $a['w']['contest'] )
		: array();

	return rest_ensure_response( $antwort );
}

/**
 * Schritt 3: die Liste einmal lesen, filtern, zuordnen, vergleichen.
 *
 * Geschrieben wird nichts – das Ergebnis liegt im Parse-Transient und der
 * Token ist an die `user_id` gebunden.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function lsg_bl_import_rest_parsen( WP_REST_Request $req ) {
	lsg_bl_import_rest_renderer_laden();

	$roh = lsg_bl_import_rest_roh(
		$req,
		array( 'url', 'adapter', 'contest', 'list', 'distanz', 'datum', 'ort' )
	);

	// Erst den Zustand rechnen: daraus kommen Adapter, aufgelöste Liste und
	// die Vorbelegungen, die der Mensch nicht angefasst hat.
	$a = lsg_bl_import_ansicht( $roh );

	if ( ! $a['adapter_cls'] ) {
		return new WP_Error(
			'lsg_bl_kein_adapter',
			__( 'Für diese Adresse gibt es noch keinen Adapter.', 'lsg-bestenliste' ),
			array( 'status' => 400 )
		);
	}
	if ( '' === $a['w']['contest'] ) {
		return new WP_Error(
			'lsg_bl_kein_wettbewerb',
			__( 'Bitte zuerst einen Wettbewerb wählen.', 'lsg-bestenliste' ),
			array( 'status' => 400 )
		);
	}

	try {
		$ergebnis = lsg_bl_parsen(
			array(
				'adapter_cls' => $a['adapter_cls'],
				'url'         => $a['w']['url'],
				'contest_id'  => $a['w']['contest'],
				'list_id'     => $a['w']['list'],
				'distanz'     => $a['w']['distanz'],
				'datum'       => $a['w']['datum'],
				'ort'         => $a['w']['ort'],
			)
		);
	} catch ( LSG_BL_Quelle_Exception $e ) {
		return lsg_bl_import_rest_fehler( $e );
	}

	// Mit dem frischen Token noch einmal rechnen – dieselbe Ansicht, die ein
	// Reload zeigen würde.
	$roh['token'] = $ergebnis['token'];
	$neu          = lsg_bl_import_ansicht( $roh );

	$antwort              = lsg_bl_import_rest_ansicht_antwort( $neu );
	$daten                = $ergebnis['daten'];
	$antwort['token']     = $ergebnis['token'];
	$antwort['trichter']  = $daten['trichter'];
	$antwort['warnungen'] = $daten['warnungen'];
	$antwort['meta']      = array(
		'eventName'   => $daten['event_name'],
		'contestName' => $daten['contest_name'],
		'listName'    => $daten['list_name'],
		'distanz'     => $daten['distanz'],
		'datum'       => $daten['datum'],
		'datumQuelle' => $daten['datum_quelle'],
		'ort'         => $daten['ort'],
		'zeitTyp'     => $daten['zeit_typ'],
		'quelleUrl'   => $daten['quelle_url'],
		'jahr'        => $daten['jahr'],
	);
	$antwort['zeilen']    = lsg_bl_import_rest_zeilen( $daten['zeilen'] );

	return rest_ensure_response( $antwort );
}

/**
 * Die Vorschauzeilen für die JSON-Antwort eindampfen.
 *
 * Was hier fehlt, fehlt mit Absicht: die Kandidatenlisten und Rohfelder
 * gehören in die Tabelle, nicht in eine Schnittstelle, an der sich jemand
 * bedient. Für die Anzeige liefert dieselbe Antwort das gerenderte Fragment.
 *
 * @param array $zeilen Zeilen aus dem Parse-Transient.
 * @return array
 */
function lsg_bl_import_rest_zeilen( array $zeilen ) {
	$status_liste = lsg_bl_p4_status_liste();
	$out          = array();

	foreach ( $zeilen as $i => $z ) {
		$waehlbar = lsg_bl_zeile_waehlbar( $z['status'] );
		$out[]    = array(
			'index'       => (int) $i,
			'nachname'    => (string) $z['nachname'],
			'vorname'     => (string) $z['vorname'],
			'jahrgang'    => (int) $z['jahrgang'],
			'ak'          => (string) $z['ak'],
			'zeit'        => (string) $z['zeit'],
			'zeitAlt'     => (string) $z['time_alt'],
			'athletesId'  => (int) $z['athletes_id'],
			'status'      => (string) $z['status'],
			'waehlbar'    => $waehlbar,
			'vorauswahl'  => $waehlbar && ! empty( $status_liste[ $z['status'] ]['vorauswahl'] ),
		);
	}

	return $out;
}

/**
 * Die angehakten Zeilen übernehmen.
 *
 * @param WP_REST_Request $req Request.
 * @return WP_REST_Response|WP_Error
 */
function lsg_bl_import_rest_uebernehmen( WP_REST_Request $req ) {
	lsg_bl_import_rest_renderer_laden();

	$roh = lsg_bl_import_rest_roh(
		$req,
		array( 'url', 'adapter', 'contest', 'list', 'distanz', 'datum', 'ort', 'token' )
	);

	$auswahl = array();
	foreach ( (array) $req->get_param( 'zeilen' ) as $i ) {
		$auswahl[] = (int) $i;
	}

	try {
		$ergebnis = lsg_bl_uebernehmen( isset( $roh['token'] ) ? $roh['token'] : '', $auswahl );
	} catch ( LSG_BL_Quelle_Exception $e ) {
		return lsg_bl_import_rest_fehler( $e );
	}

	// Der Vorgang steht jetzt mit seinem Resultat im Transient – die Ansicht
	// zeigt dieselbe Tabelle wie nach einem Reload, mit Resultatspalte.
	$a       = lsg_bl_import_ansicht( $roh );
	$antwort = lsg_bl_import_rest_ansicht_antwort( $a );

	return rest_ensure_response( array_merge( $antwort, $ergebnis ) );
}
