<?php
/**
 * Die reine Logik der Seite „Sportler" (Plan, Abschnitt 11): Formularprüfung,
 * Dublettenschlüssel, Diff und die Altersklassen, die mit einem geänderten
 * Jahrgang nicht mehr passen.
 *
 * Ohne Datenbank – genau dafür ist class-lsg-athlet-form.php frei von $wpdb.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Sportler_Test extends TestCase {

	/* ------------------------------------------------------------------
	 * Das Formular
	 * --------------------------------------------------------------- */

	/**
	 * Der Normalfall geht durch, und die Werte kommen bereinigt zurück.
	 */
	public function test_gueltige_eingabe() {
		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => '  Müller ',
				'firstname' => ' Peter',
				'born'      => '1976',
				'cat'       => 'm',
				'active'    => '1',
			),
			2026
		);

		$this->assertTrue( $p['ok'] );
		$this->assertSame( array(), $p['fehler'] );
		$this->assertSame( 'Müller', $p['werte']['name'] );
		$this->assertSame( 'Peter', $p['werte']['firstname'] );
		$this->assertSame( 1976, $p['werte']['born'] );
	}

	/**
	 * ⚠ Alle Fehler auf einmal, nicht der erste. Wer drei Felder falsch
	 * ausgefüllt hat, soll das in einem Durchgang erfahren.
	 */
	public function test_alle_fehler_auf_einmal() {
		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => '',
				'firstname' => '',
				'born'      => 0,
			),
			2026
		);

		$this->assertFalse( $p['ok'] );
		$this->assertArrayHasKey( 'name', $p['fehler'] );
		$this->assertArrayHasKey( 'firstname', $p['fehler'] );
		$this->assertArrayHasKey( 'born', $p['fehler'] );
	}

	/**
	 * Der Jahrgang ist Pflicht – ohne ihn findet P3 den Sportler nicht
	 * (6.5.3, 11.2).
	 */
	public function test_jahrgang_ist_pflicht() {
		$leer = lsg_bl_athlet_felder_leer();
		$this->assertSame( 0, $leer['born'] );

		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => 'Müller',
				'firstname' => 'Peter',
				'born'      => 0,
			),
			2026
		);
		$this->assertFalse( $p['ok'] );
		$this->assertArrayHasKey( 'born', $p['fehler'] );
	}

	/**
	 * Grenzen nach unten und nach oben.
	 *
	 * @dataProvider jahrgaenge
	 *
	 * @param int  $born Eingabe.
	 * @param bool $ok   Erwartung.
	 */
	public function test_jahrgang_grenzen( $born, $ok ) {
		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => 'Müller',
				'firstname' => 'Peter',
				'born'      => $born,
			),
			2026
		);
		$this->assertSame( $ok, $p['ok'], (string) $born );
	}

	/**
	 * @return array<string,array{0:int,1:bool}>
	 */
	public function jahrgaenge() {
		return array(
			'zu früh'        => array( 1899, false ),
			'gerade noch'    => array( 1900, true ),
			'ältester echte' => array( 1929, true ),
			'laufendes Jahr' => array( 2026, true ),
			'Zukunft'        => array( 2027, false ),
		);
	}

	/**
	 * Zu lange Namen passen nicht in varchar(30) – gemessen in Zeichen,
	 * nicht in Bytes. „ä" ist ein Zeichen, aber zwei Bytes.
	 */
	public function test_namenslaenge_zaehlt_zeichen() {
		$dreissig = str_repeat( 'ä', 30 );

		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => $dreissig,
				'firstname' => 'Peter',
				'born'      => 1976,
			),
			2026
		);
		$this->assertTrue( $p['ok'], '30 Zeichen passen' );

		$p = lsg_bl_athlet_formular_pruefen(
			array(
				'name'      => $dreissig . 'ä',
				'firstname' => 'Peter',
				'born'      => 1976,
			),
			2026
		);
		$this->assertFalse( $p['ok'], '31 Zeichen passen nicht' );
	}

	/**
	 * Geschlecht und Status kennen zwei Werte, und alles andere wird auf
	 * den Vorgabewert gezogen statt abgelehnt: ein Radiobutton kann nur
	 * durch Manipulation etwas anderes liefern.
	 */
	public function test_zwei_werte_je_schalter() {
		$basis = array(
			'name'      => 'Müller',
			'firstname' => 'Peter',
			'born'      => 1976,
		);

		$p = lsg_bl_athlet_formular_pruefen( array_merge( $basis, array( 'cat' => 'F' ) ), 2026 );
		$this->assertSame( 'f', $p['werte']['cat'] );

		$p = lsg_bl_athlet_formular_pruefen( array_merge( $basis, array( 'cat' => 'x' ) ), 2026 );
		$this->assertSame( 'm', $p['werte']['cat'] );

		$p = lsg_bl_athlet_formular_pruefen( array_merge( $basis, array( 'active' => '0' ) ), 2026 );
		$this->assertSame( '0', $p['werte']['active'] );

		$p = lsg_bl_athlet_formular_pruefen( array_merge( $basis, array( 'active' => 'ja' ) ), 2026 );
		$this->assertSame( '0', $p['werte']['active'] );
	}

	/* ------------------------------------------------------------------
	 * Die Dublettensperre (11.2)
	 * --------------------------------------------------------------- */

	/**
	 * ⚠ Der Schlüssel muss dieselbe Normalisierung fahren wie die Abfrage
	 * in lsg_bl_athlet_dublette(): kleingeschrieben, ohne Randleerzeichen.
	 */
	public function test_schluessel_normalisiert() {
		$this->assertSame(
			lsg_bl_athlet_schluessel( 'Müller', 'Peter', 1976 ),
			lsg_bl_athlet_schluessel( '  müller ', 'PETER', '1976' )
		);
	}

	/**
	 * Gleicher Name, anderer Jahrgang: zwei Personen. So steht „Becker,
	 * Klaus" (1963 und 1969) im Bestand, und so ist es richtig.
	 */
	public function test_gleicher_name_anderer_jahrgang() {
		$this->assertNotSame(
			lsg_bl_athlet_schluessel( 'Becker', 'Klaus', 1963 ),
			lsg_bl_athlet_schluessel( 'Becker', 'Klaus', 1969 )
		);
	}

	/* ------------------------------------------------------------------
	 * Was sich geändert hat
	 * --------------------------------------------------------------- */

	/**
	 * Ohne Änderung kein Diff – und damit kein Schreibvorgang und keine
	 * Log-Zeile (11.4, wie 7.5).
	 */
	public function test_kein_diff_ohne_aenderung() {
		$alt = array(
			'name'      => 'Müller',
			'firstname' => 'Peter',
			'born'      => '1976',
			'cat'       => 'm',
			'active'    => '1',
		);
		$this->assertSame( array(), lsg_bl_athlet_diff( $alt, $alt ) );
	}

	/**
	 * Der Diff nennt Klartext, keine Datenbankwerte: „ehemalig", nicht „0".
	 */
	public function test_diff_spricht_klartext() {
		$alt = array(
			'name'      => 'Müller',
			'firstname' => 'Peter',
			'born'      => '1976',
			'cat'       => 'm',
			'active'    => '1',
		);
		$neu = array_merge( $alt, array( 'born' => 1975, 'active' => '0' ) );

		$text = lsg_bl_athlet_diff_text( lsg_bl_athlet_diff( $alt, $neu ) );
		$this->assertSame( 'Jahrgang 1976 → 1975, Status aktiv → ehemalig', $text );
	}

	/**
	 * `born` wird als Zahl verglichen, nicht als String – sonst wäre
	 * „1976" gegen 1976 eine Änderung.
	 */
	public function test_jahrgang_vergleicht_als_zahl() {
		$alt = array(
			'name'      => 'Müller',
			'firstname' => 'Peter',
			'born'      => '1976',
			'cat'       => 'm',
			'active'    => '1',
		);
		$neu = array_merge( $alt, array( 'born' => 1976 ) );
		$this->assertSame( array(), lsg_bl_athlet_diff( $alt, $neu ) );
	}

	/* ------------------------------------------------------------------
	 * Die Altersklassen ziehen mit (11.2)
	 * --------------------------------------------------------------- */

	/**
	 * Der Anlass für den ganzen Abschnitt: ein geänderter Jahrgang macht
	 * jede gespeicherte Altersklasse dieses Athleten falsch.
	 */
	public function test_abweichungen_nach_jahrgangswechsel() {
		$zeilen = array(
			array( 'id' => 1, 'jahr' => 2019, 'distance' => '10km', 'time' => '00:48:30', 'ak' => 'mhk' ),
			array( 'id' => 2, 'jahr' => 2024, 'distance' => 'HM', 'time' => '01:38:12', 'ak' => 'mhk' ),
		);

		$abw = lsg_bl_athlet_ak_abweichungen( $zeilen, 1965, 'm' );

		$this->assertCount( 2, $abw );
		$this->assertSame( 'mhk', $abw[0]['ak_alt'] );
		$this->assertSame( 'm50', $abw[0]['ak_neu'] );
		$this->assertSame( 'm55', $abw[1]['ak_neu'] );
		$this->assertSame( 1, $abw[0]['id'] );
	}

	/**
	 * Passt schon alles, kommt nichts zurück – der Kasten erscheint dann
	 * gar nicht.
	 */
	public function test_keine_abweichung_keine_liste() {
		$zeilen = array(
			array( 'id' => 1, 'jahr' => 2019, 'distance' => '10km', 'time' => '00:48:30', 'ak' => 'm50' ),
		);
		$this->assertSame( array(), lsg_bl_athlet_ak_abweichungen( $zeilen, 1965, 'm' ) );
	}

	/**
	 * Groß-/Kleinschreibung im Bestand ist keine Abweichung. `lsg_best.ak`
	 * ist gewachsen, und „M50" gegen „m50" wäre ein Unterschied, den
	 * niemand sehen will.
	 */
	public function test_schreibweise_ist_keine_abweichung() {
		$zeilen = array(
			array( 'id' => 1, 'jahr' => 2019, 'distance' => '10km', 'time' => '00:48:30', 'ak' => 'M50' ),
		);
		$this->assertSame( array(), lsg_bl_athlet_ak_abweichungen( $zeilen, 1965, 'm' ) );
	}

	/**
	 * Das Geschlecht wechselt das m/w mit – dieselbe Rechnung, anderer
	 * Buchstabe.
	 */
	public function test_geschlecht_wechselt_mit() {
		$zeilen = array(
			array( 'id' => 1, 'jahr' => 2019, 'distance' => '10km', 'time' => '00:48:30', 'ak' => 'm50' ),
		);
		$abw = lsg_bl_athlet_ak_abweichungen( $zeilen, 1965, 'f' );
		$this->assertCount( 1, $abw );
		$this->assertSame( 'w50', $abw[0]['ak_neu'] );
	}

	/**
	 * ⚠ Ohne Veranstaltungsjahr wird nichts gerechnet und nichts gemeldet.
	 * Ein leeres `ak` ist ehrlicher als ein geratenes – und im Bestand gibt
	 * es Zeilen ohne brauchbares Datum (9.3).
	 */
	public function test_ohne_jahr_wird_nichts_gerechnet() {
		$zeilen = array(
			array( 'id' => 1, 'jahr' => 0, 'distance' => '10km', 'time' => '00:48:30', 'ak' => 'mhk' ),
		);
		$this->assertSame( array(), lsg_bl_athlet_ak_abweichungen( $zeilen, 1965, 'm' ) );
	}
}
