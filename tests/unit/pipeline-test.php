<?php
/**
 * Die Pipeline-Schicht: P2, Trichter, Vorbelegung der drei Felder,
 * Zustandslogik und der Fingerabdruck der Vorschau.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Pipeline_Test extends TestCase {

	/** @var LSG_BL_Ergebnis[] */
	private static $liste;

	public static function setUpBeforeClass(): void {
		$p1          = LSG_BL_RaceResult_Adapter::parse_liste( lsg_bl_fixture( 'raceresult-375768-contest2.json' ) );
		self::$liste = $p1['zeilen'];
	}

	/**
	 * Eine Ergebniszeile bauen.
	 *
	 * @param string $verein Vereinsfeld.
	 * @param string $platz  Gesamtplatz.
	 * @return LSG_BL_Ergebnis
	 */
	private function zeile( $verein, $platz = '' ) {
		$e         = new LSG_BL_Ergebnis();
		$e->verein = $verein;
		$e->platz  = $platz;
		$e->zeit   = '01:00:00';
		return $e;
	}

	/* ------------------------------------------------------------------
	 * P2
	 * --------------------------------------------------------------- */

	public function test_p2_auf_der_echten_liste() {
		$p2 = lsg_bl_p2_filtern( self::$liste );

		$this->assertCount( 11, $p2['lsg'] );

		// Die ausgefilterten Schreibweisen kommen mit Häufigkeit zurück –
		// das ist die Sicherung gegen den stillen Fehler.
		$this->assertSame( 25, $p2['abgelehnt']['LSG Weiher'] );
		$this->assertSame( 646, $p2['anzahl_abgelehnt'] );
		$this->assertSame( 657, count( $p2['lsg'] ) + $p2['anzahl_abgelehnt'] );

		// „LSG Weiher" enthält lsg → steht ganz oben, weil dort am
		// wahrscheinlichsten ein Alias fehlt.
		$this->assertContains( 'LSG Weiher', $p2['nahe'] );
		$this->assertSame( 'LSG Weiher', array_key_first( $p2['abgelehnt'] ) );

		// ⚠ Ein Wohnort in Klammern ist keine verpasste Vereinsschreibweise,
		// sondern gar keine – er wird gezählt, aber nicht markiert. Sonst
		// dränge die häufige `(Ort)`-Form das eine `LSG Ka.` nach unten.
		$this->assertArrayHasKey( '(Karlsruhe)', $p2['abgelehnt'] );
		$this->assertNotContains( '(Karlsruhe)', $p2['nahe'] );

		// Der eigene Verband ohne „LSG" im Namen wird dagegen markiert – das
		// ist genau die Frage, die dieser Block stellen soll.
		$this->assertContains( 'Karlsruher Lemminge e.V.', $p2['nahe'] );
	}

	public function test_p2_zaehlt_zeilen_ohne_verein() {
		$p2 = lsg_bl_p2_filtern(
			array(
				$this->zeile( 'LSG Karlsruhe' ),
				$this->zeile( '' ),
				$this->zeile( '   ' ),
			)
		);

		$this->assertCount( 1, $p2['lsg'] );
		// Ein Mitglied, das ohne Verein gemeldet war, darf nicht unsichtbar
		// verschwinden (Plan 6.5.2).
		$this->assertSame( 2, $p2['abgelehnt']['(kein Verein)'] );
		$this->assertNotContains( '(kein Verein)', $p2['nahe'] );
	}

	public function test_p2_mit_alias() {
		$zeilen = array(
			$this->zeile( 'LSG Karlsruhe' ),
			$this->zeile( 'LSG Ka.' ),
			$this->zeile( 'LSG Weiher' ),
		);

		$ohne = lsg_bl_p2_filtern( $zeilen );
		$this->assertCount( 1, $ohne['lsg'] );

		$mit = lsg_bl_p2_filtern( $zeilen, array( lsg_bl_verein_normalisieren( 'LSG Ka.' ) ) );
		$this->assertCount( 2, $mit['lsg'] );
		// Ein Alias oeffnet nicht die Schleusen fuer andere Schreibweisen.
		$this->assertArrayHasKey( 'LSG Weiher', $mit['abgelehnt'] );
	}

	/**
	 * @dataProvider naehen
	 *
	 * @param string $verein   Schreibweise.
	 * @param int    $erwartet Stufe.
	 */
	public function test_verein_naehe( $verein, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_verein_naehe( $verein ), 'Verein: ' . $verein );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public function naehen() {
		return array(
			'LSG Ka.'               => array( 'LSG Ka.', 2 ),
			'LSG KA'                => array( 'LSG KA', 2 ),
			'LSG Weiher'            => array( 'LSG Weiher', 2 ),
			'eigener Verband'       => array( 'Karlsruher Lemminge e.V.', 1 ),
			'anderer Verein am Ort' => array( 'LG Region Karlsruhe', 1 ),
			'Wohnort in Klammern'   => array( '(Karlsruhe)', 0 ),
			'fremder Verein'        => array( 'TV Bad Säckingen', 0 ),
			'leer'                  => array( '', 0 ),
		);
	}

	/* ------------------------------------------------------------------
	 * Trichter
	 * --------------------------------------------------------------- */

	public function test_trichter_zeigt_nur_gelaufene_stufen() {
		$t              = lsg_bl_trichter_leer();
		$t['gelesen']   = 658;
		$t['verworfen'] = 1;
		$t['lsg']       = 11;

		$stufen = lsg_bl_trichter_stufen( $t );
		$keys   = array_column( $stufen, 'key' );

		$this->assertSame( array( 'gelesen', 'verworfen', 'lsg' ), $keys );

		// Die Phase steuert die Darstellung: Pfeil zwischen Phasen, Komma
		// innerhalb. „7 neu → 1 schneller" waere falsch – das sind
		// Geschwister, keine Stufen.
		$this->assertSame( array( 1, 1, 2 ), array_column( $stufen, 'phase' ) );

		// P3 und P4 sind null – „noch nicht gelaufen" darf nicht wie ein
		// Nulltreffer aussehen.
		$this->assertNotContains( 'zugeordnet', $keys );
		$this->assertNotContains( 'neu', $keys );
	}

	public function test_trichter_phasen() {
		$t = lsg_bl_trichter_leer();
		foreach ( array( 'gelesen' => 658, 'verworfen' => 1, 'lsg' => 11, 'zugeordnet' => 10, 'offen' => 1, 'neu' => 7, 'schneller' => 1, 'langsamer' => 1, 'gleich' => 1 ) as $k => $v ) {
			$t[ $k ] = $v;
		}

		$stufen = lsg_bl_trichter_stufen( $t );

		$this->assertSame(
			array( 'gelesen', 'verworfen', 'lsg', 'zugeordnet', 'offen', 'neu', 'schneller', 'langsamer', 'gleich' ),
			array_column( $stufen, 'key' )
		);
		$this->assertSame( array( 1, 1, 2, 3, 3, 4, 4, 4, 4 ), array_column( $stufen, 'phase' ) );
	}

	public function test_trichter_null_ist_nicht_null_treffer() {
		$t            = lsg_bl_trichter_leer();
		$t['gelesen'] = 400;
		$t['lsg']     = 0;

		$keys = array_column( lsg_bl_trichter_stufen( $t ), 'key' );

		// Ein LSG-Wert von 0 MUSS sichtbar sein – genau daran erkennt man,
		// dass der Vereinsfilter nicht greift (Plan 6.5).
		$this->assertContains( 'lsg', $keys );
		// Eine verworfene Zeile gab es nicht, also erklaert die Null nichts.
		$this->assertNotContains( 'verworfen', $keys );
	}

	/* ------------------------------------------------------------------
	 * Zeitläufe
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider zeitlaeufe
	 *
	 * @param string $name     Wettbewerbsname.
	 * @param bool   $erwartet Zeitlauf?
	 */
	public function test_zeitlauf_erkennen( $name, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_ist_zeitlauf_name( $name ), 'Name: ' . $name );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function zeitlaeufe() {
		return array(
			'6h'                => array( '6h Lauf Karlsruhe', true ),
			'12 Stunden'        => array( '12 Stunden Ultralauf', true ),
			'24h'               => array( '24h Rennen', true ),
			'24 std'            => array( '24 Std. Staffellauf', true ),
			'Stundenlauf'       => array( 'Stundenlauf Pfinztal', true ),
			'Halbmarathon'      => array( 'Hauptlauf 21,1km', false ),
			'10 km'             => array( '10 KM Linhardt-Lauf', false ),
			'Uhrzeit im Namen'  => array( 'Team-Staffel Start 14:15 Uhr', false ),
			'leer'              => array( '', false ),
		);
	}

	/* ------------------------------------------------------------------
	 * Ort
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider orte
	 *
	 * @param string $event    Veranstaltungsname.
	 * @param string $erwartet Erwarteter Ort.
	 */
	public function test_ort_aus_eventname( $event, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_ort_aus_eventname( $event ), 'Event: ' . $event );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function orte() {
		return array(
			'race result'      => array( '17. SWE Halbmarathon Ettlingen', 'Ettlingen' ),
			'mit Komma'        => array( 'Silvesterlauf, Bruchsal', 'Bruchsal' ),
			// Endet der Name auf ein Laufwort, bleibt das Feld leer – lieber
			// nichts als ein Ort, der keiner ist.
			'Runtix'           => array( '19. Hambrücker Lußhardtlauf', '' ),
			'Marathon am Ende' => array( 'Badischer Marathon', '' ),
			'nur Jahreszahl'   => array( 'Volkslauf 2026', '' ),
			'leer'             => array( '', '' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Vorbelegung
	 * --------------------------------------------------------------- */

	/**
	 * Discovery-Daten wie sie der Cache hält – ohne WordPress gebaut.
	 *
	 * @return array
	 */
	private function discovery() {
		return array(
			'adapter'       => 'raceresult',
			'adapter_cls'   => 'LSG_BL_RaceResult_Adapter',
			'adapter_label' => 'race result',
			'event_id'      => '375768',
			'event_name'    => '17. SWE Halbmarathon Ettlingen',
			'url'           => 'https://my.raceresult.com/375768/',
			'contest_id'    => '2',
			'list_id'       => 'B45FAB',
			'contests'      => array(
				array(
					'id'   => '1',
					'name' => 'Walking 21,1km',
				),
				array(
					'id'   => '2',
					'name' => 'Hauptlauf 21,1km',
				),
				array(
					'id'   => '8',
					'name' => 'Bambini 500m (<2019)',
				),
				array(
					'id'   => '99',
					'name' => '12 Stunden Ultralauf',
				),
			),
			'lists'         => array(),
			'datum'         => array(
				'datum'   => '',
				'quelle'  => '',
				'hinweis' => 'Die Quelle nennt kein Datum – bitte eintragen.',
			),
		);
	}

	public function test_vorbelegung_halbmarathon() {
		$v = lsg_bl_import_vorbelegung( $this->discovery(), '2' );

		$this->assertSame( 'Hauptlauf 21,1km', $v['contest_name'] );
		$this->assertSame( 'HM', $v['distanz'] );
		$this->assertSame( 'Ettlingen', $v['ort'] );
		$this->assertFalse( $v['zeitlauf'] );

		// race result nennt kein Datum – das Feld bleibt leer, mit Hinweis.
		$this->assertSame( '', $v['datum'] );
		$this->assertStringContainsString( 'kein Datum', $v['datum_hinweis'] );
	}

	public function test_vorbelegung_walking_schlaegt_auch_hm_vor() {
		// Beide Wettbewerbe heissen „21,1km" – am Distanzwert allein sind sie
		// nicht zu unterscheiden. Genau darum bleibt das Dropdown sichtbar
		// und aenderbar (Plan 6.5.1).
		$v = lsg_bl_import_vorbelegung( $this->discovery(), '1' );
		$this->assertSame( 'HM', $v['distanz'] );
	}

	public function test_vorbelegung_bambini_bleibt_leer() {
		$v = lsg_bl_import_vorbelegung( $this->discovery(), '8' );
		$this->assertSame( '', $v['distanz'] );
		$this->assertFalse( $v['zeitlauf'] );
	}

	public function test_vorbelegung_zeitlauf_wird_benannt() {
		$v = lsg_bl_import_vorbelegung( $this->discovery(), '99' );

		$this->assertTrue( $v['zeitlauf'] );
		// Auch wenn eine Distanz abzuleiten waere: das Select bietet keine
		// Zeitlauf-Codes an, also bleibt das Feld leer.
		$this->assertSame( '', $v['distanz'] );
	}

	public function test_vorbelegung_nennt_nie_eine_distanz_ausserhalb_des_selects() {
		$erlaubt = lsg_bl_import_distanzen();
		$disc    = $this->discovery();

		foreach ( $disc['contests'] as $c ) {
			$v = lsg_bl_import_vorbelegung( $disc, $c['id'] );
			if ( '' !== $v['distanz'] ) {
				$this->assertContains( $v['distanz'], $erlaubt, 'Wettbewerb: ' . $c['name'] );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Was fehlt noch?
	 * --------------------------------------------------------------- */

	public function test_parsen_bleibt_gesperrt_bis_beides_steht() {
		$this->assertStringContainsString( 'die Distanz', lsg_bl_import_was_fehlt( '', '2026-05-17' ) );
		$this->assertStringContainsString( 'Veranstaltungsdatum', lsg_bl_import_was_fehlt( 'HM', '' ) );

		// Beide fehlen → beide werden genannt, damit die Meldung sagt, welcher
		// Wert fehlt (Plan 6.5.1).
		$beides = lsg_bl_import_was_fehlt( '', '' );
		$this->assertStringContainsString( 'die Distanz', $beides );
		$this->assertStringContainsString( 'Veranstaltungsdatum', $beides );

		// Nur das Jahr ist kein vollstaendiges Datum.
		$this->assertNotSame( '', lsg_bl_import_was_fehlt( 'HM', '2026' ) );

		$this->assertSame( '', lsg_bl_import_was_fehlt( 'HM', '2026-05-17' ) );
	}

	/* ------------------------------------------------------------------
	 * Plausibilität
	 * --------------------------------------------------------------- */

	public function test_datum_hinweise() {
		$jetzt = mktime( 12, 0, 0, 9, 1, 2026 );

		$this->assertSame( array(), lsg_bl_datum_hinweise( '2026-05-17', '17. SWE Halbmarathon Ettlingen', $jetzt ) );

		$zukunft = lsg_bl_datum_hinweise( '2027-05-17', '', $jetzt );
		$this->assertStringContainsString( 'Zukunft', $zukunft[0] );

		$alt = lsg_bl_datum_hinweise( '2010-05-17', '', $jetzt );
		$this->assertStringContainsString( 'zehn Jahre', $alt[0] );

		// Weicht das Jahr von der Jahreszahl im Namen ab, werden beide
		// gezeigt – ohne zu entscheiden, welche stimmt.
		$abweichung = lsg_bl_datum_hinweise( '2026-05-17', 'Halbmarathon Ettlingen 2025', $jetzt );
		$this->assertCount( 1, $abweichung );
		$this->assertStringContainsString( '2025', $abweichung[0] );
		$this->assertStringContainsString( '2026', $abweichung[0] );

		// Ohne Datum keine Hinweise.
		$this->assertSame( array(), lsg_bl_datum_hinweise( '', 'irgendwas', $jetzt ) );
	}

	/* ------------------------------------------------------------------
	 * Zustände
	 * --------------------------------------------------------------- */

	public function test_zustaende_sind_vollstaendig() {
		$alle = lsg_bl_import_zustaende();

		// Alle elf aus Plan 6.11 – ein Zustand, der nicht dargestellt ist,
		// wird spaeter als Bug gemeldet.
		$this->assertCount( 11, $alle );
		foreach ( array( 'leer', 'erkenne', 'unbekannt', 'erkannt', 'bereit', 'parse', 'vorschau', 'uebernahme', 'gespeichert', 'teilfehler', 'fehler' ) as $k ) {
			$this->assertArrayHasKey( $k, $alle );
			$this->assertNotSame( '', $alle[ $k ] );
		}
	}

	/**
	 * @dataProvider zustaende
	 *
	 * @param array  $ctx      Kontext.
	 * @param string $erwartet Erwarteter Zustand.
	 */
	public function test_zustand( array $ctx, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_import_zustand( $ctx ) );
	}

	/**
	 * @return array<string,array>
	 */
	public function zustaende() {
		$basis = array(
			'url'         => 'https://my.raceresult.com/375768/',
			'adapter_cls' => 'LSG_BL_RaceResult_Adapter',
			'fehler'      => '',
			'contest_id'  => '',
			'distanz'     => '',
			'datum'       => '',
			'vorschau'    => null,
		);

		return array(
			'ohne URL'            => array( array_merge( $basis, array( 'url' => '' ) ), 'leer' ),
			'ohne Adapter'        => array( array_merge( $basis, array( 'adapter_cls' => null ) ), 'unbekannt' ),
			'Fehler schlaegt alles' => array( array_merge( $basis, array( 'fehler' => 'kaputt' ) ), 'fehler' ),
			'ohne Wettbewerb'     => array( $basis, 'erkannt' ),
			'ohne Distanz'        => array( array_merge( $basis, array( 'contest_id' => '2', 'datum' => '2026-05-17' ) ), 'erkannt' ),
			'ohne Datum'          => array( array_merge( $basis, array( 'contest_id' => '2', 'distanz' => 'HM' ) ), 'erkannt' ),
			'bereit'              => array( array_merge( $basis, array( 'contest_id' => '2', 'distanz' => 'HM', 'datum' => '2026-05-17' ) ), 'bereit' ),
			'Vorschau'            => array( array_merge( $basis, array( 'contest_id' => '2', 'distanz' => 'HM', 'datum' => '2026-05-17', 'vorschau' => array( 'x' ) ) ), 'vorschau' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Fingerabdruck der Vorschau
	 * --------------------------------------------------------------- */

	public function test_fingerprint_verwirft_bei_datum_und_distanz() {
		$basis = array(
			'adapter'    => 'raceresult',
			'event_id'   => '375768',
			'contest_id' => '2',
			'list_id'    => 'B45FAB',
			'distanz'    => 'HM',
			'datum'      => '2026-05-17',
		);
		$fp = lsg_bl_import_fingerprint( $basis );

		$this->assertSame( $fp, lsg_bl_import_fingerprint( $basis ) );

		// Beide gehen in P4 ein: die Distanz in die Suche nach dem Bestand,
		// das Datum in das Jahr. Aendert sich einer, ist die Tabelle ungueltig.
		$this->assertNotSame( $fp, lsg_bl_import_fingerprint( array_merge( $basis, array( 'distanz' => '10km' ) ) ) );
		$this->assertNotSame( $fp, lsg_bl_import_fingerprint( array_merge( $basis, array( 'datum' => '2026-05-18' ) ) ) );
		$this->assertNotSame( $fp, lsg_bl_import_fingerprint( array_merge( $basis, array( 'contest_id' => '1' ) ) ) );
		$this->assertNotSame( $fp, lsg_bl_import_fingerprint( array_merge( $basis, array( 'list_id' => '4A3BBD' ) ) ) );
	}

	public function test_fingerprint_ignoriert_den_ort() {
		$basis = array(
			'adapter'    => 'raceresult',
			'event_id'   => '375768',
			'contest_id' => '2',
			'list_id'    => 'B45FAB',
			'distanz'    => 'HM',
			'datum'      => '2026-05-17',
		);

		// Der Ort landet in lsg_best.town, geht aber in keinen Vergleich ein.
		// Ihn zu aendern darf keine Tabelle wegwerfen.
		$this->assertSame(
			lsg_bl_import_fingerprint( $basis ),
			lsg_bl_import_fingerprint( array_merge( $basis, array( 'ort' => 'Ettlingen' ) ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Gesamtsieg
	 * --------------------------------------------------------------- */

	public function test_gesamtsieg_nur_in_der_gesamtwertung() {
		$sieger = array( 'platz' => '1' );
		$zweite = array( 'platz' => '2' );

		$this->assertTrue( lsg_bl_ist_gesamtsieg( $sieger, true ) );
		$this->assertFalse( lsg_bl_ist_gesamtsieg( $zweite, true ) );

		// In einer nach Geschlecht oder Altersklasse gefilterten Liste ist
		// Platz 1 ein Klassensieg – ein falsch gemeldeter Sieg waere
		// aergerlicher als ein uebersehener (Plan 6.5.5).
		$this->assertFalse( lsg_bl_ist_gesamtsieg( $sieger, false ) );

		$this->assertFalse( lsg_bl_ist_gesamtsieg( array(), true ) );
	}

	/**
	 * In der echten Ettlinger Liste hat kein LSG-Läufer gewonnen – Platz 6 ist
	 * der beste, und der gehört zu LSG Weiher. Der Test hält fest, dass die
	 * Erkennung deshalb auch nichts meldet.
	 */
	public function test_kein_gesamtsieg_in_der_echten_liste() {
		$p2 = lsg_bl_p2_filtern( self::$liste );

		$siege = 0;
		foreach ( $p2['lsg'] as $e ) {
			if ( lsg_bl_ist_gesamtsieg( $e->to_array(), true ) ) {
				++$siege;
			}
		}
		$this->assertSame( 0, $siege );

		// Der Gesamtsieger der Liste ist aber sehr wohl als solcher erkennbar.
		$this->assertTrue( lsg_bl_ist_gesamtsieg( self::$liste[0]->to_array(), true ) );
	}
}
