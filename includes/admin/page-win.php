<?php
/**
 * Admin-Seite „Gesamtsiege" – lsg_win pflegen (Plan, Abschnitt 12).
 *
 * Der zweite Teil der Phase 4 und der letzte Menüpunkt aus 6.2. Gesamtsiege
 * waren immer Handarbeit (6.5.5) – bis hierher gab es nur keinen Weg, sie
 * einzutragen, ausser über phpMyAdmin.
 *
 * ⚠ **Hier wird nichts normalisiert** (12.1). `lsg_win` ist eine Chronik:
 * „48 Runden" ist eine gültige Zeit und „Pforzheim nach Basel" eine gültige
 * Distanz. Wer hier die Prüfungen aus 6.5.1 und 7.2 anwendet, wirft ein
 * Drittel des Bestands weg.
 *
 * ⚠ Das Menü registriert page-import.php, die Capability wird in JEDEM
 * Handler geprüft (6.9).
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
 * Eine Adresse auf diese Seite.
 *
 * @param array $args Query-Argumente.
 * @return string
 */
function lsg_bl_win_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v && '0' !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste-win';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Die Formularwerte, die in der Query weitergegeben werden.
 *
 * Über denselben Weg kommt auch die Vorbelegung aus der Übernahme-Tabelle
 * (12.6) – sie ist nichts als eine Adresse mit diesen Parametern.
 *
 * @param array $w Werte.
 * @return array
 */
function lsg_bl_win_query_werte( array $w ) {
	return array(
		'id'      => isset( $w['id'] ) ? (int) $w['id'] : 0,
		'datum'   => isset( $w['datum'] ) ? (string) $w['datum'] : '',
		'ort'     => isset( $w['ort'] ) ? (string) $w['ort'] : '',
		'event'   => isset( $w['event'] ) ? (string) $w['event'] : '',
		'distanz' => isset( $w['distanz'] ) ? (string) $w['distanz'] : '',
		'athlet'  => isset( $w['athlet'] ) ? (int) $w['athlet'] : 0,
		'zeit'    => isset( $w['zeit'] ) ? (string) $w['zeit'] : '',
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
function lsg_bl_admin_win_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_win' );

	$schritt = isset( $_POST['schritt'] ) ? sanitize_key( wp_unslash( $_POST['schritt'] ) ) : 'pruefen';

	$eingabe = array(
		'id'      => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
		'datum'   => lsg_bl_datum_eingabe_lesen( isset( $_POST['datum'] ) ? wp_unslash( $_POST['datum'] ) : '' ),
		'ort'     => isset( $_POST['ort'] ) ? sanitize_text_field( wp_unslash( $_POST['ort'] ) ) : '',
		'event'   => isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '',
		'distanz' => isset( $_POST['distanz'] ) ? sanitize_text_field( wp_unslash( $_POST['distanz'] ) ) : '',
		'athlet'  => isset( $_POST['athlet'] ) ? (int) $_POST['athlet'] : 0,
		'zeit'    => isset( $_POST['zeit'] ) ? sanitize_text_field( wp_unslash( $_POST['zeit'] ) ) : '',
	);

	$ziel = array_merge(
		array( 'action' => $eingabe['id'] > 0 ? 'edit' : 'new' ),
		lsg_bl_win_query_werte( $eingabe )
	);

	if ( 'speichern' !== $schritt ) {
		wp_safe_redirect( lsg_bl_win_url( $ziel ) );
		exit;
	}

	$jahr_max = (int) gmdate( 'Y', time() ) + 1;
	$p        = lsg_bl_win_formular_pruefen( $eingabe, $jahr_max );

	if ( ! $p['ok'] ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Nichts gespeichert – bitte die markierten Felder ansehen.', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_win_url( $ziel ) );
		exit;
	}

	$ergebnis = lsg_bl_win_speichern( $p['werte'] );
	lsg_bl_admin_notice_setzen( $ergebnis['typ'], $ergebnis['text'] );

	if ( 'error' !== $ergebnis['typ'] ) {
		wp_safe_redirect( lsg_bl_win_url( array( 'jahr' => (int) $p['werte']['jahr'] ) ) );
		exit;
	}

	wp_safe_redirect( lsg_bl_win_url( $ziel ) );
	exit;
}
add_action( 'admin_post_lsg_bl_win_speichern', 'lsg_bl_admin_win_post' );

/**
 * Der Schreibvorgang (Plan 12.3, 12.5).
 *
 * ⚠ Die Dublettenprüfung läuft hier ein zweites Mal, gegen die Datenbank
 * unmittelbar vor dem Schreiben – wie in 7.3 und 11.2.
 *
 * @param array $w Geprüfte Werte.
 * @return array{typ:string,text:string,id:int}
 */
function lsg_bl_win_speichern( array $w ) {
	$id  = (int) $w['id'];
	$alt = $id > 0 ? lsg_bl_win_zeile( $id ) : null;

	if ( $id > 0 && ! $alt ) {
		return array(
			'typ'  => 'error',
			'text' => __( 'Diesen Gesamtsieg gibt es nicht (mehr).', 'lsg-bestenliste' ),
			'id'   => 0,
		);
	}

	$athlet = lsg_bl_athlet( (int) $w['athlet'] );
	if ( ! $athlet ) {
		return array(
			'typ'  => 'error',
			'text' => __( 'Diesen Sportler gibt es nicht.', 'lsg-bestenliste' ),
			'id'   => 0,
		);
	}

	$dublette = lsg_bl_win_dublette( $w['athlet'], $w['datum'], $w['event'], $id );
	if ( $dublette ) {
		return array(
			'typ'  => 'error',
			'text' => sprintf(
				/* translators: 1: Name, 2: Veranstaltung, 3: Datum, 4: id */
				__( 'Nicht gespeichert: %1$s steht für „%2$s" am %3$s schon in den Gesamtsiegen (#%4$d).', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $athlet ),
				$dublette['event'],
				lsg_bl_format_date( (int) $dublette['date'] ),
				(int) $dublette['id']
			),
			'id'   => 0,
		);
	}

	/* ---- Anlegen ---- */
	if ( 0 === $id ) {
		$res = lsg_bl_win_anlegen( $w );
		if ( ! $res['ok'] ) {
			return array(
				'typ'  => 'error',
				/* translators: %s: Fehlertext der Datenbank */
				'text' => sprintf( __( 'Nicht gespeichert: %s', 'lsg-bestenliste' ), $res['fehler'] ),
				'id'   => 0,
			);
		}

		lsg_bl_win_protokollieren( 'win_insert', $w, $athlet, $res['id'], __( 'Gesamtsieg eingetragen', 'lsg-bestenliste' ) );

		return array(
			'typ'  => 'success',
			'text' => sprintf(
				/* translators: 1: Name, 2: Veranstaltung */
				__( 'Gesamtsieg eingetragen: %1$s, %2$s.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $athlet ),
				$w['event']
			),
			'id'   => $res['id'],
		);
	}

	/* ---- Ändern ---- */
	$alt_form = array(
		'datum'   => lsg_bl_format_date_iso( (int) $alt['date'] ),
		'ort'     => (string) $alt['town'],
		'event'   => (string) $alt['event'],
		'distanz' => (string) $alt['distance'],
		'athlet'  => (int) $alt['athletes_id'],
		'zeit'    => (string) $alt['time'],
	);

	$diff = lsg_bl_win_diff( $alt_form, $w );

	if ( ! $diff ) {
		// ⚠ Wie in 7.5 und 11.4: nichts geändert, nichts geschrieben.
		return array(
			'typ'  => 'info',
			'text' => __( 'Nichts geändert – nichts gespeichert.', 'lsg-bestenliste' ),
			'id'   => $id,
		);
	}

	$res = lsg_bl_win_aendern( $id, $w );
	if ( ! $res['ok'] ) {
		return array(
			'typ'  => 'error',
			/* translators: %s: Fehlertext der Datenbank */
			'text' => sprintf( __( 'Nicht gespeichert: %s', 'lsg-bestenliste' ), $res['fehler'] ),
			'id'   => $id,
		);
	}

	$meldung = lsg_bl_win_diff_text( $diff );
	lsg_bl_win_protokollieren( 'win_update', $w, $athlet, $id, $meldung );

	return array(
		'typ'  => 'success',
		'text' => sprintf(
			/* translators: 1: Veranstaltung, 2: die geänderten Felder */
			__( '%1$s gespeichert – %2$s.', 'lsg-bestenliste' ),
			$w['event'],
			$meldung
		),
		'id'   => $id,
	);
}

/**
 * Einen Vorgang protokollieren (Plan 12.5).
 *
 * ⚠ Anders als bei der Sportlerpflege ist `event_name` hier gefüllt – ein
 * Gesamtsieg hat eine Veranstaltung. Die Log-Liste zeigt sie deshalb direkt,
 * und die Ableitung aus 11.4 greift gar nicht erst.
 *
 * ⚠ `roh_zeit` und `time_neu` tragen die Eingabe unverändert, auch
 * „48 Runden": ein Log, das die Eingabe normalisiert, protokolliert nicht die
 * Eingabe (12.5).
 *
 * @param string $aktion  win_insert | win_update | win_delete.
 * @param array  $w       Formularwerte.
 * @param array  $athlet  Zeile aus lsg_athlete.
 * @param int    $win_id  lsg_win.id.
 * @param string $meldung Klartext.
 * @return int run_id.
 */
function lsg_bl_win_protokollieren( $aktion, array $w, array $athlet, $win_id, $meldung ) {
	$zeile = lsg_bl_log_manuell_zeile(
		array(
			'athletes_id' => (int) $athlet['id'],
			'name'        => (string) $athlet['name'],
			'firstname'   => (string) $athlet['firstname'],
			'born'        => (int) $athlet['born'],
			'ak'          => '',
			'time'        => (string) $w['zeit'],
		),
		$aktion,
		'',
		$meldung,
		0,
		(string) $w['zeit']
	);

	/*
	 * ⚠ `best_id` bleibt 0: die Spalte zeigt auf `lsg_best`, und ein
	 * Gesamtsieg steht dort nicht. Die id der win-Zeile hat im Log keine
	 * eigene Spalte – sie steckt in der Meldung, wenn sie gebraucht wird.
	 */
	unset( $win_id );

	/*
	 * ⚠ Ist die Distanz länger als 15 Zeichen, kürzen die beiden
	 * `distance`-Spalten des Logs sie ab (siehe den Torwächter in
	 * lsg_bl_log_schreiben) – dann reist sie zusätzlich in der Meldung mit,
	 * die 255 Zeichen fasst. Steht sie dort ohnehin schon, bleibt es dabei:
	 * zweimal „Pforzheim nach Basel" in einer Zeile hilft niemandem.
	 */
	$voll = (string) $w['distanz'];
	if ( lsg_bl_zeichen( $voll ) > 15 && false === mb_strpos( $meldung, $voll ) ) {
		$meldung = $meldung . ' – ' . $voll;
	}
	$zeile['meldung'] = mb_substr( $meldung, 0, 255 );

	return lsg_bl_log_manuell(
		array(
			'datum'      => (string) $w['datum'],
			'jahr'       => (int) $w['jahr'],
			'distanz'    => (string) $w['distanz'],
			'ort'        => (string) $w['ort'],
			'event_name' => (string) $w['event'],
		),
		array(
			'angelegt'     => ( 'win_insert' === $aktion ) ? 1 : 0,
			'aktualisiert' => ( 'win_update' === $aktion ) ? 1 : 0,
		),
		array( $zeile )
	);
}

/**
 * Löschen (Plan 12.4).
 *
 * @return void
 */
function lsg_bl_admin_win_loeschen_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_win_loeschen' );

	$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$row = $id > 0 ? lsg_bl_win_zeile( $id ) : null;

	if ( ! $row ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Diesen Gesamtsieg gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_win_url() );
		exit;
	}

	$athlet = lsg_bl_athlet( (int) $row['athletes_id'] );
	$w      = array(
		'datum'   => lsg_bl_format_date_iso( (int) $row['date'] ),
		'jahr'    => lsg_bl_year_from_timestamp( (int) $row['date'] ),
		'ort'     => (string) $row['town'],
		'event'   => (string) $row['event'],
		'distanz' => (string) $row['distance'],
		'zeit'    => (string) $row['time'],
	);

	// ⚠ Zuerst protokollieren, dann löschen – wie in 7.4 und 11.3.
	if ( $athlet ) {
		lsg_bl_win_protokollieren(
			'win_delete',
			$w,
			$athlet,
			$id,
			sprintf(
				/* translators: 1: Distanz, 2: Zeit, 3: Ort */
				__( '%1$s, %2$s, %3$s', 'lsg-bestenliste' ),
				$row['distance'],
				$row['time'],
				$row['town']
			)
		);
	}

	$res = lsg_bl_win_loeschen( $id );

	if ( $res['ok'] ) {
		lsg_bl_admin_notice_setzen(
			'success',
			sprintf(
				/* translators: %s: Veranstaltung */
				__( 'Gesamtsieg „%s" gelöscht. Der vollständige Datensatz steht im Import-Log.', 'lsg-bestenliste' ),
				$row['event']
			)
		);
	} else {
		lsg_bl_admin_notice_setzen(
			'error',
			/* translators: %s: Fehlertext der Datenbank */
			sprintf( __( 'Nicht gelöscht: %s', 'lsg-bestenliste' ), $res['fehler'] )
		);
	}

	wp_safe_redirect( lsg_bl_win_url() );
	exit;
}
add_action( 'admin_post_lsg_bl_win_loeschen', 'lsg_bl_admin_win_loeschen_post' );

/* -------------------------------------------------------------------------
 * Die Seite
 * ---------------------------------------------------------------------- */

/**
 * Der Einstieg – Liste, Formular oder Rückfrage.
 *
 * @return void
 */
function lsg_bl_admin_win_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	echo '<div class="wrap lsg-bl-win" id="lsg-bl-win">';

	$notice = lsg_bl_admin_notice_holen();
	if ( $notice ) {
		lsg_bl_admin_notice( $notice['typ'], $notice['text'] );
	}

	if ( 'new' === $action || 'edit' === $action ) {
		lsg_bl_win_formular_anzeigen( $action );
	} elseif ( 'delete' === $action ) {
		lsg_bl_win_loeschen_rueckfrage();
	} else {
		lsg_bl_win_liste_anzeigen();
	}

	echo '</div>';
}

/**
 * Die Liste (Plan 12.4).
 *
 * @return void
 */
function lsg_bl_win_liste_anzeigen() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$filter = lsg_bl_win_filter(
		array(
			'jahr'    => isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0,
			'athlet'  => isset( $_GET['athlet'] ) ? (int) $_GET['athlet'] : 0,
			's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
			'order'   => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
		)
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Gesamtsiege', 'lsg-bestenliste' ) );
	printf(
		' <a href="%1$s" class="page-title-action">%2$s</a>',
		esc_url( lsg_bl_win_url( array( 'action' => 'new' ) ) ),
		esc_html__( 'Gesamtsieg eintragen', 'lsg-bestenliste' )
	);
	echo '<hr class="wp-header-end">';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Siege in der Gesamtwertung – die Chronik hinter dem Block „Gesamtsiege". Der Import erkennt einen Sieg und markiert ihn, eingetragen wird er hier.',
			'lsg-bestenliste'
		)
	);

	// ⚠ Erst hier laden (siehe Kopf der Klasse).
	require_once LSG_BL_PATH . 'includes/admin/class-lsg-win-table.php';

	$tabelle = new LSG_BL_Win_Table( $filter );
	$tabelle->prepare_items();

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-win' ) );
	lsg_bl_win_filterleiste( $filter );
	$tabelle->search_box( __( 'Veranstaltung oder Ort suchen', 'lsg-bestenliste' ), 'lsg-bl-win-suche' );
	echo '</form>';

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-win' ) );
	foreach ( array( 'jahr', 'athlet', 's', 'orderby', 'order' ) as $k ) {
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
function lsg_bl_win_filterleiste( array $filter ) {
	echo '<div class="lsg-bl-bestfilter">';

	echo '<label>' . esc_html__( 'Jahr', 'lsg-bestenliste' ) . ' ';
	echo '<select name="jahr">';
	printf( '<option value="">%s</option>', esc_html__( 'alle', 'lsg-bestenliste' ) );
	foreach ( lsg_bl_win_jahre() as $j ) {
		printf(
			'<option value="%1$d"%2$s>%1$d</option>',
			(int) $j,
			selected( $filter['jahr'], (int) $j, false )
		);
	}
	echo '</select></label> ';

	printf( '<button class="button">%s</button>', esc_html__( 'Filtern', 'lsg-bestenliste' ) );

	if ( $filter['athlet'] > 0 ) {
		$a = lsg_bl_athlet( $filter['athlet'] );
		if ( $a ) {
			printf(
				' <span class="lsg-bl-aktivfilter">%1$s <a href="%2$s">%3$s</a></span>',
				esc_html( sprintf( 'nur %s', lsg_bl_athlet_label( $a ) ) ),
				esc_url( lsg_bl_win_url( array( 'jahr' => $filter['jahr'] ) ) ),
				esc_html__( 'Filter aufheben', 'lsg-bestenliste' )
			);
		}
	}

	echo '</div>';
}

/**
 * Die Rückfrage vor dem Löschen (Plan 12.4).
 *
 * @return void
 */
function lsg_bl_win_loeschen_rueckfrage() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$row = $id > 0 ? lsg_bl_win_zeile( $id ) : null;

	printf( '<h1>%s</h1>', esc_html__( 'Gesamtsieg löschen', 'lsg-bestenliste' ) );

	if ( ! $row ) {
		lsg_bl_admin_notice( 'error', __( 'Diesen Gesamtsieg gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( lsg_bl_win_url() ),
			esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
		);
		return;
	}

	echo '<div class="lsg-bl-loeschen">';
	printf( '<p>%s</p>', esc_html__( 'Dieser Eintrag wird endgültig aus den Gesamtsiegen entfernt:', 'lsg-bestenliste' ) );

	echo '<table class="widefat striped" style="max-width:40em"><tbody>';
	$felder = array(
		__( 'Datum', 'lsg-bestenliste' )         => lsg_bl_format_date( (int) $row['date'] ),
		__( 'Ort', 'lsg-bestenliste' )           => $row['town'],
		__( 'Veranstaltung', 'lsg-bestenliste' ) => $row['event'],
		__( 'Distanz', 'lsg-bestenliste' )       => $row['distance'],
		__( 'Sportler', 'lsg-bestenliste' )      => lsg_bl_athlete_display_name( $row['name'], $row['firstname'] )
			. ( $row['born'] ? ' (' . (int) $row['born'] . ')' : '' ),
		__( 'Zeit', 'lsg-bestenliste' )          => $row['time'],
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
	wp_nonce_field( 'lsg_bl_win_loeschen' );
	echo '<input type="hidden" name="action" value="lsg_bl_win_loeschen">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $id );
	printf(
		'<button class="button button-primary">%s</button> <a class="button" href="%s">%s</a>',
		esc_html__( 'Endgültig löschen', 'lsg-bestenliste' ),
		esc_url( lsg_bl_win_url() ),
		esc_html__( 'Abbrechen', 'lsg-bestenliste' )
	);
	echo '</form>';
	echo '</div>';
}

/**
 * Das Formular (Plan 12.3).
 *
 * @param string $action new | edit.
 * @return void
 */
function lsg_bl_win_formular_anzeigen( $action ) {
	$w     = lsg_bl_win_felder_leer();
	$zeile = null;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( 'edit' === $action ) {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$zeile = $id > 0 ? lsg_bl_win_zeile( $id ) : null;

		if ( ! $zeile ) {
			printf( '<h1>%s</h1>', esc_html__( 'Gesamtsieg bearbeiten', 'lsg-bestenliste' ) );
			lsg_bl_admin_notice( 'error', __( 'Diesen Gesamtsieg gibt es nicht (mehr).', 'lsg-bestenliste' ) );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( lsg_bl_win_url() ),
				esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
			);
			return;
		}

		$w = array(
			'id'      => (int) $zeile['id'],
			'datum'   => lsg_bl_format_date_iso( (int) $zeile['date'] ),
			'ort'     => (string) $zeile['town'],
			'event'   => (string) $zeile['event'],
			'distanz' => (string) $zeile['distance'],
			'athlet'  => (int) $zeile['athletes_id'],
			'zeit'    => (string) $zeile['time'],
		);
	}

	/*
	 * ⚠ Die Query sticht die gespeicherte Zeile aus, und entscheidend ist, ob
	 * der Parameter DA ist – nicht, ob er einen Wert hat (wie 6.5.1, 7.2,
	 * 11.2). Über genau diesen Weg kommt auch die Vorbelegung aus der
	 * Übernahme-Tabelle (12.6): sie ist nur eine Adresse.
	 */
	$angefasst = false;
	foreach ( array( 'datum', 'ort', 'event', 'distanz', 'athlet', 'zeit' ) as $k ) {
		if ( ! isset( $_GET[ $k ] ) ) {
			continue;
		}
		$angefasst = true;
		$roh       = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
		$w[ $k ]   = ( 'athlet' === $k ) ? (int) $roh : $roh;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$jahr_max = (int) gmdate( 'Y', time() ) + 1;
	$p        = lsg_bl_win_formular_pruefen( $w, $jahr_max );
	$werte    = $p['werte'];

	// Rot nur, wenn jemand etwas eingegeben oder eine Zeile geöffnet hat.
	$fehler = ( $angefasst || null !== $zeile ) ? $p['fehler'] : array();

	$dublette = null;
	if ( ! isset( $fehler['athlet'] ) && ! isset( $fehler['datum'] ) && ! isset( $fehler['event'] ) ) {
		$dublette = lsg_bl_win_dublette( $werte['athlet'], $werte['datum'], $werte['event'], (int) $werte['id'] );
	}

	printf(
		'<h1>%s</h1>',
		esc_html(
			'edit' === $action
				? __( 'Gesamtsieg bearbeiten', 'lsg-bestenliste' )
				: __( 'Gesamtsieg eintragen', 'lsg-bestenliste' )
		)
	);

	if ( $dublette ) {
		lsg_bl_admin_notice(
			'error',
			sprintf(
				/* translators: 1: Veranstaltung, 2: Datum, 3: id */
				__( 'Dieser Sieg steht schon in der Liste: „%1$s" am %2$s (#%3$d). Derselbe Sportler am selben Tag bei derselben Veranstaltung kann nur einmal gewonnen haben.', 'lsg-bestenliste' ),
				$dublette['event'],
				lsg_bl_format_date( (int) $dublette['date'] ),
				(int) $dublette['id']
			)
		);
	}

	printf( '<form method="post" action="%s" class="lsg-bl-winform">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_win' );
	echo '<input type="hidden" name="action" value="lsg_bl_win_speichern">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $werte['id'] );

	echo '<table class="form-table" role="presentation"><tbody>';

	lsg_bl_formularzeile(
		__( 'Datum', 'lsg-bestenliste' ),
		'lsg-bl-win-datum',
		isset( $fehler['datum'] ) ? $fehler['datum'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="date" name="datum" id="lsg-bl-win-datum" value="%s" class="regular-text" required>',
				esc_attr( $werte['datum'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Das Veranstaltungsdatum, nicht das Erfassungsdatum.', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Ort', 'lsg-bestenliste' ),
		'lsg-bl-win-ort',
		isset( $fehler['ort'] ) ? $fehler['ort'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="ort" id="lsg-bl-win-ort" value="%s" maxlength="30" class="regular-text" list="lsg-bl-win-orte" required>',
				esc_attr( $werte['ort'] )
			);
			lsg_bl_win_datalist( 'lsg-bl-win-orte', lsg_bl_win_vorschlaege( 'town' ) );
		}
	);

	lsg_bl_formularzeile(
		__( 'Veranstaltung', 'lsg-bestenliste' ),
		'lsg-bl-win-event',
		isset( $fehler['event'] ) ? $fehler['event'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="event" id="lsg-bl-win-event" value="%s" maxlength="40" class="regular-text" required>',
				esc_attr( $werte['event'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Höchstens 40 Zeichen – so lang ist die Spalte. Ein zu langer Name wird nicht gekürzt, sondern zurückgewiesen.', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Distanz', 'lsg-bestenliste' ),
		'lsg-bl-win-distanz',
		isset( $fehler['distanz'] ) ? $fehler['distanz'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="distanz" id="lsg-bl-win-distanz" value="%s" maxlength="20" class="regular-text" list="lsg-bl-win-distanzen" required>',
				esc_attr( $werte['distanz'] )
			);
			lsg_bl_win_datalist( 'lsg-bl-win-distanzen', lsg_bl_win_vorschlaege( 'distance' ) );
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Freitext, kein Auswahlfeld: hier stehen auch „90 Minuten" oder „Pforzheim nach Basel". Die Vorschläge sind das, was schon vorkommt.', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Sportler', 'lsg-bestenliste' ),
		'lsg-bl-win-athlet',
		isset( $fehler['athlet'] ) ? $fehler['athlet'] : '',
		function () use ( $werte ) {
			lsg_bl_athleten_select( (int) $werte['athlet'], 'athlet', 'lsg-bl-win-athlet' );
			printf(
				'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Fehlt jemand, wird er nicht hier angelegt – Sportler werden getrennt gepflegt.', 'lsg-bestenliste' ),
				esc_url( lsg_bl_athlet_url( array( 'action' => 'new' ) ) ),
				esc_html__( 'Sportler anlegen', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Zeit', 'lsg-bestenliste' ),
		'lsg-bl-win-zeit',
		isset( $fehler['zeit'] ) ? $fehler['zeit'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="zeit" id="lsg-bl-win-zeit" value="%s" maxlength="15" class="regular-text" list="lsg-bl-win-zeiten" required>',
				esc_attr( $werte['zeit'] )
			);
			lsg_bl_win_datalist( 'lsg-bl-win-zeiten', lsg_bl_win_vorschlaege( 'time', 12 ) );
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Ebenfalls Freitext und ungeprüft: „01:19:51", aber auch „241,621 km" oder „48 Runden". Was hier steht, steht so auf der Website.', 'lsg-bestenliste' )
			);
		}
	);

	echo '</tbody></table>';

	printf(
		'<button type="submit" name="schritt" value="pruefen" class="button">%s</button> ',
		esc_html__( 'Prüfen', 'lsg-bestenliste' )
	);
	printf(
		'<button type="submit" name="schritt" value="speichern" class="button button-primary"%1$s>%2$s</button> ',
		$dublette ? ' disabled="disabled"' : '',
		esc_html__( 'Speichern', 'lsg-bestenliste' )
	);
	printf(
		'<a class="button" href="%1$s">%2$s</a>',
		esc_url( lsg_bl_win_url() ),
		esc_html__( 'Abbrechen', 'lsg-bestenliste' )
	);

	echo '</form>';

	if ( $zeile ) {
		printf(
			'<p class="lsg-bl-loeschlink"><a href="%1$s">%2$s</a></p>',
			esc_url( lsg_bl_win_url( array( 'action' => 'delete', 'id' => (int) $zeile['id'] ) ) ),
			esc_html__( 'Diesen Gesamtsieg löschen', 'lsg-bestenliste' )
		);
	}
}

/**
 * Eine Vorschlagsliste ausgeben (Plan 12.1).
 *
 * ⚠ `<datalist>` schlägt vor, es schränkt nicht ein: das Feld bleibt ein
 * Textfeld, eine Eingabe ausserhalb der Liste wird angenommen. Ohne
 * JavaScript verhält es sich genauso, und wo der Browser das Element nicht
 * kennt, fällt es ersatzlos weg.
 *
 * @param string   $id     HTML-id.
 * @param string[] $werte  Vorschläge.
 * @return void
 */
function lsg_bl_win_datalist( $id, array $werte ) {
	if ( ! $werte ) {
		return;
	}

	printf( '<datalist id="%s">', esc_attr( $id ) );
	foreach ( $werte as $wert ) {
		printf( '<option value="%s"></option>', esc_attr( $wert ) );
	}
	echo '</datalist>';
}
