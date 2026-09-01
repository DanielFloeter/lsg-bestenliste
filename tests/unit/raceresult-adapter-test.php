<?php
/**
 * RaceResultAdapter gegen die Referenz-Fixture (Ettlingen 375768,
 * Hauptlauf 21,1km, 658 Datensätze).
 *
 * Kein Netz, kein WordPress: der Adapter bekommt seinen Getter injiziert.
 * Genau dafür ist der Abruf vom Parser getrennt (Plan, Abschnitt 5).
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class RaceResult_Adapter_Test extends TestCase {

	/** @var string */
	private static $config;

	/** @var string */
	private static $liste;

	public static function setUpBeforeClass(): void {
		self::$config = lsg_bl_fixture( 'raceresult-375768-config.json' );
		self::$liste  = lsg_bl_fixture( 'raceresult-375768-contest2.json' );
	}

	/**
	 * Ein Adapter, dessen Getter aus den Fixtures bedient wird.
	 *
	 * @return LSG_BL_RaceResult_Adapter
	 */
	private function adapter() {
		return new LSG_BL_RaceResult_Adapter(
			lsg_bl_fake_getter(
				array(
					'/results/config' => self::$config,
					'/results/list'   => self::$liste,
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @dataProvider urls
	 *
	 * @param string $url      Eingegebene URL.
	 * @param int    $erwartet Erwarteter Score.
	 */
	public function test_erkennung( $url, $erwartet ) {
		$this->assertSame( $erwartet, LSG_BL_RaceResult_Adapter::erkennt( $url ), 'URL: ' . $url );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public function urls() {
		return array(
			'mit ID und Fragment' => array( 'https://my.raceresult.com/375768/#2_B45FAB', 90 ),
			'mit ID'              => array( 'https://my.raceresult.com/375768/', 90 ),
			'ohne trailing slash' => array( 'https://my.raceresult.com/375768', 90 ),
			'ohne ID'             => array( 'https://my.raceresult.com/', 40 ),
			'anderer Subdomain'   => array( 'https://my4.raceresult.com/375768/results/list', 90 ),
			'Apex'                => array( 'https://raceresult.com/375768/', 90 ),

			// ⚠ Die Prüfung läuft auf dem Host, nie auf der ganzen URL.
			'Tippfehler-Domain'   => array( 'https://boeseraceresult.com/375768/', 0 ),
			'Name im Querystring' => array( 'https://angreifer.example/?x=my.raceresult.com', 0 ),
			'fremdes Portal'      => array( 'https://runtix.com/sts/10050/3152/21/total', 0 ),
			'Unsinn'              => array( 'nicht mal eine url', 0 ),
			'leer'                => array( '', 0 ),
		);
	}

	public function test_fragment_wird_gelesen() {
		$f = LSG_BL_RaceResult_Adapter::fragment_lesen( 'https://my.raceresult.com/375768/#2_B45FAB' );
		$this->assertSame( '2', $f['contest'] );
		$this->assertSame( 'B45FAB', $f['list'] );

		$leer = LSG_BL_RaceResult_Adapter::fragment_lesen( 'https://my.raceresult.com/375768/' );
		$this->assertSame( '', $leer['contest'] );
		$this->assertSame( '', $leer['list'] );
	}

	/* ------------------------------------------------------------------ */

	public function test_config_wird_ausgewertet() {
		$c = LSG_BL_RaceResult_Adapter::parse_config( self::$config );

		$this->assertSame( 'my4.raceresult.com', $c['server'] );
		$this->assertNotSame( 'my.raceresult.com', $c['server'], 'Der Datenserver ist nicht der Eingangsserver.' );
		$this->assertNotSame( '', $c['key'] );
		$this->assertSame( '17. SWE Halbmarathon Ettlingen', $c['eventname'] );

		$this->assertCount( 9, $c['contests'] );
		$this->assertSame( 'Hauptlauf 21,1km', $c['contests']['2'] );
		$this->assertSame( 'Walking 21,1km', $c['contests']['1'] );

		// Numerisch sortiert – sonst stünde "11" vor "2".
		// (array_keys() liefert hier int, weil PHP numerische String-Keys
		// still zu int macht. Der Contest-Key selbst bleibt trotzdem ein
		// String – LSG_BL_Wettbewerb castet ihn, damit Runtix' "w" überlebt.)
		$this->assertSame(
			array( '1', '2', '8', '9', '11', '12', '13', '14', '15' ),
			array_map( 'strval', array_keys( $c['contests'] ) )
		);

		// ⚠ Die Listen stehen unter Tab.Config.Lists, nicht unter
		// config.lists wie in Plan 4.1 notiert.
		$this->assertCount( 16, $c['lists'] );
	}

	public function test_listen_haengen_am_wettbewerb() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://my.raceresult.com/375768/#2_B45FAB' );

		$listen = $adapter->listen( $ref, '2' );
		$this->assertCount( 3, $listen );

		$namen = array_map(
			function ( $l ) {
				return $l->name;
			},
			$listen
		);
		$this->assertSame(
			array( 'Gesamtergebnisliste', 'Ergebnisliste M/W', 'Ergebnisliste AK' ),
			$namen
		);

		// Nur die Gesamtwertung darf einen Gesamtsieg melden – in einer nach
		// Geschlecht oder Altersklasse gefilterten Liste ist Platz 1 ein
		// Klassensieg (Plan 6.5.5).
		$this->assertTrue( $listen[0]->gesamtwertung );
		$this->assertFalse( $listen[1]->gesamtwertung );
		$this->assertFalse( $listen[2]->gesamtwertung );

		// Der Walking-Wettbewerb hat eine eigene Liste.
		$this->assertCount( 1, $adapter->listen( $ref, '1' ) );
	}

	public function test_wettbewerbe_kommen_als_value_objects() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://my.raceresult.com/375768/' );

		$w = $adapter->wettbewerbe( $ref );
		$this->assertCount( 9, $w );
		$this->assertInstanceOf( 'LSG_BL_Wettbewerb', $w[0] );

		// Contest-Keys bleiben Strings.
		foreach ( $w as $eintrag ) {
			$this->assertIsString( $eintrag->id );
		}
	}

	public function test_event_lesen_fuellt_den_kontext() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://my.raceresult.com/375768/#2_B45FAB' );

		$this->assertSame( 'raceresult', $ref->adapter );
		$this->assertSame( '375768', $ref->event_id );
		$this->assertSame( '17. SWE Halbmarathon Ettlingen', $ref->event_name );
		$this->assertSame( '2', $ref->contest_id );
		$this->assertSame( 'B45FAB', $ref->list_id );
	}

	public function test_url_ohne_event_id_wird_abgelehnt() {
		$this->expectException( 'LSG_BL_Quelle_Exception' );
		$this->adapter()->eventLesen( 'https://my.raceresult.com/' );
	}

	/* ------------------------------------------------------------------ */

	public function test_liste_wird_vollstaendig_gelesen() {
		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( self::$liste );

		// 658 Datensätze – identisch zur Zahl in der früheren
		// zieleinlauf.json, mit der die Quelle verifiziert wurde.
		$this->assertSame( 658, $p1['gelesen'] );

		// Eine DNS-Zeile wird verworfen und gezählt, nicht stillschweigend
		// durchgereicht.
		$this->assertSame( 1, $p1['verworfen'] );
		$this->assertCount( 657, $p1['zeilen'] );

		// Die Liste heißt „…Brutto" und führt kein Netto-Feld.
		$this->assertSame( 'brutto', $p1['zeit_typ'] );
		$this->assertSame( '01.1_Ergebnisse|Zieleinlauf_Brutto', $p1['listname'] );
	}

	/**
	 * ⚠ Der Kern des Feld-Mappings: die Datenzeilen haben zwei zusätzliche
	 * führende Felder (BIB, ID) vor dem Platz. 8 Labels stehen 9 Werten
	 * gegenüber. Wer nach Spaltenposition rät, liest alles um zwei
	 * verschoben – und merkt es nicht, weil Platz und Startnummer beide
	 * Zahlen sind.
	 */
	public function test_feldmapping_ueber_datafields() {
		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( self::$liste );
		$e  = $p1['zeilen'][0];

		$this->assertSame( 'BORGHARDT', $e->nachname );
		$this->assertSame( 'Lukas', $e->vorname );
		$this->assertSame( 'BORGHARDT Lukas', $e->teilnehmer );
		$this->assertFalse( $e->namen_unsicher );
		$this->assertSame( 'TV Bad Säckingen', $e->verein );
		$this->assertSame( 1991, $e->jahrgang );
		$this->assertSame( 'm', $e->geschlecht );
		$this->assertSame( '01:13:08', $e->zeit );
		$this->assertSame( '1:13:08', $e->roh_zeit );
		$this->assertSame( 'brutto', $e->zeit_typ );
		$this->assertSame( '1', $e->platz );
		$this->assertSame( '396', $e->startnummer );
	}

	public function test_umlaute_ueberleben_den_parser() {
		$p1     = LSG_BL_RaceResult_Adapter::parse_liste( self::$liste );
		$namen  = array();
		foreach ( $p1['zeilen'] as $e ) {
			$namen[] = $e->nachname;
		}

		$this->assertContains( 'KÜHN', $namen );
		$this->assertContains( 'HÄFFNER', $namen );
		$this->assertContains( 'GEIßLER', $namen );
		$this->assertContains( 'STÖßER', $namen );

		$vereine = array();
		foreach ( $p1['zeilen'] as $e ) {
			$vereine[ $e->verein ] = true;
		}
		$this->assertArrayHasKey( 'TV Bad Säckingen', $vereine );
	}

	/**
	 * P2 auf echten Daten. Dieselbe Liste enthält „LSG Weiher" neben
	 * „LSG Karlsruhe" – genau der Fall, für den die UND-Verknüpfung da ist.
	 */
	public function test_vereinsfilter_auf_der_echten_liste() {
		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( self::$liste );

		$lsg     = array();
		$verpasst = array();
		foreach ( $p1['zeilen'] as $e ) {
			if ( lsg_bl_ist_lsg( $e->verein ) ) {
				$lsg[] = $e;
			} elseif ( false !== stripos( $e->verein, 'lsg' ) ) {
				$verpasst[ $e->verein ] = true;
			}
		}

		$this->assertCount( 11, $lsg, 'Elf LSG-Karlsruhe-Zeilen in dieser Liste.' );

		foreach ( $lsg as $e ) {
			$this->assertStringContainsString( 'LSG Karlsruhe', $e->verein );
		}

		// LSG Weiher ist ein anderer Verein und fällt bewusst durch.
		$this->assertArrayHasKey( 'LSG Weiher', $verpasst );
	}

	/**
	 * Der Weg von der URL bis zu den normalisierten Zeilen – ohne Netz.
	 * Das ist das Abnahmekriterium von M1.
	 */
	public function test_laden_von_der_url_bis_zur_zeile() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://my.raceresult.com/375768/#2_B45FAB' );
		$zeilen  = $adapter->laden( $ref, '2', 'B45FAB' );

		$this->assertCount( 657, $zeilen );
		$this->assertInstanceOf( 'LSG_BL_Ergebnis', $zeilen[0] );

		// Die Kennzahlen für den Trichter hängen am Kontext.
		$this->assertSame( 658, $ref->meta['p1']['gelesen'] );
		$this->assertSame( 1, $ref->meta['p1']['verworfen'] );
		$this->assertSame( 'brutto', $ref->meta['p1']['zeit_typ'] );

		// Jede Zeile trägt eine verwertbare Zeit – sonst wäre sie nicht hier.
		foreach ( $zeilen as $e ) {
			$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}:\d{2}$/', $e->zeit );
		}
	}

	public function test_unbekannte_liste_wird_gemeldet() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://my.raceresult.com/375768/' );

		$this->expectException( 'LSG_BL_Quelle_Exception' );
		$adapter->laden( $ref, '2', 'GIBTSNICHT' );
	}

	public function test_kaputte_antworten_werfen_klartext() {
		try {
			LSG_BL_RaceResult_Adapter::parse_config( 'kein json' );
			$this->fail( 'Erwartet wurde eine LSG_BL_Quelle_Exception.' );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$this->assertStringContainsString( 'JSON', $e->getMessage() );
		}

		try {
			LSG_BL_RaceResult_Adapter::parse_config( '{"server":"my4.raceresult.com"}' );
			$this->fail( 'Erwartet wurde eine LSG_BL_Quelle_Exception.' );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$this->assertStringContainsString( 'key', $e->getMessage() );
		}

		$this->expectException( 'LSG_BL_Quelle_Exception' );
		LSG_BL_RaceResult_Adapter::parse_liste( '{"list":{}}' );
	}

	public function test_leere_ergebnisliste_ist_kein_fehler() {
		$leer = '{"list":{"ListName":"x","Fields":[' .
			'{"Expression":"AnzeigeName","Label":"Name"},' .
			'{"Expression":"MitStatus([TIME2])","Label":"Zeit"}' .
			']},"data":[],"DataFields":["BIB","ID","AnzeigeName","MitStatus([TIME2])"]}';

		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( $leer );

		$this->assertSame( 0, $p1['gelesen'] );
		$this->assertSame( array(), $p1['zeilen'] );
	}

	/**
	 * Nettozeit hat Vorrang, und der verwendete Typ wird mitgeführt – sonst
	 * vergleicht man später Netto gegen Brutto, ohne es zu merken.
	 */
	public function test_netto_schlaegt_brutto() {
		$json = '{"list":{"ListName":"x","Fields":[' .
			'{"Expression":"AnzeigeName","Label":"Name"},' .
			'{"Expression":"YEAR","Label":"Jg"},' .
			'{"Expression":"T1","Label":"Zeit"},' .
			'{"Expression":"T2","Label":"Netto"}' .
			']},"data":[["7","8","MUSTER Erika","1990","1:00:00","0:59:00"]],' .
			'"DataFields":["BIB","ID","AnzeigeName","YEAR","T1","T2"]}';

		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( $json );

		$this->assertSame( 'netto', $p1['zeit_typ'] );
		$this->assertSame( '00:59:00', $p1['zeilen'][0]->zeit );
		$this->assertSame( 'netto', $p1['zeilen'][0]->zeit_typ );
	}

	/**
	 * Gruppierte Listen (die AK-Liste) liefern data als Objekt, nicht als
	 * Array. Beide Formen müssen dieselbe flache Zeilenliste ergeben.
	 */
	public function test_gruppierte_liste_wird_flachgezogen() {
		$json = '{"list":{"ListName":"AK","Fields":[' .
			'{"Expression":"AnzeigeName","Label":"Name"},' .
			'{"Expression":"T","Label":"Zeit"}' .
			']},"data":{"M30":[["1","2","EINS Anna","1:00:00"],["3","4","ZWEI Bea","1:01:00"]],' .
			'"M40":[["5","6","DREI Cara","1:02:00"]]},' .
			'"DataFields":["BIB","ID","AnzeigeName","T"]}';

		$p1 = LSG_BL_RaceResult_Adapter::parse_liste( $json );

		$this->assertSame( 3, $p1['gelesen'] );
		$this->assertSame( 'EINS', $p1['zeilen'][0]->nachname );
		$this->assertSame( 'DREI', $p1['zeilen'][2]->nachname );
	}
}
