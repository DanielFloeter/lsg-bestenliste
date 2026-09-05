<?php
/**
 * Admin-Seite „Sportler" – lsg_athlete pflegen (Plan 11).
 *
 * Der erste Teil der Phase 4 aus der README. Ohne diese Seite endet jeder Weg,
 * den der Plan für einen fehlenden Athleten vorsieht, in phpMyAdmin: die offene
 * Zeile aus P3 (6.5.3), der Hinweis unter dem Athleten-Dropdown der
 * Bestenlisten-Pflege (7.2) und die Aufzählung in 7.6 zeigen alle hierher.
 *
 * ⚠ Das Menü registriert page-import.php – ein add_menu_page() an mehreren
 * Stellen wäre ein zweiter Eintrag im Menü.
 *
 * ⚠ Die Capability wird in JEDEM Handler geprüft, nicht nur beim Rendern
 * (6.9). add_submenu_page() versteckt den Eintrag, es schützt ihn nicht.
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
function lsg_bl_athlet_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v && '0' !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste-athleten';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Die Formularwerte, die in der Query weitergegeben werden.
 *
 * ⚠ Der Zustand des Formulars steht in der Query, nicht in einer Session –
 * derselbe Grund wie beim Import (6.9) und bei der Bestenlisten-Pflege: ein
 * Reload wiederholt nichts, und die halbausgefüllte Eingabe ist verlinkbar.
 *
 * ⚠ `active` reist als `aktiv`/`ehemalig`, nicht als `1`/`0`. Grund ist
 * lsg_bl_athlet_url(): die Funktion wirft leere Werte und Nullen aus der Query,
 * eine `active=0` käme also nie an.
 *
 * @param array $w Werte.
 * @return array
 */
function lsg_bl_athlet_query_werte( array $w ) {
	return array(
		'id'        => isset( $w['id'] ) ? (int) $w['id'] : 0,
		'name'      => isset( $w['name'] ) ? (string) $w['name'] : '',
		'firstname' => isset( $w['firstname'] ) ? (string) $w['firstname'] : '',
		'born'      => isset( $w['born'] ) ? (int) $w['born'] : 0,
		'cat'       => isset( $w['cat'] ) ? (string) $w['cat'] : '',
		'status'    => isset( $w['active'] ) && '0' === (string) $w['active'] ? 'ehemalig' : 'aktiv',
		'akmit'     => empty( $w['akmit'] ) ? '' : '1',
	);
}

/**
 * Die Formularwerte aus einer Anfrage lesen.
 *
 * @param array $quelle $_GET oder $_POST, bereits unslashed gelesen.
 * @return array
 */
function lsg_bl_athlet_werte_lesen( array $quelle ) {
	return array(
		'id'        => isset( $quelle['id'] ) ? (int) $quelle['id'] : 0,
		'name'      => isset( $quelle['name'] ) ? (string) $quelle['name'] : '',
		'firstname' => isset( $quelle['firstname'] ) ? (string) $quelle['firstname'] : '',
		'born'      => isset( $quelle['born'] ) ? (int) $quelle['born'] : 0,
		'cat'       => isset( $quelle['cat'] ) ? (string) $quelle['cat'] : 'm',
		'active'    => ( isset( $quelle['status'] ) && 'ehemalig' === $quelle['status'] ) ? '0' : '1',
		'akmit'     => ! empty( $quelle['akmit'] ),
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
function lsg_bl_admin_athlet_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_athlet' );

	$schritt = isset( $_POST['schritt'] ) ? sanitize_key( wp_unslash( $_POST['schritt'] ) ) : 'pruefen';

	$roh = array(
		'id'        => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
		'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		'firstname' => isset( $_POST['firstname'] ) ? sanitize_text_field( wp_unslash( $_POST['firstname'] ) ) : '',
		'born'      => isset( $_POST['born'] ) ? (int) $_POST['born'] : 0,
		'cat'       => isset( $_POST['cat'] ) ? sanitize_key( wp_unslash( $_POST['cat'] ) ) : 'm',
		'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'aktiv',
		'akmit'     => ! empty( $_POST['akmit'] ),
	);

	$eingabe = lsg_bl_athlet_werte_lesen( $roh );

	$ziel = array_merge(
		array( 'action' => $eingabe['id'] > 0 ? 'edit' : 'new' ),
		lsg_bl_athlet_query_werte( $eingabe )
	);

	if ( 'speichern' !== $schritt ) {
		// Nur prüfen: zurück ins Formular, die Anzeige rechnet neu.
		wp_safe_redirect( lsg_bl_athlet_url( $ziel ) );
		exit;
	}

	$jahr_max = (int) gmdate( 'Y', time() );
	$p        = lsg_bl_athlet_formular_pruefen( $eingabe, $jahr_max );

	if ( ! $p['ok'] ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Nichts gespeichert – bitte die markierten Felder ansehen.', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_athlet_url( $ziel ) );
		exit;
	}

	$ergebnis = lsg_bl_athlet_speichern( $p['werte'], ! empty( $eingabe['akmit'] ) );
	lsg_bl_admin_notice_setzen( $ergebnis['typ'], $ergebnis['text'] );

	if ( 'error' !== $ergebnis['typ'] ) {
		wp_safe_redirect( lsg_bl_athlet_url( array( 's' => $p['werte']['name'] ) ) );
		exit;
	}

	wp_safe_redirect( lsg_bl_athlet_url( $ziel ) );
	exit;
}
add_action( 'admin_post_lsg_bl_athlet_speichern', 'lsg_bl_admin_athlet_post' );

/**
 * Der Schreibvorgang (Plan 11.2, 11.4).
 *
 * ⚠ Die Dublettenprüfung läuft hier ein zweites Mal, gegen die Datenbank
 * unmittelbar vor dem Schreiben. Die erste Prüfung im Formular ist Anzeige;
 * zwischen ihr und dem Speichern kann jemand anders denselben Sportler
 * angelegt haben. Derselbe Aufbau wie bei der Jahresbestzeit in 7.3.
 *
 * @param array $w               Geprüfte Werte.
 * @param bool  $ak_mitschreiben Die betroffenen lsg_best.ak mitschreiben.
 * @return array{typ:string,text:string,id:int}
 */
function lsg_bl_athlet_speichern( array $w, $ak_mitschreiben = false ) {
	$id  = (int) $w['id'];
	$alt = $id > 0 ? lsg_bl_athlet( $id ) : null;

	if ( $id > 0 && ! $alt ) {
		return array(
			'typ'  => 'error',
			'text' => __( 'Diesen Sportler gibt es nicht (mehr).', 'lsg-bestenliste' ),
			'id'   => 0,
		);
	}

	$dublette = lsg_bl_athlet_dublette( $w['name'], $w['firstname'], $w['born'], $id );
	if ( $dublette ) {
		return array(
			'typ'  => 'error',
			'text' => sprintf(
				/* translators: 1: Name, Vorname (Jahrgang), 2: id */
				__( 'Nicht gespeichert: %1$s steht schon in der Liste (#%2$d). Zwei Sportler mit gleichem Namen und gleichem Jahrgang machen den Import für diesen Namen blind.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $dublette ),
				(int) $dublette['id']
			),
			'id'   => 0,
		);
	}

	/* ---- Anlegen ---- */
	if ( 0 === $id ) {
		$res = lsg_bl_athlet_anlegen( $w );
		if ( ! $res['ok'] ) {
			return array(
				'typ'  => 'error',
				'text' => sprintf(
					/* translators: %s: Fehlertext der Datenbank */
					__( 'Nicht gespeichert: %s', 'lsg-bestenliste' ),
					$res['fehler']
				),
				'id'   => 0,
			);
		}

		$neu = array_merge( $w, array( 'id' => $res['id'] ) );
		lsg_bl_athlet_protokollieren( 'athlet_insert', $neu, __( 'Sportler angelegt', 'lsg-bestenliste' ), array() );

		return array(
			'typ'  => 'success',
			'text' => sprintf(
				/* translators: %s: Name, Vorname (Jahrgang) */
				__( '%s angelegt.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $neu )
			),
			'id'   => $res['id'],
		);
	}

	/* ---- Ändern ---- */
	$diff = lsg_bl_athlet_diff( $alt, $w );

	// Die Abweichungen werden gegen die Werte gerechnet, die gespeichert
	// werden sollen – nicht gegen die alten.
	$abw = lsg_bl_athlet_ak_abweichungen( lsg_bl_athlet_best_alle( $id ), $w['born'], $w['cat'] );

	if ( ! $diff && ( ! $ak_mitschreiben || ! $abw ) ) {
		// ⚠ Ein Speichern, das nichts ändert, schreibt nichts und erzeugt
		// keine Log-Zeile – dieselbe Regel wie in 7.5.
		return array(
			'typ'  => 'info',
			'text' => __( 'Nichts geändert – nichts gespeichert.', 'lsg-bestenliste' ),
			'id'   => $id,
		);
	}

	if ( $diff ) {
		$res = lsg_bl_athlet_aendern( $id, $w );
		if ( ! $res['ok'] ) {
			return array(
				'typ'  => 'error',
				'text' => sprintf(
					/* translators: %s: Fehlertext der Datenbank */
					__( 'Nicht gespeichert: %s', 'lsg-bestenliste' ),
					$res['fehler']
				),
				'id'   => $id,
			);
		}
	}

	$geschrieben = array();
	if ( $ak_mitschreiben && $abw ) {
		lsg_bl_athlet_ak_schreiben( $abw );
		$geschrieben = $abw;
	}

	$meldung = $diff
		? lsg_bl_athlet_diff_text( $diff )
		: __( 'Altersklassen nachgerechnet', 'lsg-bestenliste' );

	lsg_bl_athlet_protokollieren(
		'athlet_update',
		array_merge( $w, array( 'id' => $id ) ),
		$meldung,
		$geschrieben
	);

	$text = sprintf(
		/* translators: 1: Name, Vorname (Jahrgang), 2: die geänderten Felder */
		__( '%1$s gespeichert – %2$s.', 'lsg-bestenliste' ),
		lsg_bl_athlet_label( array_merge( $w, array( 'id' => $id ) ) ),
		$meldung
	);

	if ( $geschrieben ) {
		$text .= ' ' . sprintf(
			/* translators: %d: Anzahl der Ergebniszeilen */
			_n(
				'%d Altersklasse im Bestand mitgeschrieben.',
				'%d Altersklassen im Bestand mitgeschrieben.',
				count( $geschrieben ),
				'lsg-bestenliste'
			),
			count( $geschrieben )
		);
	} elseif ( $abw ) {
		$text .= ' ' . sprintf(
			/* translators: %d: Anzahl der Ergebniszeilen */
			_n(
				'%d Ergebniszeile trägt weiterhin eine unpassende Altersklasse.',
				'%d Ergebniszeilen tragen weiterhin eine unpassende Altersklasse.',
				count( $abw ),
				'lsg-bestenliste'
			),
			count( $abw )
		);
	}

	return array(
		'typ'  => 'success',
		'text' => $text,
		'id'   => $id,
	);
}

/**
 * Einen Vorgang protokollieren (Plan 11.4).
 *
 * ⚠ Je nachgerechneter Ergebniszeile eine eigene Log-Zeile, nicht eine für
 * den ganzen Vorgang. Sonst beantwortet das Log die Frage nicht, für die es da
 * ist: „warum steht bei X diese Altersklasse" (7.5). Alle hängen am selben
 * `run_id` wie die Änderung, die sie ausgelöst hat.
 *
 * @param string $aktion    athlet_insert | athlet_update | athlet_delete.
 * @param array  $athlet    id, name, firstname, born, cat, active.
 * @param string $meldung   Klartext.
 * @param array  $ak_zeilen Ergebnis von lsg_bl_athlet_ak_abweichungen().
 * @return int run_id.
 */
function lsg_bl_athlet_protokollieren( $aktion, array $athlet, $meldung, array $ak_zeilen = array() ) {
	$basis = array(
		'athletes_id' => (int) $athlet['id'],
		'name'        => (string) $athlet['name'],
		'firstname'   => (string) $athlet['firstname'],
		'born'        => (int) $athlet['born'],
		'ak'          => '',
		'time'        => '',
	);

	$zeilen = array(
		lsg_bl_log_manuell_zeile( $basis, $aktion, '', $meldung, 0, '' ),
	);

	foreach ( $ak_zeilen as $a ) {
		$zeilen[] = lsg_bl_log_manuell_zeile(
			array_merge(
				$basis,
				array(
					'ak'   => (string) $a['ak_neu'],
					'time' => (string) $a['time'],
				)
			),
			'ak_update',
			'',
			sprintf( '%s → %s', $a['ak_alt'], $a['ak_neu'] ),
			(int) $a['id'],
			''
		);
	}

	$bilanz = array(
		'angelegt'     => ( 'athlet_insert' === $aktion ) ? 1 : 0,
		'aktualisiert' => ( 'athlet_update' === $aktion ) ? 1 : 0,
	);

	/*
	 * ⚠ `datum`, `jahr`, `distanz` und `ort` bleiben leer: ein Sportler hat
	 * kein Veranstaltungsdatum. Der Trichter aus 6.5 bleibt wie in 7.5 auf 0 –
	 * eine 1 sähe aus, als wäre etwas gelesen und gefiltert worden.
	 */
	return lsg_bl_log_manuell(
		array(
			'datum'   => '',
			'jahr'    => 0,
			'distanz' => '',
			'ort'     => '',
		),
		$bilanz,
		$zeilen
	);
}

/**
 * Löschen (Plan 11.3).
 *
 * @return void
 */
function lsg_bl_admin_athlet_loeschen_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_athlet_loeschen' );

	$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$row = $id > 0 ? lsg_bl_athlet( $id ) : null;

	if ( ! $row ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Diesen Sportler gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_athlet_url() );
		exit;
	}

	/*
	 * ⚠ Noch einmal zählen, unmittelbar vor dem Löschen. Zwischen der
	 * Rückfrage und diesem Klick kann ein Import gelaufen sein – dann gehört
	 * die Zeile jemandem, und der Löschknopf war eine Momentaufnahme.
	 */
	$ref = lsg_bl_athlet_referenzen( $id );
	if ( $ref['gesamt'] > 0 ) {
		lsg_bl_admin_notice_setzen(
			'error',
			sprintf(
				/* translators: 1: Name, 2: Anzahl */
				__( 'Nicht gelöscht: an %1$s hängen inzwischen %2$d Einträge. Wer nicht mehr im Verein ist, wird auf „ehemalig" gesetzt.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $row ),
				(int) $ref['gesamt']
			)
		);
		wp_safe_redirect( lsg_bl_athlet_url( array( 'action' => 'edit', 'id' => $id ) ) );
		exit;
	}

	// ⚠ Zuerst protokollieren, dann löschen. Andersherum ginge der Datensatz
	// verloren, sobald zwischen beiden Schritten etwas schiefgeht.
	/*
	 * ⚠ Die Meldung trägt, was die Rohfelder nicht tragen: Geschlecht und
	 * Status. Name und Jahrgang stehen ohnehin in eigenen Spalten – stünde
	 * hier „Sportler gelöscht", stünde es zweimal in derselben Zeile, und der
	 * vollständige Datensatz wäre er trotzdem nicht (11.3).
	 */
	lsg_bl_athlet_protokollieren(
		'athlet_delete',
		array(
			'id'        => $id,
			'name'      => $row['name'],
			'firstname' => $row['firstname'],
			'born'      => (int) $row['born'],
		),
		sprintf(
			/* translators: 1: Geschlecht, 2: Status */
			__( '%1$s, %2$s', 'lsg-bestenliste' ),
			'f' === strtolower( (string) $row['cat'] )
				? __( 'weiblich', 'lsg-bestenliste' )
				: __( 'männlich', 'lsg-bestenliste' ),
			'1' === (string) $row['active']
				? __( 'aktiv', 'lsg-bestenliste' )
				: __( 'ehemalig', 'lsg-bestenliste' )
		),
		array()
	);

	$res = lsg_bl_athlet_loeschen( $id );

	if ( $res['ok'] ) {
		lsg_bl_admin_notice_setzen(
			'success',
			sprintf(
				/* translators: %s: Name, Vorname (Jahrgang) */
				__( '%s gelöscht. Der vollständige Datensatz steht im Import-Log.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $row )
			)
		);
	} else {
		lsg_bl_admin_notice_setzen(
			'error',
			sprintf(
				/* translators: %s: Fehlertext der Datenbank */
				__( 'Nicht gelöscht: %s', 'lsg-bestenliste' ),
				$res['fehler']
			)
		);
	}

	wp_safe_redirect( lsg_bl_athlet_url() );
	exit;
}
add_action( 'admin_post_lsg_bl_athlet_loeschen', 'lsg_bl_admin_athlet_loeschen_post' );

/* -------------------------------------------------------------------------
 * Die Seite
 * ---------------------------------------------------------------------- */

/**
 * Der Einstieg – Liste, Formular oder Rückfrage.
 *
 * @return void
 */
function lsg_bl_admin_athlet_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	echo '<div class="wrap lsg-bl-athlet" id="lsg-bl-athlet">';

	$notice = lsg_bl_admin_notice_holen();
	if ( $notice ) {
		lsg_bl_admin_notice( $notice['typ'], $notice['text'] );
	}

	if ( 'new' === $action || 'edit' === $action ) {
		lsg_bl_athlet_formular_anzeigen( $action );
	} elseif ( 'delete' === $action ) {
		lsg_bl_athlet_loeschen_rueckfrage();
	} else {
		lsg_bl_athlet_liste_anzeigen();
	}

	echo '</div>';
}

/**
 * Die Liste (Plan 11.3).
 *
 * @return void
 */
function lsg_bl_athlet_liste_anzeigen() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$filter = lsg_bl_athlet_filter(
		array(
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'aktiv',
			'geschlecht' => isset( $_GET['geschlecht'] ) ? sanitize_text_field( wp_unslash( $_GET['geschlecht'] ) ) : '',
			's'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name',
			'order'      => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',
		)
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Sportler', 'lsg-bestenliste' ) );
	printf(
		' <a href="%1$s" class="page-title-action">%2$s</a>',
		esc_url( lsg_bl_athlet_url( array( 'action' => 'new' ) ) ),
		esc_html__( 'Sportler anlegen', 'lsg-bestenliste' )
	);
	echo '<hr class="wp-header-end">';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Wer hier fehlt, ist für den Import unsichtbar: eine Ergebniszeile wird über Name und Jahrgang zugeordnet, und angelegt wird ein Sportler ausschließlich hier.',
			'lsg-bestenliste'
		)
	);

	// ⚠ Erst hier laden, nicht beim Laden des Plugins (siehe Kopf der Klasse).
	require_once LSG_BL_PATH . 'includes/admin/class-lsg-athlet-table.php';

	$tabelle = new LSG_BL_Athlet_Table( $filter );
	$tabelle->prepare_items();

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-athleten' ) );
	lsg_bl_athlet_filterleiste( $filter );
	$tabelle->search_box( __( 'Sportler suchen', 'lsg-bestenliste' ), 'lsg-bl-athlet-suche' );
	echo '</form>';

	echo '<form method="get">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-athleten' ) );
	foreach ( array( 'status', 'geschlecht', 's', 'orderby', 'order' ) as $k ) {
		if ( '' !== (string) $filter[ $k ] ) {
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
function lsg_bl_athlet_filterleiste( array $filter ) {
	echo '<div class="lsg-bl-bestfilter">';

	echo '<label>' . esc_html__( 'Status', 'lsg-bestenliste' ) . ' ';
	echo '<select name="status">';
	$stufen = array(
		'aktiv'    => __( 'aktiv', 'lsg-bestenliste' ),
		'ehemalig' => __( 'ehemalig', 'lsg-bestenliste' ),
		'alle'     => __( 'alle', 'lsg-bestenliste' ),
	);
	foreach ( $stufen as $wert => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $wert ),
			selected( $filter['status'], $wert, false ),
			esc_html( $label )
		);
	}
	echo '</select></label> ';

	echo '<label>' . esc_html__( 'Geschlecht', 'lsg-bestenliste' ) . ' ';
	echo '<select name="geschlecht">';
	printf( '<option value="">%s</option>', esc_html__( 'alle', 'lsg-bestenliste' ) );
	printf( '<option value="m"%s>%s</option>', selected( $filter['geschlecht'], 'm', false ), esc_html__( 'männlich', 'lsg-bestenliste' ) );
	printf( '<option value="f"%s>%s</option>', selected( $filter['geschlecht'], 'f', false ), esc_html__( 'weiblich', 'lsg-bestenliste' ) );
	echo '</select></label> ';

	printf( '<button class="button">%s</button>', esc_html__( 'Filtern', 'lsg-bestenliste' ) );

	echo '</div>';
}

/**
 * Die Rückfrage vor dem Löschen (Plan 11.3).
 *
 * ⚠ Zwei Schritte, und der zweite ist ein POST – wie in 7.4. Ein Löschlink,
 * den ein Crawler oder ein Prefetch anfassen kann, ist keiner.
 *
 * @return void
 */
function lsg_bl_athlet_loeschen_rueckfrage() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$row = $id > 0 ? lsg_bl_athlet( $id ) : null;

	printf( '<h1>%s</h1>', esc_html__( 'Sportler löschen', 'lsg-bestenliste' ) );

	if ( ! $row ) {
		lsg_bl_admin_notice( 'error', __( 'Diesen Sportler gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( lsg_bl_athlet_url() ),
			esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
		);
		return;
	}

	$ref = lsg_bl_athlet_referenzen( $id );

	echo '<div class="lsg-bl-loeschen">';

	echo '<table class="widefat striped" style="max-width:40em"><tbody>';
	$felder = array(
		__( 'Nachname', 'lsg-bestenliste' )   => $row['name'],
		__( 'Vorname', 'lsg-bestenliste' )    => $row['firstname'],
		__( 'Jahrgang', 'lsg-bestenliste' )   => (int) $row['born'],
		__( 'Geschlecht', 'lsg-bestenliste' ) => 'f' === strtolower( (string) $row['cat'] )
			? __( 'weiblich', 'lsg-bestenliste' )
			: __( 'männlich', 'lsg-bestenliste' ),
		__( 'Status', 'lsg-bestenliste' )     => '1' === (string) $row['active']
			? __( 'aktiv', 'lsg-bestenliste' )
			: __( 'ehemalig', 'lsg-bestenliste' ),
	);
	foreach ( $felder as $label => $wert ) {
		printf(
			'<tr><th scope="row" style="width:10em">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( (string) $wert )
		);
	}
	echo '</tbody></table>';

	/* ---- Hängt etwas daran? ---- */
	if ( $ref['gesamt'] > 0 ) {
		lsg_bl_athlet_referenzen_erklaeren( $id, $row, $ref );
		echo '</div>';
		return;
	}

	printf(
		'<p>%s</p>',
		esc_html__( 'An diesem Sportler hängt keine Ergebniszeile, kein Gesamtsieg und keine Zuordnungsregel. Er kann weg.', 'lsg-bestenliste' )
	);
	printf(
		'<p class="lsg-bl-loeschen-hinweis">%s</p>',
		esc_html__(
			'Rückgängig geht das hier nicht. Der vollständige Datensatz wird ins Log geschrieben – von dort lässt er sich neu eintippen.',
			'lsg-bestenliste'
		)
	);

	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_athlet_loeschen' );
	echo '<input type="hidden" name="action" value="lsg_bl_athlet_loeschen">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $id );
	printf(
		'<button class="button button-primary">%s</button> <a class="button" href="%s">%s</a>',
		esc_html__( 'Endgültig löschen', 'lsg-bestenliste' ),
		esc_url( lsg_bl_athlet_url() ),
		esc_html__( 'Abbrechen', 'lsg-bestenliste' )
	);
	echo '</form>';
	echo '</div>';
}

/**
 * Warum hier nicht gelöscht wird, und was stattdessen geht (Plan 11.3).
 *
 * @param int   $id     lsg_athlete.id.
 * @param array $row    Die Zeile.
 * @param array $ref    Ergebnis von lsg_bl_athlet_referenzen().
 * @return void
 */
function lsg_bl_athlet_referenzen_erklaeren( $id, array $row, array $ref ) {
	lsg_bl_admin_notice(
		'warning',
		__( 'Dieser Sportler lässt sich nicht löschen – an ihm hängen Einträge, die ohne ihn niemandem mehr gehören.', 'lsg-bestenliste' )
	);

	echo '<ul class="lsg-bl-referenzen">';

	if ( $ref['best'] > 0 ) {
		printf(
			'<li>%1$s <a href="%2$s">%3$s</a></li>',
			esc_html(
				sprintf(
					/* translators: %d: Anzahl */
					_n( '%d Zeile in der Bestenliste.', '%d Zeilen in der Bestenliste.', $ref['best'], 'lsg-bestenliste' ),
					$ref['best']
				)
			),
			esc_url( lsg_bl_best_url( array( 'athlet' => (int) $id ) ) ),
			esc_html__( 'ansehen', 'lsg-bestenliste' )
		);
	}
	if ( $ref['win'] > 0 ) {
		printf(
			'<li>%s</li>',
			esc_html(
				sprintf(
					/* translators: %d: Anzahl */
					_n( '%d Gesamtsieg.', '%d Gesamtsiege.', $ref['win'], 'lsg-bestenliste' ),
					$ref['win']
				)
			)
		);
	}
	if ( $ref['map'] > 0 ) {
		printf(
			'<li>%1$s <a href="%2$s">%3$s</a></li>',
			esc_html(
				sprintf(
					/* translators: %d: Anzahl */
					_n( '%d Zuordnungsregel.', '%d Zuordnungsregeln.', $ref['map'], 'lsg-bestenliste' ),
					$ref['map']
				)
			),
			esc_url( add_query_arg( array( 'page' => 'lsg-bestenliste-map' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'ansehen', 'lsg-bestenliste' )
		);
	}

	echo '</ul>';

	printf(
		'<p>%s</p>',
		esc_html__(
			'Wer nicht mehr im Verein ist, wird auf „ehemalig" gesetzt: er verschwindet aus den Auswahllisten, seine Zeiten bleiben in der Bestenliste stehen. Vereinsgeschichte verschwindet nicht, weil jemand den Verein verlässt.',
			'lsg-bestenliste'
		)
	);

	printf(
		'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
		esc_url( lsg_bl_athlet_url( array( 'action' => 'edit', 'id' => (int) $id ) ) ),
		esc_html__( 'Sportler bearbeiten', 'lsg-bestenliste' ),
		esc_url( lsg_bl_athlet_url() ),
		esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
	);
}

/**
 * Das Formular (Plan 11.2).
 *
 * @param string $action new | edit.
 * @return void
 */
function lsg_bl_athlet_formular_anzeigen( $action ) {
	$w     = lsg_bl_athlet_felder_leer();
	$zeile = null;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( 'edit' === $action ) {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$zeile = $id > 0 ? lsg_bl_athlet( $id ) : null;

		if ( ! $zeile ) {
			printf( '<h1>%s</h1>', esc_html__( 'Sportler bearbeiten', 'lsg-bestenliste' ) );
			lsg_bl_admin_notice( 'error', __( 'Diesen Sportler gibt es nicht (mehr).', 'lsg-bestenliste' ) );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( lsg_bl_athlet_url() ),
				esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
			);
			return;
		}

		$w = array(
			'id'        => (int) $zeile['id'],
			'name'      => (string) $zeile['name'],
			'firstname' => (string) $zeile['firstname'],
			'born'      => (int) $zeile['born'],
			'cat'       => (string) $zeile['cat'],
			'active'    => (string) $zeile['active'],
		);
	}

	/*
	 * ⚠ Die Query sticht die gespeicherte Zeile aus – und entscheidend ist,
	 * ob der Parameter DA ist, nicht ob er einen Wert hat. Sonst wäre ein
	 * absichtlich geleertes Feld von einem nicht angefassten nicht zu
	 * unterscheiden (dieselbe Regel wie in 6.5.1 und 7.2).
	 */
	$angefasst = false;
	foreach ( array( 'name', 'firstname', 'born', 'cat', 'status' ) as $k ) {
		if ( ! isset( $_GET[ $k ] ) ) {
			continue;
		}
		$angefasst = true;
		$roh       = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );

		if ( 'born' === $k ) {
			$w['born'] = (int) $roh;
		} elseif ( 'status' === $k ) {
			$w['active'] = ( 'ehemalig' === $roh ) ? '0' : '1';
		} else {
			$w[ $k ] = $roh;
		}
	}

	$akmit = isset( $_GET['akmit'] ) ? ( '1' === (string) $_GET['akmit'] ) : null;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$jahr_max = (int) gmdate( 'Y', time() );
	$p        = lsg_bl_athlet_formular_pruefen( $w, $jahr_max );
	$werte    = $p['werte'];

	// Rot nur, wenn jemand etwas eingegeben oder eine Zeile geöffnet hat –
	// ein frisches leeres Formular ist nicht falsch, nur leer.
	$fehler = ( $angefasst || null !== $zeile ) ? $p['fehler'] : array();

	$dublette = null;
	if ( ! isset( $fehler['name'] ) && ! isset( $fehler['firstname'] ) && ! isset( $fehler['born'] ) ) {
		$dublette = lsg_bl_athlet_dublette( $werte['name'], $werte['firstname'], $werte['born'], (int) $werte['id'] );
	}

	printf(
		'<h1>%s</h1>',
		esc_html(
			'edit' === $action
				? __( 'Sportler bearbeiten', 'lsg-bestenliste' )
				: __( 'Sportler anlegen', 'lsg-bestenliste' )
		)
	);

	if ( $dublette ) {
		lsg_bl_admin_notice(
			'error',
			sprintf(
				/* translators: 1: Name, Vorname (Jahrgang), 2: id */
				__( '%1$s steht schon in der Liste (#%2$d). Zwei Sportler mit gleichem Namen und gleichem Jahrgang machen den Import für diesen Namen blind – gespeichert wird das nicht.', 'lsg-bestenliste' ),
				lsg_bl_athlet_label( $dublette ),
				(int) $dublette['id']
			)
		);
	}

	printf( '<form method="post" action="%s" class="lsg-bl-athletform">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_athlet' );
	echo '<input type="hidden" name="action" value="lsg_bl_athlet_speichern">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $werte['id'] );

	echo '<table class="form-table" role="presentation"><tbody>';

	lsg_bl_formularzeile(
		__( 'Nachname', 'lsg-bestenliste' ),
		'lsg-bl-name',
		isset( $fehler['name'] ) ? $fehler['name'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="name" id="lsg-bl-name" value="%s" maxlength="30" class="regular-text" required>',
				esc_attr( $werte['name'] )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Vorname', 'lsg-bestenliste' ),
		'lsg-bl-firstname',
		isset( $fehler['firstname'] ) ? $fehler['firstname'] : '',
		function () use ( $werte ) {
			printf(
				'<input type="text" name="firstname" id="lsg-bl-firstname" value="%s" maxlength="30" class="regular-text" required>',
				esc_attr( $werte['firstname'] )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Jahrgang', 'lsg-bestenliste' ),
		'lsg-bl-born',
		isset( $fehler['born'] ) ? $fehler['born'] : '',
		function () use ( $werte, $jahr_max ) {
			printf(
				'<input type="number" name="born" id="lsg-bl-born" value="%1$s" min="1900" max="%2$d" step="1" class="small-text" required>',
				$werte['born'] > 0 ? esc_attr( (string) (int) $werte['born'] ) : '',
				(int) $jahr_max
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Pflicht: der Import ordnet über Name und Jahrgang zu, und die Altersklasse wird daraus gerechnet.', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Geschlecht', 'lsg-bestenliste' ),
		'lsg-bl-cat-m',
		'',
		function () use ( $werte ) {
			printf(
				'<label><input type="radio" name="cat" id="lsg-bl-cat-m" value="m"%s> %s</label> ',
				checked( $werte['cat'], 'm', false ),
				esc_html__( 'männlich', 'lsg-bestenliste' )
			);
			printf(
				'<label><input type="radio" name="cat" value="f"%s> %s</label>',
				checked( $werte['cat'], 'f', false ),
				esc_html__( 'weiblich', 'lsg-bestenliste' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Entscheidet über das m/w der Altersklasse und über den Filter im Frontend.', 'lsg-bestenliste' )
			);
		}
	);

	lsg_bl_formularzeile(
		__( 'Status', 'lsg-bestenliste' ),
		'lsg-bl-status-aktiv',
		'',
		function () use ( $werte ) {
			printf(
				'<label><input type="radio" name="status" id="lsg-bl-status-aktiv" value="aktiv"%s> %s</label> ',
				checked( $werte['active'], '1', false ),
				esc_html__( 'aktiv', 'lsg-bestenliste' )
			);
			printf(
				'<label><input type="radio" name="status" value="ehemalig"%s> %s</label>',
				checked( $werte['active'], '0', false ),
				esc_html__( 'ehemalig', 'lsg-bestenliste' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( '„Ehemalig" nimmt den Sportler aus den Auswahllisten. Seine Zeiten bleiben in der Bestenliste stehen.', 'lsg-bestenliste' )
			);
		}
	);

	echo '</tbody></table>';

	/* ---- Altersklassen, die nicht mehr passen (11.2) ---- */
	$abw     = array();
	$geaendert = false;
	if ( $zeile ) {
		$abw       = lsg_bl_athlet_ak_abweichungen(
			lsg_bl_athlet_best_alle( (int) $zeile['id'] ),
			$werte['born'],
			$werte['cat']
		);
		$geaendert = ( (int) $zeile['born'] !== (int) $werte['born'] )
			|| ( strtolower( (string) $zeile['cat'] ) !== strtolower( (string) $werte['cat'] ) );
	}

	if ( $abw ) {
		lsg_bl_athlet_ak_block( $abw, ( null === $akmit ) ? $geaendert : $akmit, $geaendert );
	}

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
		esc_url( lsg_bl_athlet_url() ),
		esc_html__( 'Abbrechen', 'lsg-bestenliste' )
	);

	echo '</form>';

	/* ---- Löschen, aber nur wenn nichts daranhängt (11.3) ---- */
	if ( $zeile ) {
		$ref = lsg_bl_athlet_referenzen( (int) $zeile['id'] );

		if ( 0 === $ref['gesamt'] ) {
			printf(
				'<p class="lsg-bl-loeschlink"><a href="%1$s">%2$s</a></p>',
				esc_url( lsg_bl_athlet_url( array( 'action' => 'delete', 'id' => (int) $zeile['id'] ) ) ),
				esc_html__( 'Diesen Sportler löschen', 'lsg-bestenliste' )
			);
		} else {
			printf(
				'<p class="description lsg-bl-nichtloeschbar">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html(
					sprintf(
						/* translators: %d: Anzahl */
						_n(
							'Nicht löschbar: %d Eintrag hängt an diesem Sportler.',
							'Nicht löschbar: %d Einträge hängen an diesem Sportler.',
							$ref['gesamt'],
							'lsg-bestenliste'
						),
						$ref['gesamt']
					)
				),
				esc_url( lsg_bl_athlet_url( array( 'action' => 'delete', 'id' => (int) $zeile['id'] ) ) ),
				esc_html__( 'was genau?', 'lsg-bestenliste' )
			);
		}
	}
}

/**
 * Die Ergebniszeilen, deren Altersklasse nicht mehr passt (Plan 11.2).
 *
 * ⚠ Der Haken ist eine zweite Entscheidung, keine Bedingung: ohne ihn wird
 * der Sportler trotzdem gespeichert. Sonst hinge die Korrektur eines
 * Tippfehlers im Jahrgang an einer Liste, die gerade niemand durchsehen will –
 * und der Tippfehler bliebe stehen.
 *
 * @param array $abw       Ergebnis von lsg_bl_athlet_ak_abweichungen().
 * @param bool  $vorhaken  Haken setzen?
 * @param bool  $geaendert Hat sich Jahrgang oder Geschlecht geändert?
 * @return void
 */
function lsg_bl_athlet_ak_block( array $abw, $vorhaken, $geaendert ) {
	$n = count( $abw );

	echo '<div class="lsg-bl-vergleich lsg-bl-vergleich-' . ( $geaendert ? 'besser' : 'schlechter' ) . '">';

	printf( '<h2>%s</h2>', esc_html__( 'Altersklassen im Bestand', 'lsg-bestenliste' ) );

	printf(
		'<p class="lsg-bl-vergleich-text">%s</p>',
		esc_html(
			$geaendert
				? sprintf(
					/* translators: %d: Anzahl */
					_n(
						'%d Ergebniszeile trägt mit den neuen Angaben eine andere Altersklasse als gespeichert:',
						'%d Ergebniszeilen tragen mit den neuen Angaben eine andere Altersklasse als gespeichert:',
						$n,
						'lsg-bestenliste'
					),
					$n
				)
				: sprintf(
					/* translators: %d: Anzahl */
					_n(
						'%d Ergebniszeile trägt schon jetzt eine Altersklasse, die nicht zu Jahrgang und Veranstaltungsjahr passt:',
						'%d Ergebniszeilen tragen schon jetzt eine Altersklasse, die nicht zu Jahrgang und Veranstaltungsjahr passt:',
						$n,
						'lsg-bestenliste'
					),
					$n
				)
		)
	);

	echo '<table class="widefat striped lsg-bl-aktabelle"><thead><tr>';
	printf( '<th>%s</th>', esc_html__( 'Jahr', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'Distanz', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'Leistung', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'Altersklasse', 'lsg-bestenliste' ) );
	echo '</tr></thead><tbody>';

	foreach ( $abw as $a ) {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s &rarr; <strong>%5$s</strong></td></tr>',
			esc_html( (string) $a['jahr'] ),
			esc_html( lsg_bl_distance_label( $a['distance'] ) ),
			esc_html( $a['time'] ),
			esc_html( $a['ak_alt'] ),
			esc_html( $a['ak_neu'] )
		);
	}

	echo '</tbody></table>';

	printf(
		'<p><label><input type="checkbox" name="akmit" value="1"%1$s> %2$s</label></p>',
		checked( (bool) $vorhaken, true, false ),
		esc_html(
			1 === $n
				? __( 'Die Altersklasse mitschreiben', 'lsg-bestenliste' )
				: sprintf(
					/* translators: %d: Anzahl */
					__( 'Die %d Altersklassen mitschreiben', 'lsg-bestenliste' ),
					$n
				)
		)
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Geändert wird ausschließlich die Altersklasse. Zeit, Ort und Datum bleiben. Ohne Haken wird der Sportler trotzdem gespeichert – die Zeilen bleiben dann, wie sie sind.',
			'lsg-bestenliste'
		)
	);

	echo '</div>';
}
