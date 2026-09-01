<?php
/**
 * Mapping 1 von 2: Wettbewerbsbezeichnung → Distanzcode.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Distanz_Test extends TestCase {

	/**
	 * @dataProvider wettbewerbe
	 *
	 * @param string $name     Wettbewerbsname aus der Quelle.
	 * @param string $erwartet Erwarteter Code, '' = keine Vorbelegung.
	 */
	public function test_zuordnung( $name, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_distanz_aus_name( $name ), 'Wettbewerb: ' . $name );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function wettbewerbe() {
		return array(
			// Die beiden wichtigen Fälle: die Quelle schreibt Kilometer,
			// die Datenbank den Namen.
			'Runtix HM'           => array( '21 KM Sparkasse Kraichgau-Lauf', 'HM' ),
			'Kilometer mit Komma' => array( '42,195 km', 'Marathon' ),
			'21,1 km'             => array( '21,1 km', 'HM' ),
			'21,0975 km'          => array( '21,0975 km', 'HM' ),

			// Distanzwort schlägt Zahl. Die 5 ist hier die Auflage, nicht
			// die Strecke.
			'Ordnungszahl vor Marathon' => array( '5. Ettlinger Marathon', 'Marathon' ),
			'Ordnungszahl vor HM'       => array( '17. SWE Halbmarathon Ettlingen', 'HM' ),
			'Halbmarathon vor Marathon' => array( 'Halbmarathon', 'HM' ),
			'Halbmarathon mit Zahl'     => array( 'Halbmarathon (21,1 km)', 'HM' ),
			'Marathon pur'              => array( 'Marathon', 'Marathon' ),

			// race result Ettlingen, echte Wettbewerbsnamen aus der Fixture.
			'Hauptlauf'           => array( 'Hauptlauf 21,1km', 'HM' ),
			'Walking'             => array( 'Walking 21,1km', 'HM' ),

			// Runtix Hambrücken.
			'10 KM'               => array( '10 KM Linhardt-Lauf', '10km' ),
			'5 KM'                => array( '5 KM HUK-Coburg-Lauf', '5km' ),
			'5 KM Walk'           => array( '5 KM Interstick-Walk', '5km' ),

			// Mehrdeutig → leer. Ein leeres Feld ist ehrlicher als ein
			// falsch geratenes.
			'Staffel'             => array( 'Marathon-Staffel über 4x10 km', '' ),
			'Staffel mit Meter'   => array( 'Team-/Familienstaffeln Start 14:15 Uhr (4x500m)', '' ),

			// Fremde Einheiten und Jahrgangsangaben zählen nicht.
			'Meilen'              => array( '10 Meilen', '' ),
			'Bambini'             => array( 'Bambini 500m (<2019)', '' ),
			'Kids 500'            => array( 'Kids 500m (2017/2018)', '' ),
			'Kids 1000'           => array( 'Kids 1000m (2015/2016)', '' ),
			'Jugend 1500'         => array( 'Jugend 1500 m (2013/2014)', '' ),
			'Jugend 2000'         => array( 'Jugend 2000 m (2011/2012)', '' ),

			// Strecke, die es in lsg_best nicht gibt → leer, kein Raten.
			'krumme Strecke'      => array( 'Silvesterlauf über 8,5 km', '' ),
			'7,5 km'              => array( 'Nikolauslauf 7,5 km', '' ),

			// Rest.
			'ohne alles'          => array( 'Volkslauf', '' ),
			'leer'                => array( '', '' ),
			'25 km'               => array( '25 km Ultratest', '25km' ),
			'100 km'              => array( '100 km Deutsche Meisterschaft', '100km' ),
		);
	}

	/**
	 * Zeitläufe stehen nicht im Import-Select: dort hielte lsg_best.time
	 * eine Strecke, die Parse-Pipeline erzeugt aber immer eine Zeit
	 * (Plan 6.5.1).
	 */
	public function test_zeitlaeufe_sind_vom_import_ausgenommen() {
		$erlaubt = lsg_bl_import_distanzen();

		$this->assertNotContains( '6h', $erlaubt );
		$this->assertNotContains( '12h', $erlaubt );
		$this->assertNotContains( '24h', $erlaubt );
		$this->assertCount( 9, $erlaubt );

		// Aber im Frontend und im Formular bleiben sie unverändert erhalten.
		$alle = array_keys( lsg_bl_distance_map() );
		$this->assertContains( '12h', $alle );
		$this->assertCount( 12, $alle );
	}

	/**
	 * Das Select ist geschlossen: der Import kann keine neue Distanz
	 * erzeugen. Alles, was die Zuordnung liefert, muss lsg_bl_distance_map()
	 * schon kennen.
	 */
	public function test_die_karte_bleibt_geschlossen() {
		$bekannt = array_keys( lsg_bl_distance_map() );
		foreach ( lsg_bl_distance_aliases() as $alias => $code ) {
			$this->assertContains( $code, $bekannt, 'Alias ' . $alias . ' zeigt auf einen unbekannten Code.' );
		}
		foreach ( lsg_bl_import_distanzen() as $code ) {
			$this->assertContains( $code, $bekannt );
		}
	}
}
