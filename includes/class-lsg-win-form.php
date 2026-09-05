<?php
/**
 * Die Regeln der Gesamtsiege-Pflege – ohne WordPress (Plan 12.3).
 *
 * ⚠ Kein `$wpdb`, kein `esc_*`, kein `__()`. Derselbe Schnitt wie in
 * class-lsg-leistung.php und class-lsg-athlet-form.php.
 *
 * ⚠ **Hier wird nichts normalisiert** (12.1). Weder die Zeit noch die
 * Distanz. `lsg_win` ist eine Chronik: unter den 96 Bestandszeilen stehen
 * „48 Runden" als Zeit und „Pforzheim nach Basel" als Distanz, und beides ist
 * die zutreffende Auskunft über den jeweiligen Lauf. Wer hier
 * `lsg_bl_leistung_lesen()` anwendet, wirft diese Zeilen weg.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Das leere Formular.
 *
 * @return array
 */
function lsg_bl_win_felder_leer() {
	return array(
		'id'      => 0,
		'datum'   => '',
		'ort'     => '',
		'event'   => '',
		'distanz' => '',
		'athlet'  => 0,
		'zeit'    => '',
	);
}

/**
 * Der Vergleichsschlüssel für die Dublettensperre (12.3).
 *
 * Athlet + Datum + Veranstaltung, kleingeschrieben und ohne Randleerzeichen.
 *
 * ⚠ Nicht Athlet + Datum allein: ein Etappen- oder Staffeltag kann zwei
 * Veranstaltungen tragen. Und nicht Athlet + Jahr: `lsg_win` ist keine
 * Jahrestabelle, wer dreimal im Jahr gewinnt, hat drei Zeilen (12.3).
 *
 * @param int    $athlet athletes_id.
 * @param string $datum  JJJJ-MM-TT.
 * @param string $event  Veranstaltungsname.
 * @return string
 */
function lsg_bl_win_schluessel( $athlet, $datum, $event ) {
	return (int) $athlet
		. '|' . trim( (string) $datum )
		. '|' . lsg_bl_kleinschreiben( trim( (string) $event ) );
}

/**
 * Das Formular prüfen (Plan 12.3).
 *
 * @param array $eingabe  id, datum, ort, event, distanz, athlet, zeit.
 * @param int   $jahr_max Höchstes erlaubtes Veranstaltungsjahr; 0 = keine Grenze.
 * @return array{ok:bool,fehler:array<string,string>,werte:array}
 */
function lsg_bl_win_formular_pruefen( array $eingabe, $jahr_max = 0 ) {
	$fehler = array();
	$w      = array_merge( lsg_bl_win_felder_leer(), $eingabe );

	$w['id']      = (int) $w['id'];
	$w['athlet']  = (int) $w['athlet'];
	$w['datum']   = trim( (string) $w['datum'] );
	$w['ort']     = trim( (string) $w['ort'] );
	$w['event']   = trim( (string) $w['event'] );
	$w['distanz'] = trim( (string) $w['distanz'] );
	$w['zeit']    = trim( (string) $w['zeit'] );

	/* Athlet */
	if ( $w['athlet'] <= 0 ) {
		$fehler['athlet'] = 'Ohne Sportler geht es nicht.';
	}

	/* Datum */
	$w['jahr'] = 0;
	if ( '' === $w['datum'] ) {
		$fehler['datum'] = 'Das Veranstaltungsdatum ist Pflicht.';
	} elseif ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $w['datum'], $t ) ) {
		$fehler['datum'] = 'Datum bitte als TT.MM.JJJJ.';
	} elseif ( ! checkdate( (int) $t[2], (int) $t[3], (int) $t[1] ) ) {
		$fehler['datum'] = 'Diesen Tag gibt es nicht.';
	} else {
		$w['jahr'] = (int) $t[1];
		if ( $jahr_max > 0 && $w['jahr'] > $jahr_max ) {
			$fehler['datum'] = sprintf( 'Das Datum liegt in der Zukunft – höchstens %d.', (int) $jahr_max );
		}
	}

	/* Ort */
	if ( '' === $w['ort'] ) {
		$fehler['ort'] = 'Ohne Ort geht es nicht.';
	} elseif ( lsg_bl_zeichen( $w['ort'] ) > 30 ) {
		$fehler['ort'] = 'Höchstens 30 Zeichen – so lang ist die Spalte.';
	}

	/*
	 * Veranstaltung. ⚠ Zu lang wird zurückgewiesen, nicht abgeschnitten
	 * (6.5.5, 12.3) – und die Meldung sagt, um wie viel, damit der Mensch
	 * selbst kürzt statt zu raten. Ein still gekürzter Veranstaltungsname ist
	 * eine Falschangabe, die niemand bemerkt.
	 */
	$event_lang = lsg_bl_zeichen( $w['event'] );
	if ( '' === $w['event'] ) {
		$fehler['event'] = 'Ohne Veranstaltung geht es nicht.';
	} elseif ( $event_lang > 40 ) {
		$fehler['event'] = sprintf(
			'Die Spalte fasst 40 Zeichen, das sind %1$d – bitte um %2$d kürzen.',
			$event_lang,
			$event_lang - 40
		);
	}

	/* Distanz – Freitext, nur Länge (12.1) */
	if ( '' === $w['distanz'] ) {
		$fehler['distanz'] = 'Ohne Distanz geht es nicht.';
	} elseif ( lsg_bl_zeichen( $w['distanz'] ) > 20 ) {
		$fehler['distanz'] = 'Höchstens 20 Zeichen – so lang ist die Spalte.';
	}

	/* Zeit – Freitext, nur Länge (12.1) */
	if ( '' === $w['zeit'] ) {
		$fehler['zeit'] = 'Ohne Zeit geht es nicht.';
	} elseif ( lsg_bl_zeichen( $w['zeit'] ) > 15 ) {
		$fehler['zeit'] = 'Höchstens 15 Zeichen – so lang ist die Spalte.';
	}

	return array(
		'ok'     => empty( $fehler ),
		'fehler' => $fehler,
		'werte'  => $w,
	);
}

/**
 * Was sich an einem Gesamtsieg geändert hat.
 *
 * Wie lsg_bl_best_diff() (7.5) und lsg_bl_athlet_diff() (11.4): die Meldung
 * nennt die geänderten Felder, und ein Speichern ohne Änderung schreibt gar
 * nichts.
 *
 * @param array $alt Zeile aus lsg_win, auf Formularschlüssel gebracht.
 * @param array $neu Geprüfte Formularwerte.
 * @return array<int,array{feld:string,alt:string,neu:string}>
 */
function lsg_bl_win_diff( array $alt, array $neu ) {
	$labels = array(
		'datum'   => 'Datum',
		'ort'     => 'Ort',
		'event'   => 'Veranstaltung',
		'distanz' => 'Distanz',
		'athlet'  => 'Sportler',
		'zeit'    => 'Zeit',
	);

	$diff = array();
	foreach ( $labels as $feld => $label ) {
		$a = (string) ( isset( $alt[ $feld ] ) ? $alt[ $feld ] : '' );
		$n = (string) ( isset( $neu[ $feld ] ) ? $neu[ $feld ] : '' );

		if ( 'athlet' === $feld ) {
			$a = (string) (int) $a;
			$n = (string) (int) $n;
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
 * @param array $diff Ergebnis von lsg_bl_win_diff().
 * @return string
 */
function lsg_bl_win_diff_text( array $diff ) {
	$teile = array();
	foreach ( $diff as $d ) {
		$teile[] = sprintf( '%s %s → %s', $d['feld'], $d['alt'], $d['neu'] );
	}
	return implode( ', ', $teile );
}
