<?php
/**
 * Datumserkennung, Altersklassen-Berechnung und Geschlecht aus dem
 * Klassen-Code.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Datum_Ak_Test extends TestCase {

	/**
	 * @dataProvider texte
	 *
	 * @param string $text  Freier Text.
	 * @param string $datum Erwartetes Datum 'JJJJ-MM-TT' oder ''.
	 * @param string $jahr  Erwartetes Jahr oder ''.
	 */
	public function test_datum_aus_text( $text, $datum, $jahr ) {
		$t = lsg_bl_datum_aus_text( $text, 1950, 2030 );
		$this->assertSame( $datum, $t['datum'], 'Datum aus: ' . $text );
		$this->assertSame( $jahr, $t['jahr'], 'Jahr aus: ' . $text );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function texte() {
		return array(
			'Runtix Übersicht'   => array( '[16.08.2026] 19. Hambrücker Lußhardtlauf', '2026-08-16', '2026' ),
			'ISO'                => array( 'Lauf am 2026-05-17', '2026-05-17', '2026' ),
			'Ausschreibung'      => array( 'Sonntag, den 16. August 2026', '2026-08-16', '2026' ),
			'kurzes Jahr'        => array( 'So., 16.8.26', '2026-08-16', '2026' ),
			'abgekürzter Monat'  => array( '16. Aug 2026', '2026-08-16', '2026' ),

			// Nur eine Jahreszahl: Tag und Monat fehlen und werden NICHT
			// ergänzt – kein stiller 1. Januar (Plan 6.5.1).
			'nur Jahr'           => array( '17. SWE Halbmarathon Ettlingen 2026', '', '2026' ),

			// race result Ettlingen: der Eventname nennt gar nichts.
			'race result'        => array( '17. SWE Halbmarathon Ettlingen', '', '' ),
			'Runtix Kopfzeile'   => array( '19. Hambrücker Lußhardtlauf', '', '' ),

			// Zwei Jahreszahlen sind keine Angabe, sondern eine Frage.
			'Copyright-Fußzeile' => array( 'Copyright © CODERESEARCH 2001 - 2026', '', '' ),

			// Unsinn bleibt Unsinn.
			'unmögliches Datum'  => array( '31.02.2026', '', '' ),
			'leer'               => array( '', '', '' ),
		);
	}

	/**
	 * ⚠ Der Regressionsfall: Tab.ActiveFrom aus der race-result-config ist
	 * die Gültigkeit der Ansicht, nicht der Lauf. Ein Parser, der sie liest,
	 * trägt 2022 ein. Der Adapter liest sie deshalb gar nicht erst – hier
	 * wird nur festgehalten, dass der Wert auch als Text nicht durchrutscht,
	 * falls ihn jemand später doch einmal weiterreicht.
	 */
	public function test_active_from_ist_kein_veranstaltungsdatum() {
		$config  = lsg_bl_fixture( 'raceresult-375768-config.json' );
		$adapter = new LSG_BL_RaceResult_Adapter( lsg_bl_fake_getter( array( '/results/config' => $config ) ) );

		$ref = $adapter->eventLesen( 'https://my.raceresult.com/375768/' );
		$d   = $adapter->datum( $ref );

		$this->assertSame( '', $d['datum'] );
		$this->assertSame( '', $d['quelle'] );
		$this->assertStringContainsString( 'kein Datum', $d['hinweis'] );
		$this->assertStringNotContainsString( '2022', implode( '|', $d ) );
	}

	/**
	 * @dataProvider klassen
	 *
	 * @param string $klasse   Klassen-Code der Quelle.
	 * @param string $erwartet 'm' | 'f' | ''.
	 */
	public function test_geschlecht_aus_klasse( $klasse, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_geschlecht_aus_klasse( $klasse ), 'Klasse: ' . $klasse );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function klassen() {
		return array(
			'race result AK'   => array( '1. M35', 'm' ),
			'race result MW'   => array( '1. M', 'm' ),
			'race result W'    => array( '48. W', 'f' ),
			'Runtix'           => array( 'M 30', 'm' ),
			'Runtix W'         => array( 'W 45', 'f' ),
			'Jugendklasse'     => array( '2. WJ U18', 'f' ),
			'Jahrgangsklasse'  => array( 'MJU20', 'm' ),
			'DNS-Zeile'        => array( 'DNS M40', 'm' ),
			'ohne Klasse'      => array( '', '' ),
			'nur Platz'        => array( '12.', '' ),
		);
	}

	/**
	 * @dataProvider altersklassen
	 *
	 * @param int    $jahrgang Jahrgang.
	 * @param int    $jahr     Veranstaltungsjahr.
	 * @param string $cat      lsg_athlete.cat.
	 * @param string $erwartet Erwarteter Code.
	 */
	public function test_ak_berechnen( $jahrgang, $jahr, $cat, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_ak_berechnen( $jahrgang, $jahr, $cat ) );
	}

	/**
	 * @return array<string,array>
	 */
	public function altersklassen() {
		return array(
			// Jahrgangsklassen, keine Stichtagsklassen: 1976 läuft ab dem
			// 1. Januar 2026 in m50, auch wenn er erst im November 50 wird.
			'1976 bei 2026'      => array( 1976, 2026, 'm', 'm50' ),
			'1993 bei 2026'      => array( 1993, 2026, 'm', 'm30' ),
			'unter 30'           => array( 2000, 2026, 'm', 'mhk' ),
			'genau 30'           => array( 1996, 2026, 'm', 'm30' ),
			'genau 29'           => array( 1997, 2026, 'm', 'mhk' ),
			'34 rundet ab'       => array( 1992, 2026, 'm', 'm30' ),

			// cat 'f' → Präfix 'w', nicht 'f'.
			'Frau'               => array( 1955, 2026, 'f', 'w70' ),
			'Frau Hauptklasse'   => array( 2004, 2026, 'f', 'whk' ),

			// Codes, die lsg_ak (noch) nicht kennt, werden trotzdem
			// gerechnet und geschrieben – lsg_ak ist eine Anzeigeliste,
			// keine Prüfinstanz (Plan 6.5.3).
			'm80'                => array( 1943, 2026, 'm', 'm80' ),
			'w75'                => array( 1948, 2026, 'f', 'w75' ),

			// Unbrauchbare Eingaben ergeben nichts, nicht irgendetwas.
			'ohne Jahrgang'      => array( 0, 2026, 'm', '' ),
			'ohne Jahr'          => array( 1976, 0, 'm', '' ),
			'Jahrgang in der Zukunft' => array( 2030, 2026, 'm', '' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Die Umkehrung: Jahrgangsband aus dem Klassen-Code der Quelle.
	 *
	 * Der Anlass ist Issue #2 – race result und runtix nennen in vielen
	 * Listen nur noch die AK. Was hier geprüft wird, ist deshalb vor allem
	 * die Grenze zwischen „sicher erkannt" und „lieber gar nichts".
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider baender
	 *
	 * @param string $klasse   Klassen-Code der Quelle.
	 * @param int    $jahr     Veranstaltungsjahr.
	 * @param array  $erwartet array( von, bis ) oder array().
	 */
	public function test_jahrgangsband_aus_klasse( $klasse, $jahr, $erwartet ) {
		$this->assertSame(
			$erwartet,
			lsg_bl_jahrgangsband_aus_klasse( $klasse, $jahr ),
			'Klasse: ' . $klasse
		);
	}

	/**
	 * @return array<string,array>
	 */
	public function baender() {
		return array(
			// 5er-Klassen ab 30: „M40" 2026 heißt Alter 40 bis 44.
			'M40'                => array( 'M40', 2026, array( 1982, 1986 ) ),
			'W45'                => array( 'W45', 2026, array( 1977, 1981 ) ),
			'M75'                => array( 'M75', 2026, array( 1947, 1951 ) ),

			// Dieselbe Vorarbeit wie beim Geschlecht: führender Platz,
			// Statuskürzel, Leerzeichen im Code.
			'mit Platz'          => array( '1. M35', 2026, array( 1987, 1991 ) ),
			'DNS-Zeile'          => array( 'DNS M40', 2026, array( 1982, 1986 ) ),
			'runtix-Schreibweise' => array( 'M 30', 2026, array( 1992, 1996 ) ),

			// DLV-Hauptklasse mit Zahl: 20 bis 29, keine 5er-Stufe.
			'M20'                => array( 'M20', 2026, array( 1997, 2006 ) ),

			// Hauptklasse ohne Zahl. Was eine Quelle darunter fasst,
			// schwankt – deshalb dieselbe Grenze wie lsg_bl_ak_berechnen():
			// alles unter 30.
			'blankes M'          => array( 'M', 2026, array( 1997, 2026 ) ),
			'blankes W mit Platz' => array( '48. W', 2026, array( 1997, 2026 ) ),
			'MHK'                => array( 'MHK', 2026, array( 1997, 2026 ) ),

			// U-Klassen: „unter n" heißt Alter höchstens n−1.
			'M U23'              => array( 'M U23', 2026, array( 2004, 2026 ) ),
			'MJ U20'             => array( 'MJ U20', 2026, array( 2007, 2026 ) ),
			'WJ U18'             => array( 'WJ U18', 2026, array( 2009, 2026 ) ),

			// ⚠ Und jetzt die andere Hälfte: alles, wo das Schema nicht
			// sicher ist, liefert nichts. Ein zu enges Band schriebe ein
			// Ergebnis dem Falschen gut – die Zeile bleibt dann lieber offen.
			'10er-Klasse'        => array( 'M40-49', 2026, array() ),
			'offene Klasse'      => array( 'M50+', 2026, array() ),
			'krumme Stufe'       => array( 'M32', 2026, array() ),
			'Klartext'           => array( 'Senioren', 2026, array() ),
			'nur ein AK-Platz'   => array( '12.', 2026, array() ),
			'blanke Zahl'        => array( '3', 2026, array() ),
			'ohne Klasse'        => array( '', 2026, array() ),
			'ohne Jahr'          => array( 'M40', 0, array() ),
		);
	}

	/**
	 * Das Band muss zur Berechnung passen: wer in m50 gerechnet wird, muss
	 * auch im Band von „M50" liegen. Sonst driften die beiden Richtungen
	 * auseinander und niemand merkt es.
	 */
	public function test_band_und_berechnung_passen_zusammen() {
		foreach ( range( 1940, 2020 ) as $jahrgang ) {
			$code = lsg_bl_ak_berechnen( $jahrgang, 2026, 'm' );
			$band = lsg_bl_jahrgangsband_aus_klasse( strtoupper( $code ), 2026 );

			$this->assertNotSame( array(), $band, 'Kein Band für ' . $code );
			$this->assertGreaterThanOrEqual( $band[0], $jahrgang, $code );
			$this->assertLessThanOrEqual( $band[1], $jahrgang, $code );
		}
	}
}
