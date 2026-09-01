<?php
/**
 * Namens-Splitter und Vereinsfilter (P1 und P2).
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Namen_Test extends TestCase {

	/**
	 * @dataProvider namen
	 *
	 * @param string $roh      Teilnehmerstring der Quelle.
	 * @param string $nachname Erwarteter Nachname.
	 * @param string $vorname  Erwarteter Vorname.
	 * @param bool   $unsicher Erwartete Markierung.
	 */
	public function test_splitten( $roh, $nachname, $vorname, $unsicher ) {
		$s = lsg_bl_name_splitten( $roh );
		$this->assertSame( $nachname, $s['nachname'], 'Nachname aus: ' . $roh );
		$this->assertSame( $vorname, $s['vorname'], 'Vorname aus: ' . $roh );
		$this->assertSame( $unsicher, $s['unsicher'], 'Markierung aus: ' . $roh );
	}

	/**
	 * @return array<string,array>
	 */
	public function namen() {
		return array(
			// Regel 1: Komma. Eindeutig.
			'Runtix, Komma'          => array( 'Körner, Holger', 'Körner', 'Holger', false ),
			'Komma ohne Leerzeichen' => array( 'Körner,Holger', 'Körner', 'Holger', false ),

			// Regel 2: führender GROSSBUCHSTABEN-Block.
			'race result'            => array( 'BORGHARDT Lukas', 'BORGHARDT', 'Lukas', false ),
			'Namenspartikel gross'   => array( 'VON HOFF Anna-Maria', 'VON HOFF', 'Anna-Maria', false ),
			'drei Partikel'          => array( 'VAN DER BERG Jan-Peter', 'VAN DER BERG', 'Jan-Peter', false ),
			'Bindestrich-Nachname'   => array( 'VAN WEES-SNEL Trees', 'VAN WEES-SNEL', 'Trees', false ),

			// ⚠ Das scharfe ß hat keine Grossform, die die Quellen benutzen.
			// Ohne die ß→SS-Auflösung in lsg_bl_wort_ist_gross() fiele diese
			// Zeile in Regel 3 und käme als „unsicher" heraus.
			'ss im Nachnamen'        => array( 'GEIßLER Franziska', 'GEIßLER', 'Franziska', false ),
			'ss und Umlaut'          => array( 'STÖßER Vivien', 'STÖßER', 'Vivien', false ),
			'Umlaut gross'           => array( 'KÜHN Simon', 'KÜHN', 'Simon', false ),
			'Umlaut A gross'         => array( 'HÄFFNER Nico', 'HÄFFNER', 'Nico', false ),

			// Regel 3: raten – und das auch sagen.
			// Kleingeschriebene Partikel: Regel 2 greift nicht, Regel 3 rät –
			// hier zufällig richtig, aber die Zeile wird trotzdem markiert.
			'Partikel klein'         => array( 'von Hoff Anna-Maria', 'von Hoff', 'Anna-Maria', true ),
			'drei Worte gemischt'    => array( 'Meier Klaus Peter', 'Meier Klaus', 'Peter', true ),
			'einteiliger Name'       => array( 'Müller', 'Müller', '', true ),
			'alles gross'            => array( 'MUELLER PETER', 'MUELLER', 'PETER', true ),
			'leer'                   => array( '', '', '', true ),
		);
	}

	public function test_mehrfache_leerzeichen_werden_zusammengezogen() {
		$s = lsg_bl_name_splitten( "BORGHARDT   Lukas\t" );
		$this->assertSame( 'BORGHARDT', $s['nachname'] );
		$this->assertSame( 'Lukas', $s['vorname'] );
	}

	/**
	 * @dataProvider vereine
	 *
	 * @param string $verein   Vereinsfeld der Quelle.
	 * @param bool   $erwartet Trifft der Filter?
	 */
	public function test_vereinsfilter( $verein, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_ist_lsg( $verein ), 'Verein: ' . $verein );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function vereine() {
		return array(
			// LSG UND Karlsruhe – beides muss drin sein.
			'Grundform'            => array( 'LSG Karlsruhe', true ),
			'Bindestrich'          => array( 'LSG-Karlsruhe', true ),
			'klein mit e.V.'       => array( 'lsg karlsruhe e.V.', true ),
			'mit Zusatz'           => array( 'LSG Karlsruhe/Lemminge', true ),
			'umgedreht'            => array( 'Karlsruhe LSG', true ),

			// Bewusst NICHT getroffen.
			'anderer Verein'       => array( 'LG Region Karlsruhe', false ),
			'anderer LSG-Standort' => array( 'LSG Weiher', false ),
			'nur Wohnort'          => array( '(Karlsruhe)', false ),
			'Wohnort ausgeschrieben' => array( 'Karlsruhe', false ),
			'gleicher Ort, anderer Verein' => array( 'Karlsruher Lemminge e.V.', false ),
			'kein Verein'          => array( '', false ),
			'nur Leerzeichen'      => array( '   ', false ),
		);
	}

	public function test_vereins_alias_greift() {
		$alias = array( lsg_bl_verein_normalisieren( 'LSG Ka.' ) );

		$this->assertFalse( lsg_bl_ist_lsg( 'LSG Ka.' ) );
		$this->assertTrue( lsg_bl_ist_lsg( 'LSG Ka.', $alias ) );
		// Ein Alias öffnet nicht die Schleusen für andere Schreibweisen.
		$this->assertFalse( lsg_bl_ist_lsg( 'LSG Weiher', $alias ) );
	}

	/**
	 * Basis für die Athletenzuordnung in P3: Umlaute, Bindestriche und
	 * Groß-/Kleinschreibung fallen zusammen.
	 */
	public function test_normalisierung_fuer_die_zuordnung() {
		$this->assertSame(
			lsg_bl_text_normalisieren( 'Körner' ),
			lsg_bl_text_normalisieren( 'Koerner' )
		);
		$this->assertSame(
			lsg_bl_text_normalisieren( 'Anna-Maria' ),
			lsg_bl_text_normalisieren( 'Anna Maria' )
		);
		$this->assertSame(
			lsg_bl_text_normalisieren( 'MÜLLER' ),
			lsg_bl_text_normalisieren( 'Müller' )
		);
		$this->assertSame(
			lsg_bl_text_normalisieren( 'Schlippe-Schrieber' ),
			'schlippe schrieber'
		);
		// Und zwei verschiedene Namen bleiben verschieden.
		$this->assertNotSame(
			lsg_bl_text_normalisieren( 'Weber' ),
			lsg_bl_text_normalisieren( 'Weiber' )
		);
	}
}
