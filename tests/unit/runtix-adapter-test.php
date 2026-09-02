<?php
/**
 * RuntixAdapter gegen die gespeicherten HTML-Fixtures
 * (19. Hambrücker Lußhardtlauf, Event 3152, 21 KM Kraichgau-Lauf).
 *
 * Kein Netz, kein WordPress: der Adapter bekommt seinen Getter injiziert.
 *
 * Die Fixtures sind nachgebaut, nicht Byte-Kopien – Klassennamen,
 * Spaltenreihenfolge und Werte sind am 2026-09-02 live geprüft. Der Grund
 * steht in tests/README.md.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Runtix_Adapter_Test extends TestCase {

	/** @var string */
	private static $total;

	/** @var string */
	private static $jahr;

	/** @var string */
	private static $event;

	public static function setUpBeforeClass(): void {
		self::$total = lsg_bl_fixture( 'runtix-3152-21-total.html' );
		self::$jahr  = lsg_bl_fixture( 'runtix-10020-2026.html' );
		self::$event = lsg_bl_fixture( 'runtix-10021-3152.html' );
	}

	/**
	 * Ein Adapter, dessen Getter aus den Fixtures bedient wird.
	 *
	 * ⚠ Die Reihenfolge der Muster zählt: '/sts/10050/3152' trifft auch
	 * '/sts/10050/3152/21/total'. Das spezifischste Muster muss zuerst
	 * stehen.
	 *
	 * @return LSG_BL_Runtix_Adapter
	 */
	private function adapter() {
		return new LSG_BL_Runtix_Adapter(
			lsg_bl_fake_getter(
				array(
					'/sts/10050/3152/21/total' => self::$total,
					'/sts/10020/2026'          => self::$jahr,
					'/sts/10021/3152'          => self::$event,
					'/sts/10050/3152'          => self::$total,
				)
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Erkennung und URL-Zerlegung
	 * --------------------------------------------------------------- */

	/**
	 * @dataProvider urls
	 *
	 * @param string $url      Eingegebene URL.
	 * @param int    $erwartet Erwarteter Score.
	 */
	public function test_erkennung( $url, $erwartet ) {
		$this->assertSame( $erwartet, LSG_BL_Runtix_Adapter::erkennt( $url ), 'URL: ' . $url );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public function urls() {
		return array(
			'Ergebnisliste vollständig' => array( 'https://runtix.com/sts/10050/3152/21/total', 90 ),
			'nur Event'                 => array( 'https://runtix.com/sts/10050/3152', 90 ),
			'Veranstaltungsseite'       => array( 'https://runtix.com/sts/10021/3152', 90 ),
			'Walk-Contest „w"'          => array( 'https://runtix.com/sts/10050/3152/w/ac', 90 ),
			'Jahresübersicht'           => array( 'https://runtix.com/sts/10020/2026', 40 ),
			'Startseite'                => array( 'https://runtix.com/', 40 ),
			'mit www'                   => array( 'https://www.runtix.com/sts/10050/3152', 90 ),
			'fremder Host'              => array( 'https://my.raceresult.com/375768/', 0 ),
			// Der Klassiker: runtix.com.example.org gehoert NICHT hierher.
			'Host mit Suffix-Trick'     => array( 'https://runtix.com.example.org/sts/10050/3152', 0 ),
			'Unsinn'                    => array( 'nicht mal eine URL', 0 ),
		);
	}

	/**
	 * @dataProvider zerlegungen
	 *
	 * @param string $url      URL.
	 * @param array  $erwartet Erwartete Bestandteile.
	 */
	public function test_url_zerlegen( $url, array $erwartet ) {
		$this->assertSame( $erwartet, LSG_BL_Runtix_Adapter::url_zerlegen( $url ), 'URL: ' . $url );
	}

	/**
	 * @return array<string,array{0:string,1:array}>
	 */
	public function zerlegungen() {
		return array(
			'vollständig'      => array(
				'https://runtix.com/sts/10050/3152/21/total',
				array(
					'modul'    => '10050',
					'event_id' => '3152',
					'contest'  => '21',
					'rlt'      => 'total',
				),
			),
			// Fallstrick 2: „w" darf nicht zu 0 werden.
			'Contest „w"'      => array(
				'https://runtix.com/sts/10050/3152/w/ac',
				array(
					'modul'    => '10050',
					'event_id' => '3152',
					'contest'  => 'w',
					'rlt'      => 'ac',
				),
			),
			// Fallstrick 1: mit Schrägstrich am Ende darf nichts kaputtgehen.
			'trailing slash'   => array(
				'https://runtix.com/sts/10050/3152/21/total/',
				array(
					'modul'    => '10050',
					'event_id' => '3152',
					'contest'  => '21',
					'rlt'      => 'total',
				),
			),
			'Jahresübersicht'  => array(
				'https://runtix.com/sts/10020/2026',
				array(
					'modul'    => '10020',
					'event_id' => '',
					'contest'  => '',
					'rlt'      => '',
				),
			),
			'ohne sts'         => array(
				'https://runtix.com/impressum',
				array(
					'modul'    => '',
					'event_id' => '',
					'contest'  => '',
					'rlt'      => '',
				),
			),
		);
	}

	/**
	 * Der URL-Bauer hängt NIE einen Schrägstrich an – mit einem liefert
	 * runtix die Liste nicht (Fallstrick 1, live geprüft).
	 */
	public function test_url_bauen_ohne_trailing_slash() {
		$this->assertSame(
			'https://runtix.com/sts/10050/3152/21/total',
			LSG_BL_Runtix_Adapter::url_bauen( '10050', '3152', '21', 'total' )
		);
		$this->assertSame(
			'https://runtix.com/sts/10050/3152',
			LSG_BL_Runtix_Adapter::url_bauen( '10050', '3152' )
		);
		$this->assertSame(
			'https://runtix.com/sts/10020/2026',
			LSG_BL_Runtix_Adapter::url_bauen( '10020', '2026' )
		);
		// Ohne rlt endet die Adresse beim Wettbewerb, ohne Schrägstrich.
		$this->assertSame(
			'https://runtix.com/sts/10050/3152/w',
			LSG_BL_Runtix_Adapter::url_bauen( '10050', '3152', 'w' )
		);
	}

	public function test_vorauswahl_kommt_aus_dem_pfad() {
		$this->assertSame(
			array(
				'contest' => '21',
				'list'    => 'total',
			),
			LSG_BL_Runtix_Adapter::vorauswahl_aus_url( 'https://runtix.com/sts/10050/3152/21/total' )
		);
		$this->assertSame(
			array(
				'contest' => '',
				'list'    => '',
			),
			LSG_BL_Runtix_Adapter::vorauswahl_aus_url( 'https://runtix.com/sts/10050/3152' )
		);
	}

	public function test_hosts_sind_die_allowlist() {
		$this->assertSame(
			array( 'runtix.com', '*.runtix.com' ),
			LSG_BL_Runtix_Adapter::hosts()
		);
	}

	/* ------------------------------------------------------------------
	 * Rahmen: Eventname, Wettbewerbe, Listen
	 * --------------------------------------------------------------- */

	public function test_eventname_ohne_praefix() {
		$rahmen = LSG_BL_Runtix_Adapter::parse_rahmen( self::$total );

		// Der h1 lautet „Ergebnislisten - 19. Hambrücker Lußhardtlauf".
		// Übrig bleiben soll der Name, den ein Mensch nennen würde.
		$this->assertSame( '19. Hambrücker Lußhardtlauf', $rahmen['eventname'] );

		// Und er muss die Umlaute überlebt haben: ohne den
		// Meta-Charset-Vorspann macht libxml daraus „LuÃŸhardtlauf".
		$this->assertStringContainsString( 'ü', $rahmen['eventname'] );
		$this->assertStringContainsString( 'ß', $rahmen['eventname'] );
	}

	public function test_wettbewerbe_mit_string_keys() {
		$rahmen = LSG_BL_Runtix_Adapter::parse_rahmen( self::$total );

		$ids = array();
		foreach ( $rahmen['contests'] as $c ) {
			$ids[] = $c['id'];
		}

		// Vier Wettbewerbe, die Leerzeile „ --- " ist keiner.
		$this->assertSame( array( '21', '10', '5', 'w' ), $ids );

		// Fallstrick 2, hier als Zusicherung: jeder Key ist ein String,
		// und „w" ist einer davon.
		foreach ( $ids as $id ) {
			$this->assertIsString( $id );
		}
		$this->assertContains( 'w', $ids );
	}

	public function test_wettbewerbsnamen() {
		$rahmen = LSG_BL_Runtix_Adapter::parse_rahmen( self::$total );
		$namen  = array();
		foreach ( $rahmen['contests'] as $c ) {
			$namen[ $c['id'] ] = $c['name'];
		}

		$this->assertSame( '21 KM Sparkasse Kraichgau-Lauf (21.1km)', $namen['21'] );
		$this->assertSame( '5 KM - Interstick-Walk (5km)', $namen['w'] );
	}

	public function test_listen_und_gesamtwertung() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://runtix.com/sts/10050/3152/21/total' );

		$gefunden = array();
		foreach ( $adapter->listen( $ref, '21' ) as $l ) {
			$gefunden[ $l->id ] = $l->gesamtwertung;
		}

		$this->assertSame(
			array(
				'total' => true,
				'sex'   => false,
				'ac'    => false,
			),
			$gefunden,
			'Platz 1 in der Geschlechts- oder AK-Liste ist kein Gesamtsieg.'
		);
	}

	public function test_eventlesen_uebernimmt_vorauswahl_aus_dem_pfad() {
		$ref = $this->adapter()->eventLesen( 'https://runtix.com/sts/10050/3152/w/ac' );

		$this->assertSame( 'runtix', $ref->adapter );
		$this->assertSame( '3152', $ref->event_id );
		$this->assertSame( 'w', $ref->contest_id );
		$this->assertSame( 'ac', $ref->list_id );
		$this->assertSame( '19. Hambrücker Lußhardtlauf', $ref->event_name );
	}

	public function test_eventlesen_ohne_id_wird_abgelehnt() {
		$this->expectException( 'LSG_BL_Quelle_Exception' );
		$this->adapter()->eventLesen( 'https://runtix.com/sts/10020/2026' );
	}

	/* ------------------------------------------------------------------
	 * Die Ergebnistabelle
	 * --------------------------------------------------------------- */

	/**
	 * @return array Ergebnis von parse_liste().
	 */
	private function liste() {
		return LSG_BL_Runtix_Adapter::parse_liste( self::$total );
	}

	public function test_zeilenzahl() {
		$p = $this->liste();

		$this->assertSame( 22, $p['gelesen'] );
		$this->assertSame( 0, $p['verworfen'] );
		$this->assertCount( 22, $p['zeilen'] );

		// Die Kopfzeile ist keine Datenzeile.
		foreach ( $p['zeilen'] as $z ) {
			$this->assertNotSame( 'Teilnehmer', $z->teilnehmer );
		}
	}

	public function test_zeit_ist_brutto_und_gerundet() {
		$p = $this->liste();
		$z = $p['zeilen'][0];

		// Runtix liefert HH:MM:SS.t – die Zehntel werden gerundet, nicht
		// abgeschnitten. 01:11:54.9 → 01:11:55.
		$this->assertSame( '01:11:54.9', $z->roh_zeit );
		$this->assertSame( '01:11:55', $z->zeit );
		$this->assertSame( 'brutto', $z->zeit_typ );
		$this->assertSame( 'brutto', $p['zeit_typ'] );
	}

	/**
	 * Fallstrick 3, der wichtigste Test dieser Datei.
	 *
	 * `col-place-ageclass` hält den PLATZ in der Altersklasse,
	 * `col-ageclass` den Klassencode. Wer mit
	 * contains(@class,'col-ageclass') sucht, trifft die erste Spalte und
	 * liest „4" als Altersklasse – und findet dann kein Geschlecht.
	 */
	public function test_ageclass_nicht_mit_place_ageclass_verwechseln() {
		$z = $this->zeile( 'Harrer' );

		$this->assertSame( 'M 60', $z->quelle_klasse, 'Der Klassencode, nicht der AK-Platz.' );
		$this->assertSame( 'm', $z->geschlecht );
		$this->assertSame( '71', $z->platz, 'Gesamtplatz aus col-place-total.' );
		$this->assertSame( '1082', $z->startnummer );
	}

	/**
	 * Fallstrick 4: die Zeitspalte heißt `class="col-time "` – mit
	 * Leerzeichen. Ein Vergleich auf Gleichheit des rohen Attributs
	 * findet sie nicht, und dann ist jede Zeile „ohne verwertbare Zeit".
	 */
	public function test_zeitspalte_trotz_leerzeichen_im_attribut() {
		$p = $this->liste();
		foreach ( $p['zeilen'] as $z ) {
			$this->assertNotSame( '', $z->zeit, 'Zeile ohne Zeit: ' . $z->teilnehmer );
			$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', $z->zeit );
		}
	}

	/**
	 * Die eine Zeile, um die es beim Import überhaupt geht.
	 */
	public function test_der_lsg_treffer() {
		$z = $this->zeile( 'Harrer' );

		$this->assertSame( 'Harrer', $z->nachname );
		$this->assertSame( 'Jürgen', $z->vorname );
		$this->assertFalse( $z->namen_unsicher );
		$this->assertSame( 1966, $z->jahrgang );
		$this->assertSame( 'LSG Karlsruhe', $z->verein );
		$this->assertSame( '01:43:41', $z->zeit );
	}

	/**
	 * Der Beleg, dass der Vereinsfilter `LSG` UND `Karlsruhe` verlangen
	 * muss: in derselben Liste stehen neun `LSG Weiher`-Zeilen.
	 */
	public function test_lsg_weiher_ist_nicht_lsg_karlsruhe() {
		$vereine = array();
		foreach ( $this->liste()['zeilen'] as $z ) {
			if ( '' !== $z->verein ) {
				$vereine[ $z->verein ] = isset( $vereine[ $z->verein ] ) ? $vereine[ $z->verein ] + 1 : 1;
			}
		}

		$this->assertArrayHasKey( 'LSG Weiher', $vereine );
		$this->assertArrayHasKey( 'LSG Karlsruhe', $vereine );
		$this->assertSame( 1, $vereine['LSG Karlsruhe'] );

		$this->assertTrue( lsg_bl_ist_lsg( 'LSG Karlsruhe' ) );
		$this->assertFalse( lsg_bl_ist_lsg( 'LSG Weiher' ) );
		$this->assertFalse( lsg_bl_ist_lsg( 'Karlsruhe' ) );
	}

	/**
	 * Umlaute und ß, an drei Stellen und in drei Schreibweisen. Der Test
	 * fällt sofort um, wenn die Zeichenkodierung beim Parsen kippt.
	 *
	 * @dataProvider namen
	 *
	 * @param string $suche    Teil des Namens in der Fixture.
	 * @param string $nachname Erwarteter Nachname.
	 * @param string $vorname  Erwarteter Vorname.
	 * @param bool   $unsicher Musste der Splitter raten?
	 */
	public function test_namen( $suche, $nachname, $vorname, $unsicher ) {
		$z = $this->zeile( $suche );

		$this->assertSame( $nachname, $z->nachname, 'Nachname bei ' . $suche );
		$this->assertSame( $vorname, $z->vorname, 'Vorname bei ' . $suche );
		$this->assertSame( $unsicher, $z->namen_unsicher, 'unsicher bei ' . $suche );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function namen() {
		return array(
			'Umlaut im Vornamen'   => array( 'Harrer', 'Harrer', 'Jürgen', false ),
			'Umlaut im Nachnamen'  => array( 'Pählke', 'Pählke', 'Frank', false ),
			'ß im Nachnamen'       => array( 'Geißler', 'Geißler', 'Franziska', false ),
			'GROSS mit Umlaut'     => array( 'KRÜGER', 'KRÜGER', 'Hilmar', false ),
			'alles GROSS'          => array( 'SEIDER', 'SEIDER', 'FRANK', false ),
			'alles klein'          => array( 'weschenfelder', 'weschenfelder', 'andreas', false ),
			'Titel im Vornamen'    => array( 'Nees', 'Nees', 'Dr. Corinna', false ),
			'Doppelkomma'          => array( 'Michalewski', 'Michalewski', 'Patrick', false ),
		);
	}

	/**
	 * Der kaputte Name aus der echten Liste. Wichtig ist nicht, dass der
	 * Splitter ihn richtig auflöst – das kann er nicht –, sondern dass er
	 * das Raten zugibt. Die Oberfläche zeigt solche Zeilen markiert.
	 */
	public function test_kaputter_name_wird_als_unsicher_markiert() {
		$z = $this->zeile( 'Bensching' );

		$this->assertSame( 'Bensching Klaus, Bensching', $z->teilnehmer );
		$this->assertNotSame( '', $z->nachname );
		$this->assertNotSame( '', $z->vorname );
	}

	public function test_fehlender_verein_bleibt_leer() {
		$z = $this->zeile( 'Rau, Darian' );

		$this->assertSame( '', $z->verein, 'Kein Ort in Klammern, kein Platzhalter – leer.' );
		$this->assertSame( 2002, $z->jahrgang );
	}

	/**
	 * Runtix schreibt bei fehlendem Verein nichts – anders als race result,
	 * das den Wohnort in Klammern setzt. Ein Ort ohne Klammern kommt aber
	 * auch hier vor („Karlsruhe" als Team), und der darf nicht treffen.
	 */
	public function test_ort_als_verein_trifft_nicht() {
		$z = $this->zeile( 'Ziereisen' );

		$this->assertSame( 'Karlsruhe', $z->verein );
		$this->assertFalse( lsg_bl_ist_lsg( $z->verein ) );
	}

	public function test_geschlecht_aus_dem_klassencode() {
		$this->assertSame( 'f', $this->zeile( 'Wunsch' )->geschlecht );
		$this->assertSame( 'W 30', $this->zeile( 'Wunsch' )->quelle_klasse );
		$this->assertSame( 'm', $this->zeile( 'Moritz' )->geschlecht );
		$this->assertSame( 'M HK', $this->zeile( 'Moritz' )->quelle_klasse );
	}

	/**
	 * Zwei Zeilen mit demselben Gesamtplatz (163) – ein echter
	 * Zeitgleichstand aus der Liste. Beide müssen durchkommen; wer nach
	 * Platz indexiert, verliert eine.
	 */
	public function test_gleichstand_verliert_keine_zeile() {
		$mit_163 = array();
		foreach ( $this->liste()['zeilen'] as $z ) {
			if ( '163' === $z->platz ) {
				$mit_163[] = $z->teilnehmer;
			}
		}

		$this->assertCount( 2, $mit_163 );
		$this->assertContains( 'Zorzit, Patrick', $mit_163 );
		$this->assertContains( 'Stein, Lisa', $mit_163 );
	}

	/**
	 * Platz 1 – die einzige Zeile, aus der ein Gesamtsieg werden kann, und
	 * nur in der Gesamtwertung.
	 */
	public function test_platz_eins_ist_erkennbar() {
		$z = $this->liste()['zeilen'][0];

		$this->assertSame( '1', $z->platz );
		$this->assertTrue( lsg_bl_ist_gesamtsieg( $z->to_array(), true ) );

		// Und nur dort: Platz 1 in der Geschlechts- oder AK-Liste ist kein
		// Gesamtsieg (Plan 6.5.5).
		$this->assertFalse( lsg_bl_ist_gesamtsieg( $z->to_array(), false ) );
	}

	public function test_leere_seite_wird_gemeldet() {
		$this->expectException( 'LSG_BL_Quelle_Exception' );
		LSG_BL_Runtix_Adapter::parse_liste( '<html><body><p>Nichts hier.</p></body></html>' );
	}

	/* ------------------------------------------------------------------
	 * Datum – der eigentliche Grund für den ganzen Aufwand
	 * --------------------------------------------------------------- */

	/**
	 * Auf der Ergebnisseite steht kein Datum. Kein einziges. Deshalb
	 * überhaupt die Zweistufen-Auflösung.
	 */
	public function test_ergebnisseite_nennt_kein_datum() {
		$this->assertSame(
			0,
			preg_match( '/\d{1,2}\.\d{1,2}\.\d{4}/', self::$total ),
			'Wenn runtix hier doch ein Datum liefert, gehört der Weg vereinfacht.'
		);
	}

	public function test_datum_kommt_aus_der_jahresuebersicht() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://runtix.com/sts/10050/3152/21/total' );

		$d = $adapter->datum( $ref, '21' );

		$this->assertSame( '2026-08-16', $d['datum'] );
		$this->assertSame( 'liste', $d['quelle'], 'Die Übersicht ist die maßgebliche Quelle.' );
		$this->assertStringContainsString( '2026', $d['hinweis'] );
	}

	/**
	 * Der Eintrag wird über die Event-ID gefunden, nicht über den Namen.
	 * Die Übersicht enthält Zeilen, deren Namenslink auf /sts/10021/ zeigt
	 * (58 von 157 live) – über den Ergebnis-Link allein wären die nicht zu
	 * finden.
	 */
	public function test_jahresuebersicht_findet_ueber_die_id() {
		$jahr = LSG_BL_Runtix_Adapter::parse_jahr( self::$jahr );

		$this->assertArrayHasKey( '3152', $jahr );
		$this->assertSame( '2026-08-16', $jahr['3152']['datum'] );
		$this->assertSame( '19. Hambrücker Lußhardtlauf', $jahr['3152']['name'] );

		// Die Zeile ohne Ergebnisse ist trotzdem dabei.
		$this->assertArrayHasKey( '3190', $jahr );
		$this->assertSame( '2026-01-01', $jahr['3190']['datum'] );

		$this->assertArrayHasKey( '3187', $jahr );
		$this->assertSame( '2026-08-15', $jahr['3187']['datum'] );
	}

	/**
	 * Die Veranstaltungsseite liefert nur JAHRESZAHLEN, kein Datum – und
	 * die drei Ablenkungen (Lastschrifteinzug 19.08., Meldeschluss 15.08.,
	 * Stand der Ausschreibung 12.03.) dürfen nirgends als Lauftag landen.
	 */
	public function test_veranstaltungsseite_liefert_nur_jahre() {
		$e = LSG_BL_Runtix_Adapter::parse_event( self::$event );

		$this->assertSame( '19. Hambrücker Lußhardtlauf', $e['eventname'] );
		$this->assertSame( '2026-08-16', $e['roh_datum'], 'Aus dem <strong> mit dem Wochentag.' );
		$this->assertContains( 2026, $e['jahre'] );

		// Und keiner der Ablenkungstermine wurde zum Lauftag.
		$this->assertNotSame( '2026-08-19', $e['roh_datum'] );
		$this->assertNotSame( '2026-08-15', $e['roh_datum'] );
		$this->assertNotSame( '2026-03-12', $e['roh_datum'] );
	}

	/**
	 * Die Fußzeile nennt „Copyright © CODERESEARCH 2001 - 2026". 2001
	 * gehört zum Betreiber, nicht zum Lauf – und darf kein Jahres-Versuch
	 * werden (Plan 4.2).
	 */
	public function test_fusszeilenjahr_wird_ignoriert() {
		$e = LSG_BL_Runtix_Adapter::parse_event( self::$event );

		$this->assertNotContains( 2001, $e['jahre'] );
		$this->assertSame( 2026, $e['jahre'][0], 'Der Lauftag steht vorn.' );
	}

	/**
	 * Höchstens zwei Jahres-Versuche. Ein durchprobiertes Jahrzehnt wären
	 * zehn Fremdabrufe für ein Datum, das ein Mensch in fünf Sekunden
	 * eintippt.
	 */
	public function test_hoechstens_zwei_jahresversuche() {
		$abrufe = array();

		$getter = function ( $url ) use ( &$abrufe ) {
			$abrufe[] = $url;
			if ( false !== strpos( $url, '/sts/10050/' ) ) {
				return self::$total;
			}
			if ( false !== strpos( $url, '/sts/10021/' ) ) {
				// Eine Veranstaltungsseite ohne jeden Datumshinweis.
				return '<html><body><h1>Lauf ohne Datum</h1></body></html>';
			}
			// Jede Jahresübersicht ist leer – der schlechteste Fall.
			return '<html><body><div id="competitions"></div></body></html>';
		};

		$adapter = new LSG_BL_Runtix_Adapter( $getter );
		$ref     = new LSG_BL_Event_Ref( 'runtix', '3152', 'https://runtix.com/sts/10050/3152' );

		$d = $adapter->datum( $ref );

		$jahresabrufe = 0;
		foreach ( $abrufe as $u ) {
			if ( false !== strpos( $u, '/sts/10020/' ) ) {
				++$jahresabrufe;
			}
		}

		$this->assertLessThanOrEqual( 2, $jahresabrufe, 'Abrufe: ' . implode( ', ', $abrufe ) );
		$this->assertSame( '', $d['datum'], 'Im Zweifel leer – kein stiller 1. Januar.' );
		$this->assertNotSame( '', $d['hinweis'], 'Die Oberfläche muss etwas anzeigen können.' );
	}

	/**
	 * Findet die Übersicht nichts, ist der Ausschreibungstext der
	 * zweitbeste Wert – und wird als solcher ausgewiesen, damit die
	 * Oberfläche zur Bestätigung auffordert.
	 */
	public function test_ausschreibung_als_rueckfall() {
		$getter = function ( $url ) {
			if ( false !== strpos( $url, '/sts/10050/' ) ) {
				return self::$total;
			}
			if ( false !== strpos( $url, '/sts/10021/' ) ) {
				return self::$event;
			}
			return '<html><body><div id="competitions"></div></body></html>';
		};

		$adapter = new LSG_BL_Runtix_Adapter( $getter );
		$ref     = new LSG_BL_Event_Ref( 'runtix', '3152', 'https://runtix.com/sts/10050/3152' );

		$d = $adapter->datum( $ref );

		$this->assertSame( '2026-08-16', $d['datum'] );
		$this->assertSame( 'ausschreibung', $d['quelle'] );
		$this->assertStringContainsString( 'prüfen', $d['hinweis'] );
	}

	public function test_quelle_url_zeigt_auf_die_gelesene_liste() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://runtix.com/sts/10050/3152/21/total' );

		$this->assertSame(
			'https://runtix.com/sts/10050/3152/21/total',
			$adapter->quelleUrl( $ref, '21', 'total' )
		);
		// Ohne Listenangabe die Gesamtwertung – das ist, was ein Mensch
		// unter „die Ergebnisliste" versteht.
		$this->assertSame(
			'https://runtix.com/sts/10050/3152/w/total',
			$adapter->quelleUrl( $ref, 'w' )
		);
	}

	public function test_laden_ohne_wettbewerb_wird_abgelehnt() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://runtix.com/sts/10050/3152' );

		$this->expectException( 'LSG_BL_Quelle_Exception' );
		$adapter->laden( $ref, '' );
	}

	public function test_laden_legt_kennzahlen_am_kontext_ab() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://runtix.com/sts/10050/3152/21/total' );

		$zeilen = $adapter->laden( $ref, '21', 'total' );

		$this->assertCount( 22, $zeilen );
		$this->assertSame( 22, $ref->meta['p1']['gelesen'] );
		$this->assertSame( 0, $ref->meta['p1']['verworfen'] );
		$this->assertSame( 'brutto', $ref->meta['p1']['zeit_typ'] );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Eine Zeile über einen Namensteil finden.
	 *
	 * @param string $suche Teilstring des rohen Teilnehmerfelds.
	 * @return LSG_BL_Ergebnis
	 */
	private function zeile( $suche ) {
		foreach ( $this->liste()['zeilen'] as $z ) {
			if ( false !== mb_strpos( $z->teilnehmer, $suche ) ) {
				return $z;
			}
		}
		$this->fail( 'Keine Zeile mit „' . $suche . '" in der Fixture.' );
	}
}
