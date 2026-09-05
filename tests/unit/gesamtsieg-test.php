<?php
/**
 * Die reine Logik der Seite „Gesamtsiege" (Plan, Abschnitt 12):
 * Formularprüfung, Dublettenschlüssel und Diff.
 *
 * Der Schwerpunkt liegt auf dem, was diese Seite von der Bestenlisten-Pflege
 * unterscheidet: **hier wird nichts normalisiert** (12.1). Ein Test, der
 * „48 Runden" durchgehen lässt, ist die Zusicherung, dass niemand
 * versehentlich lsg_bl_leistung_lesen() davorschaltet.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Gesamtsieg_Test extends TestCase {

	/**
	 * Ein vollständiger, gültiger Satz Werte.
	 *
	 * @param array $ueberschreiben Einzelne Felder ersetzen.
	 * @return array
	 */
	private function eingabe( array $ueberschreiben = array() ) {
		return array_merge(
			array(
				'datum'   => '2025-08-01',
				'ort'     => 'Ettlingen',
				'event'   => 'Ettlinger Halbmarathon',
				'distanz' => 'Halbmarathon',
				'athlet'  => 429,
				'zeit'    => '01:19:51',
			),
			$ueberschreiben
		);
	}

	/* ------------------------------------------------------------------
	 * Das Formular
	 * --------------------------------------------------------------- */

	public function test_gueltige_eingabe() {
		$p = lsg_bl_win_formular_pruefen( $this->eingabe(), 2027 );

		$this->assertTrue( $p['ok'] );
		$this->assertSame( array(), $p['fehler'] );
		$this->assertSame( 2025, $p['werte']['jahr'] );
	}

	/**
	 * Alle Felder sind Pflicht – und alle Fehler kommen auf einmal.
	 */
	public function test_alle_pflichtfelder() {
		$p = lsg_bl_win_formular_pruefen(
			array(
				'datum'   => '',
				'ort'     => '',
				'event'   => '',
				'distanz' => '',
				'athlet'  => 0,
				'zeit'    => '',
			),
			2027
		);

		$this->assertFalse( $p['ok'] );
		foreach ( array( 'datum', 'ort', 'event', 'distanz', 'athlet', 'zeit' ) as $feld ) {
			$this->assertArrayHasKey( $feld, $p['fehler'], $feld );
		}
	}

	/* ------------------------------------------------------------------
	 * Der Kern von 12.1: nichts wird normalisiert
	 * --------------------------------------------------------------- */

	/**
	 * ⚠ Der wichtigste Test dieser Datei. Im Bestand stehen diese Werte, und
	 * eine Prüfung, die sie zurückweist, wirft ein Drittel der Chronik weg.
	 *
	 * @dataProvider chronikwerte
	 *
	 * @param string $distanz Freitext-Distanz.
	 * @param string $zeit    Freitext-Zeit.
	 */
	public function test_freitext_geht_durch( $distanz, $zeit ) {
		$p = lsg_bl_win_formular_pruefen(
			$this->eingabe(
				array(
					'distanz' => $distanz,
					'zeit'    => $zeit,
				)
			),
			2027
		);

		$this->assertTrue( $p['ok'], $distanz . ' / ' . $zeit );
		// Und zwar unverändert – nicht gerundet, nicht aufgefüllt, nicht
		// umformatiert.
		$this->assertSame( $distanz, $p['werte']['distanz'] );
		$this->assertSame( $zeit, $p['werte']['zeit'] );
	}

	/**
	 * Alles echte Werte aus `lsg_win` (Befund 2026-09-05).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function chronikwerte() {
		return array(
			'Staffel'      => array( '90 Minuten', '48 Runden' ),
			'Backyard'     => array( '328,57 km', '44:21:00' ),
			'Zeitlauf'     => array( '24h', '241,621 km' ),
			'Etappenlauf'  => array( 'Pforzheim nach Basel', '52:11:00' ),
			'Loops'        => array( '187,796 km/28 Loops', '24:00:00' ),
			'uneinheitlich' => array( '10km', '00:34:35' ),
			'mit Leerzeichen' => array( '10 km', '00:34:35' ),
		);
	}

	/**
	 * `44:21:00` ist mehr als ein Tag – und bleibt trotzdem stehen. Die
	 * Zeitprüfung des Imports käme hier ins Straucheln.
	 */
	public function test_zeit_ueber_24_stunden() {
		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'zeit' => '44:21:00' ) ), 2027 );
		$this->assertTrue( $p['ok'] );
		$this->assertSame( '44:21:00', $p['werte']['zeit'] );
	}

	/* ------------------------------------------------------------------
	 * Spaltenbreiten
	 * --------------------------------------------------------------- */

	/**
	 * ⚠ Ein zu langer Veranstaltungsname wird zurückgewiesen, nicht
	 * abgeschnitten (6.5.5) – und die Meldung sagt, um wie viel.
	 */
	public function test_event_zu_lang_wird_abgewiesen() {
		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'event' => str_repeat( 'A', 40 ) ) ), 2027 );
		$this->assertTrue( $p['ok'], '40 Zeichen passen' );

		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'event' => str_repeat( 'A', 43 ) ) ), 2027 );
		$this->assertFalse( $p['ok'] );
		$this->assertStringContainsString( 'um 3 kürzen', $p['fehler']['event'] );
	}

	/**
	 * „Pforzheim nach Basel" hat genau 20 Zeichen – die Spaltenbreite. Ein
	 * Zeichen mehr geht nicht.
	 */
	public function test_distanz_passt_genau() {
		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'distanz' => 'Pforzheim nach Basel' ) ), 2027 );
		$this->assertTrue( $p['ok'] );

		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'distanz' => 'Pforzheim nach Basell' ) ), 2027 );
		$this->assertFalse( $p['ok'] );
	}

	/* ------------------------------------------------------------------
	 * Das Datum
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider datumsfaelle
	 *
	 * @param string $datum Eingabe.
	 * @param bool   $ok    Erwartung.
	 */
	public function test_datum( $datum, $ok ) {
		$p = lsg_bl_win_formular_pruefen( $this->eingabe( array( 'datum' => $datum ) ), 2026 );
		$this->assertSame( $ok, $p['ok'], $datum );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function datumsfaelle() {
		return array(
			'normal'        => array( '2025-08-01', true ),
			'Schaltjahr'    => array( '2024-02-29', true ),
			'kein Schalttag' => array( '2025-02-29', false ),
			'31. April'     => array( '2025-04-31', false ),
			'Zukunft'       => array( '2028-01-01', false ),
			'Unsinn'        => array( '01.08.2025', false ),
			'leer'          => array( '', false ),
		);
	}

	/* ------------------------------------------------------------------
	 * Die Dublettensperre (12.3)
	 * --------------------------------------------------------------- */

	/**
	 * Athlet + Datum + Veranstaltung, normalisiert.
	 */
	public function test_schluessel_normalisiert() {
		$this->assertSame(
			lsg_bl_win_schluessel( 429, '2025-08-01', 'Ettlinger Halbmarathon' ),
			lsg_bl_win_schluessel( '429', '2025-08-01', '  ETTLINGER halbmarathon ' )
		);
	}

	/**
	 * ⚠ Der Unterschied zu `lsg_best`: mehrere Siege im selben Jahr sind der
	 * Normalfall. Nur derselbe Tag UND dieselbe Veranstaltung ist eine
	 * Dublette.
	 */
	public function test_mehrere_siege_sind_erlaubt() {
		$a = lsg_bl_win_schluessel( 429, '2025-08-01', 'Ettlinger Halbmarathon' );

		$this->assertNotSame( $a, lsg_bl_win_schluessel( 429, '2025-09-11', 'Ettlinger Halbmarathon' ) );
		$this->assertNotSame( $a, lsg_bl_win_schluessel( 429, '2025-08-01', 'Anderer Lauf' ) );
		$this->assertNotSame( $a, lsg_bl_win_schluessel( 430, '2025-08-01', 'Ettlinger Halbmarathon' ) );
	}

	/* ------------------------------------------------------------------
	 * Was sich geändert hat
	 * --------------------------------------------------------------- */

	public function test_kein_diff_ohne_aenderung() {
		$alt = $this->eingabe();
		$this->assertSame( array(), lsg_bl_win_diff( $alt, $alt ) );
	}

	public function test_diff_nennt_die_felder() {
		$alt = $this->eingabe();
		$neu = $this->eingabe(
			array(
				'ort'  => 'Bruchsal',
				'zeit' => '01:18:02',
			)
		);

		$this->assertSame(
			'Ort Ettlingen → Bruchsal, Zeit 01:19:51 → 01:18:02',
			lsg_bl_win_diff_text( lsg_bl_win_diff( $alt, $neu ) )
		);
	}

	/**
	 * Der Athlet wird als Zahl verglichen – „429" gegen 429 ist keine
	 * Änderung.
	 */
	public function test_athlet_vergleicht_als_zahl() {
		$alt = $this->eingabe( array( 'athlet' => '429' ) );
		$neu = $this->eingabe( array( 'athlet' => 429 ) );
		$this->assertSame( array(), lsg_bl_win_diff( $alt, $neu ) );
	}
}
