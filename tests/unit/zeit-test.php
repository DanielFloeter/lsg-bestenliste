<?php
/**
 * Zeit-Normalisierung – die vier Schreibweisen und ihre Fallstricke.
 *
 * @package lsg-bestenliste
 */

use PHPUnit\Framework\TestCase;

class Zeit_Test extends TestCase {

	/**
	 * @dataProvider zeiten
	 *
	 * @param string $roh      Rohwert.
	 * @param string $erwartet Erwartete Normalform.
	 */
	public function test_normalisierung( $roh, $erwartet ) {
		$this->assertSame( $erwartet, lsg_bl_zeit_normalisieren( $roh ), 'Eingabe: ' . $roh );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function zeiten() {
		return array(
			// HH:MM:SS – führende Null ergänzen.
			'HH:MM:SS mit einstelliger Stunde' => array( '1:13:08', '01:13:08' ),
			'HH:MM:SS vollständig'             => array( '01:13:08', '01:13:08' ),

			// Zehntel, World-Athletics-Regel: aufrunden.
			'Zehntel runden auf'               => array( '01:11:54.9', '01:11:55' ),
			'Zehntel .0 runden nicht'          => array( '01:11:54.0', '01:11:54' ),
			'Zehntel .000 runden nicht'        => array( '01:11:54.000', '01:11:54' ),
			'Millisekunden .004 runden auf'    => array( '01:11:54.004', '01:11:55' ),
			'Zehntel .1 runden auf'            => array( '01:11:54.1', '01:11:55' ),

			// Übertrag über Minute UND Stunde – ein Zusammensetzen aus den
			// Einzelgruppen ergäbe 01:11:60.
			'Übertrag über Minute und Stunde'  => array( '01:11:59.9', '01:12:00' ),
			'Übertrag über die Minute'         => array( '00:38:59.5', '00:39:00' ),

			// MM:SS – die Stundenangabe fehlt bei kurzen Distanzen.
			'MM:SS'                            => array( '38:57', '00:38:57' ),
			'MM:SS mit Zehntel, Punkt'         => array( '18:57.3', '00:18:58' ),
			'MM:SS mit Zehntel, Komma'         => array( '18:57,3', '00:18:58' ),
			'MM:SS mit Zehntel .0'             => array( '18:57.0', '00:18:57' ),

			// Lange Läufe: dreistellige Stunden bleiben erlaubt.
			'dreistellige Stunde'              => array( '100:00:00', '100:00:00' ),

			// Nicht verwertbar → '' und die Zeile wird verworfen.
			'DNF'                              => array( 'DNF', '' ),
			'DSQ'                              => array( 'DSQ', '' ),
			'DNS'                              => array( 'DNS', '' ),
			'dns klein'                        => array( 'dns', '' ),
			'leer'                             => array( '', '' ),
			'Strich'                           => array( '-', '' ),

			// Kein Rückfall auf den Zahlen-Fallback von
			// lsg_bl_parse_performance(): was hier nicht erkannt wird,
			// liefert '' – der tolerante Zweig ist für die historischen
			// Tippfehler im Bestand da, nicht für neue.
			'Tippfehler-Schreibweise'          => array( '01:20.24', '' ),
			'nur eine Zahl'                    => array( '4711', '' ),
			'Sekunden über 59'                 => array( '01:11:74', '' ),
			'Minuten über 59'                  => array( '01:71:14', '' ),
			'Text'                             => array( 'schnell', '' ),
		);
	}

	public function test_zeit_wird_getrimmt() {
		$this->assertSame( '01:13:08', lsg_bl_zeit_normalisieren( '  1:13:08  ' ) );
	}
}
