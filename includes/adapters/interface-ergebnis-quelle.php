<?php
/**
 * Die Schnittstelle, hinter der jede Ergebnisquelle steckt.
 *
 * Das Interface trennt bewusst Discovery (erkennt, eventLesen, wettbewerbe,
 * listen) von Datenabruf (laden). Die Admin-Seite arbeitet ausschließlich
 * gegen die Discovery-Methoden und muss deshalb keinen einzigen Adapter
 * namentlich kennen (Plan, Abschnitt 5).
 *
 * ⚠ Der Abruf gehört nicht in den Parser. Ein Adapter bekommt seinen
 * HTTP-Getter im Konstruktor übergeben; die eigentlichen Parser sind
 * statische Methoden, die einen String entgegennehmen und normalisierte
 * Zeilen zurückgeben. Nur so lassen sie sich gegen eine Fixture prüfen,
 * ohne WordPress und ohne Netz.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface LSG_BL_Ergebnis_Quelle {

	/**
	 * Eindeutiger Schlüssel, z.B. 'raceresult'.
	 *
	 * @return string
	 */
	public static function key();

	/**
	 * Anzeigename für die UI, z.B. 'race result'.
	 *
	 * @return string
	 */
	public static function label();

	/**
	 * Kann dieser Adapter die URL bedienen?
	 * Höherer Rückgabewert = sicherere Erkennung. 0 = nein.
	 *
	 * @param string $url Eingegebene URL.
	 * @return int 0..100
	 */
	public static function erkennt( $url );

	/**
	 * Hosts, die dieser Adapter abrufen darf. Wildcard nur als Suffix
	 * ('*.raceresult.com'). Die Menge dieser Hosts IST die SSRF-Allowlist –
	 * jede abgerufene Adresse läuft dagegen, auch der zweite Request, dessen
	 * Host aus der Antwort eines Fremdservers stammt (Plan 6.10).
	 *
	 * @return string[]
	 */
	public static function hosts();

	/**
	 * Schritt 1→2: Event-Kontext aus der URL lösen.
	 *
	 * @param string $url Eingegebene URL.
	 * @return LSG_BL_Event_Ref
	 * @throws LSG_BL_Quelle_Exception Wenn die URL nicht auflösbar ist.
	 */
	public function eventLesen( $url );

	/**
	 * Schritt 2: verfügbare Wettbewerbe.
	 *
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return LSG_BL_Wettbewerb[]
	 */
	public function wettbewerbe( LSG_BL_Event_Ref $ref );

	/**
	 * Schritt 2b: Ergebnislisten eines Wettbewerbs.
	 * Leeres Array = es gibt nichts zu wählen, das Feld bleibt ausgeblendet.
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (String!).
	 * @return LSG_BL_Liste[]
	 */
	public function listen( LSG_BL_Event_Ref $ref, $contest_id );

	/**
	 * Schritt 3: die eigentlichen Daten, normalisiert.
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    Listen-ID oder null.
	 * @return LSG_BL_Ergebnis[]
	 */
	public function laden( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null );

	/**
	 * Die Adresse, unter der ein Mensch dieselbe Liste im Browser sieht.
	 * Wandert nach lsg_import_run.source_url.
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    Listen-ID oder null.
	 * @return string
	 */
	public function quelleUrl( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null );

	/**
	 * Veranstaltungsdatum, soweit die Quelle es hergibt.
	 *
	 * Im Zweifel leer – kein Raten, kein stiller 1. Januar (Plan 6.5.1).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @return array{datum:string,quelle:string,hinweis:string}
	 *         datum  'JJJJ-MM-TT' oder '' · quelle  liste|ausschreibung|api|name|jahr|''
	 */
	public function datum( LSG_BL_Event_Ref $ref, $contest_id = '' );
}

/**
 * Fehler beim Abrufen oder Auswerten einer Quelle. Trägt eine Meldung, die
 * so, wie sie ist, in eine `notice notice-error` gehört – kein stiller
 * Abbruch (Plan 6.3, 6.11).
 */
class LSG_BL_Quelle_Exception extends Exception {
}
