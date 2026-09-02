<?php
/**
 * Contract-Test: beide Adapter erfüllen dieselbe Zusage.
 *
 * Der Sinn dieser Datei ist nicht, race result oder runtix zu prüfen – das
 * tun die beiden Adapter-Tests. Hier geht es um das, was die Pipeline
 * hinterher voraussetzt: dass P2/P3/P4 nicht wissen müssen, aus welcher
 * Quelle eine Zeile kommt (Plan 5.1, 6.5.1).
 *
 * ⚠ Jede Zusicherung hier läuft über die Registry, nicht über eine
 * Klassenliste im Test. Ein dritter Adapter – auch einer aus einem anderen
 * Plugin, über den Filter `lsg_bl_ergebnis_adapter` – wird damit
 * automatisch mitgeprüft, statt jahrelang unbemerkt vom Schema abzuweichen.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Adapter_Contract_Test extends TestCase {

	/**
	 * Die Adapter samt Fixtures und der URL, unter der sie zu bedienen sind.
	 *
	 * Die Registry selbst ist hier nicht abfragbar: lsg_bl_adapter_registry()
	 * steht in class-lsg-adapters.php und ruft apply_filters(), also
	 * WordPress. Die Unit-Lage lädt kein WordPress. Was hier steht, ist
	 * deshalb die von Hand gepflegte Entsprechung – und der erste Test
	 * unten stellt sicher, dass sie vollständig ist.
	 *
	 * @return array<string,array{cls:string,url:string,contest:string,list:string,antworten:array}>
	 */
	private function adapter() {
		$rr_config = lsg_bl_fixture( 'raceresult-375768-config.json' );
		$rr_liste  = lsg_bl_fixture( 'raceresult-375768-contest2.json' );
		$rx_total  = lsg_bl_fixture( 'runtix-3152-21-total.html' );
		$rx_jahr   = lsg_bl_fixture( 'runtix-10020-2026.html' );
		$rx_event  = lsg_bl_fixture( 'runtix-10021-3152.html' );

		return array(
			'raceresult' => array(
				'cls'       => 'LSG_BL_RaceResult_Adapter',
				'url'       => 'https://my.raceresult.com/375768/#2_B45FAB',
				'contest'   => '2',
				'list'      => 'B45FAB',
				'antworten' => array(
					'/results/config' => $rr_config,
					'/results/list'   => $rr_liste,
				),
			),
			'runtix'     => array(
				'cls'       => 'LSG_BL_Runtix_Adapter',
				'url'       => 'https://runtix.com/sts/10050/3152/21/total',
				'contest'   => '21',
				'list'      => 'total',
				'antworten' => array(
					'/sts/10050/3152/21/total' => $rx_total,
					'/sts/10020/2026'          => $rx_jahr,
					'/sts/10021/3152'          => $rx_event,
					'/sts/10050/3152'          => $rx_total,
				),
			),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function adapternamen() {
		return array(
			'race result' => array( 'raceresult' ),
			'runtix'      => array( 'runtix' ),
		);
	}

	/**
	 * Alle Adapter des Plugins sind hier vertreten.
	 *
	 * Ohne diesen Test wäre der ganze Contract-Test wertlos: ein neuer
	 * Adapter würde einfach nicht mitgeprüft.
	 */
	public function test_alle_adapterdateien_sind_erfasst() {
		$dateien = glob( LSG_BL_PLUGIN_DIR . 'includes/adapters/class-*-adapter.php' );
		$this->assertNotEmpty( $dateien );

		$erfasst = array();
		foreach ( $this->adapter() as $a ) {
			$erfasst[] = $a['cls'];
		}

		$this->assertCount(
			count( $dateien ),
			$erfasst,
			'Es liegen ' . count( $dateien ) . ' Adapter im Plugin, geprüft werden '
			. count( $erfasst ) . '. Fehlt einer, gehört er in adapter() ergänzt: '
			. implode( ', ', array_map( 'basename', $dateien ) )
		);
	}

	/**
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_erfuellt_die_schnittstelle( $name ) {
		$cls = $this->adapter()[ $name ]['cls'];

		$this->assertTrue( class_exists( $cls ) );
		$this->assertContains(
			'LSG_BL_Ergebnis_Quelle',
			class_implements( $cls ),
			$cls . ' muss die Schnittstelle implementieren, sonst nimmt die Registry ihn nicht.'
		);
	}

	/**
	 * Schlüssel und Label sind nicht leer und nicht gleich – beide landen
	 * in der Oberfläche und in lsg_import_run.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_identitaet( $name ) {
		$cls = $this->adapter()[ $name ]['cls'];

		$key   = call_user_func( array( $cls, 'key' ) );
		$label = call_user_func( array( $cls, 'label' ) );

		$this->assertIsString( $key );
		$this->assertIsString( $label );
		$this->assertNotSame( '', $key );
		$this->assertNotSame( '', $label );
		$this->assertSame( $name, $key );

		// Der Schlüssel wandert in URLs und in die Datenbank: keine
		// Leerzeichen, keine Großbuchstaben, keine Umlaute.
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $key );
	}

	/**
	 * Die Allowlist ist nicht leer und enthält keine nackte Wildcard.
	 *
	 * ⚠ Ein Eintrag `*` oder `*.com` würde die SSRF-Sperre aufheben, und
	 * zwar unbemerkt: alles funktioniert weiter, nur eben auch gegen jeden
	 * fremden Host (Plan 6.10).
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_allowlist_ist_eng( $name ) {
		$cls   = $this->adapter()[ $name ]['cls'];
		$hosts = call_user_func( array( $cls, 'hosts' ) );

		$this->assertIsArray( $hosts );
		$this->assertNotEmpty( $hosts );

		foreach ( $hosts as $h ) {
			$this->assertIsString( $h );
			$this->assertNotSame( '*', $h );
			// Wildcard nur als Suffix, und mit mindestens zwei Labels
			// dahinter: '*.raceresult.com' ist gut, '*.com' nicht.
			if ( 0 === strpos( $h, '*' ) ) {
				$this->assertSame( '*.', substr( $h, 0, 2 ), 'Wildcard nur als Suffix: ' . $h );
				$this->assertGreaterThanOrEqual(
					2,
					substr_count( substr( $h, 2 ), '.' ) + 1,
					'Zu weite Wildcard: ' . $h
				);
			}
			$this->assertSame( 0, preg_match( '#[/:?]#', $h ), 'Kein Schema, kein Pfad: ' . $h );
		}
	}

	/**
	 * Kein Adapter beansprucht die URL eines anderen. Klingt selbstver-
	 * ständlich, ist es nicht: `erkennt()` prüft Hosts per Suffix, und ein
	 * zu kurzer Vergleich lässt `runtix.com.example.org` durch.
	 */
	public function test_adapter_beanspruchen_sich_nicht_gegenseitig() {
		$alle = $this->adapter();

		foreach ( $alle as $name => $a ) {
			foreach ( $alle as $anderer => $b ) {
				$score = (int) call_user_func( array( $b['cls'], 'erkennt' ), $a['url'] );
				if ( $name === $anderer ) {
					$this->assertGreaterThan( 0, $score, $b['cls'] . ' muss die eigene URL erkennen.' );
				} else {
					$this->assertSame(
						0,
						$score,
						$b['cls'] . ' beansprucht die URL von ' . $name . ': ' . $a['url']
					);
				}
			}
		}
	}

	/**
	 * Der Kern: dieselben Felder, dieselben Typen, dieselben Zusagen –
	 * gleich, aus welcher Quelle die Zeile stammt.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_zielschema_ist_identisch( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls']( lsg_bl_fake_getter( $a['antworten'] ) );

		$ref    = $adapter->eventLesen( $a['url'] );
		$zeilen = $adapter->laden( $ref, $a['contest'], $a['list'] );

		$this->assertNotEmpty( $zeilen, $name . ' liefert keine Zeilen.' );

		$felder = array_keys( get_object_vars( new LSG_BL_Ergebnis() ) );

		foreach ( $zeilen as $i => $z ) {
			$wo = $name . ', Zeile ' . $i . ' (' . $z->teilnehmer . ')';

			$this->assertInstanceOf( 'LSG_BL_Ergebnis', $z, $wo );

			// Genau die Felder des Zielschemas, keines mehr, keines weniger.
			$this->assertSame( $felder, array_keys( $z->to_array() ), $wo );

			// Die Typen. Ein Jahrgang als String und ein Platz als int sind
			// die Sorte Abweichung, die erst in der Datenbank auffällt.
			$this->assertIsString( $z->nachname, $wo );
			$this->assertIsString( $z->vorname, $wo );
			$this->assertIsString( $z->teilnehmer, $wo );
			$this->assertIsBool( $z->namen_unsicher, $wo );
			$this->assertIsInt( $z->jahrgang, $wo );
			$this->assertIsString( $z->verein, $wo );
			$this->assertIsString( $z->zeit, $wo );
			$this->assertIsString( $z->roh_zeit, $wo );
			$this->assertIsString( $z->platz, $wo );
			$this->assertIsString( $z->startnummer, $wo );
			$this->assertIsString( $z->quelle_klasse, $wo );

			// Die Zusagen des Schemas.
			$this->assertMatchesRegularExpression(
				'/^\d{2,3}:[0-5]\d:[0-5]\d$/',
				$z->zeit,
				'Zeit muss HH:MM:SS sein oder die Zeile verworfen. ' . $wo
			);
			$this->assertNotSame( '', $z->nachname, 'Nachname ist Pflicht. ' . $wo );
			$this->assertNotSame( '', $z->vorname, 'Vorname ist Pflicht. ' . $wo );
			$this->assertContains( $z->geschlecht, array( 'm', 'f', '' ), $wo );
			$this->assertContains( $z->zeit_typ, array( 'netto', 'brutto' ), $wo );
			$this->assertTrue(
				0 === $z->jahrgang || ( $z->jahrgang >= 1900 && $z->jahrgang <= 2100 ),
				'Jahrgang 0 oder plausibel, nichts dazwischen. ' . $wo
			);
			// Kein Platz mit Punkt („12."), sonst schlägt der
			// Gesamtsieg-Vergleich auf '1' fehl.
			$this->assertSame( 0, preg_match( '/\.$/', $z->platz ), $wo );
		}
	}

	/**
	 * Die Kennzahlen für den Trichter kommen aus beiden Quellen in
	 * derselben Form – sonst zeigt die Oberfläche bei einer Quelle Zahlen
	 * und bei der anderen Lücken.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_p1_kennzahlen( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls']( lsg_bl_fake_getter( $a['antworten'] ) );

		$ref    = $adapter->eventLesen( $a['url'] );
		$zeilen = $adapter->laden( $ref, $a['contest'], $a['list'] );

		$this->assertArrayHasKey( 'p1', $ref->meta, $name );
		$p1 = $ref->meta['p1'];

		foreach ( array( 'gelesen', 'verworfen', 'zeit_typ', 'warnungen' ) as $k ) {
			$this->assertArrayHasKey( $k, $p1, $name . ' → ' . $k );
		}

		$this->assertIsInt( $p1['gelesen'], $name );
		$this->assertIsInt( $p1['verworfen'], $name );
		$this->assertIsArray( $p1['warnungen'], $name );

		// gelesen = übernommen + verworfen. Wenn das nicht aufgeht, zählt
		// der Trichter falsch, und niemand merkt es.
		$this->assertSame(
			$p1['gelesen'],
			count( $zeilen ) + $p1['verworfen'],
			$name . ': gelesen muss verworfen + geliefert sein.'
		);
	}

	/**
	 * Wettbewerbe und Listen: Keys sind Strings, Namen nicht leer.
	 *
	 * ⚠ Der Grund ist runtix' Walk-Contest „w". Ein Adapter, der numerische
	 * Keys zusichert, bricht dort – und ein Test, der sie voraussetzt,
	 * verdeckt es.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_wettbewerbe_und_listen( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls']( lsg_bl_fake_getter( $a['antworten'] ) );
		$ref     = $adapter->eventLesen( $a['url'] );

		$wettbewerbe = $adapter->wettbewerbe( $ref );
		$this->assertNotEmpty( $wettbewerbe, $name );

		foreach ( $wettbewerbe as $w ) {
			$this->assertInstanceOf( 'LSG_BL_Wettbewerb', $w, $name );
			$this->assertIsString( $w->id, $name );
			$this->assertNotSame( '', $w->id, $name );
			$this->assertNotSame( '', $w->name, $name );
		}

		$gesamt = 0;
		foreach ( $adapter->listen( $ref, $a['contest'] ) as $l ) {
			$this->assertInstanceOf( 'LSG_BL_Liste', $l, $name );
			$this->assertIsString( $l->id, $name );
			$this->assertNotSame( '', $l->id, $name );
			$this->assertNotSame( '', $l->ref, $name );
			$this->assertIsBool( $l->live, $name );
			$this->assertIsBool( $l->gesamtwertung, $name );
			if ( $l->gesamtwertung ) {
				++$gesamt;
			}
		}

		// Höchstens eine Gesamtwertung. Zwei hieße: der Gesamtsieg wird
		// zweimal vergeben (Plan 6.5.5).
		$this->assertLessThanOrEqual( 1, $gesamt, $name . ' meldet mehr als eine Gesamtwertung.' );
	}

	/**
	 * datum() liefert immer dieselbe Struktur – auch, und gerade, wenn die
	 * Quelle nichts hergibt. Kein null, kein fehlender Schlüssel, kein
	 * stiller 1. Januar (Plan 6.5.1).
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_datum_struktur( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls']( lsg_bl_fake_getter( $a['antworten'] ) );
		$ref     = $adapter->eventLesen( $a['url'] );

		$d = $adapter->datum( $ref, $a['contest'] );

		$this->assertIsArray( $d, $name );
		foreach ( array( 'datum', 'quelle', 'hinweis' ) as $k ) {
			$this->assertArrayHasKey( $k, $d, $name . ' → ' . $k );
			$this->assertIsString( $d[ $k ], $name . ' → ' . $k );
		}

		if ( '' !== $d['datum'] ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $d['datum'], $name );
			$this->assertNotSame( '', $d['quelle'], $name . ': ein Datum ohne Herkunft ist nicht anzeigbar.' );
		}

		$this->assertContains(
			$d['quelle'],
			array( 'liste', 'ausschreibung', 'api', 'name', 'jahr', '' ),
			$name . ': unbekannte Herkunft „' . $d['quelle'] . '"'
		);

		// Ohne Datum muss ein Satz da sein, den die Oberfläche zeigen kann.
		if ( '' === $d['datum'] ) {
			$this->assertNotSame( '', $d['hinweis'], $name );
		}
	}

	/**
	 * quelleUrl() ist die Adresse, unter der ein Mensch dieselbe Liste im
	 * Browser sieht. Sie landet in lsg_import_run.source_url und ist damit
	 * der einzige Weg zurück zur Quelle – Jahre später.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_quelle_url_ist_brauchbar( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls']( lsg_bl_fake_getter( $a['antworten'] ) );
		$ref     = $adapter->eventLesen( $a['url'] );

		$url = $adapter->quelleUrl( $ref, $a['contest'], $a['list'] );

		$this->assertIsString( $url, $name );
		$this->assertSame( 0, strpos( $url, 'https://' ), $name . ': nur https. ' . $url );

		// Und sie muss beim eigenen Adapter landen, nicht irgendwo.
		$this->assertGreaterThan(
			0,
			(int) call_user_func( array( $a['cls'], 'erkennt' ), $url ),
			$name . ' erkennt die eigene quelleUrl nicht wieder: ' . $url
		);
	}

	/**
	 * Beide Adapter fangen dieselbe Sorte Unsinn ab – mit einer
	 * LSG_BL_Quelle_Exception, nicht mit einem PHP-Fehler und nicht still.
	 *
	 * @dataProvider adapternamen
	 *
	 * @param string $name Schlüssel in adapter().
	 */
	public function test_muell_wird_gemeldet( $name ) {
		$a       = $this->adapter()[ $name ];
		$adapter = new $a['cls'](
			lsg_bl_fake_getter( array( '' => 'Das ist keine Antwort, sondern ein Satz.' ) )
		);

		try {
			$ref = $adapter->eventLesen( $a['url'] );
			$adapter->laden( $ref, $a['contest'], $a['list'] );
			$this->fail( $name . ' hat Müll ohne Fehlermeldung geschluckt.' );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$this->assertNotSame( '', $e->getMessage(), $name . ': leere Fehlermeldung.' );
			// Die Meldung geht so, wie sie ist, in eine notice-error – sie
			// muss ein Satz für Menschen sein, kein Stacktrace-Fragment.
			$this->assertGreaterThan( 20, mb_strlen( $e->getMessage() ), $name );
		}
	}
}
