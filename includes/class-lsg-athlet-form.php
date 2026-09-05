<?php
/**
 * Die Regeln der Sportler-Pflege – ohne WordPress (Plan 11.2).
 *
 * ⚠ Kein `$wpdb`, kein `esc_*`, kein `__()`. Derselbe Schnitt wie in
 * class-lsg-leistung.php: hier stehen die Entscheidungen, in page-athlet.php
 * steht die Ausgabe, und was hier zurückkommt, ist Klartext, den die Ansicht
 * escaped. Nur so bleibt die Prüfung ohne WordPress testbar
 * (`tests/unit/`, Abschnitt 8).
 *
 * ⚠ Die Prüfung sammelt ALLE Fehler, statt beim ersten abzubrechen. Wer drei
 * Felder falsch ausgefüllt hat, soll das in einem Durchgang erfahren.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Das leere Formular.
 *
 * ⚠ `born` ist 0 und nicht etwa ein plausibles Jahr. Der Jahrgang ist Pflicht
 * (11.2) – dieselbe Begründung wie bei `lsg_athlete_map.born` in 6.5.3: P3
 * ordnet über Name UND Jahrgang zu, und ein Vorgabewert lädt nur dazu ein, ihn
 * stehen zu lassen.
 *
 * @return array
 */
function lsg_bl_athlet_felder_leer() {
	return array(
		'id'        => 0,
		'name'      => '',
		'firstname' => '',
		'born'      => 0,
		'cat'       => 'm',
		'active'    => '1',
	);
}

/**
 * Der Vergleichsschlüssel für die Dublettensperre (11.2).
 *
 * Nachname + Vorname + Jahrgang, kleingeschrieben und ohne Randleerzeichen –
 * damit „müller" und „Müller " nicht aneinander vorbeilaufen.
 *
 * ⚠ Die Datenbankabfrage in lsg_bl_athlet_dublette() vergleicht mit
 * `LOWER(TRIM(...))` und muss deshalb dieselbe Normalisierung fahren wie diese
 * Funktion. Zwei Stellen, eine Regel: wer eine ändert, ändert beide.
 *
 * @param string $name      Nachname.
 * @param string $firstname Vorname.
 * @param int    $born      Jahrgang.
 * @return string
 */
function lsg_bl_athlet_schluessel( $name, $firstname, $born ) {
	return lsg_bl_kleinschreiben( trim( (string) $name ) )
		. '|' . lsg_bl_kleinschreiben( trim( (string) $firstname ) )
		. '|' . (int) $born;
}

/**
 * Das Formular prüfen (Plan 11.2).
 *
 * @param array $eingabe  id, name, firstname, born, cat, active.
 * @param int   $jahr_max Höchster erlaubter Jahrgang; 0 = keine Obergrenze.
 * @return array{ok:bool,fehler:array<string,string>,werte:array}
 */
function lsg_bl_athlet_formular_pruefen( array $eingabe, $jahr_max = 0 ) {
	$fehler = array();
	$w      = array_merge( lsg_bl_athlet_felder_leer(), $eingabe );

	$w['id']        = (int) $w['id'];
	$w['name']      = trim( (string) $w['name'] );
	$w['firstname'] = trim( (string) $w['firstname'] );
	$w['born']      = (int) $w['born'];

	/* Nachname */
	if ( '' === $w['name'] ) {
		$fehler['name'] = 'Ohne Nachnamen geht es nicht.';
	} elseif ( lsg_bl_zeichen( $w['name'] ) > 30 ) {
		$fehler['name'] = 'Höchstens 30 Zeichen – so lang ist die Spalte.';
	}

	/* Vorname */
	if ( '' === $w['firstname'] ) {
		$fehler['firstname'] = 'Ohne Vornamen geht es nicht.';
	} elseif ( lsg_bl_zeichen( $w['firstname'] ) > 30 ) {
		$fehler['firstname'] = 'Höchstens 30 Zeichen – so lang ist die Spalte.';
	}

	/*
	 * Jahrgang. Pflicht, und mit Grenzen: 1900 nach unten, weil `year(4)` ab
	 * 1901 zählt und alles darunter ohnehin ein Tippfehler wäre; nach oben das
	 * laufende Jahr, das der Aufrufer mitgibt.
	 */
	if ( $w['born'] <= 0 ) {
		$fehler['born'] = 'Der Jahrgang ist Pflicht – ohne ihn findet der Import den Sportler nicht.';
	} elseif ( $w['born'] < 1900 ) {
		$fehler['born'] = 'Das sieht nach einem Tippfehler aus – vierstelliges Jahr ab 1900.';
	} elseif ( $jahr_max > 0 && $w['born'] > $jahr_max ) {
		$fehler['born'] = sprintf( 'Der Jahrgang liegt in der Zukunft – höchstens %d.', (int) $jahr_max );
	}

	/* Geschlecht – zwei Werte, mehr kennt die Altersklassenrechnung nicht. */
	$w['cat'] = ( 'f' === strtolower( (string) $w['cat'] ) ) ? 'f' : 'm';

	/* Status */
	$w['active'] = ( '1' === (string) $w['active'] ) ? '1' : '0';

	return array(
		'ok'     => empty( $fehler ),
		'fehler' => $fehler,
		'werte'  => $w,
	);
}

/**
 * Was sich an einem Sportler geändert hat.
 *
 * Dieselbe Rolle wie lsg_bl_best_diff() in 7.5: die Meldung soll die
 * geänderten Felder nennen, und ein Speichern ohne Änderung soll gar nichts
 * schreiben.
 *
 * @param array $alt Zeile aus lsg_athlete.
 * @param array $neu Geprüfte Formularwerte.
 * @return array<int,array{feld:string,alt:string,neu:string}>
 */
function lsg_bl_athlet_diff( array $alt, array $neu ) {
	$labels = array(
		'name'      => 'Nachname',
		'firstname' => 'Vorname',
		'born'      => 'Jahrgang',
		'cat'       => 'Geschlecht',
		'active'    => 'Status',
	);

	$klartext = array(
		'cat'    => array(
			'm' => 'männlich',
			'f' => 'weiblich',
		),
		'active' => array(
			'1' => 'aktiv',
			'0' => 'ehemalig',
		),
	);

	$diff = array();
	foreach ( $labels as $feld => $label ) {
		$a = (string) ( isset( $alt[ $feld ] ) ? $alt[ $feld ] : '' );
		$n = (string) ( isset( $neu[ $feld ] ) ? $neu[ $feld ] : '' );

		if ( 'born' === $feld ) {
			$a = (string) (int) $a;
			$n = (string) (int) $n;
		}
		if ( isset( $klartext[ $feld ] ) ) {
			$a = isset( $klartext[ $feld ][ $a ] ) ? $klartext[ $feld ][ $a ] : $a;
			$n = isset( $klartext[ $feld ][ $n ] ) ? $klartext[ $feld ][ $n ] : $n;
		}

		if ( $a !== $n ) {
			$diff[] = array(
				'feld' => $label,
				'alt'  => $a,
				'neu'  => $n,
			);
		}
	}

	return $diff;
}

/**
 * Den Diff als einen Satz.
 *
 * @param array $diff Ergebnis von lsg_bl_athlet_diff().
 * @return string z.B. „Jahrgang 1975 → 1976, Status aktiv → ehemalig".
 */
function lsg_bl_athlet_diff_text( array $diff ) {
	$teile = array();
	foreach ( $diff as $d ) {
		$teile[] = sprintf( '%s %s → %s', $d['feld'], $d['alt'], $d['neu'] );
	}
	return implode( ', ', $teile );
}

/**
 * Welche Bestandszeilen bekämen mit diesem Jahrgang eine andere Altersklasse?
 *
 * ⚠ Die Funktion rechnet, sie schreibt nicht. Und sie bekommt das Jahr je
 * Zeile mitgegeben, statt es aus dem Zeitstempel zu ziehen: das Umrechnen
 * hängt an `wp_timezone()` (6.5.4) und gehört damit nicht in eine Datei, die
 * ohne WordPress laufen soll.
 *
 * @param array  $zeilen Je Zeile: id, jahr, distance, time, ak.
 * @param int    $born   Der Jahrgang, mit dem gerechnet wird.
 * @param string $cat    'm' | 'f'.
 * @return array<int,array{id:int,jahr:int,distance:string,time:string,ak_alt:string,ak_neu:string}>
 */
function lsg_bl_athlet_ak_abweichungen( array $zeilen, $born, $cat ) {
	$out = array();

	foreach ( $zeilen as $z ) {
		$jahr = isset( $z['jahr'] ) ? (int) $z['jahr'] : 0;
		if ( $jahr <= 0 ) {
			// Ohne Veranstaltungsjahr ist keine Altersklasse zu rechnen. Die
			// Zeile bleibt, wie sie ist – ein leeres `ak` ist ehrlicher als
			// ein geratenes.
			continue;
		}

		$neu = lsg_bl_ak_berechnen( (int) $born, $jahr, (string) $cat );
		$alt = (string) ( isset( $z['ak'] ) ? $z['ak'] : '' );

		if ( '' === $neu || lsg_bl_kleinschreiben( $alt ) === lsg_bl_kleinschreiben( $neu ) ) {
			continue;
		}

		$out[] = array(
			'id'       => isset( $z['id'] ) ? (int) $z['id'] : 0,
			'jahr'     => $jahr,
			'distance' => (string) ( isset( $z['distance'] ) ? $z['distance'] : '' ),
			'time'     => (string) ( isset( $z['time'] ) ? $z['time'] : '' ),
			'ak_alt'   => $alt,
			'ak_neu'   => $neu,
		);
	}

	return $out;
}
