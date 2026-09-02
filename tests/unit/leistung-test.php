<?php
/**
 * Die reine Logik der Seite „Bestenliste" (Plan, Abschnitt 7):
 * Leistungsfeld, Streckenprüfung, Jahresbestzeit-Prüfung, Formular,
 * Zuordnungsregeln.
 *
 * Ohne Datenbank – genau dafür ist class-lsg-leistung.php frei von $wpdb.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Leistung_Test extends TestCase {

	/* ------------------------------------------------------------------
	 * Das Leistungsfeld wechselt mit der Distanz
	 * --------------------------------------------------------------- */

	/**
	 * Der eigentliche Grund, warum diese Seite die Zeitläufe kann und der
	 * Import nicht: bei 6h/12h/24h hält lsg_best.time eine Strecke.
	 */
	public function test_feld_folgt_der_distanz() {
		$zeit = lsg_bl_leistung_feld( 'HM' );
		$this->assertSame( 'time', $zeit['typ'] );
		$this->assertSame( 'Zeit', $zeit['label'] );
		$this->assertSame( 'schneller', $zeit['wort'] );

		$strecke = lsg_bl_leistung_feld( '12h' );
		$this->assertSame( 'distance', $strecke['typ'] );
		$this->assertSame( 'Strecke', $strecke['label'] );
		$this->assertSame( 'weiter', $strecke['wort'] );

		// Ein stehengebliebenes „01:36:44" unter „Strecke" wäre die
		// naheliegendste Fehleingabe überhaupt – die Muster dürfen sich
		// deshalb nicht überschneiden.
		$this->assertNotSame( $zeit['pattern'], $strecke['pattern'] );
	}

	/**
	 * Alle drei Zeitläufe, nicht nur der eine, an den man denkt.
	 *
	 * @dataProvider zeitlaeufe
	 *
	 * @param string $code Distanzcode.
	 */
	public function test_alle_zeitlaeufe_sind_strecken( $code ) {
		$this->assertSame( 'distance', lsg_bl_leistung_feld( $code )['typ'], $code );
		$this->assertSame( 'weiter', lsg_bl_besser_wort( $code ), $code );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function zeitlaeufe() {
		return array(
			'6 Stunden'  => array( '6h' ),
			'12 Stunden' => array( '12h' ),
			'24 Stunden' => array( '24h' ),
		);
	}

	/**
	 * „112,737 km ist schneller" wäre schlicht falsch.
	 */
	public function test_vergleichswort() {
		$this->assertSame( 'schneller', lsg_bl_besser_wort( 'HM', true ) );
		$this->assertSame( 'langsamer', lsg_bl_besser_wort( 'HM', false ) );
		$this->assertSame( 'weiter', lsg_bl_besser_wort( '24h', true ) );
		$this->assertSame( 'kürzer', lsg_bl_besser_wort( '24h', false ) );
	}

	/* ------------------------------------------------------------------
	 * Zeiten – dieselbe Normalisierung wie P1
	 * --------------------------------------------------------------- */

	/**
	 * ⚠ Keine zweite Implementierung (Plan 7.2). Wenn der Import und das
	 * Formular verschieden normalisieren, stehen in derselben Spalte zwei
	 * Schreibweisen – und die Sortierung wird unerklärlich.
	 *
	 * @dataProvider zeiten
	 *
	 * @param string $roh      Eingabe.
	 * @param string $erwartet Erwarteter Wert, '' = abgelehnt.
	 */
	public function test_zeit_wie_beim_import( $roh, $erwartet ) {
		$r = lsg_bl_leistung_lesen( 'HM', $roh );

		$this->assertSame( $erwartet, $r['wert'], 'Eingabe: ' . $roh );

		if ( '' === $erwartet ) {
			$this->assertNotSame( '', $r['fehler'], 'Ablehnung ohne Begründung: ' . $roh );
			// Die Meldung geht so, wie sie ist, ans Feld – sie muss ein Satz
			// für Menschen sein.
			$this->assertGreaterThan( 20, mb_strlen( $r['fehler'] ), $roh );
		} else {
			$this->assertSame( '', $r['fehler'], $roh );
			// Und sie muss identisch sein mit dem, was P1 liefert.
			$this->assertSame( lsg_bl_zeit_normalisieren( $roh ), $r['wert'], $roh );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function zeiten() {
		return array(
			'vollständig'        => array( '01:36:44', '01:36:44' ),
			'führende Null weg'  => array( '1:13:08', '01:13:08' ),
			'ohne Stunde'        => array( '38:57', '00:38:57' ),
			'Zehntel aufrunden'  => array( '01:11:54.9', '01:11:55' ),
			'Zehntel .0'         => array( '01:11:54.0', '01:11:54' ),
			// ⚠ Der Tippfehler, der im Bestand wirklich steht. Über das
			// Formular darf kein neuer dazukommen (Plan 7.2).
			'Tippfehler 01:20.24' => array( '01:20.24', '' ),
			'nur eine Zahl'      => array( '9644', '' ),
			'Text'               => array( 'ganz schnell', '' ),
			'leer'               => array( '', '' ),
			'DNF'                => array( 'DNF', '' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Strecken – der Fall, den es nur hier gibt
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider strecken
	 *
	 * @param string $roh      Eingabe.
	 * @param string $erwartet Erwarteter Wert, '' = abgelehnt.
	 */
	public function test_strecke( $roh, $erwartet ) {
		$r = lsg_bl_leistung_lesen( '12h', $roh );

		$this->assertSame( $erwartet, $r['wert'], 'Eingabe: ' . $roh );

		if ( '' === $erwartet ) {
			$this->assertNotSame( '', $r['fehler'], 'Ablehnung ohne Begründung: ' . $roh );
		} else {
			$this->assertSame( '', $r['fehler'], $roh );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function strecken() {
		return array(
			'so steht es im Bestand' => array( '96,723 km', '96,723 km' ),
			'dreistellig'            => array( '112,737 km', '112,737 km' ),
			'ohne km'                => array( '96,723', '96,723 km' ),
			'Punkt statt Komma'      => array( '96.723 km', '96,723 km' ),
			'Leerzeichen egal'       => array( '96,723km', '96,723 km' ),

			// ⚠ Entschieden: KEINE führende Null (Plan 7.2). Von 199
			// Zeitlauf-Zeilen entsprachen 173 dieser Form; die 23 mit
			// Auffüllung sind mit V1 angeglichen.
			'führende Null'          => array( '096,723 km', '' ),
			'zwei führende Nullen'   => array( '096,723', '' ),

			// Die drei Nachkommastellen sind Pflicht und werden NICHT
			// stillschweigend ergänzt: wer „96,7" tippt, hat vielleicht
			// 96,700 gemeint – oder sich verschrieben.
			'eine Nachkommastelle'   => array( '96,7 km', '' ),
			'zwei Nachkommastellen'  => array( '64,16 km', '' ),
			'vier Nachkommastellen'  => array( '96,7231 km', '' ),
			'ohne Nachkommastellen'  => array( '228 km', '' ),
			'nur eine Zahl'          => array( '228', '' ),

			'unter einem Kilometer'  => array( '0,500 km', '' ),
			'Zeit im Streckenfeld'   => array( '01:36:44', '' ),
			'Text'                   => array( 'ziemlich weit', '' ),
			'leer'                   => array( '', '' ),
		);
	}

	/**
	 * Die Fehlermeldung soll den richtigen Wert vorschlagen, nicht nur
	 * meckern. Das ist der Unterschied zwischen einer Prüfung und einer
	 * Hürde.
	 */
	public function test_fehlermeldungen_schlagen_das_richtige_vor() {
		$r = lsg_bl_leistung_lesen( '12h', '096,723 km' );
		$this->assertStringContainsString( '96,723 km', $r['fehler'] );

		$r = lsg_bl_leistung_lesen( '12h', '96,7 km' );
		$this->assertStringContainsString( '96,700 km', $r['fehler'] );

		$r = lsg_bl_leistung_lesen( '12h', '228 km' );
		$this->assertStringContainsString( '228,000 km', $r['fehler'] );
	}

	/**
	 * Was durchkommt, muss lsg_bl_parse_performance() OHNE den
	 * Zahlen-Fallback erwischen (Plan 7.2). Der Fallback existiert für die
	 * historischen Tippfehler; er sortiert solche Zeilen irgendwohin, aber
	 * nicht dorthin, wo sie hingehören.
	 */
	public function test_kein_zahlen_fallback() {
		foreach ( array( '96,723 km', '112,737 km', '5,000 km' ) as $s ) {
			$r    = lsg_bl_leistung_lesen( '24h', $s );
			$perf = lsg_bl_parse_performance( '24h', $r['wert'] );

			$this->assertSame( 'higher', $perf['better'], $s );
			// Der Fallback liefert bei Strecken dieselbe Zahl, aber ohne die
			// km-Einheit erkannt zu haben. Sicher ist: der Wert muss genau
			// die Kilometerzahl sein.
			$this->assertSame(
				(float) str_replace( ',', '.', str_replace( ' km', '', $r['wert'] ) ),
				$perf['sort'],
				$s
			);
		}

		foreach ( array( '01:36:44', '00:38:57', '01:11:55' ) as $s ) {
			$r    = lsg_bl_leistung_lesen( 'HM', $s );
			$perf = lsg_bl_parse_performance( 'HM', $r['wert'] );

			$this->assertSame( 'lower', $perf['better'], $s );
			// Positiv: der Zahlen-Fallback liefert einen NEGATIVEN sort-Wert.
			// Ein negativer Wert hier hieße, die Zeit wurde nicht als Zeit
			// erkannt.
			$this->assertGreaterThan( 0, $perf['sort'], $s );
		}
	}

	/* ------------------------------------------------------------------
	 * Jahresbestzeit-Prüfung (Plan 7.3)
	 * --------------------------------------------------------------- */

	/**
	 * Bestandszeilen bauen.
	 *
	 * @param array $paare id => time.
	 * @return array<int,array>
	 */
	private function bestand( array $paare ) {
		$out = array();
		foreach ( $paare as $id => $time ) {
			$out[] = array(
				'id'   => (int) $id,
				'time' => (string) $time,
				'town' => 'Bruchsal',
				'date' => 1747000000,
				'ak'   => 'm50',
			);
		}
		return $out;
	}

	public function test_kein_bestand() {
		$p = lsg_bl_best_pruefung( 'HM', '01:36:44', array() );

		$this->assertSame( 'keine', $p['lage'] );
		$this->assertSame( 0, $p['best_id'] );
		$this->assertSame( 'anlegen', $p['vorbelegung'] );
		$this->assertNotSame( '', $p['text'] );

		$a = lsg_bl_best_aktion( $p );
		$this->assertSame( 'insert', $a['aktion'] );
	}

	public function test_neue_leistung_besser() {
		$p = lsg_bl_best_pruefung( 'HM', '01:36:44', $this->bestand( array( 7 => '01:38:12' ) ) );

		$this->assertSame( 'besser', $p['lage'] );
		$this->assertSame( 7, $p['best_id'] );
		$this->assertSame( '01:38:12', $p['time_alt'] );
		$this->assertSame( 'ueberschreiben', $p['vorbelegung'] );
		$this->assertStringContainsString( 'schneller', $p['text'] );
		$this->assertStringContainsString( '01:38:12', $p['text'] );
		$this->assertStringContainsString( '01:36:44', $p['text'] );

		$a = lsg_bl_best_aktion( $p );
		$this->assertSame( 'update', $a['aktion'] );
		$this->assertSame( 7, $a['best_id'] );
	}

	/**
	 * ⚠ Geprüft und gewarnt wird, gesperrt nicht (Plan 7.3): der Mensch am
	 * Formular weiß Dinge, die die Datenbank nicht weiß – etwa dass der
	 * vorhandene Eintrag falsch ist. Was er nicht wissen kann, ist, dass es
	 * ihn überhaupt gibt.
	 */
	public function test_neue_leistung_schlechter_nur_mit_haken() {
		$p = lsg_bl_best_pruefung( 'HM', '01:38:12', $this->bestand( array( 7 => '01:36:44' ) ) );

		$this->assertSame( 'schlechter', $p['lage'] );
		$this->assertSame( 'nichts', $p['vorbelegung'] );
		$this->assertStringContainsString( 'langsamer', $p['text'] );

		// Ohne Haken: nichts, mit Begründung.
		$ohne = lsg_bl_best_aktion( $p, false );
		$this->assertSame( 'nichts', $ohne['aktion'] );
		$this->assertStringContainsString( 'ersetzen', $ohne['grund'] );

		// Mit Haken: überschreiben.
		$mit = lsg_bl_best_aktion( $p, true );
		$this->assertSame( 'update', $mit['aktion'] );
		$this->assertSame( 7, $mit['best_id'] );
	}

	/**
	 * Auch mit Haken nicht: es gäbe nichts zu ändern. Ein „update", das
	 * denselben Wert schreibt, stünde als Änderung im Log und wäre keine.
	 */
	public function test_identisch_wird_nie_geschrieben() {
		$p = lsg_bl_best_pruefung( 'HM', '01:36:44', $this->bestand( array( 7 => '01:36:44' ) ) );

		$this->assertSame( 'gleich', $p['lage'] );
		$this->assertSame( 'nichts', $p['vorbelegung'] );

		$this->assertSame( 'nichts', lsg_bl_best_aktion( $p, false )['aktion'] );
		$this->assertSame( 'nichts', lsg_bl_best_aktion( $p, true )['aktion'] );
	}

	/**
	 * Bei Zeitläufen ist mehr besser – und hier wird der Zweig 'higher'
	 * tatsächlich erreicht, anders als im Import (Plan 7.3).
	 */
	public function test_zeitlauf_mehr_ist_besser() {
		$p = lsg_bl_best_pruefung( '12h', '112,737 km', $this->bestand( array( 3 => '96,723 km' ) ) );

		$this->assertSame( 'besser', $p['lage'] );
		$this->assertStringContainsString( 'weiter', $p['text'] );

		// Und umgekehrt.
		$q = lsg_bl_best_pruefung( '12h', '96,723 km', $this->bestand( array( 3 => '112,737 km' ) ) );
		$this->assertSame( 'schlechter', $q['lage'] );
		$this->assertStringContainsString( 'kürzer', $q['text'] );
	}

	/**
	 * ⚠ Der Fall, an dem eine String-Sortierung scheitern würde: 112 < 96
	 * als Text, weil '1' kleiner als '9' ist.
	 */
	public function test_zeitlauf_nicht_als_string_vergleichen() {
		$p = lsg_bl_best_pruefung( '24h', '112,737 km', $this->bestand( array( 1 => '96,723 km' ) ) );
		$this->assertSame( 'besser', $p['lage'], '112,737 km ist weiter als 96,723 km.' );
	}

	/**
	 * Mehr als eine Bestandszeile: die BESTE ist der Bezug, und die IDs
	 * stehen im Zusatz – dieselbe Regel wie 6.5.4, in derselben
	 * Formulierung.
	 */
	public function test_doppelzeile_beste_ist_bezug() {
		$p = lsg_bl_best_pruefung(
			'HM',
			'01:30:00',
			$this->bestand(
				array(
					4  => '01:35:00',
					11 => '01:33:00',
				)
			)
		);

		$this->assertSame( 'besser', $p['lage'] );
		$this->assertSame( 11, $p['best_id'], 'Bezug ist die bessere der beiden.' );
		$this->assertSame( '01:33:00', $p['time_alt'] );
		$this->assertSame( array( 4, 11 ), $p['doppelt'] );
		$this->assertStringContainsString( 'Doppelzeile im Bestand', $p['zusatz'] );
		$this->assertStringContainsString( '#4, #11', $p['zusatz'] );
	}

	/**
	 * ⚠ Die bearbeitete Zeile ist nicht ihr eigener Konflikt. Ohne diesen
	 * Filter meldete jedes Bearbeiten „steht bereits so in der Datenbank"
	 * und liesse sich nie speichern.
	 */
	public function test_eigene_zeile_ist_kein_konflikt() {
		$bestand = $this->bestand( array( 7 => '01:36:44' ) );

		// Ohne Ausnahme: gleich, nichts zu tun.
		$ohne = lsg_bl_best_pruefung( 'HM', '01:36:44', $bestand );
		$this->assertSame( 'gleich', $ohne['lage'] );

		// Mit Ausnahme: als wäre der Bestand leer – der Ort lässt sich also
		// korrigieren, ohne die Zeit zu ändern.
		$mit = lsg_bl_best_pruefung( 'HM', '01:36:44', $bestand, 7 );
		$this->assertSame( 'keine', $mit['lage'] );
		$this->assertSame( 'insert', lsg_bl_best_aktion( $mit )['aktion'] );
	}

	/**
	 * Beim Bearbeiten eine FREMDE Zeile im selben Jahr: das ist die
	 * Hintertür zur Doppelzeile (Plan 7.4) und muss sichtbar bleiben.
	 */
	public function test_fremde_zeile_beim_bearbeiten_bleibt_sichtbar() {
		$bestand = $this->bestand(
			array(
				7 => '01:36:44',
				9 => '01:40:00',
			)
		);

		$p = lsg_bl_best_pruefung( 'HM', '01:38:00', $bestand, 7 );

		$this->assertSame( 9, $p['best_id'], 'Zeile 9 bleibt als Bezug übrig.' );
		$this->assertSame( 'besser', $p['lage'] );
		$this->assertSame( array(), $p['doppelt'], 'Nach dem Filter ist nur noch eine Zeile übrig.' );
	}

	/* ------------------------------------------------------------------
	 * Das Formular
	 * --------------------------------------------------------------- */

	/**
	 * Ein Athlet.
	 *
	 * @param int    $born Jahrgang.
	 * @param string $cat  m|f.
	 * @return array
	 */
	private function athlet( $born = 1976, $cat = 'm' ) {
		return array(
			'id'        => 42,
			'name'      => 'Flöter',
			'firstname' => 'Daniel',
			'born'      => $born,
			'cat'       => $cat,
			'active'    => '1',
		);
	}

	public function test_formular_vollstaendig() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '2026-05-17',
				'distanz'  => 'HM',
				'leistung' => '1:36:44',
				'ort'      => 'Ettlingen',
			),
			$this->athlet()
		);

		$this->assertTrue( $p['ok'], implode( ' | ', $p['fehler'] ) );
		$this->assertSame( array(), $p['fehler'] );
		$this->assertSame( '01:36:44', $p['werte']['leistung'], 'normalisiert, nicht roh' );
		$this->assertSame( 2026, $p['werte']['jahr'] );
		$this->assertSame( 'm50', $p['werte']['ak'] );
	}

	/**
	 * ⚠ Es wird nicht beim ersten Fehler abgebrochen. Wer vier Felder falsch
	 * ausgefüllt hat, soll das in einem Durchgang erfahren.
	 */
	public function test_alle_fehler_auf_einmal() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 0,
				'datum'    => '',
				'distanz'  => '',
				'leistung' => '',
				'ort'      => '',
			),
			null
		);

		$this->assertFalse( $p['ok'] );
		foreach ( array( 'athlet', 'datum', 'distanz', 'ort' ) as $feld ) {
			$this->assertArrayHasKey( $feld, $p['fehler'], 'Fehler fehlt: ' . $feld );
			$this->assertNotSame( '', $p['fehler'][ $feld ], $feld );
		}
	}

	/**
	 * ⚠ Das Formular legt keinen Athleten an (Plan 7.2). Eine ID, die es
	 * nicht gibt, ist deshalb ein Fehler und kein Anlass zum Anlegen.
	 */
	public function test_unbekannter_athlet_ist_ein_fehler() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 9999,
				'datum'    => '2026-05-17',
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => 'Ettlingen',
			),
			null
		);

		$this->assertFalse( $p['ok'] );
		$this->assertArrayHasKey( 'athlet', $p['fehler'] );
	}

	/**
	 * Geschlossene Liste – alle zwölf Codes, aber nur die.
	 */
	public function test_distanz_ist_geschlossen() {
		$basis = array(
			'athlet'   => 42,
			'datum'    => '2026-05-17',
			'leistung' => '01:36:44',
			'ort'      => 'Ettlingen',
		);

		foreach ( array_keys( lsg_bl_distance_map() ) as $code ) {
			$e             = $basis;
			$e['distanz']  = $code;
			$e['leistung'] = ( 'distance' === lsg_bl_distance_type( $code ) ) ? '96,723 km' : '01:36:44';

			$p = lsg_bl_best_formular_pruefen( $e, $this->athlet() );
			$this->assertArrayNotHasKey( 'distanz', $p['fehler'], $code );
		}

		// Und jetzt der Freitext, den es nicht geben darf.
		foreach ( array( '42km', 'Halbmarathon', '5 km', 'HM ', 'hm' ) as $unsinn ) {
			$e            = $basis;
			$e['distanz'] = $unsinn;

			$p = lsg_bl_best_formular_pruefen( $e, $this->athlet() );
			$this->assertArrayHasKey( 'distanz', $p['fehler'], 'durchgelassen: ' . $unsinn );
		}
	}

	/**
	 * ⚠ Zwölf Codes hier, neun beim Import. Die drei Zeitläufe sind der
	 * ganze Grund für diese Seite (Plan 7.2).
	 */
	public function test_zwoelf_gegen_neun() {
		$this->assertCount( 12, lsg_bl_distance_map() );
		$this->assertCount( 9, lsg_bl_import_distanzen() );

		$fehlen = array_diff( array_keys( lsg_bl_distance_map() ), lsg_bl_import_distanzen() );
		$this->assertSame( array( '6h', '12h', '24h' ), array_values( $fehlen ) );
	}

	/**
	 * ⚠ Das Jahr kommt aus dem eingegebenen Veranstaltungsdatum, nie aus
	 * date('Y') (Plan 7.3): ein im Januar nachgetragener Dezemberlauf gehört
	 * ins Vorjahr.
	 */
	public function test_jahr_aus_dem_datum() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '2019-12-31',
				'distanz'  => 'Marathon',
				'leistung' => '03:12:00',
				'ort'      => 'Silvesterlauf',
			),
			$this->athlet()
		);

		$this->assertTrue( $p['ok'], implode( ' | ', $p['fehler'] ) );
		$this->assertSame( 2019, $p['werte']['jahr'] );
		// Und die AK rechnet mit diesem Jahr, nicht mit heute.
		$this->assertSame( 'm40', $p['werte']['ak'], '2019 − 1976 = 43 → m40' );
	}

	public function test_datum_muss_lesbar_sein() {
		foreach ( array( '', '17.05.2026', '2026-02-31', 'irgendwann' ) as $roh ) {
			$p = lsg_bl_best_formular_pruefen(
				array(
					'athlet'   => 42,
					'datum'    => $roh,
					'distanz'  => 'HM',
					'leistung' => '01:36:44',
					'ort'      => 'Ettlingen',
				),
				$this->athlet()
			);
			// ⚠ TT.MM.JJJJ wird vom Handler übersetzt, nicht hier: diese
			// Funktion bekommt schon ISO. Alles andere ist ein Fehler.
			$this->assertArrayHasKey( 'datum', $p['fehler'], 'durchgelassen: ' . $roh );
		}
	}

	public function test_lauf_in_der_zukunft() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '2099-05-17',
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => 'Ettlingen',
			),
			$this->athlet(),
			2027
		);

		$this->assertArrayHasKey( 'datum', $p['fehler'] );
		$this->assertStringContainsString( 'Zukunft', $p['fehler']['datum'] );
	}

	/**
	 * Fängt den vertippten Jahrgang an der Stelle, an der er auffällt: eine
	 * AK lässt sich daraus nicht rechnen.
	 */
	public function test_lauf_vor_dem_geburtsjahr() {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '1970-05-17',
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => 'Ettlingen',
			),
			$this->athlet( 1976 )
		);

		$this->assertArrayHasKey( 'datum', $p['fehler'] );
		$this->assertSame( '', $p['werte']['ak'], 'Ohne plausibles Jahr keine Altersklasse.' );
	}

	/**
	 * ⚠ Die Spalte ist varchar(30), und gezählt werden ZEICHEN, nicht Bytes:
	 * „Bad Säckingen" ist 13 Zeichen, aber 14 Bytes.
	 */
	public function test_ort_laenge_in_zeichen() {
		$this->assertSame( 13, lsg_bl_zeichen( 'Bad Säckingen' ) );
		$this->assertSame( 14, strlen( 'Bad Säckingen' ), 'Bytes, zum Vergleich.' );

		// 30 Zeichen mit Umlauten – das sind 34 Bytes und muss durchgehen.
		$ort = str_repeat( 'ä', 30 );
		$p   = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '2026-05-17',
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => $ort,
			),
			$this->athlet()
		);
		$this->assertArrayNotHasKey( 'ort', $p['fehler'], '30 Zeichen müssen passen.' );

		// 31 Zeichen: abgelehnt, nicht abgeschnitten.
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => '2026-05-17',
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => str_repeat( 'a', 31 ),
			),
			$this->athlet()
		);
		$this->assertArrayHasKey( 'ort', $p['fehler'] );
		$this->assertStringContainsString( '31', $p['fehler']['ort'] );
	}

	/**
	 * Die AK wird gerechnet, nicht eingegeben – und zwar mit derselben
	 * Formel wie P3 (Plan 7.2).
	 *
	 * @dataProvider altersklassen
	 *
	 * @param int    $born     Jahrgang.
	 * @param string $cat      m|f.
	 * @param string $datum    ISO-Datum.
	 * @param string $erwartet AK-Code.
	 */
	public function test_ak_wird_gerechnet( $born, $cat, $datum, $erwartet ) {
		$p = lsg_bl_best_formular_pruefen(
			array(
				'athlet'   => 42,
				'datum'    => $datum,
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => 'Ettlingen',
			),
			$this->athlet( $born, $cat )
		);

		$this->assertSame( $erwartet, $p['werte']['ak'] );
		// Und identisch mit der Formel aus P3 – keine zweite Rechnung.
		$this->assertSame(
			lsg_bl_ak_berechnen( $born, (int) substr( $datum, 0, 4 ), $cat ),
			$p['werte']['ak']
		);
	}

	/**
	 * @return array<string,array{0:int,1:string,2:string,3:string}>
	 */
	public function altersklassen() {
		return array(
			'm50'                => array( 1976, 'm', '2026-05-17', 'm50' ),
			'w35'                => array( 1991, 'f', '2026-05-17', 'w35' ),
			'Hauptklasse männl.' => array( 2002, 'm', '2026-05-17', 'mhk' ),
			'Hauptklasse weibl.' => array( 1999, 'f', '2026-05-17', 'whk' ),
			// ⚠ Kein Randfall: im Bestand stehen 32 solche Zeilen. Der Code
			// wird geschrieben, auch wenn er in lsg_ak fehlt (Plan 6.5.3).
			'm80 – fehlt in lsg_ak' => array( 1943, 'm', '2026-05-17', 'm80' ),
			'Geburtstagsgrenze'  => array( 1996, 'm', '2026-05-17', 'm30' ),
		);
	}

	public function test_ak_satz() {
		$satz = lsg_bl_ak_satz( 'm50', $this->athlet( 1976 ), 2026 );

		$this->assertStringContainsString( 'm50', $satz );
		$this->assertStringContainsString( '1976', $satz );
		$this->assertStringContainsString( '2026', $satz );

		$this->assertSame( '', lsg_bl_ak_satz( '', $this->athlet(), 2026 ) );
	}

	/* ------------------------------------------------------------------
	 * Was sich beim Bearbeiten geändert hat
	 * --------------------------------------------------------------- */

	/**
	 * Eine Bestandszeile, wie sie aus der Datenbank kommt.
	 *
	 * @return array
	 */
	private function alte_zeile() {
		return array(
			'id'          => 7,
			'athletes_id' => 42,
			'distance'    => 'HM',
			'time'        => '01:36:44',
			'town'        => 'Ettlingen',
			'date'        => 1747000000,
		);
	}

	/**
	 * Die neuen Werte, wie das Formular sie liefert.
	 *
	 * @param array $anders Abweichungen.
	 * @return array
	 */
	private function neue_werte( array $anders = array() ) {
		return array_merge(
			array(
				'athlet'   => 42,
				'distanz'  => 'HM',
				'leistung' => '01:36:44',
				'ort'      => 'Ettlingen',
				'datum_ts' => 1747000000,
			),
			$anders
		);
	}

	/**
	 * ⚠ Nichts geändert heißt: nicht schreiben. Ein Update, das denselben
	 * Wert schreibt, stünde als Änderung im Log und wäre keine.
	 */
	public function test_diff_leer_wenn_nichts_anders_ist() {
		$this->assertSame( array(), lsg_bl_best_diff( $this->alte_zeile(), $this->neue_werte() ) );
		$this->assertSame( '', lsg_bl_best_diff_text( array() ) );
	}

	/**
	 * Der Anlass für die ganze Funktion: nur der Ort ändert sich, und die
	 * Meldung soll nicht „96,723 km → 96,723 km" lauten.
	 */
	public function test_diff_nur_der_ort() {
		$d = lsg_bl_best_diff( $this->alte_zeile(), $this->neue_werte( array( 'ort' => 'Bruchsal' ) ) );

		$this->assertCount( 1, $d );
		$this->assertSame( 'Ort', $d[0]['feld'] );
		$this->assertSame( 'Ettlingen', $d[0]['alt'] );
		$this->assertSame( 'Bruchsal', $d[0]['neu'] );

		$text = lsg_bl_best_diff_text( $d );
		$this->assertSame( 'Ort Ettlingen → Bruchsal', $text );
		$this->assertStringNotContainsString( '01:36:44', $text );
	}

	public function test_diff_mehrere_felder() {
		$d = lsg_bl_best_diff(
			$this->alte_zeile(),
			$this->neue_werte(
				array(
					'leistung' => '01:35:00',
					'ort'      => 'Bruchsal',
					'datum_ts' => 1750000000,
				)
			)
		);

		$this->assertCount( 3, $d );

		$text = lsg_bl_best_diff_text( $d );
		$this->assertStringContainsString( 'Leistung 01:36:44 → 01:35:00', $text );
		$this->assertStringContainsString( 'Ort Ettlingen → Bruchsal', $text );
		// Ein Timestamp taugt nicht als Anzeige – das Feld wird nur genannt.
		$this->assertStringContainsString( 'Datum', $text );
		$this->assertStringNotContainsString( '1750000000', $text );
	}

	public function test_diff_athlet_und_distanz() {
		$d = lsg_bl_best_diff(
			$this->alte_zeile(),
			$this->neue_werte(
				array(
					'athlet'  => 43,
					'distanz' => 'Marathon',
				)
			)
		);

		$felder = array();
		foreach ( $d as $e ) {
			$felder[] = $e['feld'];
		}
		$this->assertSame( array( 'Sportler', 'Distanz' ), $felder );

		$text = lsg_bl_best_diff_text( $d );
		$this->assertStringContainsString( 'Distanz HM → Marathon', $text );
		// Eine Athleten-ID ist für einen Menschen keine Auskunft.
		$this->assertStringNotContainsString( '43', $text );
	}

	/* ------------------------------------------------------------------
	 * Zuordnungsregeln (Plan 6.5.3)
	 * --------------------------------------------------------------- */

	public function test_regel_wird_normalisiert_gespeichert() {
		$p = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 42,
				'born'        => 1976,
				'vorname'     => 'Harry',
				'nachname'    => 'FLÖTER',
				'modus'       => 'feld',
				'aktiv'       => true,
			),
			$this->athlet()
		);

		$this->assertTrue( $p['ok'], implode( ' | ', $p['fehler'] ) );
		// ⚠ Normalisiert, wie P3 vergleicht – sonst trifft die Regel nie.
		$this->assertSame( 'harry', $p['werte']['vorname'] );
		$this->assertSame( 'floeter', $p['werte']['nachname'] );
	}

	/**
	 * ⚠ Eine Regel ohne Vor- UND Nachname zieht jeden LSG-Läufer dieses
	 * Jahrgangs auf einen Athleten (Plan 6.5.3) – und zwar erst beim
	 * nächsten Import, weit weg von der Stelle, an der sie entstand.
	 */
	public function test_regel_nur_mit_jahrgang_wird_abgelehnt() {
		$p = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 42,
				'born'        => 1976,
				'vorname'     => '',
				'nachname'    => '',
				'modus'       => 'feld',
			),
			$this->athlet()
		);

		$this->assertFalse( $p['ok'] );
		$this->assertArrayHasKey( 'nachname', $p['fehler'] );
	}

	public function test_regel_braucht_einen_athleten() {
		$p = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 0,
				'nachname'    => 'floeter',
			),
			null
		);
		$this->assertArrayHasKey( 'athletes_id', $p['fehler'] );

		$q = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 9999,
				'nachname'    => 'floeter',
			),
			null
		);
		$this->assertArrayHasKey( 'athletes_id', $q['fehler'] );
	}

	/**
	 * Ein Jahrgang, der nicht zum Athleten passt, könnte nie greifen –
	 * verglichen wird gegen den Jahrgang aus der Ergebnisliste, und der
	 * müsste beides sein.
	 */
	public function test_regel_jahrgang_muss_passen() {
		$p = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 42,
				'born'        => 1980,
				'nachname'    => 'floeter',
			),
			$this->athlet( 1976 )
		);

		$this->assertArrayHasKey( 'born', $p['fehler'] );

		// Leer heißt „beliebig" und ist erlaubt.
		$q = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 42,
				'born'        => 0,
				'nachname'    => 'floeter',
			),
			$this->athlet( 1976 )
		);
		$this->assertArrayNotHasKey( 'born', $q['fehler'] );
	}

	public function test_regel_modus_ist_geschlossen() {
		$p = lsg_bl_map_pruefen(
			array(
				'athletes_id' => 42,
				'nachname'    => 'floeter',
				'modus'       => 'irgendwie',
			),
			$this->athlet()
		);

		$this->assertArrayHasKey( 'modus', $p['fehler'] );
		$this->assertSame( array( 'feld', 'egal' ), array_keys( lsg_bl_map_modi() ) );
	}

	/* ------------------------------------------------------------------
	 * Regelkollisionen
	 * --------------------------------------------------------------- */

	/**
	 * Eine Regel bauen.
	 *
	 * @param int    $id     ID.
	 * @param int    $aid    athletes_id.
	 * @param string $vor    Vorname, normalisiert.
	 * @param string $nach   Nachname, normalisiert.
	 * @param int    $born   Jahrgang, 0 = beliebig.
	 * @param string $modus  feld|egal.
	 * @param int    $aktiv  1|0.
	 * @return array
	 */
	private function regel( $id, $aid, $vor, $nach, $born = 0, $modus = 'feld', $aktiv = 1 ) {
		return array(
			'id'          => $id,
			'athletes_id' => $aid,
			'vorname'     => $vor,
			'nachname'    => $nach,
			'born'        => $born,
			'modus'       => $modus,
			'aktiv'       => $aktiv,
			'notiz'       => '',
		);
	}

	/**
	 * ⚠ Zwei Regeln, die dieselbe Zeile treffen, sind ein Fehler und keine
	 * Auswahlfrage (Plan 6.5.3): beim Import bliebe die Zeile `offen`, und
	 * die Meldung nennt beide IDs.
	 */
	public function test_kollision_gleiche_regel_verschiedene_athleten() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
				$this->regel( 2, 43, 'harry', 'floeter', 1976 ),
			)
		);

		$this->assertSame( array( 2 ), $k[1] );
		$this->assertSame( array( 1 ), $k[2] );
	}

	/**
	 * Zwei Regeln auf DENSELBEN Athleten sind harmlos: das Ergebnis ist
	 * dasselbe, egal welche greift.
	 */
	public function test_gleicher_athlet_keine_kollision() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
				$this->regel( 2, 42, 'hary', 'floeter', 1976 ),
			)
		);

		$this->assertSame( array(), $k );
	}

	public function test_verschiedene_jahrgaenge_keine_kollision() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
				$this->regel( 2, 43, 'harry', 'floeter', 1980 ),
			)
		);

		$this->assertSame( array(), $k );
	}

	/**
	 * Ein leeres Feld heißt „beliebig" und überschneidet sich mit jedem
	 * Wert – das ist der Fall, den man am leichtesten übersieht.
	 */
	public function test_leeres_feld_kollidiert_mit_allem() {
		$k = lsg_bl_map_kollisionen(
			array(
				// „Nachname floeter, Vorname beliebig"
				$this->regel( 1, 42, '', 'floeter', 1976 ),
				// „Nachname floeter, Vorname harry"
				$this->regel( 2, 43, 'harry', 'floeter', 1976 ),
			)
		);

		$this->assertArrayHasKey( 1, $k );
		$this->assertArrayHasKey( 2, $k );
	}

	/**
	 * Ein leerer Jahrgang überschneidet sich ebenfalls mit jedem.
	 */
	public function test_leerer_jahrgang_kollidiert() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 0 ),
				$this->regel( 2, 43, 'harry', 'floeter', 1976 ),
			)
		);

		$this->assertArrayHasKey( 1, $k );
	}

	public function test_verschiedene_namen_keine_kollision() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
				$this->regel( 2, 43, 'harry', 'meier', 1976 ),
			)
		);

		$this->assertSame( array(), $k );
	}

	/**
	 * ⚠ Eine abgeschaltete Regel kollidiert nicht – sie greift beim Import
	 * nicht. Genau deshalb ist das Abschalten die vorgesehene Antwort auf
	 * eine Kollision.
	 */
	public function test_abgeschaltete_regel_kollidiert_nicht() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
				$this->regel( 2, 43, 'harry', 'floeter', 1976, 'feld', 0 ),
			)
		);

		$this->assertSame( array(), $k );
	}

	/**
	 * Bei `modus = 'egal'` ist die Feldzuordnung offen – eine Regel
	 * „harry/floeter, egal in welchem Feld" trifft dieselbe Zeile wie eine
	 * feldweise Regel mit denselben Werten.
	 */
	public function test_egal_kollidiert_mit_feld() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976, 'egal' ),
				$this->regel( 2, 43, 'floeter', 'harry', 1976, 'feld' ),
			)
		);

		$this->assertArrayHasKey( 1, $k, 'Vertauschte Belegung trifft dieselbe Zeile.' );
	}

	/**
	 * Drei verschiedene Token gehen in zwei Namensfelder nicht auf – also
	 * keine Kollision.
	 */
	public function test_egal_mit_drei_token_kollidiert_nicht() {
		$k = lsg_bl_map_kollisionen(
			array(
				$this->regel( 1, 42, 'harry', 'floeter', 1976, 'egal' ),
				$this->regel( 2, 43, 'klaus', 'meier', 1976, 'egal' ),
			)
		);

		$this->assertSame( array(), $k );
	}

	public function test_keine_regeln_keine_kollisionen() {
		$this->assertSame( array(), lsg_bl_map_kollisionen( array() ) );
		$this->assertSame(
			array(),
			lsg_bl_map_kollisionen( array( $this->regel( 1, 42, 'harry', 'floeter', 1976 ) ) )
		);
	}

	/**
	 * ⚠ Die eigentliche Zusicherung: eine kollidierende Regel und P3 sind
	 * sich einig. Was lsg_bl_map_kollisionen() meldet, muss beim Import auch
	 * wirklich als `mehrdeutig` durchkommen – sonst warnt die
	 * Pflegeoberfläche vor etwas, das nicht passiert (oder schlimmer:
	 * schweigt zu etwas, das passiert).
	 */
	public function test_kollision_deckt_sich_mit_p3() {
		$regeln = array(
			$this->regel( 1, 42, 'harry', 'floeter', 1976 ),
			$this->regel( 2, 43, 'harry', 'floeter', 1976 ),
		);

		$k = lsg_bl_map_kollisionen( $regeln );
		$this->assertArrayHasKey( 1, $k, 'Die Pflegeoberfläche warnt.' );

		// Und P3 lässt die Zeile tatsächlich offen. Verglichen wird gegen die
		// normalisierten Namen – so bekommt lsg_bl_regel_trifft() sie auch
		// in P3 übergeben.
		$vor  = lsg_bl_text_normalisieren( 'Harry' );
		$nach = lsg_bl_text_normalisieren( 'Flöter' );

		$treffer = array();
		foreach ( $regeln as $r ) {
			if ( lsg_bl_regel_trifft( $r, $vor, $nach ) ) {
				$treffer[] = (int) $r['id'];
			}
		}

		$this->assertCount( 2, $treffer, 'Beide Regeln treffen – P3 meldet mehrdeutig.' );
	}
}
