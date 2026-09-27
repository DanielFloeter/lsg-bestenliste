<?php
/**
 * RacepediaAdapter gegen die gespeicherten HTML-Fixtures
 * (Topiblüten-Lauf Staffort 2026, 10 km Lauf, Wettbewerb 8112).
 *
 * Kein Netz, kein WordPress: der Adapter bekommt seinen Getter injiziert.
 *
 * Die beiden Fixtures sind Byte-Kopien vom 2026-09-27.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Racepedia_Adapter_Test extends TestCase {

	/** @var string */
	private static $start;

	/** @var string */
	private static $liste;

	public static function setUpBeforeClass(): void {
		self::$start = lsg_bl_fixture( 'racepedia-topiblueten-2026-ergebnisse.html' );
		self::$liste = lsg_bl_fixture( 'racepedia-topiblueten-2026-8112-all.html' );
	}

	/**
	 * ⚠ Die Reihenfolge der Muster zählt: '/ergebnisse' trifft auch
	 * '/ergebnisse/8112/all/all'.
	 *
	 * @return LSG_BL_Racepedia_Adapter
	 */
	private function adapter() {
		return new LSG_BL_Racepedia_Adapter(
			lsg_bl_fake_getter(
				array(
					'/ergebnisse/8112/all/all' => self::$liste,
					'/ergebnisse'              => self::$start,
				)
			)
		);
	}

	/**
	 * Eine Tabelle im racepedia-Aufbau, für die Sonderfälle.
	 *
	 * @param string[] $koepfe Spaltenköpfe (ohne die versteckte erste).
	 * @param array[]  $zeilen Zellen je Zeile (ohne die versteckte erste).
	 * @return string
	 */
	private static function tabelle( array $koepfe, array $zeilen ) {
		$html = "<html><body><table class='datatable result_table'><thead><tr><th style='display:none;'></th>";
		foreach ( $koepfe as $k ) {
			$html .= '<th>' . $k . '</th>';
		}
		$html .= '<th></th></tr></thead><tbody>';
		foreach ( $zeilen as $z ) {
			$html .= "<tr><td style='display:none;'>X</td>";
			foreach ( $z as $zelle ) {
				$html .= '<td>' . $zelle . '</td>';
			}
			$html .= '<td></td></tr>';
		}
		return $html . '</tbody></table></body></html>';
	}

	/* ------------------------------------------------------------------
	 * Erkennung und URL-Zerlegung
	 * --------------------------------------------------------------- */

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public function erkennung() {
		return array(
			'Übersicht'          => array( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse', 90 ),
			'Wettbewerb'         => array( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/w', 90 ),
			'Startseite'         => array( 'https://burglauf-2026.racepedia.de/', 90 ),
			'Großbuchstaben'     => array( 'https://Forstsportlauf-2026.racepedia.de/ergebnisse', 90 ),
			'Portal www'         => array( 'https://www.racepedia.de/', 40 ),
			'Portal nackt'       => array( 'https://racepedia.de/', 40 ),
			'fremder Host'       => array( 'https://my.raceresult.com/375768/', 0 ),
			'Suffix-Angriff'     => array( 'https://x.racepedia.de.angreifer.example/ergebnisse', 0 ),
			'Präfix-Angriff'     => array( 'https://boeseracepedia.de/ergebnisse', 0 ),
			'keine URL'          => array( 'Topiblüten-Lauf', 0 ),
		);
	}

	/**
	 * @dataProvider erkennung
	 *
	 * @param string $url      Eingabe.
	 * @param int    $erwartet Score.
	 */
	public function test_erkennt( $url, $erwartet ) {
		$this->assertSame( $erwartet, LSG_BL_Racepedia_Adapter::erkennt( $url ), 'URL: ' . $url );
	}

	/**
	 * @return array<string,array{0:string,1:array}>
	 */
	public function zerlegung() {
		$ev = 'topiblueten-lauf-staffort-2026';
		$b  = 'https://' . $ev . '.racepedia.de';
		return array(
			'Übersicht'    => array( $b . '/ergebnisse', array( 'event_id' => $ev, 'contest' => '', 'liste' => '' ) ),
			'Wettbewerb'   => array( $b . '/ergebnisse/8112', array( 'event_id' => $ev, 'contest' => '8112', 'liste' => '' ) ),
			'Gesamt'       => array( $b . '/ergebnisse/8112/all/all', array( 'event_id' => $ev, 'contest' => '8112', 'liste' => 'all' ) ),
			'weiblich'     => array( $b . '/ergebnisse/8112/all/w', array( 'event_id' => $ev, 'contest' => '8112', 'liste' => 'w' ) ),
			'AK-Filter'    => array( $b . '/ergebnisse/8112/M30', array( 'event_id' => $ev, 'contest' => '8112', 'liste' => '' ) ),
			'Gruppiert'    => array( $b . '/ergebnisse/8112/grouped/all', array( 'event_id' => $ev, 'contest' => '8112', 'liste' => '' ) ),
			'Anmeldung'    => array( $b . '/anmeldung', array( 'event_id' => $ev, 'contest' => '', 'liste' => '' ) ),
			'kleingemacht' => array( 'https://Forstsportlauf-2026.racepedia.de/', array( 'event_id' => 'forstsportlauf-2026', 'contest' => '', 'liste' => '' ) ),
			'Portal'       => array( 'https://www.racepedia.de/ergebnisse/8112', array( 'event_id' => '', 'contest' => '', 'liste' => '' ) ),
		);
	}

	/**
	 * @dataProvider zerlegung
	 *
	 * @param string $url      Eingabe.
	 * @param array  $erwartet Bestandteile.
	 */
	public function test_url_zerlegen( $url, $erwartet ) {
		$this->assertSame( $erwartet, LSG_BL_Racepedia_Adapter::url_zerlegen( $url ), 'URL: ' . $url );
	}

	public function test_url_bauen() {
		$this->assertSame(
			'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse',
			LSG_BL_Racepedia_Adapter::url_bauen( 'topiblueten-lauf-staffort-2026' )
		);
		$this->assertSame(
			'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/all',
			LSG_BL_Racepedia_Adapter::url_bauen( 'topiblueten-lauf-staffort-2026', '8112' )
		);
		$this->assertSame(
			'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/w',
			LSG_BL_Racepedia_Adapter::url_bauen( 'topiblueten-lauf-staffort-2026', '8112', 'w' )
		);
	}

	public function test_vorauswahl_aus_url() {
		$this->assertSame(
			array(
				'contest' => '8112',
				'list'    => 'm',
			),
			LSG_BL_Racepedia_Adapter::vorauswahl_aus_url( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/m' )
		);
	}

	/* ------------------------------------------------------------------
	 * Rahmen: Name, Datum, Wettbewerbe
	 * --------------------------------------------------------------- */

	public function test_rahmen_der_uebersicht() {
		$r = LSG_BL_Racepedia_Adapter::parse_rahmen( self::$start );

		// Ohne „Racepedia - "-Präfix, mit Umlaut.
		$this->assertSame( 'Topiblüten-Lauf Staffort', $r['eventname'] );
		$this->assertSame( '27. SEPTEMBER 2026', $r['datum_text'] );
		$this->assertSame( 'Sportgelände Stutensee-Staffort', $r['ort'] );
		$this->assertSame(
			array(
				array(
					'id'   => '8112',
					'name' => '10 km Lauf',
				),
				array(
					'id'   => '8113',
					'name' => '5 km Lauf',
				),
			),
			$r['contests']
		);
	}

	/**
	 * Wer die Listenseite eingibt, bekommt dieselben Wettbewerbe – dort
	 * stehen sie im Select.
	 */
	public function test_rahmen_der_listenseite() {
		$r = LSG_BL_Racepedia_Adapter::parse_rahmen( self::$liste );

		$this->assertSame( array( '8112', '8113' ), array_column( $r['contests'], 'id' ) );
		$this->assertSame( '10 km Lauf', $r['contests'][0]['name'] );
	}

	public function test_seite_ohne_wettbewerbe_wird_gemeldet() {
		$this->expectException( LSG_BL_Quelle_Exception::class );
		LSG_BL_Racepedia_Adapter::parse_rahmen( '<html><body><p>Nichts hier.</p></body></html>' );
	}

	public function test_datum_aus_dem_kopf() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse' );

		$d = $adapter->datum( $ref );
		$this->assertSame( '2026-09-27', $d['datum'] );
		$this->assertSame( 'api', $d['quelle'] );
	}

	public function test_nur_gesamt_ist_gesamtwertung() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse' );

		$gesamt = array();
		foreach ( $adapter->listen( $ref, '8112' ) as $l ) {
			$gesamt[ $l->id ] = $l->gesamtwertung;
		}
		$this->assertSame(
			array(
				'all' => true,
				'm'   => false,
				'w'   => false,
			),
			$gesamt
		);
	}

	/* ------------------------------------------------------------------
	 * Ergebnisliste
	 * --------------------------------------------------------------- */

	public function test_liste_vollstaendig() {
		$p = LSG_BL_Racepedia_Adapter::parse_liste( self::$liste );

		$this->assertSame( 89, $p['gelesen'] );
		$this->assertSame( 0, $p['verworfen'] );
		$this->assertCount( 89, $p['zeilen'] );
		$this->assertSame( 'brutto', $p['zeit_typ'] );
	}

	public function test_erste_zeile() {
		$z = LSG_BL_Racepedia_Adapter::parse_liste( self::$liste )['zeilen'][0];

		$this->assertSame( 'Dihlmann', $z->nachname );
		$this->assertSame( 'Christian', $z->vorname );
		$this->assertSame( '1', $z->platz );
		$this->assertSame( '220', $z->startnummer );
		// Die AK-Spalte, nicht „AK Platz" (1) und nicht die versteckte erste.
		$this->assertSame( 'M35', $z->quelle_klasse );
		$this->assertSame( 'm', $z->geschlecht );
		$this->assertSame( 'Laufteam Rennwerk', $z->verein );
		$this->assertSame( '00:34:09', $z->zeit );
		$this->assertSame( 0, $z->jahrgang );
	}

	public function test_lsg_zeile() {
		$lsg = array();
		foreach ( LSG_BL_Racepedia_Adapter::parse_liste( self::$liste )['zeilen'] as $z ) {
			if ( 'LSG Karlsruhe' === $z->verein ) {
				$lsg[] = $z;
			}
		}

		$this->assertCount( 1, $lsg );
		$this->assertSame( 'Rechlitz', $lsg[0]->nachname );
		$this->assertSame( 'Robert', $lsg[0]->vorname );
		$this->assertSame( '27', $lsg[0]->platz );
		$this->assertSame( 'M60', $lsg[0]->quelle_klasse );
		$this->assertSame( '00:46:07', $lsg[0]->zeit );
	}

	public function test_w_wird_f() {
		$frauen = array();
		foreach ( LSG_BL_Racepedia_Adapter::parse_liste( self::$liste )['zeilen'] as $z ) {
			if ( 'Rayker' === $z->nachname && 'Svenja' === $z->vorname ) {
				$frauen[] = $z;
			}
		}
		$this->assertCount( 1, $frauen );
		$this->assertSame( 'f', $frauen[0]->geschlecht );
		$this->assertSame( 'W35', $frauen[0]->quelle_klasse );
	}

	public function test_warnt_ohne_jahrgang_aber_mit_ak() {
		$w = implode( ' ', LSG_BL_Racepedia_Adapter::parse_liste( self::$liste )['warnungen'] );
		$this->assertStringContainsString( 'keinen Jahrgang', $w );
		$this->assertStringContainsString( 'Jahrgangsband der Altersklasse', $w );
	}

	/**
	 * Burglauf, Salacher Löwenlauf: kein „AK", kein „AK Platz".
	 */
	public function test_liste_ohne_altersklasse() {
		$html = self::tabelle(
			array( '# Platz', 'Start Nr.', 'm/w', 'Name', 'Team', 'Gesamtzeit' ),
			array( array( '1', '12', 'w', 'Muster, Erika', 'LSG Karlsruhe', '00:21:30' ) )
		);
		$p = LSG_BL_Racepedia_Adapter::parse_liste( $html );

		$this->assertCount( 1, $p['zeilen'] );
		$z = $p['zeilen'][0];
		$this->assertSame( '', $z->quelle_klasse );
		$this->assertSame( 'f', $z->geschlecht );
		$this->assertSame( '12', $z->startnummer );
		$this->assertSame( 'Muster', $z->nachname );
		$this->assertStringContainsString( 'weder Jahrgang noch Altersklasse', implode( ' ', $p['warnungen'] ) );
	}

	public function test_ohne_zeit_wird_verworfen() {
		$html = self::tabelle(
			array( '# Platz', 'Start Nr.', 'm/w', 'Name', 'Team', 'Gesamtzeit' ),
			array(
				array( '1', '12', 'm', 'Muster, Max', '', '00:40:00' ),
				array( '', '13', 'm', 'Aufgeber, Paul', '', 'DNF' ),
			)
		);
		$p = LSG_BL_Racepedia_Adapter::parse_liste( $html );

		$this->assertSame( 2, $p['gelesen'] );
		$this->assertSame( 1, $p['verworfen'] );
		$this->assertCount( 1, $p['zeilen'] );
	}

	/**
	 * Vor dem Lauf: Kopf ja, Zeilen nein (Rund um den Kellerskopf,
	 * 2026-09-27 vormittags).
	 */
	public function test_leere_tabelle_heisst_noch_keine_ergebnisse() {
		$this->expectException( LSG_BL_Quelle_Exception::class );
		$this->expectExceptionMessage( 'keine Ergebnisse' );
		LSG_BL_Racepedia_Adapter::parse_liste(
			self::tabelle( array( '# Platz', 'Name', 'Gesamtzeit' ), array() )
		);
	}

	public function test_seite_ohne_tabelle_wird_gemeldet() {
		$this->expectException( LSG_BL_Quelle_Exception::class );
		LSG_BL_Racepedia_Adapter::parse_liste( self::$start );
	}

	/* ------------------------------------------------------------------
	 * Durchstich
	 * --------------------------------------------------------------- */

	public function test_durchstich() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/all' );

		$this->assertSame( 'racepedia', $ref->adapter );
		$this->assertSame( 'topiblueten-lauf-staffort-2026', $ref->event_id );
		$this->assertSame( 'Topiblüten-Lauf Staffort', $ref->event_name );
		$this->assertSame( '8112', $ref->contest_id );
		$this->assertSame( 'all', $ref->list_id );

		$zeilen = $adapter->laden( $ref, '8112', 'all' );
		$this->assertCount( 89, $zeilen );
		$this->assertSame( 89, $ref->meta['p1']['gelesen'] );

		$this->assertSame(
			'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse/8112/all/all',
			$adapter->quelleUrl( $ref, '8112', 'all' )
		);
	}

	public function test_unbekannte_liste_wird_gemeldet() {
		$adapter = $this->adapter();
		$ref     = $adapter->eventLesen( 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse' );

		$this->expectException( LSG_BL_Quelle_Exception::class );
		$adapter->laden( $ref, '8112', 'M30' );
	}
}
