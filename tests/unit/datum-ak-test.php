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
}
