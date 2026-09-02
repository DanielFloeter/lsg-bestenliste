<?php
/**
 * Die Parse-Pipeline, soweit sie ohne WordPress auskommt.
 *
 * ⚠ Diese Datei ruft keine WordPress-Funktion auf und liest kein $wpdb – wie
 * class-lsg-normalize.php. Alles, was Transients, Optionen oder den Abruf
 * braucht, steht in class-lsg-import.php.
 *
 * Die Pipeline hat vier Stufen mit je einem definierten Zwischenergebnis
 * (Plan 6.5):
 *
 *   P1  Alle Ergebnisse lesen        →  Rohzeilen, normalisiert
 *   P2  Auf LSG Karlsruhe filtern    →  nur Vereinsmitglieder
 *   P3  Athleten zuordnen            →  lsg_athlete.id je Zeile
 *   P4  Gegen lsg_best abgleichen    →  Status je Zeile
 *
 * P1 steckt im Adapter (der liest und normalisiert). P2, die Entscheidungs-
 * logik von P3 und die Statusbildung von P4 stehen hier – jeweils als reine
 * Funktion, die ihre Kandidaten übergeben bekommt. Wer sie aus der Datenbank
 * holt, steht in class-lsg-athlete.php; wer die Stufen verkettet, in
 * class-lsg-import.php.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Der Trichter
 * ---------------------------------------------------------------------- */

/**
 * Das Zählwerk der Oberfläche.
 *
 * `null` heißt „diese Stufe ist noch nicht gelaufen", `0` heißt „gelaufen und
 * nichts gefunden". Der Unterschied ist wichtig: eine Stufe, die es noch
 * nicht gibt, darf nicht wie ein Nulltreffer aussehen.
 *
 * @return array<string,int|null>
 */
function lsg_bl_trichter_leer() {
	return array(
		'gelesen'    => 0,
		'verworfen'  => 0,
		'lsg'        => 0,
		'zugeordnet' => null,   // P3, ab M3
		'offen'      => null,   // P3
		'neu'        => null,   // P4
		'schneller'  => null,   // P4
		'langsamer'  => null,   // P4
		'gleich'     => null,   // P4
	);
}

/**
 * Die Stufen des Trichters als Label/Wert-Paare, in Leserichtung – und nur
 * die, die wirklich gelaufen sind.
 *
 * Das ist die wichtigste Kontrollanzeige der ganzen Seite: springt „LSG" auf
 * 0, stimmt der Vereinsfilter nicht, und man sieht es sofort (Plan 6.5).
 *
 * @param array $trichter Ergebnis von lsg_bl_trichter_leer(), gefüllt.
 * @return array<int,array{key:string,label:string,wert:int}>
 */
function lsg_bl_trichter_stufen( array $trichter ) {
	/*
	 * Die Phase entscheidet über die Darstellung: zwischen Phasen steht ein
	 * Pfeil, innerhalb einer Phase ein Komma. „7 neu → 1 schneller" wäre
	 * falsch – das sind Geschwister, keine Stufen. Der Plan schreibt es
	 * genauso: „428 gelesen → 9 LSG → 8 zugeordnet, 1 ohne Zuordnung →
	 * 5 neu, 2 schneller, 1 langsamer, 1 offen" (Plan 6.5).
	 */
	$labels = array(
		'gelesen'    => array( 1, 'gelesen' ),
		'verworfen'  => array( 1, 'ohne Zeit' ),
		'lsg'        => array( 2, 'LSG' ),
		'zugeordnet' => array( 3, 'zugeordnet' ),
		'offen'      => array( 3, 'ohne Zuordnung' ),
		'neu'        => array( 4, 'neu' ),
		'schneller'  => array( 4, 'schneller' ),
		'langsamer'  => array( 4, 'langsamer' ),
		'gleich'     => array( 4, 'gleich' ),
	);

	$out = array();
	foreach ( $labels as $key => $def ) {
		if ( ! array_key_exists( $key, $trichter ) || null === $trichter[ $key ] ) {
			continue;
		}
		// „ohne Zeit" nur zeigen, wenn es welche gab – sonst ist die Stufe
		// eine Null, die nichts erklärt.
		if ( 'verworfen' === $key && 0 === (int) $trichter[ $key ] ) {
			continue;
		}
		$out[] = array(
			'key'   => $key,
			'phase' => $def[0],
			'label' => $def[1],
			'wert'  => (int) $trichter[ $key ],
		);
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * P2 – auf LSG Karlsruhe filtern
 * ---------------------------------------------------------------------- */

/**
 * Anzeigemarke für Zeilen ohne Vereinsangabe.
 *
 * Sie steht an einer Stelle, weil zwei Orte sie vergleichen: der Block der
 * nicht übernommenen Vereine zeigt sie, und die Alias-Aktion lehnt sie ab –
 * ein Alias auf „kein Verein" würde jede vereinslose Zeile übernehmen.
 *
 * @return string
 */
function lsg_bl_ohne_verein_marke() {
	return '(kein Verein)';
}

/**
 * Wie nah ist diese Vereinsschreibweise am eigenen Verein?
 *
 * Das steuert die Reihenfolge im Block „nicht übernommene Vereine" und die
 * Markierung an der Zeile. Der Zweck des Blocks ist, eine VERPASSTE
 * Schreibweise zu finden – also muss oben stehen, was danach aussieht, nicht
 * das, was am häufigsten vorkommt.
 *
 *   2  enthält `lsg`        →  `LSG Ka.`, `LSG KA`, `LSG Weiher`
 *   1  enthält `karlsruhe`  →  `Karlsruher Lemminge`, `LG Region Karlsruhe`
 *   0  alles andere
 *
 * ⚠ Ausgenommen ist die Form `(Ort)`: race result setzt bei fehlendem Verein
 * den Wohnort in Klammern. `(Karlsruhe)` ist also keine verpasste
 * Vereinsschreibweise, sondern gar keine – und da es davon viele gibt, würde
 * es sonst das eine `LSG Ka.` nach unten drängen.
 *
 * @param string $verein Rohe Schreibweise aus der Quelle.
 * @return int 0, 1 oder 2.
 */
function lsg_bl_verein_naehe( $verein ) {
	$roh = trim( (string) $verein );
	$n   = lsg_bl_verein_normalisieren( $roh );

	if ( '' === $n ) {
		return 0;
	}
	if ( false !== strpos( $n, 'lsg' ) ) {
		return 2;
	}
	// Nur ein Wohnort in Klammern – kein Verein.
	if ( preg_match( '/^\(\s*[^()]+\s*\)$/', $roh ) ) {
		return 0;
	}
	if ( false !== strpos( $n, 'karlsruhe' ) ) {
		return 1;
	}
	return 0;
}

/**
 * P2: aus den gelesenen Zeilen die des Vereins heraussuchen.
 *
 * Zurück kommen nicht nur die Treffer, sondern auch die ausgefilterten
 * Vereinsschreibweisen mit ihrer Häufigkeit. Das ist die Sicherung gegen
 * stille Fehler: steht dort ein `LSG Ka.` oder `LSG KA`, sieht man den
 * verpassten Treffer sofort, statt ihn nie zu bemerken (Plan 6.5.2).
 *
 * @param LSG_BL_Ergebnis[] $zeilen   Ergebnis von P1.
 * @param string[]          $aliasse  Zusätzlich als LSG geltende, bereits
 *                                    normalisierte Vereinsschreibweisen.
 * @return array{
 *   lsg:LSG_BL_Ergebnis[],
 *   abgelehnt:array<string,int>,
 *   anzahl_abgelehnt:int,
 *   nahe:string[]
 * }
 */
function lsg_bl_p2_filtern( array $zeilen, array $aliasse = array() ) {
	$lsg       = array();
	$abgelehnt = array();
	$nahe      = array();

	foreach ( $zeilen as $e ) {
		$verein = isset( $e->verein ) ? (string) $e->verein : '';

		if ( lsg_bl_ist_lsg( $verein, $aliasse ) ) {
			$lsg[] = $e;
			continue;
		}

		// Zeilen ohne Vereinsangabe fallen durch den Filter. Sie erscheinen
		// trotzdem im Block, damit ein Mitglied, das ohne Verein gemeldet
		// war, nicht unsichtbar verschwindet.
		$anzeige = ( '' === trim( $verein ) ) ? lsg_bl_ohne_verein_marke() : trim( $verein );

		if ( ! isset( $abgelehnt[ $anzeige ] ) ) {
			$abgelehnt[ $anzeige ] = 0;
		}
		++$abgelehnt[ $anzeige ];

		$nahe[ $anzeige ] = lsg_bl_verein_naehe( $verein );
	}

	// Sortierung: erst die Nähe zum Verein, dann die Häufigkeit, dann
	// alphabetisch. So steht ein einzelnes `LSG Ka.` über zwanzig Zeilen eines
	// fremden Vereins – und genau darum geht es in diesem Block.
	uksort(
		$abgelehnt,
		function ( $a, $b ) use ( $abgelehnt, $nahe ) {
			$na = isset( $nahe[ $a ] ) ? $nahe[ $a ] : 0;
			$nb = isset( $nahe[ $b ] ) ? $nahe[ $b ] : 0;
			if ( $na !== $nb ) {
				return $nb - $na;
			}
			if ( $abgelehnt[ $a ] !== $abgelehnt[ $b ] ) {
				return $abgelehnt[ $b ] - $abgelehnt[ $a ];
			}
			return strcasecmp( $a, $b );
		}
	);

	return array(
		'lsg'              => $lsg,
		'abgelehnt'        => $abgelehnt,
		'anzahl_abgelehnt' => array_sum( $abgelehnt ),
		'nahe'             => array_keys( array_filter( $nahe ) ),
	);
}

/* -------------------------------------------------------------------------
 * Distanz, Datum und Ort – die drei Felder über der Tabelle
 * ---------------------------------------------------------------------- */

/**
 * Ist das ein Zeitlauf-Wettbewerb?
 *
 * Zeitläufe sind vom Import ausgenommen: dort hält `lsg_best.time` eine
 * Strecke (`112,737 km`), die Parse-Pipeline erzeugt aber immer eine Zeit.
 * Stünde `6h` im Select, würde eine Zeit in ein Streckenfeld geschrieben und
 * P4 vergliche sie anschließend als Zahl – ein stiller Fehler ohne
 * Fehlermeldung (Plan 6.5.1).
 *
 * Erkannt wird das am Wettbewerbsnamen, damit die Oberfläche den Grund
 * nennen kann, statt nur „keine passende Distanz" zu sagen.
 *
 * @param string $name Wettbewerbsname aus der Quelle.
 * @return bool
 */
function lsg_bl_ist_zeitlauf_name( $name ) {
	$n = lsg_bl_text_normalisieren( $name );
	if ( '' === $n ) {
		return false;
	}
	if ( preg_match( '/\b(6|12|24)\s*(h|std|stunden|stundenlauf)\b/', $n ) ) {
		return true;
	}
	return (bool) preg_match( '/\bstundenlauf\b|\b(sechs|zwoelf|vierundzwanzig)\s*stunden\b/', $n );
}

/**
 * Ort aus dem Veranstaltungsnamen – als Vorbelegung, nicht als Feststellung.
 *
 * Die Heuristik ist absichtlich schmal: das letzte Wort des Eventnamens,
 * wenn es kein Laufwort ist. „17. SWE Halbmarathon Ettlingen" ergibt
 * „Ettlingen"; „19. Hambrücker Lußhardtlauf" ergibt nichts, weil das letzte
 * Wort auf „lauf" endet. Lieber ein leeres Feld als ein Ort, der keiner ist.
 *
 * @param string $event_name Veranstaltungsname.
 * @return string Leer, wenn sich nichts Sinnvolles ableiten lässt.
 */
function lsg_bl_ort_aus_eventname( $event_name ) {
	$name = trim( preg_replace( '/\s+/u', ' ', (string) $event_name ) );
	if ( '' === $name ) {
		return '';
	}

	$worte = explode( ' ', $name );
	$letzt = trim( array_pop( $worte ), " \t\n\r\0\x0B.,;:()[]\"'" );
	if ( '' === $letzt ) {
		return '';
	}

	$n = lsg_bl_text_normalisieren( $letzt );
	if ( '' === $n || strlen( $n ) < 3 ) {
		return '';
	}

	// Laufwörter sind keine Orte.
	if ( preg_match( '/lauf|laeufe|run|race|marathon|walk|cup|trail|meile|staffel|meeting|challenge|nacht|berglauf|crosslauf/', $n ) ) {
		return '';
	}
	// Reine Zahlen und Jahrgangsangaben auch nicht.
	if ( preg_match( '/^\d+$/', $n ) ) {
		return '';
	}

	return $letzt;
}

/**
 * Plausibilitätshinweise zu einem Veranstaltungsdatum.
 *
 * Hinweise, keine Sperren (Plan 6.5.1): der Mensch am Formular weiß Dinge,
 * die die Software nicht weiß.
 *
 * @param string $datum      'JJJJ-MM-TT' oder ''.
 * @param string $event_name Veranstaltungsname, für den Jahresvergleich.
 * @param int    $jetzt_ts   Aktueller Zeitstempel (wird übergeben, damit die
 *                           Funktion ohne WordPress prüfbar bleibt).
 * @return string[] Klartext-Hinweise, leer wenn alles unauffällig ist.
 */
function lsg_bl_datum_hinweise( $datum, $event_name = '', $jetzt_ts = 0 ) {
	$hinweise = array();

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( (string) $datum ), $m ) ) {
		return $hinweise;
	}
	if ( $jetzt_ts <= 0 ) {
		$jetzt_ts = time();
	}

	$jahr       = (int) $m[1];
	$jetzt_jahr = (int) gmdate( 'Y', $jetzt_ts );

	// Zum Vergleich auf Mittag, damit ein Datum von heute nicht wegen der
	// Uhrzeit als Zukunft gilt.
	$datum_ts = strtotime( $datum . ' 12:00:00 UTC' );

	// 86400 statt DAY_IN_SECONDS: diese Datei soll ohne WordPress ladbar
	// bleiben, und die Konstante gehört WordPress.
	if ( $datum_ts && $datum_ts > $jetzt_ts + 86400 ) {
		$hinweise[] = 'Der Lauf liegt in der Zukunft – stimmt das Datum?';
	}
	if ( $jahr < $jetzt_jahr - 10 ) {
		$hinweise[] = sprintf(
			'Der Lauf liegt mehr als zehn Jahre zurück (%d) – ist das so gewollt?',
			$jahr
		);
	}

	// Weicht das Jahr von der Jahreszahl im Eventnamen ab, werden beide
	// gezeigt – ohne zu entscheiden, welche stimmt.
	$aus_name = lsg_bl_datum_aus_text( $event_name );
	$name_jahr = ( '' !== $aus_name['jahr'] ) ? (int) $aus_name['jahr'] : 0;
	if ( $name_jahr > 0 && $name_jahr !== $jahr ) {
		$hinweise[] = sprintf(
			'Der Veranstaltungsname nennt %1$d, das Datumsfeld %2$d.',
			$name_jahr,
			$jahr
		);
	}

	return $hinweise;
}

/* -------------------------------------------------------------------------
 * Lesehilfen auf den Discovery-Daten
 *
 * Rechnen nur auf dem uebergebenen Array – kein Abruf, kein WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Name eines Wettbewerbs aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return string
 */
function lsg_bl_contest_name( array $disc, $contest_id ) {
	foreach ( $disc['contests'] as $c ) {
		if ( (string) $c['id'] === (string) $contest_id ) {
			return $c['name'];
		}
	}
	return '';
}

/**
 * Listen eines Wettbewerbs aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return array<int,array>
 */
function lsg_bl_contest_listen( array $disc, $contest_id ) {
	$out = array();
	foreach ( $disc['lists'] as $l ) {
		if ( (string) $l['contest'] === (string) $contest_id ) {
			$out[] = $l;
		}
	}
	return $out;
}

/**
 * Eine bestimmte Liste aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @param string $list_id    Listen-ID.
 * @return array|null
 */
function lsg_bl_contest_liste( array $disc, $contest_id, $list_id ) {
	$listen = lsg_bl_contest_listen( $disc, $contest_id );
	foreach ( $listen as $l ) {
		if ( (string) $l['id'] === (string) $list_id ) {
			return $l;
		}
	}
	return $listen ? $listen[0] : null;
}

/**
 * Vorbelegung der drei Felder über der Tabelle: Distanz, Datum, Ort.
 *
 * Jedes davon vorbelegt, wenn es sich ermitteln lässt, und in jedem Fall
 * änderbar. Im Zweifel bleibt das Feld leer – ein leeres Feld ist ehrlicher
 * als ein falsch geratenes (Plan 6.5.1).
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return array{distanz:string,datum:string,datum_quelle:string,datum_hinweis:string,ort:string,zeitlauf:bool,contest_name:string}
 */
function lsg_bl_import_vorbelegung( array $disc, $contest_id ) {
	$contest_name = lsg_bl_contest_name( $disc, $contest_id );

	$distanz = lsg_bl_distanz_aus_name( $contest_name );
	if ( '' !== $distanz && ! in_array( $distanz, lsg_bl_import_distanzen(), true ) ) {
		// Zeitläufe stehen nicht im Select – lieber leer als ein Wert, den
		// das Feld nicht anbietet.
		$distanz = '';
	}

	$datum = isset( $disc['datum'] ) ? (array) $disc['datum'] : array();

	return array(
		'contest_name'  => $contest_name,
		'distanz'       => $distanz,
		'datum'         => isset( $datum['datum'] ) ? (string) $datum['datum'] : '',
		'datum_quelle'  => isset( $datum['quelle'] ) ? (string) $datum['quelle'] : '',
		'datum_hinweis' => isset( $datum['hinweis'] ) ? (string) $datum['hinweis'] : '',
		'ort'           => lsg_bl_ort_aus_eventname( $disc['event_name'] ),
		'zeitlauf'      => lsg_bl_ist_zeitlauf_name( $contest_name ),
	);
}

/* -------------------------------------------------------------------------
 * P3 – Athleten zuordnen
 * ---------------------------------------------------------------------- */

/**
 * Trifft diese Zuordnungsregel auf eine Quellzeile zu?
 *
 * Bedeutung der Felder (Plan 6.5.3):
 *   born      immer Pflicht – keine Regel ohne Jahrgang
 *   vorname / nachname   beschreiben die QUELLE, nicht lsg_athlete;
 *                        leeres Feld = beliebig; normalisiert gespeichert
 *   modus 'feld'  Vorname gegen Vornamensfeld, Nachname gegen Nachnamensfeld
 *   modus 'egal'  jedes gesetzte Token muss in EINEM der beiden Felder
 *                 vorkommen, egal in welchem – deckt vertauschte Spalten ab
 *                 und den Fall, dass der Splitter danebengegriffen hat
 *
 * @param array  $regel        Zeile aus lsg_athlete_map.
 * @param string $vorname_norm Normalisierter Vorname der Quelle.
 * @param string $nachname_norm Normalisierter Nachname der Quelle.
 * @return bool
 */
function lsg_bl_regel_trifft( array $regel, $vorname_norm, $nachname_norm ) {
	$r_vor  = isset( $regel['vorname'] ) ? (string) $regel['vorname'] : '';
	$r_nach = isset( $regel['nachname'] ) ? (string) $regel['nachname'] : '';
	$modus  = isset( $regel['modus'] ) ? (string) $regel['modus'] : 'feld';

	// Eine Regel ohne beide Namen zieht jeden Läufer dieses Jahrgangs an sich.
	// Sie kann gar nicht erst angelegt werden (lsg_bl_regel_gueltig()); hier
	// steht die Sicherung ein zweites Mal, falls doch eine im Bestand liegt.
	if ( '' === $r_vor && '' === $r_nach ) {
		return false;
	}

	if ( 'egal' === $modus ) {
		$felder = array( $vorname_norm, $nachname_norm );
		foreach ( array( $r_vor, $r_nach ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( ! in_array( $token, $felder, true ) ) {
				return false;
			}
		}
		return true;
	}

	// modus 'feld'
	if ( '' !== $r_vor && $r_vor !== $vorname_norm ) {
		return false;
	}
	if ( '' !== $r_nach && $r_nach !== $nachname_norm ) {
		return false;
	}
	return true;
}

/**
 * Darf diese Regel angelegt werden?
 *
 * ⚠ Eine Regel ohne Vor- UND Nachname (nur Jahrgang) wird abgelehnt: sie
 * würde jeden LSG-Läufer dieses Jahrgangs auf einen Athleten ziehen
 * (Plan 6.5.3).
 *
 * @param array $regel athletes_id, born, vorname, nachname.
 * @return string Leer, wenn gültig – sonst der Grund im Klartext.
 */
function lsg_bl_regel_gueltig( array $regel ) {
	$born  = isset( $regel['born'] ) ? (int) $regel['born'] : 0;
	$vor   = isset( $regel['vorname'] ) ? trim( (string) $regel['vorname'] ) : '';
	$nach  = isset( $regel['nachname'] ) ? trim( (string) $regel['nachname'] ) : '';
	$ziel  = isset( $regel['athletes_id'] ) ? (int) $regel['athletes_id'] : 0;

	if ( $ziel <= 0 ) {
		return 'Eine Regel braucht einen Athleten, auf den sie zeigt.';
	}
	if ( $born <= 1900 ) {
		return 'Eine Regel braucht einen Jahrgang – ohne ihn ist sie nicht eindeutig genug.';
	}
	if ( '' === $vor && '' === $nach ) {
		return 'Eine Regel braucht mindestens einen Namen. Nur mit Jahrgang würde sie jeden LSG-Läufer dieses Jahrgangs auf denselben Athleten ziehen.';
	}
	return '';
}

/**
 * P3: einen Athleten zu einer Quellzeile finden.
 *
 * In dieser Reihenfolge, erster Treffer gewinnt (Plan 6.5.3):
 *
 *   1  name + firstname + born exakt (case-insensitive)   → `exakt`
 *   2  Zuordnungsregel aus lsg_athlete_map                → `regel`
 *   3  normalisierter Name + born                         → `normalisiert`
 *   –  mehrere Treffer                                    → `mehrdeutig`
 *   –  kein Treffer                                       → `offen`
 *
 * ⚠ Eine vierte Stufe „ähnlicher Name, wahrscheinlich dieselbe Person" gibt
 * es bewusst NICHT. Entweder die Zuordnung ist eindeutig, oder die Zeile wird
 * nicht importiert. Ein „wahrscheinlich" hätte niemand bestätigt, ohne es
 * doch von Hand zu prüfen.
 *
 * ⚠ Der Jahrgang ist in jeder Stufe Pflicht. Zwei Personen mit gleichem Namen
 * und gleichem Jahrgang im Verein sind unwahrscheinlich; ein Namensabgleich
 * ohne Jahrgang würde dagegen früher oder später Ergebnisse dem Falschen
 * zuschreiben. Liefert die Quelle keinen Jahrgang, bleibt die Zeile `offen` –
 * auch wenn der Name eindeutig aussieht.
 *
 * @param array $zeile    nachname, vorname, jahrgang (aus P1).
 * @param array $athleten Kandidaten desselben Jahrgangs: id, name, firstname,
 *                        born, cat, active.
 * @param array $regeln   Aktive Regeln desselben Jahrgangs aus lsg_athlete_map.
 * @return array{athletes_id:int,match_type:string,meldung:string,regeln:int[]}
 */
function lsg_bl_p3_zuordnen( array $zeile, array $athleten, array $regeln ) {
	$offen = function ( $meldung, $type = 'offen', $regel_ids = array() ) {
		return array(
			'athletes_id' => 0,
			'match_type'  => $type,
			'meldung'     => $meldung,
			'regeln'      => $regel_ids,
		);
	};

	$jahrgang = isset( $zeile['jahrgang'] ) ? (int) $zeile['jahrgang'] : 0;
	if ( $jahrgang <= 0 ) {
		return $offen( 'Keine Zuordnung möglich – die Ergebnisliste nennt keinen Jahrgang' );
	}

	$q_nach = isset( $zeile['nachname'] ) ? (string) $zeile['nachname'] : '';
	$q_vor  = isset( $zeile['vorname'] ) ? (string) $zeile['vorname'] : '';

	$q_nach_norm = lsg_bl_text_normalisieren( $q_nach );
	$q_vor_norm  = lsg_bl_text_normalisieren( $q_vor );

	/* --- Stufe 1: exakt, nur Groß-/Kleinschreibung egal ---------------- */
	//
	// ⚠ Nicht strcasecmp(): das arbeitet byteweise und faltet keine Umlaute.
	// „KÖRNER" gegen „Körner" liefe damit durch Stufe 1 hindurch und würde
	// erst in Stufe 3 gefunden – der Treffer stünde dann als `normalisiert`
	// im Log, obwohl er exakt war.
	$treffer = array();
	foreach ( $athleten as $a ) {
		if ( (int) $a['born'] !== $jahrgang ) {
			continue;
		}
		if ( lsg_bl_kleinschreiben( $a['name'] ) === lsg_bl_kleinschreiben( $q_nach )
			&& lsg_bl_kleinschreiben( $a['firstname'] ) === lsg_bl_kleinschreiben( $q_vor )
		) {
			$treffer[] = (int) $a['id'];
		}
	}
	$treffer = array_values( array_unique( $treffer ) );
	if ( 1 === count( $treffer ) ) {
		return array(
			'athletes_id' => $treffer[0],
			'match_type'  => 'exakt',
			'meldung'     => '',
			'regeln'      => array(),
		);
	}
	if ( count( $treffer ) > 1 ) {
		return $offen(
			sprintf(
				'Keine Zuordnung möglich – zwei Sportler heißen gleich und sind vom selben Jahrgang (#%s)',
				implode( ', #', $treffer )
			),
			'mehrdeutig'
		);
	}

	/* --- Stufe 2: Zuordnungsregel -------------------------------------- */
	$regel_treffer = array();
	foreach ( $regeln as $r ) {
		if ( (int) $r['born'] !== $jahrgang ) {
			continue;
		}
		if ( isset( $r['aktiv'] ) && ! $r['aktiv'] ) {
			continue;
		}
		if ( lsg_bl_regel_trifft( $r, $q_vor_norm, $q_nach_norm ) ) {
			$regel_treffer[ (int) $r['id'] ] = (int) $r['athletes_id'];
		}
	}

	if ( count( $regel_treffer ) > 1 ) {
		// ⚠ Zwei Regeln, die dieselbe Zeile treffen, sind ein Fehler, keine
		// Auswahlfrage. Sonst entscheidet die Sortierreihenfolge der
		// Datenbank darüber, wem ein Ergebnis gutgeschrieben wird – und das
		// fällt niemandem auf.
		$ids = array_keys( $regel_treffer );
		return $offen(
			sprintf(
				'Keine Zuordnung möglich – Regeln #%s treffen beide zu',
				implode( ' und #', $ids )
			),
			'mehrdeutig',
			$ids
		);
	}
	if ( 1 === count( $regel_treffer ) ) {
		$ids = array_keys( $regel_treffer );
		return array(
			'athletes_id' => (int) reset( $regel_treffer ),
			'match_type'  => 'regel',
			'meldung'     => '',
			'regeln'      => $ids,
		);
	}

	/* --- Stufe 3: normalisiert ----------------------------------------- */
	$treffer = array();
	foreach ( $athleten as $a ) {
		if ( (int) $a['born'] !== $jahrgang ) {
			continue;
		}
		if ( lsg_bl_text_normalisieren( $a['name'] ) === $q_nach_norm
			&& lsg_bl_text_normalisieren( $a['firstname'] ) === $q_vor_norm
		) {
			$treffer[] = (int) $a['id'];
		}
	}
	$treffer = array_values( array_unique( $treffer ) );
	if ( 1 === count( $treffer ) ) {
		return array(
			'athletes_id' => $treffer[0],
			'match_type'  => 'normalisiert',
			'meldung'     => '',
			'regeln'      => array(),
		);
	}
	if ( count( $treffer ) > 1 ) {
		return $offen(
			sprintf(
				'Keine Zuordnung möglich – zwei Sportler heißen normalisiert gleich (#%s)',
				implode( ', #', $treffer )
			),
			'mehrdeutig'
		);
	}

	return $offen( 'Keine Zuordnung möglich – kein Sportler mit diesem Namen und Jahrgang' );
}

/**
 * Ähnliche Athleten als Lesehilfe unter einer nicht zuordenbaren Zeile.
 *
 * ⚠ Reine Lesehilfe, kein Auswahlfeld. Sie beantwortet die häufigste Frage
 * von selbst – „gibt es den schon, nur anders geschrieben?" – und macht
 * sichtbar, warum die vorhandenen Datensätze eben NICHT passen: der eine im
 * Namen, der andere im Jahrgang (Plan 6.5.3).
 *
 * @param array $zeile    nachname, vorname, jahrgang.
 * @param array $athleten Kandidaten: id, name, firstname, born.
 * @param int   $limit    Höchstzahl.
 * @return array<int,array{id:int,name:string,firstname:string,born:int,grund:string}>
 */
function lsg_bl_p3_aehnliche( array $zeile, array $athleten, $limit = 5 ) {
	$q_nach = lsg_bl_text_normalisieren( isset( $zeile['nachname'] ) ? $zeile['nachname'] : '' );
	$q_vor  = lsg_bl_text_normalisieren( isset( $zeile['vorname'] ) ? $zeile['vorname'] : '' );
	$q_jahr = isset( $zeile['jahrgang'] ) ? (int) $zeile['jahrgang'] : 0;

	if ( '' === $q_nach && '' === $q_vor ) {
		return array();
	}

	$kandidaten = array();
	foreach ( $athleten as $a ) {
		$a_nach = lsg_bl_text_normalisieren( $a['name'] );
		$a_vor  = lsg_bl_text_normalisieren( $a['firstname'] );
		$a_jahr = (int) $a['born'];

		$rang  = 99;
		$grund = '';

		if ( $a_nach === $q_nach && $a_vor === $q_vor ) {
			// Name passt, Jahrgang nicht – sonst wäre die Zeile zugeordnet.
			$rang  = 1;
			$grund = 'Name passt, Jahrgang nicht';
		} elseif ( $a_nach === $q_nach && $a_jahr === $q_jahr ) {
			$rang  = 2;
			$grund = 'Nachname und Jahrgang passen, Vorname nicht';
		} elseif ( $a_jahr === $q_jahr && '' !== $q_nach && levenshtein( $a_nach, $q_nach ) <= 2 ) {
			$rang  = 3;
			$grund = 'Jahrgang passt, Nachname ähnlich';
		} elseif ( $a_nach === $q_nach ) {
			$rang  = 4;
			$grund = 'Nachname passt';
		}

		if ( 99 === $rang ) {
			continue;
		}

		$kandidaten[] = array(
			'id'        => (int) $a['id'],
			'name'      => (string) $a['name'],
			'firstname' => (string) $a['firstname'],
			'born'      => $a_jahr,
			'grund'     => $grund,
			'_rang'     => $rang,
		);
	}

	usort(
		$kandidaten,
		function ( $a, $b ) {
			if ( $a['_rang'] !== $b['_rang'] ) {
				return $a['_rang'] - $b['_rang'];
			}
			return strcasecmp( $a['name'] . $a['firstname'], $b['name'] . $b['firstname'] );
		}
	);

	$kandidaten = array_slice( $kandidaten, 0, (int) $limit );
	foreach ( $kandidaten as &$k ) {
		unset( $k['_rang'] );
	}
	return $kandidaten;
}

/* -------------------------------------------------------------------------
 * P4 – gegen lsg_best abgleichen
 * ---------------------------------------------------------------------- */

/**
 * Klartext je Status, wie er in der Tabelle steht (Plan 6.5.4).
 *
 * @return array<string,array{label:string,vorauswahl:bool}>
 */
function lsg_bl_p4_status_liste() {
	return array(
		'neu'        => array(
			'label'      => 'Noch keine Zeit in der Datenbank vorhanden',
			'vorauswahl' => true,
		),
		'schneller'  => array(
			'label'      => 'Neue Zeit ist schneller',
			'vorauswahl' => true,
		),
		'langsamer'  => array(
			'label'      => 'Neue Zeit ist langsamer',
			'vorauswahl' => false,
		),
		'gleich'     => array(
			'label'      => 'Zeit bereits vorhanden',
			'vorauswahl' => false,
		),
		'offen'      => array(
			'label'      => 'Keine Zuordnung möglich – wird nicht importiert',
			'vorauswahl' => false,
		),
		'mehrdeutig' => array(
			'label'      => 'Keine Zuordnung möglich – wird nicht importiert',
			'vorauswahl' => false,
		),
	);
}

/**
 * P4: Status einer Zeile gegen den Bestand.
 *
 * Der Bezugsrahmen ist immer EIN Jahr: `lsg_best` hält Jahresbestleistungen –
 * eine Zeile ist die beste Leistung eines Athleten auf einer Distanz in einem
 * Kalenderjahr, nicht ein Wettkampfergebnis. Über Jahresgrenzen hinweg wird
 * nie überschrieben (Plan 6.5.4).
 *
 * ⚠ Die Abfrage darf genau eine oder keine Zeile liefern – aber sie kann
 * mehr. Dann ist die BESTE der gefundenen Zeilen der Bezug, geschrieben wird
 * ausschließlich dorthin, die übrigen bleiben unangetastet, und die
 * Statusspalte bekommt den Zusatz „Doppelzeile im Bestand". Kein stilles
 * `LIMIT 1`, kein automatisches Aufräumen: Der Import meldet den kaputten
 * Bestand, er repariert ihn nicht.
 *
 * ⚠ Verglichen wird über lsg_bl_parse_performance(), nicht per strcmp: die
 * Funktion kennt die Formatvarianten des Bestands, auch die fehlende
 * Stundenangabe. Ein String-Vergleich läge bei `38:57` gegen `01:38:57`
 * falsch.
 *
 * @param string $distanz  Kanonischer Distanzcode.
 * @param string $zeit_neu Normalisierte Zeit der Quelle, 'HH:MM:SS'.
 * @param array  $bestand  Zeilen aus lsg_best: id, time, town, date.
 * @return array{status:string,best_id:int,time_alt:string,doppelt:int[],zusatz:string}
 */
function lsg_bl_p4_status( $distanz, $zeit_neu, array $bestand ) {
	$leer = array(
		'status'   => 'neu',
		'best_id'  => 0,
		'time_alt' => '',
		'doppelt'  => array(),
		'zusatz'   => '',
	);

	if ( ! $bestand ) {
		return $leer;
	}

	// Die beste der gefundenen Zeilen ist der Bezug.
	$bezug      = null;
	$bezug_perf = null;
	foreach ( $bestand as $b ) {
		$perf = lsg_bl_parse_performance( $distanz, $b['time'] );
		if ( null === $bezug_perf || lsg_bl_perf_besser( $perf, $bezug_perf ) ) {
			$bezug      = $b;
			$bezug_perf = $perf;
		}
	}

	$zusatz  = '';
	$doppelt = array();
	if ( count( $bestand ) > 1 ) {
		foreach ( $bestand as $b ) {
			$doppelt[] = (int) $b['id'];
		}
		sort( $doppelt );
		$zusatz = sprintf(
			'Doppelzeile im Bestand (ids #%s) – bitte bereinigen',
			implode( ', #', $doppelt )
		);
	}

	$neu_perf = lsg_bl_parse_performance( $distanz, $zeit_neu );

	if ( $neu_perf['sort'] === $bezug_perf['sort'] ) {
		$status = 'gleich';
	} elseif ( lsg_bl_perf_besser( $neu_perf, $bezug_perf ) ) {
		$status = 'schneller';
	} else {
		$status = 'langsamer';
	}

	return array(
		'status'   => $status,
		'best_id'  => (int) $bezug['id'],
		'time_alt' => (string) $bezug['time'],
		'doppelt'  => $doppelt,
		'zusatz'   => $zusatz,
	);
}

/**
 * Ist die eine Leistung besser als die andere?
 *
 * `better` kommt aus lsg_bl_parse_performance(): bei Zeiten ist kleiner
 * besser, bei Zeitläufen größer. Im Import wird der Zeitlauf-Zweig nie
 * erreicht – die Distanzen 6h/12h/24h stehen gar nicht erst im Select –,
 * aber das Formular aus Abschnitt 7 benutzt dieselbe Funktion.
 *
 * @param array $a Ergebnis von lsg_bl_parse_performance().
 * @param array $b Ergebnis von lsg_bl_parse_performance().
 * @return bool
 */
function lsg_bl_perf_besser( array $a, array $b ) {
	if ( 'higher' === $a['better'] ) {
		return $a['sort'] > $b['sort'];
	}
	return $a['sort'] < $b['sort'];
}

/**
 * Statustext einer Zeile im Klartext, mit alter und neuer Zeit im Vergleich.
 *
 * Nicht nur ein Icon: was gleich passiert, muss lesbar dastehen (Plan 6.6).
 *
 * @param array $zeile Zeile mit status, zeit, time_alt, zusatz, match_meldung.
 * @return string
 */
function lsg_bl_status_text( array $zeile ) {
	$status = isset( $zeile['status'] ) ? (string) $zeile['status'] : '';
	$neu    = isset( $zeile['zeit'] ) ? (string) $zeile['zeit'] : '';
	$alt    = isset( $zeile['time_alt'] ) ? (string) $zeile['time_alt'] : '';

	switch ( $status ) {
		case 'neu':
			$text = 'Noch keine Zeit in der Datenbank vorhanden';
			break;
		case 'schneller':
			$text = sprintf( 'Neue Zeit ist schneller (%1$s → %2$s)', $alt, $neu );
			break;
		case 'langsamer':
			$text = sprintf( 'Neue Zeit ist langsamer (%s bleibt)', $alt );
			break;
		case 'gleich':
			$text = 'Zeit bereits vorhanden';
			break;
		case 'offen':
		case 'mehrdeutig':
			$text = isset( $zeile['match_meldung'] ) && '' !== $zeile['match_meldung']
				? (string) $zeile['match_meldung']
				: 'Keine Zuordnung möglich – wird nicht importiert';
			break;
		default:
			$text = $status;
	}

	if ( ! empty( $zeile['zusatz'] ) ) {
		$text .= ' · ' . $zeile['zusatz'];
	}

	return $text;
}

/**
 * Hat diese Zeile eine Checkbox?
 *
 * `offen` und `mehrdeutig` bekommen keine: dort gibt es kein Ziel zum
 * Schreiben (Plan 6.6). Damit sind sie auch von einer künftigen
 * „Alle"-Checkbox automatisch ausgenommen.
 *
 * @param string $status Status der Zeile.
 * @return bool
 */
function lsg_bl_zeile_waehlbar( $status ) {
	return ! in_array( $status, array( 'offen', 'mehrdeutig' ), true );
}

/**
 * Innerhalb EINES Imports zweimal derselbe Athlet auf derselben Distanz?
 *
 * Kommt vor – Staffel plus Einzellauf, oder zwei Listen nacheinander. Dann
 * gewinnt die bessere Leistung; die schlechtere wird als `langsamer`
 * mitgeführt und ist abwählbar, nicht stillschweigend verworfen (Plan 6.5.4).
 *
 * @param array  $zeilen  Zeilen mit athletes_id, zeit, status.
 * @param string $distanz Distanzcode.
 * @return array Dieselben Zeilen, Status angepasst.
 */
function lsg_bl_p4_dubletten_im_import( array $zeilen, $distanz ) {
	$beste = array();

	foreach ( $zeilen as $i => $z ) {
		$aid = isset( $z['athletes_id'] ) ? (int) $z['athletes_id'] : 0;
		if ( ! $aid || ! lsg_bl_zeile_waehlbar( $z['status'] ) ) {
			continue;
		}
		$perf = lsg_bl_parse_performance( $distanz, $z['zeit'] );
		if ( ! isset( $beste[ $aid ] ) || lsg_bl_perf_besser( $perf, $beste[ $aid ]['perf'] ) ) {
			$beste[ $aid ] = array(
				'index' => $i,
				'perf'  => $perf,
			);
		}
	}

	foreach ( $zeilen as $i => $z ) {
		$aid = isset( $z['athletes_id'] ) ? (int) $z['athletes_id'] : 0;
		if ( ! $aid || ! isset( $beste[ $aid ] ) || $beste[ $aid ]['index'] === $i ) {
			continue;
		}
		// Diese Zeile ist die schlechtere von zweien im selben Vorgang.
		$zeilen[ $i ]['status']   = 'langsamer';
		$zeilen[ $i ]['time_alt'] = $zeilen[ $beste[ $aid ]['index'] ]['zeit'];
		$zeilen[ $i ]['zusatz']   = trim(
			$zeilen[ $i ]['zusatz'] . ' Derselbe Sportler steht in diesem Import mit einer besseren Zeit.'
		);
	}

	return $zeilen;
}

/* -------------------------------------------------------------------------
 * Gesamtsieg (Plan 6.5.5 – erkennen und markieren, noch nicht schreiben)
 * ---------------------------------------------------------------------- */

/**
 * Ist diese Zeile ein Gesamtsieg?
 *
 * Erkennung: `platz` ist 1. Mehr braucht es nicht – aber nur in der
 * **Gesamtwertung**. In einer nach Geschlecht oder Altersklasse gefilterten
 * Liste ist Platz 1 kein Gesamtsieg, sondern ein Klassensieg. Bei jeder
 * anderen Liste wird deshalb keiner gemeldet: ein falsch gemeldeter Sieg wäre
 * deutlich ärgerlicher als ein übersehener.
 *
 * ⚠ Das Ergebnis hat vorerst keine Wirkung auf die Übernahme. Geschrieben
 * wird nach `lsg_win` noch nicht (Plan 6.5.5, 9.2).
 *
 * @param array $zeile         Ergebniszeile als Array (LSG_BL_Ergebnis::to_array()).
 * @param bool  $gesamtwertung Ist die gewählte Liste die Gesamtwertung?
 * @return bool
 */
function lsg_bl_ist_gesamtsieg( array $zeile, $gesamtwertung ) {
	if ( ! $gesamtwertung ) {
		return false;
	}
	$platz = isset( $zeile['platz'] ) ? trim( (string) $zeile['platz'] ) : '';
	return ( '1' === $platz );
}

/* -------------------------------------------------------------------------
 * Vorschau-Fingerabdruck
 * ---------------------------------------------------------------------- */

/**
 * Fingerabdruck der Werte, von denen die Vorschau abhängt.
 *
 * ⚠ Wird Datum oder Distanz nach dem Parsen geändert, ist die Vorschau
 * ungültig: beide gehen in P4 ein – die Distanz in die Suche nach dem
 * Bestand, das Datum in das Jahr. Die Oberfläche verwirft die Tabelle dann
 * und schaltet zurück auf „Parsen" (Plan 6.5.1).
 *
 * ⚠ Der Ort steht bewusst NICHT drin. Er landet in `lsg_best.town`, geht aber
 * in keinen Vergleich ein – ihn zu ändern darf keine Tabelle wegwerfen.
 *
 * @param array $args adapter, event_id, contest_id, list_id, distanz, datum.
 * @return string
 */
function lsg_bl_import_fingerprint( array $args ) {
	$teile = array();
	foreach ( array( 'adapter', 'event_id', 'contest_id', 'list_id', 'distanz', 'datum' ) as $k ) {
		$teile[] = isset( $args[ $k ] ) ? (string) $args[ $k ] : '';
	}
	return md5( implode( '|', $teile ) );
}

/* -------------------------------------------------------------------------
 * Zustände der Oberfläche
 * ---------------------------------------------------------------------- */

/**
 * Alle Zustände der Import-Seite, mit Klartext für die Anzeige.
 *
 * Ein Zustand, der nicht dargestellt ist, wird später als Bug gemeldet –
 * daher stehen hier alle elf aus Plan 6.11, auch die, die erst mit einem
 * späteren Meilenstein sichtbar werden.
 *
 * @return array<string,string>
 */
function lsg_bl_import_zustaende() {
	return array(
		'leer'        => 'URL eingeben',
		'erkenne'     => 'Adresse wird geprüft …',            // sichtbar erst mit JS (M6)
		'unbekannt'   => 'Kein Adapter für diese Adresse',
		// „erkannt" deckt zwei Lagen ab, die der Plan nicht trennt: Wettbewerb
		// noch nicht gewählt, und Wettbewerb gewählt, aber Distanz oder Datum
		// fehlen noch. In beiden Fällen ist die Auswahl unvollständig und der
		// Parsen-Button gesperrt – deshalb ein Label, das beides trägt.
		'erkannt'     => 'Quelle erkannt – Auswahl vervollständigen',
		'bereit'      => 'Bereit zum Parsen',
		'parse'       => 'Ergebnisliste wird gelesen …',       // M6
		'vorschau'    => 'Vorschau',
		'uebernahme'  => 'Ergebnisse werden übernommen …',     // M3/M6
		'gespeichert' => 'Übernommen',                          // M3
		'teilfehler'  => 'Teilweise übernommen',                // M3
		'fehler'      => 'Fehler',
	);
}

/**
 * Welchen Zustand hat die Seite?
 *
 * Eine Funktion, ein Wahrheitsort – die Oberfläche entscheidet nicht selbst,
 * was sie zeigt.
 *
 * @param array $ctx url, adapter_cls, fehler, discovery, contest_id,
 *                   distanz, datum, vorschau.
 * @return string Schlüssel aus lsg_bl_import_zustaende().
 */
function lsg_bl_import_zustand( array $ctx ) {
	if ( ! empty( $ctx['fehler'] ) ) {
		return 'fehler';
	}
	if ( empty( $ctx['url'] ) ) {
		return 'leer';
	}
	if ( empty( $ctx['adapter_cls'] ) ) {
		return 'unbekannt';
	}
	if ( ! empty( $ctx['uebernommen'] ) ) {
		// „7 von 9 übernommen" darf weder wie ein glatter Erfolg aussehen
		// noch wie ein Totalausfall – deshalb ein eigener Zustand (Plan 6.11).
		$u = (array) $ctx['uebernommen'];
		$schief = ( ! empty( $u['konflikte'] ) || ! empty( $u['fehler'] ) );
		return $schief ? 'teilfehler' : 'gespeichert';
	}
	if ( ! empty( $ctx['vorschau'] ) ) {
		return 'vorschau';
	}
	if ( empty( $ctx['contest_id'] ) ) {
		return 'erkannt';
	}
	if ( empty( $ctx['distanz'] ) || empty( $ctx['datum'] ) ) {
		return 'erkannt';
	}
	return 'bereit';
}

/**
 * Was fehlt noch, damit „Parsen" freigegeben wird?
 *
 * Der Parsen-Button bleibt gesperrt, bis Distanz UND vollständiges Datum
 * stehen – mit einer Meldung, die sagt, welcher der beiden Werte fehlt
 * (Plan 6.5.1).
 *
 * @param string $distanz Gewählter Distanzcode.
 * @param string $datum   'JJJJ-MM-TT' oder ''.
 * @return string Klartext, oder '' wenn beides steht.
 */
function lsg_bl_import_was_fehlt( $distanz, $datum ) {
	$fehlt = array();

	if ( '' === trim( (string) $distanz ) ) {
		$fehlt[] = 'die Distanz';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( (string) $datum ) ) ) {
		$fehlt[] = 'ein vollständiges Veranstaltungsdatum';
	}

	if ( ! $fehlt ) {
		return '';
	}

	return 'Vor dem Parsen fehlt noch ' . implode( ' und ', $fehlt ) . '.';
}

/**
 * Klartext zur Herkunft eines Datums, wie er am Feld steht (Plan 6.5.1).
 *
 * @param string $quelle liste|ausschreibung|api|name|jahr|manuell|''
 * @return string
 */
function lsg_bl_datum_quelle_label( $quelle ) {
	$map = array(
		'liste'         => 'aus der Veranstaltungsübersicht',
		'ausschreibung' => 'aus der Ausschreibung gelesen',
		'api'           => 'aus der Quelle übernommen',
		'name'          => 'aus dem Namen gelesen',
		'jahr'          => 'nur das Jahr erkannt – Tag und Monat ergänzen',
		'manuell'       => '',
	);
	return isset( $map[ $quelle ] ) ? $map[ $quelle ] : '';
}
