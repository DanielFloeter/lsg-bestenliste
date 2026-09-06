<?php
/**
 * P3 (Athleten zuordnen) und P4 (Abgleich gegen lsg_best) – die reine
 * Entscheidungslogik, ohne Datenbank.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class P3_P4_Test extends TestCase {

	/**
	 * Ein Ausschnitt aus lsg_athlete, mit den Personen, die im Plan
	 * namentlich vorkommen.
	 *
	 * @return array<int,array>
	 */
	private function athleten() {
		return array(
			array(
				'id'        => 171,
				'name'      => 'Dr. Pfeiffer',
				'firstname' => 'Wolfram',
				'born'      => 1961,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 183,
				'name'      => 'van Wees',
				'firstname' => 'Harry',
				'born'      => 1943,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 377,
				'name'      => 'Schlippe-Schrieber',
				'firstname' => 'Gudrun',
				'born'      => 1955,
				'cat'       => 'f',
				'active'    => '1',
			),
			// ⚠ Die Person, die ein früherer Planentwurf mit 377 verwechselt
			// hatte. Sie steht hier, damit ein Test es merkt, falls jemand die
			// Regel wieder auf sie umbiegt.
			array(
				'id'        => 337,
				'name'      => 'Österle',
				'firstname' => 'Hans-Jörg',
				'born'      => 1967,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 500,
				'name'      => 'Körner',
				'firstname' => 'Holger',
				'born'      => 1993,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 501,
				'name'      => 'Weber',
				'firstname' => 'Claus',
				'born'      => 1969,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 502,
				'name'      => 'Weber',
				'firstname' => 'Klaus',
				'born'      => 1972,
				'cat'       => 'm',
				'active'    => '1',
			),
		);
	}

	/**
	 * Die drei Startregeln aus Plan 6.5.3.
	 *
	 * @return array<int,array>
	 */
	private function regeln() {
		return array(
			array(
				'id'          => 1,
				'athletes_id' => 171,
				'born'        => 1961,
				'vorname'     => 'wolfram',
				'nachname'    => 'pfeiffer',
				'modus'       => 'feld',
				'aktiv'       => 1,
			),
			array(
				'id'          => 2,
				'athletes_id' => 183,
				'born'        => 1943,
				'vorname'     => 'harry',
				'nachname'    => '',
				'modus'       => 'feld',
				'aktiv'       => 1,
			),
			array(
				'id'          => 3,
				'athletes_id' => 377,
				'born'        => 1955,
				'vorname'     => 'gudrun',
				'nachname'    => '',
				'modus'       => 'egal',
				'aktiv'       => 1,
			),
		);
	}

	/**
	 * @param string $nachname Nachname.
	 * @param string $vorname  Vorname.
	 * @param int    $jahrgang Jahrgang.
	 * @return array
	 */
	private function zeile( $nachname, $vorname, $jahrgang ) {
		return array(
			'nachname' => $nachname,
			'vorname'  => $vorname,
			'jahrgang' => $jahrgang,
		);
	}

	/* ------------------------------------------------------------------
	 * P3 – die drei Stufen
	 * --------------------------------------------------------------- */

	public function test_stufe1_exakt() {
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Körner', 'Holger', 1993 ), $this->athleten(), array() );

		$this->assertSame( 500, $r['athletes_id'] );
		$this->assertSame( 'exakt', $r['match_type'] );
	}

	public function test_stufe1_ignoriert_gross_klein() {
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'KÖRNER', 'holger', 1993 ), $this->athleten(), array() );
		$this->assertSame( 500, $r['athletes_id'] );
		$this->assertSame( 'exakt', $r['match_type'] );
	}

	public function test_stufe3_normalisiert() {
		// Umlaut aufgelöst: „Koerner" findet „Körner".
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Koerner', 'Holger', 1993 ), $this->athleten(), array() );

		$this->assertSame( 500, $r['athletes_id'] );
		$this->assertSame( 'normalisiert', $r['match_type'] );
	}

	public function test_gleicher_name_anderer_jahrgang_trifft_nicht() {
		// ⚠ Der Jahrgang ist in JEDER Stufe Pflicht (Plan 6.5.3).
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Körner', 'Holger', 1994 ), $this->athleten(), array() );

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertSame( 'offen', $r['match_type'] );
		$this->assertStringContainsString( 'kein Sportler mit diesem Namen und Jahrgang', $r['meldung'] );
	}

	public function test_ohne_jahrgang_und_ohne_ak_bleibt_offen() {
		// Auch wenn der Name eindeutig aussieht.
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Körner', 'Holger', 0 ), $this->athleten(), array() );

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertStringContainsString( 'nennt keinen Jahrgang', $r['meldung'] );
	}

	/* ------------------------------------------------------------------
	 * P3 – Zuordnung über das Jahrgangsband der Altersklasse (Issue #2)
	 *
	 * race result und runtix nennen in vielen Listen keinen Jahrgang mehr,
	 * nur noch die AK. Dann tritt deren Jahrgangsband an die Stelle des
	 * Jahrgangs – mit eigenem match_type, damit im Protokoll sichtbar
	 * bleibt, worauf die Zuordnung beruhte.
	 * --------------------------------------------------------------- */

	/**
	 * Eine Zeile ohne Jahrgang, dafür mit dem Band ihrer Altersklasse.
	 *
	 * @param string $nachname Nachname.
	 * @param string $vorname  Vorname.
	 * @param int    $von      Untere Grenze des Bandes.
	 * @param int    $bis      Obere Grenze des Bandes.
	 * @return array
	 */
	private function zeile_ak( $nachname, $vorname, $von, $bis ) {
		return array(
			'nachname'     => $nachname,
			'vorname'      => $vorname,
			'jahrgang'     => 0,
			'jahrgang_von' => $von,
			'jahrgang_bis' => $bis,
		);
	}

	public function test_ak_band_ordnet_zu() {
		// Körner, Holger ist 1993 – „M30" bei einer Veranstaltung 2026.
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Körner', 'Holger', 1992, 1996 ),
			$this->athleten(),
			array()
		);

		$this->assertSame( 500, $r['athletes_id'] );
		// ⚠ Nicht 'exakt': der Name war exakt, der Jahrgang aber geschlossen.
		$this->assertSame( 'ak', $r['match_type'] );
	}

	public function test_ak_band_auch_normalisiert() {
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Koerner', 'Holger', 1992, 1996 ),
			$this->athleten(),
			array()
		);

		$this->assertSame( 500, $r['athletes_id'] );
		$this->assertSame( 'ak', $r['match_type'] );
	}

	public function test_ak_band_trifft_daneben_nicht() {
		// Dasselbe Band eine Klasse weiter: Körner fällt heraus.
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Körner', 'Holger', 1987, 1991 ),
			$this->athleten(),
			array()
		);

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertSame( 'offen', $r['match_type'] );
		$this->assertStringContainsString( 'in dieser Altersklasse', $r['meldung'] );
	}

	/**
	 * Der eigentliche Gewinn: die AK trennt zwei Namensvettern genauso wie
	 * ein Jahrgang, solange sie in verschiedene Klassen fallen.
	 */
	public function test_ak_band_trennt_zwei_weber() {
		// Weber, Claus (1969) ist 2026 in M55 – Weber, Klaus (1972) in M50.
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Weber', 'Claus', 1967, 1971 ),
			$this->athleten(),
			array()
		);
		$this->assertSame( 501, $r['athletes_id'] );

		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Weber', 'Klaus', 1972, 1976 ),
			$this->athleten(),
			array()
		);
		$this->assertSame( 502, $r['athletes_id'] );
	}

	/**
	 * ⚠ Und dort, wo die AK NICHT trennt, wird auch nichts zugeordnet. Das
	 * ist der Preis des Verfahrens, und er wird bezahlt, nicht umgangen.
	 */
	public function test_ak_band_mehrdeutig_wird_nicht_zugeordnet() {
		$athleten = array(
			array(
				'id'        => 600,
				'name'      => 'Maier',
				'firstname' => 'Thomas',
				'born'      => 1982,
				'cat'       => 'm',
				'active'    => '1',
			),
			array(
				'id'        => 601,
				'name'      => 'Maier',
				'firstname' => 'Thomas',
				'born'      => 1985,
				'cat'       => 'm',
				'active'    => '1',
			),
		);

		// Mit Jahrgang wären das zwei klar verschiedene Personen. Im Band
		// von „M40" (1982–1986) liegen sie beide.
		$r = lsg_bl_p3_zuordnen( $this->zeile_ak( 'Maier', 'Thomas', 1982, 1986 ), $athleten, array() );

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertSame( 'mehrdeutig', $r['match_type'] );
		$this->assertStringContainsString( 'dieselbe Altersklasse', $r['meldung'] );

		// Derselbe Fall mit Jahrgang trifft dagegen eindeutig.
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Maier', 'Thomas', 1985 ), $athleten, array() );
		$this->assertSame( 601, $r['athletes_id'] );
		$this->assertSame( 'exakt', $r['match_type'] );
	}

	/**
	 * Regeln müssen mitziehen – sonst bliebe bei einer Liste ohne Jahrgang
	 * ausgerechnet der manuelle Ausweg zu.
	 */
	public function test_ak_band_zieht_regel_mit() {
		// Dr. Pfeiffer, Wolfram ist 1961 – „M65" bei einer Veranstaltung 2026.
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Pfeiffer', 'Wolfram', 1957, 1961 ),
			$this->athleten(),
			$this->regeln()
		);

		$this->assertSame( 171, $r['athletes_id'] );
		$this->assertSame( 'regel_ak', $r['match_type'] );
		$this->assertSame( array( 1 ), $r['regeln'] );
	}

	/**
	 * ⚠ Nennt die Quelle einen Jahrgang, schlägt er jedes Band. Er ist die
	 * Angabe der Quelle; das Band ist nur ein Schluss aus der Klasse.
	 */
	public function test_genannter_jahrgang_schlaegt_das_band() {
		$zeile                 = $this->zeile_ak( 'Körner', 'Holger', 1992, 1996 );
		$zeile['jahrgang']     = 1994;

		$r = lsg_bl_p3_zuordnen( $zeile, $this->athleten(), array() );

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertStringContainsString( 'kein Sportler mit diesem Namen und Jahrgang', $r['meldung'] );
	}

	/**
	 * Ein unbrauchbares Band ist wie gar keines.
	 */
	public function test_verdrehtes_band_bleibt_offen() {
		$r = lsg_bl_p3_zuordnen(
			$this->zeile_ak( 'Körner', 'Holger', 1996, 1992 ),
			$this->athleten(),
			array()
		);

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertSame( 'offen', $r['match_type'] );
	}

	/* ------------------------------------------------------------------
	 * P3 – die drei Startregeln
	 * --------------------------------------------------------------- */

	public function test_regel_171_pfeiffer() {
		// Die Quelle schreibt „Pfeiffer", die Datenbank „Dr. Pfeiffer".
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Pfeiffer', 'Wolfram', 1961 ), $this->athleten(), $this->regeln() );

		$this->assertSame( 171, $r['athletes_id'] );
		$this->assertSame( 'regel', $r['match_type'] );
		$this->assertSame( array( 1 ), $r['regeln'] );
	}

	public function test_regel_171_anderer_jahrgang_trifft_nicht() {
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Pfeiffer', 'Wolfram', 1962 ), $this->athleten(), $this->regeln() );
		$this->assertSame( 0, $r['athletes_id'] );
	}

	public function test_regel_183_beliebiger_nachname() {
		// Der Nachname variiert in den Listen – Vorname und Jahrgang sind im
		// Verein eindeutig.
		foreach ( array( 'van Wees', 'Wees', 'VAN WEES-SNEL', 'Vanwees' ) as $nachname ) {
			$r = lsg_bl_p3_zuordnen( $this->zeile( $nachname, 'Harry', 1943 ), $this->athleten(), $this->regeln() );
			$this->assertSame( 183, $r['athletes_id'], 'Nachname: ' . $nachname );
		}
	}

	public function test_regel_377_vertauschte_felder() {
		// ⚠ 377 ist Schlippe-Schrieber, Gudrun (1955) – NICHT 337, das ist
		// Österle, Hans-Jörg (1967). Ein früherer Planentwurf nannte die
		// falsche ID; eine Regel, die auf den Falschen zeigt, schreibt Zeiten
		// still einem Fremden gut.
		$a = lsg_bl_p3_zuordnen( $this->zeile( 'Schlippe-Schrieber', 'Gudrun', 1955 ), $this->athleten(), $this->regeln() );
		$b = lsg_bl_p3_zuordnen( $this->zeile( 'Gudrun', 'Meier', 1955 ), $this->athleten(), $this->regeln() );
		$c = lsg_bl_p3_zuordnen( $this->zeile( 'Meier', 'Gudrun', 1955 ), $this->athleten(), $this->regeln() );

		// Der volle Name trifft schon exakt – die Regel kommt gar nicht zum Zug.
		$this->assertSame( 377, $a['athletes_id'] );
		$this->assertSame( 'exakt', $a['match_type'] );

		// Vertauscht und mit fremdem Nachnamen: dafür ist modus 'egal' da.
		$this->assertSame( 377, $b['athletes_id'] );
		$this->assertSame( 'regel', $b['match_type'] );
		$this->assertSame( 377, $c['athletes_id'] );
		$this->assertSame( 'regel', $c['match_type'] );

		$this->assertNotSame( 337, $b['athletes_id'] );
	}

	public function test_regel_greift_erst_nach_dem_exakten_treffer() {
		// Wo der Name ohnehin exakt passt, kommt die Regel nicht zum Zug –
		// das begrenzt den Schaden einer breiten Regel wie `harry` + 1943.
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'van Wees', 'Harry', 1943 ), $this->athleten(), $this->regeln() );
		$this->assertSame( 'exakt', $r['match_type'] );
	}

	public function test_zwei_regeln_sind_ein_fehler_keine_auswahl() {
		$regeln   = $this->regeln();
		$regeln[] = array(
			'id'          => 17,
			'athletes_id' => 337,
			'born'        => 1943,
			'vorname'     => 'harry',
			'nachname'    => '',
			'modus'       => 'feld',
			'aktiv'       => 1,
		);

		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Unbekannt', 'Harry', 1943 ), $this->athleten(), $regeln );

		$this->assertSame( 0, $r['athletes_id'] );
		$this->assertSame( 'mehrdeutig', $r['match_type'] );
		$this->assertStringContainsString( '#2', $r['meldung'] );
		$this->assertStringContainsString( '#17', $r['meldung'] );
		$this->assertSame( array( 2, 17 ), $r['regeln'] );
	}

	public function test_abgeschaltete_regel_greift_nicht() {
		$regeln = $this->regeln();
		$regeln[1]['aktiv'] = 0;

		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Unbekannt', 'Harry', 1943 ), $this->athleten(), $regeln );
		$this->assertSame( 0, $r['athletes_id'] );
	}

	public function test_regel_ohne_namen_greift_nie() {
		$regeln = array(
			array(
				'id'          => 9,
				'athletes_id' => 183,
				'born'        => 1943,
				'vorname'     => '',
				'nachname'    => '',
				'modus'       => 'feld',
				'aktiv'       => 1,
			),
		);

		// Sie würde jeden LSG-Läufer dieses Jahrgangs auf einen Athleten
		// ziehen – also greift sie gar nicht erst.
		$r = lsg_bl_p3_zuordnen( $this->zeile( 'Irgendwer', 'Egal', 1943 ), $this->athleten(), $regeln );
		$this->assertSame( 0, $r['athletes_id'] );
	}

	public function test_regel_ohne_namen_laesst_sich_nicht_anlegen() {
		$this->assertNotSame(
			'',
			lsg_bl_regel_gueltig(
				array(
					'athletes_id' => 183,
					'born'        => 1943,
					'vorname'     => '',
					'nachname'    => '',
				)
			)
		);
		$this->assertNotSame( '', lsg_bl_regel_gueltig( array( 'athletes_id' => 0, 'born' => 1943, 'vorname' => 'harry' ) ) );
		$this->assertNotSame( '', lsg_bl_regel_gueltig( array( 'athletes_id' => 183, 'born' => 0, 'vorname' => 'harry' ) ) );

		$this->assertSame(
			'',
			lsg_bl_regel_gueltig(
				array(
					'athletes_id' => 183,
					'born'        => 1943,
					'vorname'     => 'harry',
					'nachname'    => '',
				)
			)
		);
	}

	/* ------------------------------------------------------------------
	 * P3 – Lesehilfe
	 * --------------------------------------------------------------- */

	public function test_aehnliche_athleten_sind_nur_lesehilfe() {
		// Das Beispiel aus Plan 6.5.3: Weber, Klaus (1969) ist unbekannt.
		// Weber, Claus (1969) passt im Jahrgang, nicht im Namen;
		// Weber, Klaus (1972) im Namen, nicht im Jahrgang.
		$zeile = $this->zeile( 'Weber', 'Klaus', 1969 );

		$zuordnung = lsg_bl_p3_zuordnen( $zeile, $this->athleten(), array() );
		$this->assertSame( 0, $zuordnung['athletes_id'], 'Ähnlichkeit darf NIE zuordnen.' );

		$aehnlich = lsg_bl_p3_aehnliche( $zeile, $this->athleten() );
		$ids      = array_column( $aehnlich, 'id' );

		$this->assertContains( 501, $ids );
		$this->assertContains( 502, $ids );
		$this->assertNotContains( 500, $ids );
	}

	/* ------------------------------------------------------------------
	 * P4 – die vier Fälle
	 * --------------------------------------------------------------- */

	public function test_p4_neu() {
		$r = lsg_bl_p4_status( 'HM', '01:30:56', array() );

		$this->assertSame( 'neu', $r['status'] );
		$this->assertSame( 0, $r['best_id'] );
		$this->assertSame( '', $r['time_alt'] );
	}

	public function test_p4_schneller() {
		$r = lsg_bl_p4_status( 'HM', '01:36:44', array( array( 'id' => 7, 'time' => '01:38:12' ) ) );

		$this->assertSame( 'schneller', $r['status'] );
		$this->assertSame( 7, $r['best_id'] );
		$this->assertSame( '01:38:12', $r['time_alt'] );
	}

	public function test_p4_langsamer() {
		$r = lsg_bl_p4_status( 'HM', '01:52:03', array( array( 'id' => 7, 'time' => '01:47:30' ) ) );
		$this->assertSame( 'langsamer', $r['status'] );
	}

	public function test_p4_gleich() {
		$r = lsg_bl_p4_status( 'HM', '01:38:12', array( array( 'id' => 7, 'time' => '01:38:12' ) ) );
		$this->assertSame( 'gleich', $r['status'] );
	}

	/**
	 * ⚠ Der Bestand schreibt Zeiten nicht immer als HH:MM:SS. Ein
	 * String-Vergleich läge bei `38:57` gegen `01:38:57` falsch – deshalb
	 * läuft der Vergleich über lsg_bl_parse_performance().
	 */
	public function test_p4_vergleicht_nicht_als_string() {
		$r = lsg_bl_p4_status( '10km', '00:38:57', array( array( 'id' => 7, 'time' => '38:57' ) ) );
		$this->assertSame( 'gleich', $r['status'] );

		$r = lsg_bl_p4_status( '10km', '00:38:57', array( array( 'id' => 7, 'time' => '01:38:57' ) ) );
		$this->assertSame( 'schneller', $r['status'] );
	}

	/**
	 * ⚠ Mehr als eine Bestandszeile: die beste ist der Bezug, geschrieben
	 * wird nur dorthin, und der Zusatz nennt beide ids. Kein stilles LIMIT 1
	 * (Plan 6.5.4).
	 */
	public function test_p4_doppelzeile_im_bestand() {
		$r = lsg_bl_p4_status(
			'HM',
			'01:35:00',
			array(
				array( 'id' => 11, 'time' => '01:40:00' ),
				array( 'id' => 12, 'time' => '01:37:00' ),
			)
		);

		$this->assertSame( 'schneller', $r['status'] );
		$this->assertSame( 12, $r['best_id'], 'Bezug ist die BESTE der gefundenen Zeilen.' );
		$this->assertSame( '01:37:00', $r['time_alt'] );
		$this->assertSame( array( 11, 12 ), $r['doppelt'] );
		$this->assertStringContainsString( 'Doppelzeile im Bestand', $r['zusatz'] );
		$this->assertStringContainsString( '#11', $r['zusatz'] );
		$this->assertStringContainsString( '#12', $r['zusatz'] );
	}

	public function test_p4_zeitlauf_groesser_ist_besser() {
		// Im Import wird dieser Zweig nie erreicht – 6h/12h/24h stehen nicht
		// im Select. Das Formular aus Abschnitt 7 benutzt dieselbe Funktion,
		// deshalb steht der Fall hier.
		$r = lsg_bl_p4_status( '12h', '112,737 km', array( array( 'id' => 3, 'time' => '96,723 km' ) ) );
		$this->assertSame( 'schneller', $r['status'] );

		// Auch gegen eine Altzeile mit führender Null.
		$r = lsg_bl_p4_status( '12h', '112,737 km', array( array( 'id' => 3, 'time' => '096,723 km' ) ) );
		$this->assertSame( 'schneller', $r['status'] );
	}

	/* ------------------------------------------------------------------
	 * Status, Checkbox, Dubletten im Import
	 * --------------------------------------------------------------- */

	public function test_statustext_nennt_alte_und_neue_zeit() {
		$text = lsg_bl_status_text(
			array(
				'status'   => 'schneller',
				'zeit'     => '01:36:44',
				'time_alt' => '01:38:12',
			)
		);
		$this->assertStringContainsString( '01:38:12', $text );
		$this->assertStringContainsString( '01:36:44', $text );

		$text = lsg_bl_status_text(
			array(
				'status'   => 'langsamer',
				'zeit'     => '01:52:03',
				'time_alt' => '01:47:30',
			)
		);
		$this->assertStringContainsString( '01:47:30', $text );
		$this->assertStringContainsString( 'bleibt', $text );

		// Der Zusatz hängt hinten dran, statt die Meldung zu ersetzen.
		$text = lsg_bl_status_text(
			array(
				'status'   => 'neu',
				'zeit'     => '01:30:00',
				'time_alt' => '',
				'zusatz'   => 'Doppelzeile im Bestand (ids #1, #2) – bitte bereinigen',
			)
		);
		$this->assertStringContainsString( 'Doppelzeile', $text );
	}

	public function test_offene_zeilen_haben_keine_checkbox() {
		$this->assertFalse( lsg_bl_zeile_waehlbar( 'offen' ) );
		$this->assertFalse( lsg_bl_zeile_waehlbar( 'mehrdeutig' ) );

		foreach ( array( 'neu', 'schneller', 'langsamer', 'gleich' ) as $s ) {
			$this->assertTrue( lsg_bl_zeile_waehlbar( $s ) );
		}
	}

	public function test_vorauswahl_nur_neu_und_schneller() {
		$liste = lsg_bl_p4_status_liste();

		$this->assertTrue( $liste['neu']['vorauswahl'] );
		$this->assertTrue( $liste['schneller']['vorauswahl'] );
		$this->assertFalse( $liste['langsamer']['vorauswahl'] );
		$this->assertFalse( $liste['gleich']['vorauswahl'] );
		$this->assertFalse( $liste['offen']['vorauswahl'] );
		$this->assertFalse( $liste['mehrdeutig']['vorauswahl'] );
	}

	/**
	 * Staffel plus Einzellauf: derselbe Athlet steht zweimal in einer Liste.
	 * Die bessere Leistung gewinnt, die schlechtere wird als `langsamer`
	 * mitgeführt – abwählbar, nicht stillschweigend verworfen (Plan 6.5.4).
	 */
	public function test_dubletten_im_selben_import() {
		$zeilen = array(
			array( 'athletes_id' => 500, 'zeit' => '01:40:00', 'status' => 'neu', 'time_alt' => '', 'zusatz' => '' ),
			array( 'athletes_id' => 500, 'zeit' => '01:35:00', 'status' => 'neu', 'time_alt' => '', 'zusatz' => '' ),
			array( 'athletes_id' => 501, 'zeit' => '01:50:00', 'status' => 'neu', 'time_alt' => '', 'zusatz' => '' ),
		);

		$r = lsg_bl_p4_dubletten_im_import( $zeilen, 'HM' );

		// Die schlechtere der beiden 500er-Zeilen.
		$this->assertSame( 'langsamer', $r[0]['status'] );
		$this->assertSame( '01:35:00', $r[0]['time_alt'] );
		$this->assertStringContainsString( 'in diesem Import', $r[0]['zusatz'] );

		// Die bessere bleibt, wie sie war.
		$this->assertSame( 'neu', $r[1]['status'] );
		// Ein anderer Athlet ist nicht betroffen.
		$this->assertSame( 'neu', $r[2]['status'] );
	}

	public function test_dubletten_lassen_offene_zeilen_in_ruhe() {
		$zeilen = array(
			array( 'athletes_id' => 0, 'zeit' => '01:40:00', 'status' => 'offen', 'time_alt' => '', 'zusatz' => '' ),
			array( 'athletes_id' => 0, 'zeit' => '01:35:00', 'status' => 'offen', 'time_alt' => '', 'zusatz' => '' ),
		);

		$r = lsg_bl_p4_dubletten_im_import( $zeilen, 'HM' );

		$this->assertSame( 'offen', $r[0]['status'] );
		$this->assertSame( 'offen', $r[1]['status'] );
	}
}
