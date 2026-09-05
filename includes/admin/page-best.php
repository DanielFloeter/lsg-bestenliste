<?php
/**
 * Admin-Seite „Bestenliste": Ergebnisse von Hand erfassen (Plan, Abschnitt 7).
 *
 * Vier Ansichten, unterschieden über `?action=`:
 *
 *   (keine)   Liste           7.4
 *   new       Formular, leer  7.2
 *   edit      Formular, gefüllt
 *   delete    Rückfrage vor dem Löschen
 *
 * ⚠ Das Risiko dieser Seite ist ein anderes als beim Import. Dort steht
 * zwischen Eingabe und Datenbank der ganze Trichter aus P1–P4 mit einer
 * Vorschau; hier schreibt ein Formular direkt in den Bestand. Was diese
 * Seite deshalb zwingend mitbringt (Plan 7.1):
 *
 *   - jeder Schreibvorgang wird protokolliert, in dieselben Tabellen wie der
 *     Import (7.5)
 *   - kein Löschen ohne Rückfrage, und der gelöschte Datensatz steht
 *     vollständig im Log (7.4)
 *   - Capability, Nonce und check_admin_referer() in JEDEM Handler
 *
 * ⚠ Und: die Jahresbestzeit-Prüfung läuft zweimal. Einmal für die Anzeige,
 * und noch einmal unmittelbar vor dem Schreiben – sonst entscheidet eine
 * Vorschau, die zwei Minuten alt sein kann, über einen Schreibvorgang.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Adressen
 * ---------------------------------------------------------------------- */

/**
 * Eine Adresse dieser Seite bauen.
 *
 * @param array $args Query-Argumente.
 * @return string
 */
function lsg_bl_best_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v && '0' !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste-best';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Die Formularwerte, die in der Query weitergegeben werden.
 *
 * ⚠ Der Zustand des Formulars steht in der Query, nicht in einer Session –
 * derselbe Grund wie beim Import (6.9): ein Reload wiederholt dann nichts,
 * und die halbausgefüllte Eingabe ist verlinkbar.
 *
 * @param array $w Werte.
 * @return array
 */
function lsg_bl_best_query_werte( array $w ) {
	return array(
		'id'       => isset( $w['id'] ) ? (int) $w['id'] : 0,
		'athlet'   => isset( $w['athlet'] ) ? (int) $w['athlet'] : 0,
		'datum'    => isset( $w['datum'] ) ? (string) $w['datum'] : '',
		'distanz'  => isset( $w['distanz'] ) ? (string) $w['distanz'] : '',
		'leistung' => isset( $w['leistung'] ) ? (string) $w['leistung'] : '',
		'ort'      => isset( $w['ort'] ) ? (string) $w['ort'] : '',
		'ersetzen' => empty( $w['ersetzen'] ) ? '' : '1',
	);
}

/* -------------------------------------------------------------------------
 * Handler
 * ---------------------------------------------------------------------- */

/**
 * Speichern – „Prüfen" und „Speichern" laufen durch denselben Handler.
 *
 * @return void
 */
function lsg_bl_admin_best_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_best' );

	$schritt = isset( $_POST['schritt'] ) ? sanitize_key( wp_unslash( $_POST['schritt'] ) ) : 'pruefen';

	$eingabe = array(
		'id'       => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
		'athlet'   => isset( $_POST['athlet'] ) ? (int) $_POST['athlet'] : 0,
		'datum'    => lsg_bl_datum_eingabe_lesen( isset( $_POST['datum'] ) ? wp_unslash( $_POST['datum'] ) : '' ),
		'distanz'  => isset( $_POST['distanz'] ) ? sanitize_text_field( wp_unslash( $_POST['distanz'] ) ) : '',
		'leistung' => isset( $_POST['leistung'] ) ? sanitize_text_field( wp_unslash( $_POST['leistung'] ) ) : '',
		'ort'      => isset( $_POST['ort'] ) ? sanitize_text_field( wp_unslash( $_POST['ort'] ) ) : '',
		'ersetzen' => ! empty( $_POST['ersetzen'] ),
	);

	// ⚠ Die Rohleistung wandert unverändert in die Query zurück. Wer
	// „96,7 km" getippt hat, soll seine Eingabe im Feld wiederfinden und
	// nicht ein leeres Feld mit einer Fehlermeldung daneben.
	$ziel = array_merge(
		array( 'action' => $eingabe['id'] > 0 ? 'edit' : 'new' ),
		lsg_bl_best_query_werte( $eingabe )
	);

	if ( 'speichern' !== $schritt ) {
		// Nur prüfen: zurück ins Formular, die Anzeige rechnet neu.
		wp_safe_redirect( lsg_bl_best_url( $ziel ) );
		exit;
	}

	$athlet = $eingabe['athlet'] > 0 ? lsg_bl_athlet( $eingabe['athlet'] ) : null;
	$jahr_max = (int) gmdate( 'Y', time() ) + 1;
	$p        = lsg_bl_best_formular_pruefen( $eingabe, $athlet, $jahr_max );

	if ( ! $p['ok'] ) {
		// Die Feldfehler rechnet das Formular selbst noch einmal aus – hier
		// nur die Meldung obendrüber, damit klar ist, dass nichts gespeichert
		// wurde.
		lsg_bl_admin_notice_setzen(
			'error',
			__( 'Nichts gespeichert – bitte die markierten Felder ansehen.', 'lsg-bestenliste' )
		);
		wp_safe_redirect( lsg_bl_best_url( $ziel ) );
		exit;
	}

	$ergebnis = lsg_bl_best_speichern( $p['werte'], $athlet );

	lsg_bl_admin_notice_setzen( $ergebnis['typ'], $ergebnis['text'] );

	if ( 'success' === $ergebnis['typ'] ) {
		// Nach dem Speichern in die Liste, gefiltert auf diesen Athleten:
		// wer eine Zeile nachträgt, trägt meist mehrere nach.
		wp_safe_redirect(
			lsg_bl_best_url(
				array(
					'athlet' => (int) $p['werte']['athlet'],
					'jahr'   => (int) $p['werte']['jahr'],
				)
			)
		);
		exit;
	}

	wp_safe_redirect( lsg_bl_best_url( $ziel ) );
	exit;
}

/**
 * Der Schreibvorgang selbst – und das Log dazu.
 *
 * ⚠ Die Prüfung aus 7.3 läuft hier NOCH EINMAL, gegen den Bestand von
 * jetzt. Die Anzeige im Formular kann alt sein; entschieden wird auf dem
 * Stand, auf dem geschrieben wird.
 *
 * @param array      $w      Geprüfte Werte.
 * @param array|null $athlet Zeile aus lsg_athlete.
 * @return array{typ:string,text:string,best_id:int}
 */
function lsg_bl_best_speichern( array $w, $athlet ) {
	$datum_ts = lsg_bl_datum_zu_timestamp( $w['datum'] );

	$bestand  = lsg_bl_best_zeilen( $w['athlet'], $w['distanz'], $w['jahr'] );
	$pruefung = lsg_bl_best_pruefung( $w['distanz'], $w['leistung'], $bestand, (int) $w['id'] );
	$aktion   = lsg_bl_best_aktion( $pruefung, ! empty( $w['ersetzen'] ) );

	$roh_zeit = isset( $w['leistung_roh'] ) ? (string) $w['leistung_roh'] : '';

	/* ---- Bearbeiten einer bestehenden Zeile ---- */
	if ( (int) $w['id'] > 0 ) {
		// ⚠ Beim Bearbeiten entscheidet die Prüfung nur dann über den
		// Schreibvorgang, wenn sie eine FREMDE Zeile gefunden hat: die
		// bearbeitete Zeile ist in $bestand herausgefiltert. Steht dort
		// jemand anderes, wäre das Speichern die Hintertür zur Doppelzeile,
		// die das Anlegen verhindert (Plan 7.4).
		if ( 'nichts' === $aktion['aktion'] ) {
			return array(
				'typ'     => 'warning',
				'text'    => $aktion['grund'],
				'best_id' => (int) $w['id'],
			);
		}

		if ( 'update' === $aktion['aktion'] && (int) $aktion['best_id'] !== (int) $w['id'] ) {
			return array(
				'typ'     => 'warning',
				'text'    => sprintf(
					/* translators: 1: id, 2: Leistung */
					__( 'Für diesen Sportler, diese Distanz und dieses Jahr gibt es schon Zeile #%1$d (%2$s). Zwei Zeilen darf es nicht geben – bitte dort korrigieren oder diese Zeile löschen.', 'lsg-bestenliste' ),
					(int) $aktion['best_id'],
					$pruefung['time_alt']
				),
				'best_id' => (int) $w['id'],
			);
		}

		$alt = lsg_bl_best_zeile( (int) $w['id'] );
		if ( ! $alt ) {
			return array(
				'typ'     => 'error',
				'text'    => __( 'Diese Zeile gibt es nicht mehr – sie wurde zwischenzeitlich gelöscht.', 'lsg-bestenliste' ),
				'best_id' => 0,
			);
		}

		// ⚠ Wenn sich nichts geändert hat, wird nichts geschrieben. Ein
		// Update, das denselben Wert schreibt, stünde als Änderung im Log und
		// wäre keine – dieselbe Überlegung wie bei der Lage `gleich`.
		$diff = lsg_bl_best_diff(
			$alt,
			array(
				'athlet'   => $w['athlet'],
				'distanz'  => $w['distanz'],
				'leistung' => $w['leistung'],
				'ort'      => $w['ort'],
				'datum_ts' => $datum_ts,
			)
		);

		if ( ! $diff ) {
			return array(
				'typ'     => 'info',
				'text'    => __( 'Nichts geändert – die Zeile steht schon genau so da.', 'lsg-bestenliste' ),
				'best_id' => (int) $w['id'],
			);
		}

		$res = lsg_bl_best_aendern(
			(int) $w['id'],
			array(
				'distanz'     => $w['distanz'],
				'leistung'    => $w['leistung'],
				'ort'         => $w['ort'],
				'datum_ts'    => $datum_ts,
				'athletes_id' => $w['athlet'],
				'ak'          => $w['ak'],
			)
		);

		if ( ! $res['ok'] ) {
			return array(
				'typ'     => 'error',
				'text'    => $res['fehler'],
				'best_id' => (int) $w['id'],
			);
		}

		$was = lsg_bl_best_diff_text( $diff );

		lsg_bl_best_protokollieren(
			$w,
			$athlet,
			'update',
			(int) $w['id'],
			(string) $alt['time'],
			$roh_zeit,
			$pruefung,
			sprintf( 'von Hand geändert: %s', $was )
		);

		return array(
			'typ'     => 'success',
			'text'    => sprintf(
				/* translators: %s: Liste der geänderten Felder */
				__( 'Zeile geändert: %s', 'lsg-bestenliste' ),
				$was
			),
			'best_id' => (int) $w['id'],
		);
	}

	/* ---- Neu ---- */
	if ( 'nichts' === $aktion['aktion'] ) {
		return array(
			'typ'     => 'warning',
			'text'    => $aktion['grund'],
			'best_id' => 0,
		);
	}

	if ( 'update' === $aktion['aktion'] ) {
		$res = lsg_bl_best_aendern(
			(int) $aktion['best_id'],
			array(
				'distanz'     => $w['distanz'],
				'leistung'    => $w['leistung'],
				'ort'         => $w['ort'],
				'datum_ts'    => $datum_ts,
				'athletes_id' => $w['athlet'],
				'ak'          => $w['ak'],
			)
		);

		if ( ! $res['ok'] ) {
			return array(
				'typ'     => 'error',
				'text'    => $res['fehler'],
				'best_id' => 0,
			);
		}

		lsg_bl_best_protokollieren(
			$w,
			$athlet,
			'update',
			(int) $aktion['best_id'],
			(string) $pruefung['time_alt'],
			$roh_zeit,
			$pruefung
		);

		return array(
			'typ'     => 'success',
			'text'    => sprintf(
				/* translators: 1: alte Leistung, 2: neue Leistung */
				__( 'Bestand überschrieben (%1$s → %2$s).', 'lsg-bestenliste' ),
				(string) $pruefung['time_alt'],
				$w['leistung']
			),
			'best_id' => (int) $aktion['best_id'],
		);
	}

	$res = lsg_bl_best_anlegen(
		array(
			'distanz'     => $w['distanz'],
			'leistung'    => $w['leistung'],
			'ort'         => $w['ort'],
			'datum_ts'    => $datum_ts,
			'athletes_id' => $w['athlet'],
			'ak'          => $w['ak'],
		)
	);

	if ( ! $res['ok'] ) {
		return array(
			'typ'     => 'error',
			'text'    => $res['fehler'],
			'best_id' => 0,
		);
	}

	lsg_bl_best_protokollieren( $w, $athlet, 'insert', $res['id'], '', $roh_zeit, $pruefung );

	return array(
		'typ'     => 'success',
		'text'    => sprintf(
			/* translators: %s: Leistung */
			__( 'Ergebnis angelegt (%s).', 'lsg-bestenliste' ),
			$w['leistung']
		),
		'best_id' => $res['id'],
	);
}

/**
 * Einen Formularvorgang ins Log schreiben (Plan 7.5).
 *
 * ⚠ Dieselben Tabellen wie der Import, nicht eine dritte. Ein Log, das nur
 * die importierte Hälfte des Bestands kennt, beantwortet die Frage „warum
 * steht bei X diese Zeit" in genau den Fällen nicht, in denen jemand von Hand
 * eingegriffen hat.
 *
 * @param array      $w        Geprüfte Werte.
 * @param array|null $athlet   Zeile aus lsg_athlete.
 * @param string     $aktion   insert | update | delete.
 * @param int        $best_id  Betroffene Zeile.
 * @param string     $time_alt Überschriebene bzw. gelöschte Leistung.
 * @param string     $roh_zeit Eingabe vor der Normalisierung.
 * @param array      $pruefung Ergebnis von lsg_bl_best_pruefung().
 * @param string     $meldung  Klartext; leer = aus der Aktion gebildet.
 * @return int run_id.
 */
function lsg_bl_best_protokollieren( array $w, $athlet, $aktion, $best_id, $time_alt = '', $roh_zeit = '', array $pruefung = array(), $meldung = '' ) {
	$zeile = array(
		'id'          => (int) $best_id,
		'athletes_id' => (int) $w['athlet'],
		'name'        => $athlet ? (string) $athlet['name'] : '',
		'firstname'   => $athlet ? (string) $athlet['firstname'] : '',
		'born'        => $athlet ? (int) $athlet['born'] : 0,
		'time'        => (string) $w['leistung'],
		'town'        => (string) $w['ort'],
		'ak'          => (string) $w['ak'],
	);

	if ( '' === (string) $meldung ) {
		if ( 'update' === $aktion && '' !== $time_alt ) {
			$meldung = sprintf( 'von Hand geändert (%s → %s)', $time_alt, $w['leistung'] );
		} elseif ( 'insert' === $aktion ) {
			$meldung = 'von Hand angelegt';
		} elseif ( 'delete' === $aktion ) {
			$meldung = 'von Hand gelöscht';
		}
	}

	$bilanz = array(
		'angelegt'     => ( 'insert' === $aktion ) ? 1 : 0,
		'aktualisiert' => ( 'update' === $aktion ) ? 1 : 0,
		'geloescht'    => ( 'delete' === $aktion ) ? 1 : 0,
	);

	return lsg_bl_log_manuell(
		array(
			'datum'   => (string) $w['datum'],
			'jahr'    => (int) $w['jahr'],
			'distanz' => (string) $w['distanz'],
			'ort'     => (string) $w['ort'],
			'doppelt' => isset( $pruefung['doppelt'] ) ? $pruefung['doppelt'] : array(),
		),
		$bilanz,
		array( lsg_bl_log_manuell_zeile( $zeile, $aktion, $time_alt, $meldung, (int) $best_id, $roh_zeit ) )
	);
}

/**
 * Löschen – erst nach der Rückfrage, und nur per POST.
 *
 * @return void
 */
function lsg_bl_admin_best_loeschen_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_best_loeschen' );

	$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$row = lsg_bl_best_zeile( $id );

	if ( ! $row ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Diese Zeile gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_best_url() );
		exit;
	}

	// ⚠ ZUERST protokollieren, dann löschen (Plan 7.4): Löschen ist die
	// einzige Aktion ohne Wiederherstellung in der Oberfläche, und der
	// vollständige Datensatz ist danach nur noch im Log. Andersherum – erst
	// löschen, dann protokollieren – ginge der Datensatz verloren, sobald
	// zwischen beiden Schritten etwas schiefgeht.
	$jahr = lsg_bl_year_from_timestamp( (int) $row['date'] );
	lsg_bl_best_protokollieren(
		array(
			'athlet'   => (int) $row['athletes_id'],
			'datum'    => lsg_bl_format_date_iso( (int) $row['date'] ),
			'jahr'     => (int) $jahr,
			'distanz'  => (string) $row['distance'],
			'leistung' => (string) $row['time'],
			'ort'      => (string) $row['town'],
			'ak'       => (string) $row['ak'],
		),
		$row,
		'delete',
		$id,
		(string) $row['time'],
		(string) $row['time']
	);

	$res = lsg_bl_best_loeschen( $id );

	if ( ! $res['ok'] ) {
		lsg_bl_admin_notice_setzen( 'error', $res['fehler'] );
	} else {
		lsg_bl_admin_notice_setzen(
			'success',
			sprintf(
				/* translators: 1: Athlet, 2: Distanz, 3: Leistung */
				__( 'Gelöscht: %1$s, %2$s, %3$s. Der vollständige Datensatz steht im Log.', 'lsg-bestenliste' ),
				lsg_bl_athlete_display_name( $row['name'], $row['firstname'] ),
				lsg_bl_distance_label( $row['distance'] ),
				$row['time']
			)
		);
	}

	wp_safe_redirect( lsg_bl_best_url( array( 'athlet' => (int) $row['athletes_id'] ) ) );
	exit;
}

/* -------------------------------------------------------------------------
 * Die Seite
 * ---------------------------------------------------------------------- */

/**
 * Render-Callback.
 *
 * @return void
 */
function lsg_bl_admin_best_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	echo '<div class="wrap lsg-bl-best" id="lsg-bl-best">';

	$notice = lsg_bl_admin_notice_holen();
	if ( $notice ) {
		lsg_bl_admin_notice( $notice['typ'], $notice['text'] );
	}

	if ( 'new' === $action || 'edit' === $action ) {
		lsg_bl_best_formular_anzeigen( $action );
	} elseif ( 'delete' === $action ) {
		lsg_bl_best_loeschen_rueckfrage();
	} else {
		lsg_bl_best_liste_anzeigen();
	}

	echo '</div>';
}

/**
 * Die Liste (Plan 7.4).
 *
 * @return void
 */
function lsg_bl_best_liste_anzeigen() {
	$filter = lsg_bl_best_filter(
		array(
			'jahr'       => isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0,
			'distanz'    => isset( $_GET['distanz'] ) ? sanitize_text_field( wp_unslash( $_GET['distanz'] ) ) : '',
			'geschlecht' => isset( $_GET['geschlecht'] ) ? sanitize_text_field( wp_unslash( $_GET['geschlecht'] ) ) : '',
			's'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'athlet'     => isset( $_GET['athlet'] ) ? (int) $_GET['athlet'] : 0,
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'      => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
		)
	);

	printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Bestenliste', 'lsg-bestenliste' ) );
	printf(
		' <a href="%1$s" class="page-title-action">%2$s</a>',
		esc_url( lsg_bl_best_url( array( 'action' => 'new' ) ) ),
		esc_html__( 'Ergebnis erfassen', 'lsg-bestenliste' )
	);
	echo '<hr class="wp-header-end">';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Für Zeitläufe, Läufe ohne parsebare Ergebnisliste und Korrekturen. Was ein Portal als Liste liefert, geht schneller über den Ergebnis-Import.',
			'lsg-bestenliste'
		)
	);

	// ⚠ Erst hier laden, nicht beim Laden des Plugins: `WP_List_Table` ist zu
	// dem Zeitpunkt noch nicht deklariert (siehe Kopf der Datei).
	require_once LSG_BL_PATH . 'includes/admin/class-lsg-best-table.php';

	$tabelle = new LSG_BL_Best_Table( $filter );
	$tabelle->prepare_items();

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-best' ) );
	lsg_bl_best_filterleiste( $filter );
	$tabelle->search_box( __( 'Sportler suchen', 'lsg-bestenliste' ), 'lsg-bl-best-suche' );
	echo '</form>';

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-best' ) );
	foreach ( array( 'jahr', 'distanz', 'geschlecht', 's', 'athlet', 'orderby', 'order' ) as $k ) {
		if ( '' !== (string) $filter[ $k ] && 0 !== $filter[ $k ] ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $k ), esc_attr( $filter[ $k ] ) );
		}
	}
	$tabelle->display();
	echo '</form>';
}

/**
 * Die Filterleiste über der Liste.
 *
 * @param array $filter Aktive Filter.
 * @return void
 */
function lsg_bl_best_filterleiste( array $filter ) {
	echo '<div class="lsg-bl-bestfilter">';

	/* Jahr */
	echo '<label>' . esc_html__( 'Jahr', 'lsg-bestenliste' ) . ' ';
	echo '<select name="jahr">';
	printf( '<option value="">%s</option>', esc_html__( 'alle', 'lsg-bestenliste' ) );
	foreach ( lsg_bl_best_jahre() as $j ) {
		printf(
			'<option value="%1$d"%2$s>%1$d</option>',
			(int) $j,
			selected( $filter['jahr'], (int) $j, false )
		);
	}
	echo '</select></label> ';

	/* Distanz – in der Reihenfolge der Map, nicht alphabetisch. */
	echo '<label>' . esc_html__( 'Distanz', 'lsg-bestenliste' ) . ' ';
	echo '<select name="distanz">';
	printf( '<option value="">%s</option>', esc_html__( 'alle', 'lsg-bestenliste' ) );
	foreach ( lsg_bl_distance_map() as $code => $info ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $code ),
			selected( $filter['distanz'], $code, false ),
			esc_html( $info['label'] )
		);
	}
	echo '</select></label> ';

	/* Geschlecht */
	echo '<label>' . esc_html__( 'Geschlecht', 'lsg-bestenliste' ) . ' ';
	echo '<select name="geschlecht">';
	printf( '<option value="">%s</option>', esc_html__( 'alle', 'lsg-bestenliste' ) );
	printf( '<option value="m"%s>%s</option>', selected( $filter['geschlecht'], 'm', false ), esc_html__( 'männlich', 'lsg-bestenliste' ) );
	printf( '<option value="f"%s>%s</option>', selected( $filter['geschlecht'], 'f', false ), esc_html__( 'weiblich', 'lsg-bestenliste' ) );
	echo '</select></label> ';

	printf( '<button class="button">%s</button>', esc_html__( 'Filtern', 'lsg-bestenliste' ) );

	if ( $filter['athlet'] > 0 ) {
		$a = lsg_bl_athlet( $filter['athlet'] );
		if ( $a ) {
			printf(
				' <span class="lsg-bl-aktivfilter">%1$s <a href="%2$s">%3$s</a></span>',
				esc_html( sprintf( 'nur %s', lsg_bl_athlet_label( $a ) ) ),
				esc_url( lsg_bl_best_url( array( 'jahr' => $filter['jahr'], 'distanz' => $filter['distanz'] ) ) ),
				esc_html__( 'Filter aufheben', 'lsg-bestenliste' )
			);
		}
	}

	echo '</div>';
}

/**
 * Die Rückfrage vor dem Löschen (Plan 7.4).
 *
 * ⚠ Zwei Schritte, und der zweite ist ein POST. Ein Löschlink, den ein
 * Crawler oder ein Prefetch anfassen kann, ist keiner – und ein
 * JavaScript-`confirm()` wäre auf einer Seite, die ohne JavaScript
 * funktionieren soll, keine Rückfrage, sondern eine Hoffnung.
 *
 * @return void
 */
function lsg_bl_best_loeschen_rueckfrage() {
	$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$row = $id > 0 ? lsg_bl_best_zeile( $id ) : null;

	printf( '<h1>%s</h1>', esc_html__( 'Ergebnis löschen', 'lsg-bestenliste' ) );

	if ( ! $row ) {
		lsg_bl_admin_notice( 'error', __( 'Diese Zeile gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( lsg_bl_best_url() ),
			esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
		);
		return;
	}

	echo '<div class="lsg-bl-loeschen">';
	printf( '<p>%s</p>', esc_html__( 'Diese Zeile wird endgültig aus der Bestenliste entfernt:', 'lsg-bestenliste' ) );

	echo '<table class="widefat striped" style="max-width:40em"><tbody>';
	$felder = array(
		__( 'Sportler', 'lsg-bestenliste' )   => lsg_bl_athlete_display_name( $row['name'], $row['firstname'] )
			. ( $row['born'] ? ' (' . (int) $row['born'] . ')' : '' ),
		__( 'Distanz', 'lsg-bestenliste' )    => lsg_bl_distance_label( $row['distance'] ),
		__( 'Leistung', 'lsg-bestenliste' )   => $row['time'],
		__( 'Ort', 'lsg-bestenliste' )        => $row['town'],
		__( 'Datum', 'lsg-bestenliste' )      => lsg_bl_format_date( (int) $row['date'] ),
		__( 'Altersklasse', 'lsg-bestenliste' ) => $row['ak'],
	);
	foreach ( $felder as $label => $wert ) {
		printf(
			'<tr><th scope="row" style="width:10em">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( (string) $wert )
		);
	}
	echo '</tbody></table>';

	printf(
		'<p class="lsg-bl-loeschen-hinweis">%s</p>',
		esc_html__(
			'Rückgängig geht das hier nicht. Der vollständige Datensatz wird ins Log geschrieben – von dort lässt er sich neu eintippen.',
			'lsg-bestenliste'
		)
	);

	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_best_loeschen' );
	echo '<input type="hidden" name="action" value="lsg_bl_best_loeschen">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $id );
	printf(
		'<button class="button button-primary">%s</button> <a class="button" href="%s">%s</a>',
		esc_html__( 'Endgültig löschen', 'lsg-bestenliste' ),
		esc_url( lsg_bl_best_url() ),
		esc_html__( 'Abbrechen', 'lsg-bestenliste' )
	);
	echo '</form>';
	echo '</div>';
}

/**
 * Das Formular (Plan 7.2, 7.3).
 *
 * @param string $action new | edit.
 * @return void
 */
function lsg_bl_best_formular_anzeigen( $action ) {
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

	/* ---- Werte: aus der Query, sonst aus der Zeile ---- */
	$w = lsg_bl_best_felder_leer();

	$zeile = null;
	if ( 'edit' === $action && $id > 0 ) {
		$zeile = lsg_bl_best_zeile( $id );
		if ( ! $zeile ) {
			printf( '<h1>%s</h1>', esc_html__( 'Ergebnis bearbeiten', 'lsg-bestenliste' ) );
			lsg_bl_admin_notice( 'error', __( 'Diese Zeile gibt es nicht (mehr).', 'lsg-bestenliste' ) );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( lsg_bl_best_url() ),
				esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
			);
			return;
		}

		$w['id']       = $id;
		$w['athlet']   = (int) $zeile['athletes_id'];
		$w['datum']    = lsg_bl_format_date_iso( (int) $zeile['date'] );
		$w['distanz']  = (string) $zeile['distance'];
		$w['leistung'] = (string) $zeile['time'];
		$w['ort']      = (string) $zeile['town'];
	}

	// ⚠ Die Query hat Vorrang: nach „Prüfen" steht dort der Zwischenstand.
	// Der Unterschied zwischen „noch nicht angefasst" und „bewusst geleert"
	// steht am Vorhandensein des Parameters, nicht an seinem Wert – derselbe
	// Kniff wie bei der Distanz-Vorbelegung des Imports (6.5.1).
	foreach ( array( 'athlet', 'datum', 'distanz', 'leistung', 'ort' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) {
			$roh      = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
			$w[ $k ]  = ( 'athlet' === $k ) ? (int) $roh : $roh;
		}
	}
	if ( isset( $_GET['id'] ) ) {
		$w['id'] = $id;
	}
	$w['ersetzen'] = ! empty( $_GET['ersetzen'] );

	$athlet = $w['athlet'] > 0 ? lsg_bl_athlet( $w['athlet'] ) : null;

	/* ---- Prüfen, aber nur was ausgefüllt ist ---- */
	$angefasst = false;
	foreach ( array( 'athlet', 'datum', 'distanz', 'leistung', 'ort' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) {
			$angefasst = true;
		}
	}

	$jahr_max = (int) gmdate( 'Y', time() ) + 1;
	$p        = lsg_bl_best_formular_pruefen( $w, $athlet, $jahr_max );
	$fehler   = ( $angefasst || null !== $zeile ) ? $p['fehler'] : array();
	$werte    = $p['werte'];

	/* ---- Jahresbestzeit-Prüfung, sobald es reicht ---- */
	$pruefung = null;
	if ( $athlet && '' !== $werte['distanz'] && $werte['jahr'] > 0 && '' !== $werte['leistung'] ) {
		$bestand  = lsg_bl_best_zeilen( $werte['athlet'], $werte['distanz'], $werte['jahr'] );
		$pruefung = lsg_bl_best_pruefung( $werte['distanz'], $werte['leistung'], $bestand, (int) $werte['id'] );
	}

	$feld = lsg_bl_leistung_feld( $werte['distanz'] );

	/* ---- Ausgabe ---- */
	printf(
		'<h1>%s</h1>',
		esc_html(
			( 'edit' === $action )
				? __( 'Ergebnis bearbeiten', 'lsg-bestenliste' )
				: __( 'Ergebnis erfassen', 'lsg-bestenliste' )
		)
	);

	printf(
		'<p><a href="%1$s">&larr; %2$s</a></p>',
		esc_url( lsg_bl_best_url() ),
		esc_html__( 'zur Liste', 'lsg-bestenliste' )
	);

	printf( '<form method="post" action="%s" class="lsg-bl-bestform">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_best' );
	echo '<input type="hidden" name="action" value="lsg_bl_best_speichern">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $werte['id'] );

	echo '<table class="form-table" role="presentation"><tbody>';

	/* Sportler */
	lsg_bl_formularzeile(
		__( 'Sportler', 'lsg-bestenliste' ),
		'lsg-bl-athlet',
		isset( $fehler['athlet'] ) ? $fehler['athlet'] : '',
		function () use ( $werte ) {
			lsg_bl_athleten_select( (int) $werte['athlet'] );
			printf(
				'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Fehlt jemand, wird er nicht hier angelegt – Sportler werden getrennt gepflegt.', 'lsg-bestenliste' ),
				esc_url( lsg_bl_athlet_url( array( 'action' => 'new' ) ) ),
				esc_html__( 'Sportler anlegen', 'lsg-bestenliste' )
			);
		}
	);

	/* Distanz */
	lsg_bl_formularzeile(
		__( 'Distanz', 'lsg-bestenliste' ),
		'lsg-bl-distanz',
		isset( $fehler['distanz'] ) ? $fehler['distanz'] : '',
		function () use ( $werte ) {
			echo '<select name="distanz" id="lsg-bl-distanz">';
			printf( '<option value="">%s</option>', esc_html__( '— bitte wählen —', 'lsg-bestenliste' ) );
			foreach ( lsg_bl_distance_map() as $code => $info ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $code ),
					selected( $werte['distanz'], $code, false ),
					esc_html( $info['label'] )
				);
			}
			echo '</select>';
			printf(
				'<p class="description">%s</p>',
				esc_html__(
					'Auch 6, 12 und 24 Stunden – dort hält die Bestenliste eine Strecke statt einer Zeit.',
					'lsg-bestenliste'
				)
			);
			printf(
				'<p class="description lsg-bl-nur-ohne-js">%s</p>',
				esc_html__(
					'Nach dem Wechsel der Distanz einmal „Prüfen", dann passt das Feld darunter.',
					'lsg-bestenliste'
				)
			);
		}
	);

	/* Datum */
	lsg_bl_formularzeile(
		__( 'Veranstaltungsdatum', 'lsg-bestenliste' ),
		'lsg-bl-datum',
		isset( $fehler['datum'] ) ? $fehler['datum'] : '',
		function () use ( $werte, $athlet ) {
			printf(
				'<input type="date" name="datum" id="lsg-bl-datum" value="%s" class="regular-text">',
				esc_attr( $werte['datum'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__(
					'Das Datum des Laufs, nicht das der Erfassung – es bestimmt das Jahr, gegen das verglichen wird. Ohne Datepicker: TT.MM.JJJJ eintippen.',
					'lsg-bestenliste'
				)
			);

			// ⚠ Die Altersklasse ist Anzeige, kein Feld (Plan 7.2).
			// Änderbar wäre sie nur um den Preis, dass lsg_best.ak und
			// lsg_best.athletes_id auseinanderlaufen.
			if ( $athlet && '' !== (string) $werte['ak'] ) {
				printf(
					'<p class="lsg-bl-akzeile"><strong>%s</strong></p>',
					esc_html( lsg_bl_ak_satz( $werte['ak'], $athlet, $werte['jahr'] ) )
				);

				// lsg_ak ist eine Anzeigeliste, keine Prüfinstanz: der Code
				// wird trotzdem geschrieben (Plan 6.5.3).
				$codes = lsg_bl_ak_codes();
				if ( $codes && ! in_array( strtolower( $werte['ak'] ), $codes, true ) ) {
					printf(
						'<p class="lsg-bl-akfehlt">%s</p>',
						esc_html(
							sprintf(
								/* translators: %s: AK-Code */
								__( 'Die Altersklasse %s fehlt in lsg_ak – gespeichert wird sie trotzdem, aber bis sie ergänzt ist, lässt sich im Frontend nicht danach filtern.', 'lsg-bestenliste' ),
								$werte['ak']
							)
						)
					);
				}
			}
		}
	);

	/* Leistung */
	lsg_bl_formularzeile(
		$feld['label'],
		'lsg-bl-leistung',
		isset( $fehler['leistung'] ) ? $fehler['leistung'] : '',
		function () use ( $werte, $feld ) {
			$roh = isset( $_GET['leistung'] ) ? sanitize_text_field( wp_unslash( $_GET['leistung'] ) ) : $werte['leistung'];
			printf(
				'<input type="text" name="leistung" id="lsg-bl-leistung" value="%1$s" placeholder="%2$s" pattern="%3$s" class="regular-text">',
				esc_attr( $roh ),
				esc_attr( $feld['platzhalter'] ),
				esc_attr( $feld['pattern'] )
			);
			printf( '<p class="description" id="lsg-bl-leistung-hinweis">%s</p>', esc_html( $feld['hinweis'] ) );
		}
	);

	/* Ort */
	lsg_bl_formularzeile(
		__( 'Ort', 'lsg-bestenliste' ),
		'lsg-bl-ort',
		isset( $fehler['ort'] ) ? $fehler['ort'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="ort" id="lsg-bl-ort" value="%s" maxlength="30" class="regular-text">',
				esc_attr( $werte['ort'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Höchstens 30 Zeichen – so lang ist die Spalte in der Bestenliste.', 'lsg-bestenliste' )
			);
		}
	);

	echo '</tbody></table>';

	/* ---- Der Vergleich ---- */
	if ( $pruefung ) {
		lsg_bl_best_vergleich_anzeigen( $pruefung, $werte, $feld );
	}

	/* ---- Knöpfe ---- */
	$speichern_aus = ( $pruefung && 'gleich' === $pruefung['lage'] );

	echo '<p class="submit">';
	printf(
		'<button type="submit" name="schritt" value="pruefen" class="button">%s</button> ',
		esc_html__( 'Prüfen', 'lsg-bestenliste' )
	);
	printf(
		'<button type="submit" name="schritt" value="speichern" class="button button-primary"%1$s>%2$s</button>',
		$speichern_aus ? ' disabled="disabled"' : '',
		esc_html__( 'Speichern', 'lsg-bestenliste' )
	);
	echo '</p>';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'„Prüfen" schreibt nichts. Es rechnet die Altersklasse, prüft die Eingaben und zeigt, was schon in der Bestenliste steht.',
			'lsg-bestenliste'
		)
	);

	echo '</form>';

	/* ---- Löschen, aber nicht neben „Speichern" ---- */
	if ( 'edit' === $action && $werte['id'] > 0 ) {
		printf(
			'<p class="lsg-bl-loeschlink"><a href="%1$s">%2$s</a></p>',
			esc_url( lsg_bl_best_url( array( 'action' => 'delete', 'id' => (int) $werte['id'] ) ) ),
			esc_html__( 'Dieses Ergebnis löschen', 'lsg-bestenliste' )
		);
	}
}

/**
 * Eine Zeile der form-table, mit Fehlermeldung am Feld.
 *
 * ⚠ Die Fehlermeldung steht AM Feld, nicht nur oben in einer Notice. Bei
 * sechs Feldern ist „bitte Eingaben prüfen" keine Hilfe.
 *
 * ⚠ Der Name sagt nicht „best": die Funktion ist nicht an diese Seite
 * gebunden, page-athlet.php benutzt dieselbe (11.2). Sie steht hier, weil hier
 * die ältere der beiden Seiten liegt – eine dritte Datei nur für eine
 * Tabellenzeile wäre Buchhaltung, keine Struktur.
 *
 * @param string   $label   Beschriftung.
 * @param string   $id      HTML-id des Felds.
 * @param string   $fehler  Fehlermeldung oder ''.
 * @param callable $inhalt  Gibt das Feld aus.
 * @return void
 */
function lsg_bl_formularzeile( $label, $id, $fehler, $inhalt ) {
	printf(
		'<tr class="%1$s"><th scope="row"><label for="%2$s">%3$s</label></th><td>',
		( '' !== $fehler ) ? 'lsg-bl-feldfehler' : '',
		esc_attr( $id ),
		esc_html( $label )
	);

	call_user_func( $inhalt );

	if ( '' !== $fehler ) {
		printf( '<p class="lsg-bl-fehlertext">%s</p>', esc_html( $fehler ) );
	}

	echo '</td></tr>';
}

/**
 * Das Athleten-Select (Plan 7.2).
 *
 * @param int $gewaehlt Ausgewählte athletes_id.
 * @return void
 */
function lsg_bl_athleten_select( $gewaehlt ) {
	$gruppen = lsg_bl_athleten_gruppiert();

	echo '<select name="athlet" id="lsg-bl-athlet">';
	printf( '<option value="">%s</option>', esc_html__( '— bitte wählen —', 'lsg-bestenliste' ) );

	$labels = array(
		'aktiv'    => __( 'Aktiv', 'lsg-bestenliste' ),
		'ehemalig' => __( 'Ehemalige', 'lsg-bestenliste' ),
	);

	foreach ( $labels as $key => $label ) {
		if ( empty( $gruppen[ $key ] ) ) {
			continue;
		}
		printf( '<optgroup label="%s">', esc_attr( $label ) );
		foreach ( $gruppen[ $key ] as $a ) {
			// ⚠ Der Jahrgang gehört sichtbar in den Eintrag: er unterscheidet
			// gleiche Namen, und aus ihm wird die Altersklasse gerechnet. Wer
			// ihn im Dropdown sieht, erkennt eine Fehlauswahl sofort.
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $a['id'],
				selected( (int) $gewaehlt, (int) $a['id'], false ),
				esc_html( lsg_bl_athlet_label( $a ) )
			);
		}
		echo '</optgroup>';
	}

	echo '</select>';
}

/**
 * Der Vergleich mit dem Bestand (Plan 7.3).
 *
 * @param array $pruefung Ergebnis von lsg_bl_best_pruefung().
 * @param array $werte    Geprüfte Formularwerte.
 * @param array $feld     Ergebnis von lsg_bl_leistung_feld().
 * @return void
 */
function lsg_bl_best_vergleich_anzeigen( array $pruefung, array $werte, array $feld ) {
	printf(
		'<div class="lsg-bl-vergleich lsg-bl-vergleich-%s">',
		esc_attr( $pruefung['lage'] )
	);

	printf(
		'<h2>%s</h2>',
		esc_html(
			sprintf(
				/* translators: 1: Distanz, 2: Jahr */
				__( 'Bestand: %1$s, %2$d', 'lsg-bestenliste' ),
				lsg_bl_distance_label( $werte['distanz'] ),
				(int) $werte['jahr']
			)
		)
	);

	if ( '' !== $pruefung['zusatz'] ) {
		lsg_bl_admin_notice( 'warning', $pruefung['zusatz'] );
	}

	printf( '<p class="lsg-bl-vergleich-text">%s</p>', esc_html( $pruefung['text'] ) );

	if ( $pruefung['best_id'] > 0 ) {
		printf(
			'<p class="lsg-bl-vergleich-alt">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: Leistung, 2: Ort, 3: Datum, 4: id */
					__( 'Bisher: %1$s · %2$s · %3$s (Zeile #%4$d)', 'lsg-bestenliste' ),
					$pruefung['time_alt'],
					$pruefung['town_alt'],
					lsg_bl_format_date( (int) $pruefung['date_alt'] ),
					(int) $pruefung['best_id']
				)
			)
		);
	}

	if ( 'schlechter' === $pruefung['lage'] ) {
		// Überschreiben nur nach ausdrücklichem Haken (Plan 7.3). Der Mensch
		// am Formular weiß Dinge, die die Datenbank nicht weiß – etwa dass
		// der vorhandene Eintrag falsch ist.
		printf(
			'<p><label><input type="checkbox" name="ersetzen" value="1"%1$s> %2$s</label></p>',
			checked( ! empty( $werte['ersetzen'] ), true, false ),
			esc_html__( 'Der vorhandene Eintrag ist falsch, ersetzen', 'lsg-bestenliste' )
		);
	}

	if ( 'keine' !== $pruefung['lage'] ) {
		// ⚠ Es gibt keine Option „zusätzlich anlegen" (Plan 7.3). Der Satz
		// steht hier, weil genau das die naheliegende Erwartung ist.
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Pro Sportler, Distanz und Jahr hält die Bestenliste eine Zeile. Eine zweite anzulegen ist nicht möglich – wer beide Läufe festhalten will, findet sie im Log.',
				'lsg-bestenliste'
			)
		);
	}

	echo '</div>';
}

/* -------------------------------------------------------------------------
 * Verdrahtung
 * ---------------------------------------------------------------------- */

/*
 * Die Handler haengen an admin-post.php, nicht an admin_init: so steht der
 * Zielzustand nach dem Redirect vollstaendig in der Query, und ein Reload
 * wiederholt keinen Schreibvorgang (POST -> Redirect -> GET, Plan 6.9).
 *
 * Das Menue selbst registriert page-import.php - ein add_menu_page() an
 * einer Stelle, nicht vier.
 */
add_action( 'admin_post_lsg_bl_best_speichern', 'lsg_bl_admin_best_post' );
add_action( 'admin_post_lsg_bl_best_loeschen', 'lsg_bl_admin_best_loeschen_post' );
