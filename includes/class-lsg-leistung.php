<?php
/**
 * Die reine Logik der Seite „Bestenliste" (Plan, Abschnitt 7).
 *
 * ⚠ Diese Datei ruft KEINE WordPress-Funktion und liest kein $wpdb. Sie wird
 * von der Unit-Lage geladen und ist damit ohne Datenbank prüfbar – genauso
 * wie class-lsg-normalize.php und class-lsg-pipeline.php. Alles mit $wpdb
 * steht in class-lsg-best.php, alles mit Ausgabe in
 * includes/admin/page-best.php.
 *
 * Der Grund für diesen Schnitt ist hier besonders handfest: zwischen dem
 * Formular und `lsg_best` steht kein Trichter wie beim Import. Was diese
 * Funktionen entscheiden, landet direkt im Bestand – in derselben Tabelle,
 * in der 6 000 Zeilen Vereinsgeschichte liegen.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Das Leistungsfeld
 * ---------------------------------------------------------------------- */

/**
 * Wie das Leistungsfeld für eine Distanz aussieht und was es annimmt.
 *
 * Das ist der eigentliche Grund, warum diese Seite die Zeitläufe kann und
 * der Import nicht (Plan 7.2): bei `6h`/`12h`/`24h` hält `lsg_best.time`
 * eine Strecke, keine Zeit.
 *
 * ⚠ `pattern` ist die Vorprüfung im Browser, nicht die Prüfung. Entscheidend
 * ist lsg_bl_leistung_lesen() – ein `pattern` schützt vor Tippfehlern, nicht
 * vor einem abgeschickten Formular ohne JavaScript.
 *
 * @param string $distanz Distanzcode.
 * @return array{typ:string,label:string,platzhalter:string,pattern:string,hinweis:string,wort:string}
 */
function lsg_bl_leistung_feld( $distanz ) {
	if ( 'distance' === lsg_bl_distance_type( $distanz ) ) {
		return array(
			'typ'         => 'distance',
			'label'       => 'Strecke',
			'platzhalter' => '96,723 km',
			// Keine führende Null: 1–3 Vorkommastellen, die erste nicht 0
			// (außer die Zahl ist selbst kleiner als 1 km, was es nicht
			// gibt – deshalb gar keine Ausnahme). Genau drei Nachkommastellen.
			'pattern'     => '[1-9][0-9]{0,2},[0-9]{3} ?km',
			'hinweis'     => 'Kilometer mit drei Nachkommastellen, Komma als Trenner, ohne führende Null: 96,723 km',
			'wort'        => 'weiter',
		);
	}

	return array(
		'typ'         => 'time',
		'label'       => 'Zeit',
		'platzhalter' => '01:36:44',
		'pattern'     => '[0-9]{1,3}:[0-5][0-9](:[0-5][0-9])?([.,][0-9]{1,3})?',
		'hinweis'     => 'HH:MM:SS. Kürzer geht auch (38:57 wird 00:38:57), Zehntel werden aufgerundet.',
		'wort'        => 'schneller',
	);
}

/**
 * Dasselbe für alle Distanzcodes auf einmal, für das Skript der Seite.
 *
 * ⚠ Hier wird nichts zusätzlich entschieden: die Funktion ruft für jeden Code
 * lsg_bl_leistung_feld() und reicht das Ergebnis weiter. Sonst stünde die
 * Zuordnung „Zeitlauf → Streckenfeld" zweimal da, einmal in PHP und einmal in
 * JavaScript – und beim nächsten Distanzcode fiele nur eine der beiden auf.
 *
 * @return array<string,array>
 */
function lsg_bl_leistung_felder_js() {
	$out = array();
	foreach ( array_keys( lsg_bl_distance_map() ) as $code ) {
		$out[ $code ] = lsg_bl_leistung_feld( $code );
	}
	return $out;
}

/**
 * Das Vergleichswort einer Distanz: „schneller" oder „weiter".
 *
 * Bei Zeitläufen ist mehr besser (Plan 7.3), und „112,737 km ist schneller"
 * wäre schlicht falsch.
 *
 * @param string $distanz  Distanzcode.
 * @param bool   $steigern true = „schneller"/„weiter", false = die Gegenform.
 * @return string
 */
function lsg_bl_besser_wort( $distanz, $steigern = true ) {
	if ( 'distance' === lsg_bl_distance_type( $distanz ) ) {
		return $steigern ? 'weiter' : 'kürzer';
	}
	return $steigern ? 'schneller' : 'langsamer';
}

/**
 * Eine Leistungseingabe prüfen und in die Form bringen, in der sie in
 * `lsg_best.time` steht.
 *
 * ⚠ **Abgelehnt wird alles, was lsg_bl_parse_performance() nur über den
 * Zahlen-Fallback einfangen würde** (Plan 7.2). Dieser Fallback existiert für
 * die historischen Tippfehler im Bestand – `01:20.24` für einen Halbmarathon
 * steht wirklich so da. Über das Formular darf kein neuer dazukommen: der
 * Fallback sortiert solche Zeilen zwar irgendwohin, aber nicht dorthin, wo
 * sie hingehören.
 *
 * @param string $distanz Distanzcode.
 * @param string $roh     Eingabe, wie sie im Feld stand.
 * @return array{wert:string,fehler:string} wert = '' bei Fehler.
 */
function lsg_bl_leistung_lesen( $distanz, $roh ) {
	$roh = trim( (string) $roh );

	if ( '' === $roh ) {
		return array(
			'wert'   => '',
			'fehler' => 'Bitte eine Leistung eintragen.',
		);
	}

	if ( 'distance' === lsg_bl_distance_type( $distanz ) ) {
		return lsg_bl_strecke_lesen( $roh );
	}

	$zeit = lsg_bl_zeit_normalisieren( $roh );
	if ( '' === $zeit ) {
		return array(
			'wert'   => '',
			'fehler' => sprintf(
				'„%s" ist keine Zeit, die sich eindeutig lesen lässt. Erwartet wird HH:MM:SS oder MM:SS, '
				. 'zum Beispiel 01:36:44 oder 38:57.',
				$roh
			),
		);
	}

	return array(
		'wert'   => $zeit,
		'fehler' => '',
	);
}

/**
 * Eine Streckenangabe prüfen und normalisieren.
 *
 * Zurück kommt `N,NNN km` – Komma als Dezimaltrenner, genau drei
 * Nachkommastellen, ein Leerzeichen, `km`. **Ohne führende Null**
 * (Plan 7.2): von 199 Zeitlauf-Zeilen im Bestand entsprachen 173 dieser
 * Form; die 23 mit Auffüllung sind mit V1 angeglichen worden.
 *
 * ⚠ Die drei Nachkommastellen sind Pflicht, nicht Kosmetik. Sie sind die
 * Auflösung, in der die Veranstalter messen, und `64,16 km` neben
 * `112,737 km` liest sich wie zwei verschiedene Genauigkeiten. Ergänzt
 * werden sie NICHT stillschweigend: wer `96,7 km` eintippt, hat vielleicht
 * `96,700` gemeint – oder sich verschrieben. Also Rückfrage per Fehlermeldung.
 *
 * @param string $roh Eingabe.
 * @return array{wert:string,fehler:string}
 */
function lsg_bl_strecke_lesen( $roh ) {
	$roh = trim( (string) $roh );

	$fehler = function ( $text ) {
		return array(
			'wert'   => '',
			'fehler' => $text,
		);
	};

	// Erlaubt: Zahl, Komma oder Punkt, Nachkommastellen, optional Leerzeichen,
	// optional 'km'. Alles andere wird abgelehnt – auch ein zweites Wort.
	if ( ! preg_match( '/^(\d{1,3})\s*[.,]\s*(\d{1,6})\s*(?:km)?$/i', $roh, $m ) ) {
		// Eine Zahl ohne Nachkommastellen ist der häufigste Fall und
		// verdient eine eigene Meldung – „228 km" steht so im Bestand.
		if ( preg_match( '/^(\d{1,3})\s*(?:km)?$/i', $roh, $g ) ) {
			return $fehler(
				sprintf(
					'„%1$s" hat keine Nachkommastellen. Bei Zeitläufen wird auf den Meter gemessen – '
					. 'bitte drei Stellen angeben, also zum Beispiel %2$s,000 km.',
					$roh,
					$g[1]
				)
			);
		}
		return $fehler(
			sprintf(
				'„%s" ist keine Streckenangabe, die sich eindeutig lesen lässt. Erwartet wird '
				. 'die Kilometerzahl mit drei Nachkommastellen, zum Beispiel 96,723 km.',
				$roh
			)
		);
	}

	$vor  = $m[1];
	$nach = $m[2];

	// ⚠ Führende Null im Vorkommateil ablehnen, nicht stillschweigend
	// abschneiden. `096,723 km` ist die alte Schreibweise; wer sie eintippt,
	// arbeitet vermutlich eine alte Liste ab und soll den Unterschied sehen.
	if ( strlen( $vor ) > 1 && '0' === $vor[0] ) {
		return $fehler(
			sprintf(
				'„%1$s" hat eine führende Null. Geschrieben wird ohne Auffüllung: %2$s,%3$s km.',
				$roh,
				ltrim( $vor, '0' ),
				$nach
			)
		);
	}

	if ( 3 !== strlen( $nach ) ) {
		return $fehler(
			sprintf(
				'„%1$s" hat %2$d Nachkommastellen. Es müssen genau drei sein: %3$s,%4$s km.',
				$roh,
				strlen( $nach ),
				$vor,
				substr( str_pad( $nach, 3, '0' ), 0, 3 )
			)
		);
	}

	if ( '0' === $vor ) {
		return $fehler( 'Eine Strecke unter einem Kilometer ist kein Zeitlauf-Ergebnis.' );
	}

	return array(
		'wert'   => $vor . ',' . $nach . ' km',
		'fehler' => '',
	);
}

/* -------------------------------------------------------------------------
 * Jahresbestzeit-Prüfung (Plan 7.3)
 * ---------------------------------------------------------------------- */

/**
 * Die vier Lagen aus Plan 7.3, als Struktur.
 *
 * ⚠ Dies ist bewusst NICHT lsg_bl_p4_status(). Die Stufen sind dieselben,
 * aber die Antwort ist eine andere: P4 entscheidet, ob geschrieben wird,
 * diese Funktion beschreibt, was der Mensch am Formular sehen soll – und die
 * Entscheidung bleibt bei ihm (Plan 7.3: „geprüft und gewarnt wird, gesperrt
 * nicht"). Der gemeinsame Kern steckt in lsg_bl_parse_performance() und
 * lsg_bl_perf_besser(), die beide Wege benutzen.
 *
 * ⚠ Liefert der Bestand mehr als eine Zeile, ist die BESTE der Bezug und
 * `zusatz` nennt die IDs – dieselbe Regel wie 6.5.4, in derselben
 * Formulierung. Seit V1 sollte der Fall nicht mehr auftreten.
 *
 * @param string $distanz   Distanzcode.
 * @param string $leistung  Neue Leistung, bereits normalisiert.
 * @param array  $bestand   Zeilen von lsg_bl_best_zeilen().
 * @param int    $eigene_id Beim Bearbeiten: die Zeile, die gerade bearbeitet
 *                          wird. Sie ist nicht ihr eigener Konflikt.
 * @return array{lage:string,best_id:int,time_alt:string,town_alt:string,date_alt:int,doppelt:int[],zusatz:string,text:string,vorbelegung:string}
 */
function lsg_bl_best_pruefung( $distanz, $leistung, array $bestand, $eigene_id = 0 ) {
	$eigene_id = (int) $eigene_id;

	// Die bearbeitete Zeile selbst ist kein Bestand, gegen den geprüft wird.
	// Ohne das meldete jedes Bearbeiten „steht bereits so in der Datenbank".
	if ( $eigene_id > 0 ) {
		$bestand = array_values(
			array_filter(
				$bestand,
				function ( $b ) use ( $eigene_id ) {
					return (int) $b['id'] !== $eigene_id;
				}
			)
		);
	}

	$leer = array(
		'lage'        => 'keine',
		'best_id'     => 0,
		'time_alt'    => '',
		'town_alt'    => '',
		'date_alt'    => 0,
		'doppelt'     => array(),
		'zusatz'      => '',
		'text'        => '',
		'vorbelegung' => 'anlegen',
	);

	if ( ! $bestand ) {
		// Die Regel aus Plan 7.3 gehört in den Hinweis selbst: pro Sportler,
		// Distanz und Jahr haelt die Bestenliste genau eine Zeile. Ohne
		// Bestand ist diese Leistung nicht „auch noch eine“, sondern die,
		// die diese Zeile wird.
		$leer['text'] = 'Einzige Leistung auf dieser Distanz in diesem Jahr.';
		return $leer;
	}

	// Bezug ist die beste der gefundenen Zeilen.
	$bezug      = null;
	$bezug_perf = null;
	foreach ( $bestand as $b ) {
		$perf = lsg_bl_parse_performance( $distanz, $b['time'] );
		if ( null === $bezug_perf || lsg_bl_perf_besser( $perf, $bezug_perf ) ) {
			$bezug      = $b;
			$bezug_perf = $perf;
		}
	}

	$doppelt = array();
	$zusatz  = '';
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

	$neu_perf = lsg_bl_parse_performance( $distanz, $leistung );

	if ( $neu_perf['sort'] === $bezug_perf['sort'] ) {
		$lage        = 'gleich';
		$text        = 'Diese Leistung steht bereits so in der Datenbank.';
		$vorbelegung = 'nichts';
	} elseif ( lsg_bl_perf_besser( $neu_perf, $bezug_perf ) ) {
		$lage        = 'besser';
		$text        = sprintf(
			'Die neue Leistung ist %s (%s → %s).',
			lsg_bl_besser_wort( $distanz, true ),
			$bezug['time'],
			$leistung
		);
		$vorbelegung = 'ueberschreiben';
	} else {
		$lage        = 'schlechter';
		$text        = sprintf(
			'Die neue Leistung ist %s – %s bleibt stehen.',
			lsg_bl_besser_wort( $distanz, false ),
			$bezug['time']
		);
		$vorbelegung = 'nichts';
	}

	return array(
		'lage'        => $lage,
		'best_id'     => (int) $bezug['id'],
		'time_alt'    => (string) $bezug['time'],
		'town_alt'    => isset( $bezug['town'] ) ? (string) $bezug['town'] : '',
		'date_alt'    => isset( $bezug['date'] ) ? (int) $bezug['date'] : 0,
		'doppelt'     => $doppelt,
		'zusatz'      => $zusatz,
		'text'        => $text,
		'vorbelegung' => $vorbelegung,
	);
}

/**
 * Darf mit dieser Prüfung gespeichert werden – und was passiert dann?
 *
 * ⚠ **Es gibt keine Option „zusätzlich anlegen"** (Plan 7.3). Eine zweite
 * Zeile für denselben Athleten, dieselbe Distanz und dasselbe Jahr ist kein
 * Sonderfall, sondern ein kaputter Bestand: die Bestenliste zeigt dann beide,
 * die Ewige Bestenliste dedupliziert eine davon weg, und keine der beiden
 * Ansichten ist mehr erklärbar.
 *
 * @param array $pruefung  Ergebnis von lsg_bl_best_pruefung().
 * @param bool  $ersetzen  Haken „Der vorhandene Eintrag ist falsch, ersetzen".
 * @return array{aktion:string,best_id:int,grund:string}
 *         aktion insert|update|nichts
 */
function lsg_bl_best_aktion( array $pruefung, $ersetzen = false ) {
	$lage = isset( $pruefung['lage'] ) ? (string) $pruefung['lage'] : 'keine';

	if ( 'keine' === $lage ) {
		return array(
			'aktion'  => 'insert',
			'best_id' => 0,
			'grund'   => '',
		);
	}

	if ( 'besser' === $lage ) {
		return array(
			'aktion'  => 'update',
			'best_id' => (int) $pruefung['best_id'],
			'grund'   => '',
		);
	}

	if ( 'gleich' === $lage ) {
		// Auch mit Haken nicht: es gäbe nichts zu ändern. Ein „update", das
		// denselben Wert schreibt, stünde als Änderung im Log und wäre keine.
		return array(
			'aktion'  => 'nichts',
			'best_id' => (int) $pruefung['best_id'],
			'grund'   => 'Diese Leistung steht bereits so in der Datenbank.',
		);
	}

	// 'schlechter' – nur auf ausdrücklichen Haken.
	if ( $ersetzen ) {
		return array(
			'aktion'  => 'update',
			'best_id' => (int) $pruefung['best_id'],
			'grund'   => '',
		);
	}

	return array(
		'aktion'  => 'nichts',
		'best_id' => (int) $pruefung['best_id'],
		'grund'   => sprintf(
			'Der Bestand (%s) ist besser und bleibt stehen. Wenn der vorhandene Eintrag falsch ist, '
			. 'bitte den Haken „Der vorhandene Eintrag ist falsch, ersetzen" setzen.',
			$pruefung['time_alt']
		),
	);
}

/**
 * Was sich beim Bearbeiten einer Zeile tatsächlich geändert hat.
 *
 * ⚠ Der Anlass ist eine Meldung, die sonst „Zeile geändert (96,723 km →
 * 96,723 km)" lautet – wenn jemand nur den Ort korrigiert. Das ist keine
 * Kleinigkeit: dieselbe Meldung wandert ins Log, und in zwei Jahren ist sie
 * die einzige Auskunft darüber, was an dieser Zeile passiert ist.
 *
 * ⚠ Ein leeres Ergebnis heißt: nichts hat sich geändert. Der Aufrufer
 * schreibt dann NICHT – ein Update, das denselben Wert schreibt, stünde als
 * Änderung im Log und wäre keine. Dieselbe Überlegung wie bei der Lage
 * `gleich` in lsg_bl_best_aktion().
 *
 * @param array $alt Bestandszeile: athletes_id, distance, time, town, date.
 * @param array $neu Neue Werte: athlet, distanz, leistung, ort, datum_ts.
 * @return array<int,array{feld:string,alt:string,neu:string}>
 */
function lsg_bl_best_diff( array $alt, array $neu ) {
	$paare = array(
		'Sportler' => array(
			(string) ( isset( $alt['athletes_id'] ) ? (int) $alt['athletes_id'] : 0 ),
			(string) ( isset( $neu['athlet'] ) ? (int) $neu['athlet'] : 0 ),
		),
		'Distanz'  => array(
			isset( $alt['distance'] ) ? (string) $alt['distance'] : '',
			isset( $neu['distanz'] ) ? (string) $neu['distanz'] : '',
		),
		'Leistung' => array(
			isset( $alt['time'] ) ? (string) $alt['time'] : '',
			isset( $neu['leistung'] ) ? (string) $neu['leistung'] : '',
		),
		'Ort'      => array(
			isset( $alt['town'] ) ? (string) $alt['town'] : '',
			isset( $neu['ort'] ) ? (string) $neu['ort'] : '',
		),
		'Datum'    => array(
			(string) ( isset( $alt['date'] ) ? (int) $alt['date'] : 0 ),
			(string) ( isset( $neu['datum_ts'] ) ? (int) $neu['datum_ts'] : 0 ),
		),
	);

	$out = array();
	foreach ( $paare as $feld => $werte ) {
		if ( $werte[0] === $werte[1] ) {
			continue;
		}
		$out[] = array(
			'feld' => $feld,
			'alt'  => $werte[0],
			'neu'  => $werte[1],
		);
	}

	return $out;
}

/**
 * Die Änderungen als Satz, für Meldung und Log.
 *
 * @param array $diff Ergebnis von lsg_bl_best_diff().
 * @return string Leer, wenn sich nichts geändert hat.
 */
function lsg_bl_best_diff_text( array $diff ) {
	if ( ! $diff ) {
		return '';
	}

	$teile = array();
	foreach ( $diff as $d ) {
		// Der Timestamp taugt nicht als Anzeige; das Feld wird nur genannt.
		// Umgerechnet wird er nicht – dafür bräuchte diese Datei
		// wp_timezone(), und dann wäre sie nicht mehr ohne WordPress prüfbar.
		if ( 'Datum' === $d['feld'] || 'Sportler' === $d['feld'] ) {
			$teile[] = $d['feld'];
			continue;
		}
		$teile[] = sprintf( '%s %s → %s', $d['feld'], $d['alt'], $d['neu'] );
	}

	return implode( ', ', $teile );
}

/* -------------------------------------------------------------------------
 * Das Formular als Ganzes
 * ---------------------------------------------------------------------- */

/**
 * Die Felder des Formulars mit ihren Rohwerten.
 *
 * @return array
 */
function lsg_bl_best_felder_leer() {
	return array(
		'id'       => 0,
		'athlet'   => 0,
		'datum'    => '',
		'distanz'  => '',
		'leistung' => '',
		'ort'      => '',
		'ersetzen' => false,
	);
}

/**
 * Eine Formulareingabe prüfen – alle Felder, alle Fehler auf einmal.
 *
 * ⚠ Es wird nicht beim ersten Fehler abgebrochen. Wer vier Felder falsch
 * ausgefüllt hat, soll das in einem Durchgang erfahren und nicht in vier.
 *
 * ⚠ Das Jahr kommt aus dem EINGEGEBENEN Veranstaltungsdatum, nie aus
 * `date('Y')` (Plan 7.3): ein im Januar nachgetragener Dezemberlauf gehört
 * ins Vorjahr.
 *
 * @param array      $eingabe Rohwerte aus dem Formular.
 * @param array|null $athlet  Zeile aus lsg_athlete, oder null wenn unbekannt.
 * @param int        $jahr_max Oberes plausibles Jahr; 0 = keine Prüfung.
 * @return array{ok:bool,fehler:array<string,string>,werte:array}
 */
function lsg_bl_best_formular_pruefen( array $eingabe, $athlet, $jahr_max = 0 ) {
	$fehler = array();
	$werte  = array(
		'id'       => isset( $eingabe['id'] ) ? (int) $eingabe['id'] : 0,
		'athlet'   => isset( $eingabe['athlet'] ) ? (int) $eingabe['athlet'] : 0,
		'datum'    => isset( $eingabe['datum'] ) ? (string) $eingabe['datum'] : '',
		'distanz'  => isset( $eingabe['distanz'] ) ? (string) $eingabe['distanz'] : '',
		'leistung' => '',
		'ort'      => isset( $eingabe['ort'] ) ? trim( (string) $eingabe['ort'] ) : '',
		'jahr'     => 0,
		'ak'       => '',
	);

	/* ---- Athlet ---- */
	if ( $werte['athlet'] <= 0 ) {
		$fehler['athlet'] = 'Bitte einen Sportler auswählen.';
	} elseif ( ! $athlet ) {
		// ⚠ Das Formular legt keinen Athleten an (Plan 7.2). Eine ID, die es
		// nicht gibt, ist deshalb ein Fehler und kein Anlass zum Anlegen.
		$fehler['athlet'] = 'Diesen Sportler gibt es nicht (mehr). Bitte neu auswählen.';
	}

	/* ---- Distanz ---- */
	// Geschlossene Liste: alle zwölf Codes, inkl. 6h/12h/24h (Plan 7.2).
	$distanzen = array_keys( lsg_bl_distance_map() );
	if ( '' === $werte['distanz'] ) {
		$fehler['distanz'] = 'Bitte eine Distanz auswählen.';
	} elseif ( ! in_array( $werte['distanz'], $distanzen, true ) ) {
		$fehler['distanz'] = 'Diese Distanz gibt es nicht.';
	}

	/* ---- Datum ---- */
	if ( '' === $werte['datum'] ) {
		$fehler['datum'] = 'Bitte das Veranstaltungsdatum eintragen – nicht das Datum der Erfassung.';
	} elseif ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $werte['datum'], $m )
		|| ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
	) {
		$fehler['datum'] = 'Das Datum lässt sich nicht lesen. Erwartet wird TT.MM.JJJJ.';
	} else {
		$werte['jahr'] = (int) $m[1];

		if ( $jahr_max > 0 && $werte['jahr'] > $jahr_max ) {
			$fehler['datum'] = sprintf(
				'Der Lauf liegt in der Zukunft (%d). Bitte das Datum prüfen.',
				$werte['jahr']
			);
		} elseif ( $athlet && ! empty( $athlet['born'] ) && $werte['jahr'] < (int) $athlet['born'] ) {
			// Fängt den vertippten Jahrgang mit, und zwar an der Stelle, an
			// der er auffällt: eine AK lässt sich daraus nicht rechnen.
			$fehler['datum'] = sprintf(
				'Der Lauf (%1$d) läge vor dem Geburtsjahr des Sportlers (%2$d).',
				$werte['jahr'],
				(int) $athlet['born']
			);
		}
	}

	/* ---- Leistung ---- */
	if ( ! isset( $fehler['distanz'] ) ) {
		$l = lsg_bl_leistung_lesen( $werte['distanz'], isset( $eingabe['leistung'] ) ? $eingabe['leistung'] : '' );
		if ( '' !== $l['fehler'] ) {
			$fehler['leistung'] = $l['fehler'];
		}
		$werte['leistung'] = $l['wert'];
	}

	/* ---- Ort ---- */
	if ( '' === $werte['ort'] ) {
		$fehler['ort'] = 'Bitte den Ort eintragen.';
	} elseif ( lsg_bl_zeichen( $werte['ort'] ) > 30 ) {
		// Die Spalte ist varchar(30). Abschneiden wäre stiller Datenverlust
		// mitten in einem Ortsnamen.
		$fehler['ort'] = sprintf(
			'Der Ort ist %d Zeichen lang – in die Spalte passen 30.',
			lsg_bl_zeichen( $werte['ort'] )
		);
	}

	/* ---- Altersklasse: gerechnet, nicht eingegeben ---- */
	if ( $athlet && $werte['jahr'] > 0 ) {
		$werte['ak'] = lsg_bl_ak_berechnen(
			isset( $athlet['born'] ) ? $athlet['born'] : 0,
			$werte['jahr'],
			isset( $athlet['cat'] ) ? $athlet['cat'] : ''
		);
	}

	$werte['ersetzen'] = ! empty( $eingabe['ersetzen'] );

	return array(
		'ok'     => empty( $fehler ),
		'fehler' => $fehler,
		'werte'  => $werte,
	);
}

/**
 * Zeichenzahl, nicht Bytezahl.
 *
 * ⚠ `strlen( 'Bad Säckingen' )` ist 14, nicht 13 – das ß und die Umlaute
 * belegen zwei Bytes. Gegen eine `varchar(30)`-Spalte zählt MySQL Zeichen.
 *
 * @param string $s Text.
 * @return int
 */
function lsg_bl_zeichen( $s ) {
	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( (string) $s, 'UTF-8' );
	}
	return (int) strlen( (string) $s );
}

/**
 * Der Satz unter dem Datumsfeld: welche Altersklasse gespeichert wird.
 *
 * Angezeigt wird sie als Text, nicht als Feld (Plan 7.2). Änderbar wäre sie
 * nur um den Preis, dass `lsg_best.ak` und `lsg_best.athletes_id`
 * auseinanderlaufen.
 *
 * @param string $ak     Berechneter Code.
 * @param array  $athlet Zeile aus lsg_athlete.
 * @param int    $jahr   Veranstaltungsjahr.
 * @return string
 */
function lsg_bl_ak_satz( $ak, array $athlet, $jahr ) {
	if ( '' === (string) $ak ) {
		return '';
	}
	return sprintf(
		'Altersklasse: %1$s (Jahrgang %2$d, Lauf %3$d)',
		$ak,
		isset( $athlet['born'] ) ? (int) $athlet['born'] : 0,
		(int) $jahr
	);
}

/* -------------------------------------------------------------------------
 * Zuordnungsregeln (Plan 6.5.3) – die reine Prüfung
 * ---------------------------------------------------------------------- */

/**
 * Die Modi einer Zuordnungsregel, mit dem Satz, der sie erklärt.
 *
 * @return array<string,string>
 */
function lsg_bl_map_modi() {
	return array(
		'feld' => 'feldweise – Vorname gegen Vorname, Nachname gegen Nachname',
		'egal' => 'egal welches Feld – die Quelle hat Vor- und Nachname vertauscht',
	);
}

/**
 * Eine Regeleingabe prüfen.
 *
 * ⚠ **Eine Regel ohne Vor- UND Nachname wird abgelehnt** (Plan 6.5.3). Sie
 * würde jeden LSG-Läufer dieses Jahrgangs auf einen Athleten ziehen – und
 * zwar erst beim nächsten Import, weit weg von der Stelle, an der sie
 * entstand.
 *
 * @param array      $eingabe Rohwerte.
 * @param array|null $athlet  Zeile aus lsg_athlete, oder null.
 * @return array{ok:bool,fehler:array<string,string>,werte:array}
 */
function lsg_bl_map_pruefen( array $eingabe, $athlet ) {
	$fehler = array();

	$werte = array(
		'id'          => isset( $eingabe['id'] ) ? (int) $eingabe['id'] : 0,
		'athletes_id' => isset( $eingabe['athletes_id'] ) ? (int) $eingabe['athletes_id'] : 0,
		'born'        => isset( $eingabe['born'] ) ? (int) $eingabe['born'] : 0,
		'vorname'     => lsg_bl_text_normalisieren( isset( $eingabe['vorname'] ) ? $eingabe['vorname'] : '' ),
		'nachname'    => lsg_bl_text_normalisieren( isset( $eingabe['nachname'] ) ? $eingabe['nachname'] : '' ),
		'modus'       => isset( $eingabe['modus'] ) ? (string) $eingabe['modus'] : 'feld',
		'aktiv'       => empty( $eingabe['aktiv'] ) ? 0 : 1,
		'notiz'       => trim( (string) ( isset( $eingabe['notiz'] ) ? $eingabe['notiz'] : '' ) ),
	);

	if ( $werte['athletes_id'] <= 0 ) {
		$fehler['athletes_id'] = 'Bitte den Sportler auswählen, auf den die Regel zeigt.';
	} elseif ( ! $athlet ) {
		$fehler['athletes_id'] = 'Diesen Sportler gibt es nicht (mehr).';
	}

	if ( '' === $werte['vorname'] && '' === $werte['nachname'] ) {
		$fehler['nachname'] = 'Eine Regel braucht mindestens Vor- oder Nachnamen. Nur mit dem Jahrgang '
			. 'würde sie jeden LSG-Läufer dieses Jahrgangs auf diesen Sportler ziehen.';
	}

	if ( ! array_key_exists( $werte['modus'], lsg_bl_map_modi() ) ) {
		$fehler['modus'] = 'Diesen Modus gibt es nicht.';
	}

	// Der Jahrgang darf leer bleiben (dann gilt die Regel für jeden), muss
	// aber, wenn er dasteht, zum Athleten passen – sonst trifft die Regel nie.
	if ( $werte['born'] > 0 && $athlet && ! empty( $athlet['born'] )
		&& $werte['born'] !== (int) $athlet['born']
	) {
		$fehler['born'] = sprintf(
			'Der Jahrgang %1$d passt nicht zum Sportler (%2$d). Die Regel könnte nie greifen: '
			. 'verglichen wird gegen den Jahrgang aus der Ergebnisliste, und der müsste beides sein.',
			$werte['born'],
			(int) $athlet['born']
		);
	}

	if ( lsg_bl_zeichen( $werte['notiz'] ) > 255 ) {
		$fehler['notiz'] = 'Die Notiz ist zu lang – 255 Zeichen passen in die Spalte.';
	}

	return array(
		'ok'     => empty( $fehler ),
		'fehler' => $fehler,
		'werte'  => $werte,
	);
}

/**
 * Regeln finden, die sich gegenseitig ins Gehege kommen.
 *
 * ⚠ Zwei Regeln, die dieselbe Zeile treffen können, sind ein Fehler und keine
 * Auswahlfrage (Plan 6.5.3): beim Import bliebe die Zeile `offen`, und die
 * Meldung nennt beide IDs. Hier, in der Pflegeoberfläche, ist der Ort, an dem
 * man es SIEHT, bevor der nächste Import darüber stolpert.
 *
 * Zwei Regeln kollidieren, wenn sie auf verschiedene Athleten zeigen und es
 * eine denkbare Ergebniszeile gibt, die beide treffen.
 *
 * @param array $regeln Zeilen aus lsg_athlete_map.
 * @return array<int,int[]> Regel-ID => IDs der kollidierenden Regeln.
 */
function lsg_bl_map_kollisionen( array $regeln ) {
	$aktive = array();
	foreach ( $regeln as $r ) {
		if ( empty( $r['aktiv'] ) ) {
			continue;
		}
		$aktive[] = $r;
	}

	$out = array();

	$anzahl = count( $aktive );
	for ( $i = 0; $i < $anzahl; $i++ ) {
		for ( $j = $i + 1; $j < $anzahl; $j++ ) {
			$a = $aktive[ $i ];
			$b = $aktive[ $j ];

			// Zwei Regeln auf denselben Athleten sind harmlos: das Ergebnis
			// ist dasselbe, egal welche greift.
			if ( (int) $a['athletes_id'] === (int) $b['athletes_id'] ) {
				continue;
			}

			if ( ! lsg_bl_map_ueberschneidung( $a, $b ) ) {
				continue;
			}

			$ai = (int) $a['id'];
			$bi = (int) $b['id'];

			$out[ $ai ][] = $bi;
			$out[ $bi ][] = $ai;
		}
	}

	foreach ( $out as $id => $liste ) {
		$liste = array_values( array_unique( $liste ) );
		sort( $liste );
		$out[ $id ] = $liste;
	}
	ksort( $out );

	return $out;
}

/**
 * Können diese zwei Regeln dieselbe Ergebniszeile treffen?
 *
 * Ein leeres Feld heißt „beliebig" (Plan 6.5.3) und überschneidet sich mit
 * jedem Wert. Bei `modus = 'egal'` ist zusätzlich die vertauschte Belegung
 * möglich – die Regel trifft, wenn jedes ihrer nicht-leeren Token irgendwo
 * im Namen der Zeile vorkommt.
 *
 * @param array $a Erste Regel.
 * @param array $b Zweite Regel.
 * @return bool
 */
function lsg_bl_map_ueberschneidung( array $a, array $b ) {
	$jg_a = (int) $a['born'];
	$jg_b = (int) $b['born'];
	if ( $jg_a > 0 && $jg_b > 0 && $jg_a !== $jg_b ) {
		return false;
	}

	$egal = ( 'egal' === $a['modus'] || 'egal' === $b['modus'] );

	if ( $egal ) {
		// Bei 'egal' ist die Feldzuordnung offen. Kollidieren können die
		// beiden Regeln dann immer, sobald sich ihre Token-Mengen vertragen –
		// also wenn keine der beiden ein Token verlangt, das die andere an
		// derselben Stelle ausschließt. Weil 'egal' die Stelle gar nicht
		// festlegt, bleibt nur: haben sie überhaupt ein gemeinsames Token
		// oder ein leeres Feld?
		$ta = array_values( array_filter( array( $a['vorname'], $a['nachname'] ) ) );
		$tb = array_values( array_filter( array( $b['vorname'], $b['nachname'] ) ) );

		// Eine Zeile muss alle Token beider Regeln erfüllen. Bei zwei
		// Namensfeldern gehen höchstens zwei verschiedene Token auf.
		$zusammen = array_values( array_unique( array_merge( $ta, $tb ) ) );
		return count( $zusammen ) <= 2;
	}

	// modus 'feld': Feld für Feld vergleichen. Leer = beliebig.
	foreach ( array( 'vorname', 'nachname' ) as $feld ) {
		$va = (string) $a[ $feld ];
		$vb = (string) $b[ $feld ];
		if ( '' !== $va && '' !== $vb && $va !== $vb ) {
			return false;
		}
	}

	return true;
}
