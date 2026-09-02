<?php
/**
 * Admin-Seite „Zuordnungen": die Regeln aus `lsg_athlete_map` pflegen
 * (Plan 6.2, 6.5.3).
 *
 * Wozu die Seite da ist: P3 ordnet eine Ergebniszeile in drei Stufen einem
 * Athleten zu – exakter Treffer, Regel, normalisierter Name. Die zweite
 * Stufe ist die einzige, die ein Mensch beeinflussen kann, und sie ist die
 * Antwort auf zwei Lagen, die in echten Ergebnislisten dauernd vorkommen:
 * eine Schreibweise, die es in `lsg_athlete` nicht gibt („Harry" statt
 * „Harald"), und eine Quelle, die Vor- und Nachname vertauscht.
 *
 * ⚠ Diese Seite ist der Ort, an dem eine Regelkollision SICHTBAR wird. Zwei
 * Regeln, die dieselbe Zeile treffen können, sind ein Fehler und keine
 * Auswahlfrage (6.5.3): beim Import bliebe die Zeile `offen`. Wer das erst
 * beim Import merkt, merkt es im falschen Moment.
 *
 * ⚠ Und hier wird kein Athlet angelegt. Eine Regel zeigt auf einen
 * vorhandenen Datensatz; fehlt er, gehört er ins Untermenü „Sportler"
 * (Phase 4).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eine Adresse dieser Seite bauen.
 *
 * @param array $args Query-Argumente.
 * @return string
 */
function lsg_bl_map_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v && '0' !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste-map';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/* -------------------------------------------------------------------------
 * Handler
 * ---------------------------------------------------------------------- */

/**
 * Regel anlegen oder ändern.
 *
 * @return void
 */
function lsg_bl_admin_map_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_map' );

	$eingabe = array(
		'id'          => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
		'athletes_id' => isset( $_POST['athletes_id'] ) ? (int) $_POST['athletes_id'] : 0,
		'born'        => isset( $_POST['born'] ) ? (int) $_POST['born'] : 0,
		'vorname'     => isset( $_POST['vorname'] ) ? sanitize_text_field( wp_unslash( $_POST['vorname'] ) ) : '',
		'nachname'    => isset( $_POST['nachname'] ) ? sanitize_text_field( wp_unslash( $_POST['nachname'] ) ) : '',
		'modus'       => isset( $_POST['modus'] ) ? sanitize_key( wp_unslash( $_POST['modus'] ) ) : 'feld',
		'aktiv'       => ! empty( $_POST['aktiv'] ),
		'notiz'       => isset( $_POST['notiz'] ) ? sanitize_text_field( wp_unslash( $_POST['notiz'] ) ) : '',
	);

	$athlet = $eingabe['athletes_id'] > 0 ? lsg_bl_athlet( $eingabe['athletes_id'] ) : null;
	$p      = lsg_bl_map_pruefen( $eingabe, $athlet );

	$ziel = array(
		'action'      => $eingabe['id'] > 0 ? 'edit' : 'new',
		'id'          => $eingabe['id'],
		'athletes_id' => $eingabe['athletes_id'],
		'born'        => $eingabe['born'],
		'vorname'     => $eingabe['vorname'],
		'nachname'    => $eingabe['nachname'],
		'modus'       => $eingabe['modus'],
		'aktiv'       => $eingabe['aktiv'] ? '1' : '',
		'notiz'       => $eingabe['notiz'],
	);

	if ( ! $p['ok'] ) {
		lsg_bl_admin_notice_setzen(
			'error',
			__( 'Nichts gespeichert – bitte die markierten Felder ansehen.', 'lsg-bestenliste' )
		);
		wp_safe_redirect( lsg_bl_map_url( $ziel ) );
		exit;
	}

	$res = lsg_bl_map_speichern( $p['werte'] );

	if ( ! $res['ok'] ) {
		lsg_bl_admin_notice_setzen( 'error', $res['fehler'] );
		wp_safe_redirect( lsg_bl_map_url( $ziel ) );
		exit;
	}

	// ⚠ Nach dem Speichern wird auf Kollisionen geprüft und, wenn es welche
	// gibt, sofort gewarnt. Sonst erfährt man von der Kollision beim nächsten
	// Import – an einer Zeile, die dann unerklärlich `offen` bleibt.
	$kollisionen = lsg_bl_map_kollisionen( lsg_bl_map_alle() );
	if ( isset( $kollisionen[ $res['id'] ] ) ) {
		lsg_bl_admin_notice_setzen(
			'warning',
			sprintf(
				/* translators: 1: eigene id, 2: Liste der IDs */
				__( 'Regel #%1$d gespeichert – aber sie kollidiert mit #%2$s. Beide könnten dieselbe Ergebniszeile treffen; beim Import bleibt so eine Zeile dann unzugeordnet. Bitte eine der beiden abschalten oder enger fassen.', 'lsg-bestenliste' ),
				(int) $res['id'],
				implode( ', #', $kollisionen[ $res['id'] ] )
			)
		);
	} else {
		lsg_bl_admin_notice_setzen(
			'success',
			sprintf(
				/* translators: %d: id */
				__( 'Regel #%d gespeichert.', 'lsg-bestenliste' ),
				(int) $res['id']
			)
		);
	}

	wp_safe_redirect( lsg_bl_map_url() );
	exit;
}

/**
 * Regel abschalten oder wieder einschalten.
 *
 * ⚠ Eigener Handler statt eines Feldes im Formular: das Abschalten ist die
 * vorgesehene Antwort auf eine Kollision (6.5.3), und dafür soll ein Klick
 * genügen und nicht ein Formular-Durchlauf.
 *
 * @return void
 */
function lsg_bl_admin_map_schalten_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_map_schalten' );

	$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$regel = lsg_bl_map_zeile( $id );

	if ( ! $regel ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Diese Regel gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_map_url() );
		exit;
	}

	$neu           = empty( $regel['aktiv'] ) ? 1 : 0;
	$regel['aktiv'] = $neu;

	$res = lsg_bl_map_speichern( $regel );

	if ( ! $res['ok'] ) {
		lsg_bl_admin_notice_setzen( 'error', $res['fehler'] );
	} else {
		lsg_bl_admin_notice_setzen(
			'success',
			$neu
				? sprintf( __( 'Regel #%d ist wieder aktiv.', 'lsg-bestenliste' ), $id )
				: sprintf( __( 'Regel #%d ist abgeschaltet. Sie bleibt stehen und greift beim nächsten Import nicht mehr.', 'lsg-bestenliste' ), $id )
		);
	}

	wp_safe_redirect( lsg_bl_map_url() );
	exit;
}

/**
 * Regel löschen.
 *
 * @return void
 */
function lsg_bl_admin_map_loeschen_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_map_loeschen' );

	$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$regel = lsg_bl_map_zeile( $id );

	if ( ! $regel ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Diese Regel gibt es nicht (mehr).', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_map_url() );
		exit;
	}

	if ( ! lsg_bl_map_loeschen( $id ) ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Die Regel ließ sich nicht löschen.', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_map_url() );
		exit;
	}

	// ⚠ Eine gelöschte Regel ändert nichts an bereits importierten Zeilen –
	// die stehen in `lsg_best` und bleiben. Nur künftige Importe ordnen
	// wieder anders zu. Der Satz steht hier, weil die Erwartung oft die
	// umgekehrte ist.
	lsg_bl_admin_notice_setzen(
		'success',
		sprintf(
			/* translators: 1: id, 2: Regeltext */
			__( 'Regel #%1$d gelöscht (%2$s). Bereits importierte Ergebnisse bleiben, wie sie sind – nur künftige Importe ordnen ohne diese Regel zu.', 'lsg-bestenliste' ),
			$id,
			lsg_bl_map_regeltext( $regel )
		)
	);

	wp_safe_redirect( lsg_bl_map_url() );
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
function lsg_bl_admin_map_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

	echo '<div class="wrap lsg-bl-map">';

	$notice = lsg_bl_admin_notice_holen();
	if ( $notice ) {
		lsg_bl_admin_notice( $notice['typ'], $notice['text'] );
	}

	if ( 'new' === $action || 'edit' === $action ) {
		lsg_bl_map_formular_anzeigen( $action );
	} else {
		lsg_bl_map_liste_anzeigen();
	}

	echo '</div>';
}

/**
 * Eine Regel als Satz, wie sie in der Tabelle steht.
 *
 * @param array $r Zeile aus lsg_athlete_map.
 * @return string
 */
function lsg_bl_map_regeltext( array $r ) {
	$teile = array();

	if ( '' !== (string) $r['nachname'] ) {
		$teile[] = sprintf( 'Nachname „%s"', $r['nachname'] );
	}
	if ( '' !== (string) $r['vorname'] ) {
		$teile[] = sprintf( 'Vorname „%s"', $r['vorname'] );
	}
	if ( (int) $r['born'] > 0 ) {
		$teile[] = sprintf( 'Jahrgang %d', (int) $r['born'] );
	}

	if ( ! $teile ) {
		return 'leere Regel';
	}

	$satz = implode( ', ', $teile );

	if ( 'egal' === $r['modus'] ) {
		$satz .= ' (egal in welchem Feld)';
	}

	return $satz;
}

/**
 * Die Liste der Regeln.
 *
 * @return void
 */
function lsg_bl_map_liste_anzeigen() {
	$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$regeln = lsg_bl_map_alle( $s );

	// Die Kollisionsprüfung läuft immer über ALLE Regeln, nicht über die
	// gefilterte Liste: sonst verschwindet eine Kollision, sobald man sucht.
	$kollisionen = lsg_bl_map_kollisionen( lsg_bl_map_alle() );
	$treffer     = lsg_bl_map_treffer();

	printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Zuordnungen', 'lsg-bestenliste' ) );
	printf(
		' <a href="%1$s" class="page-title-action">%2$s</a>',
		esc_url( lsg_bl_map_url( array( 'action' => 'new' ) ) ),
		esc_html__( 'Regel anlegen', 'lsg-bestenliste' )
	);
	echo '<hr class="wp-header-end">';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Eine Regel sagt: „wenn in einer Ergebnisliste dieser Name mit diesem Jahrgang steht, ist das dieser Sportler." Gebraucht wird sie für Schreibweisen, die es in den Stammdaten nicht gibt – „Harry" statt „Harald" –, und für Quellen, die Vor- und Nachname vertauschen.',
			'lsg-bestenliste'
		)
	);

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'Regeln greifen erst nach dem exakten Namenstreffer und nur auf Zeilen, die den LSG-Filter passiert haben. Wo der Name ohnehin stimmt, kommt keine Regel zum Zug.',
			'lsg-bestenliste'
		)
	);

	if ( $kollisionen ) {
		lsg_bl_admin_notice(
			'warning',
			sprintf(
				/* translators: %d: Anzahl */
				_n(
					'%d Regel kann sich mit einer anderen ins Gehege kommen – die betroffenen Zeilen sind markiert.',
					'%d Regeln können sich mit anderen ins Gehege kommen – die betroffenen Zeilen sind markiert.',
					count( $kollisionen ),
					'lsg-bestenliste'
				),
				count( $kollisionen )
			)
		);
	}

	/* ---- Suche ---- */
	echo '<form method="get" class="lsg-bl-mapsuche">';
	printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'lsg-bestenliste-map' ) );
	printf(
		'<input type="search" name="s" value="%1$s" placeholder="%2$s"> <button class="button">%3$s</button>',
		esc_attr( $s ),
		esc_attr__( 'Name in der Regel oder Sportler', 'lsg-bestenliste' ),
		esc_html__( 'Suchen', 'lsg-bestenliste' )
	);
	if ( '' !== $s ) {
		printf(
			' <a href="%1$s">%2$s</a>',
			esc_url( lsg_bl_map_url() ),
			esc_html__( 'Suche aufheben', 'lsg-bestenliste' )
		);
	}
	echo '</form>';

	if ( ! $regeln ) {
		printf(
			'<p>%s</p>',
			esc_html(
				( '' !== $s )
					? __( 'Keine Regel passt zu dieser Suche.', 'lsg-bestenliste' )
					: __( 'Noch keine Regeln. Der Normalfall – gebraucht werden sie nur, wo eine Ergebnisliste anders schreibt als die Stammdaten.', 'lsg-bestenliste' )
			)
		);
		return;
	}

	echo '<table class="widefat striped lsg-bl-maptabelle"><thead><tr>';
	printf( '<th>%s</th>', esc_html__( 'Wenn in der Liste steht', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'dann ist das', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'Notiz', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'gegriffen', 'lsg-bestenliste' ) );
	printf( '<th>%s</th>', esc_html__( 'Aktion', 'lsg-bestenliste' ) );
	echo '</tr></thead><tbody>';

	foreach ( $regeln as $r ) {
		$id     = (int) $r['id'];
		$aktiv  = ! empty( $r['aktiv'] );
		$kollid = isset( $kollisionen[ $id ] ) ? $kollisionen[ $id ] : array();

		$klassen = array();
		if ( ! $aktiv ) {
			$klassen[] = 'lsg-bl-regel-aus';
		}
		if ( $kollid ) {
			$klassen[] = 'lsg-bl-regel-kollision';
		}

		printf( '<tr class="%s">', esc_attr( implode( ' ', $klassen ) ) );

		/* Regel */
		echo '<td>';
		printf( '<strong>%s</strong>', esc_html( lsg_bl_map_regeltext( $r ) ) );
		printf( ' <span class="lsg-bl-regelid">#%d</span>', $id );
		if ( ! $aktiv ) {
			printf(
				'<br><span class="lsg-bl-ausgeschaltet">%s</span>',
				esc_html__( 'abgeschaltet – greift beim Import nicht', 'lsg-bestenliste' )
			);
		}
		if ( $kollid ) {
			printf(
				'<br><span class="lsg-bl-kollision">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: Liste der IDs */
						__( 'kollidiert mit #%s – beide könnten dieselbe Zeile treffen, dann bleibt sie unzugeordnet', 'lsg-bestenliste' ),
						implode( ', #', $kollid )
					)
				)
			);
		}
		echo '</td>';

		/* Athlet */
		echo '<td>';
		if ( $r['name'] || $r['firstname'] ) {
			printf(
				'%s',
				esc_html(
					lsg_bl_athlet_label(
						array(
							'name'      => $r['name'],
							'firstname' => $r['firstname'],
							'born'      => $r['athlet_born'],
						)
					)
				)
			);
			if ( '1' !== (string) $r['active'] ) {
				printf( ' <span class="lsg-bl-anzahl">%s</span>', esc_html__( '(ehemalig)', 'lsg-bestenliste' ) );
			}
		} else {
			// ⚠ Eine Regel auf einen gelöschten Athleten kann nie greifen und
			// gehört weg. Sie zu verschweigen wäre die schlechteste Reaktion.
			printf(
				'<span class="lsg-bl-kollision">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: athletes_id */
						__( 'Sportler %d gibt es nicht mehr – diese Regel kann nie greifen.', 'lsg-bestenliste' ),
						(int) $r['athletes_id']
					)
				)
			);
		}
		echo '</td>';

		/* Notiz */
		printf( '<td>%s</td>', esc_html( (string) $r['notiz'] ) );

		/* Treffer */
		$n = isset( $treffer[ (int) $r['athletes_id'] ] ) ? (int) $treffer[ (int) $r['athletes_id'] ] : 0;
		printf(
			'<td>%s</td>',
			$n > 0
				? esc_html( sprintf( _n( '%d Zeile', '%d Zeilen', $n, 'lsg-bestenliste' ), $n ) )
				: '<span class="lsg-bl-anzahl">' . esc_html__( 'noch nie', 'lsg-bestenliste' ) . '</span>'
		);

		/* Aktionen */
		echo '<td class="lsg-bl-mapaktionen">';
		printf(
			'<a href="%1$s">%2$s</a> ',
			esc_url( lsg_bl_map_url( array( 'action' => 'edit', 'id' => $id ) ) ),
			esc_html__( 'Bearbeiten', 'lsg-bestenliste' )
		);

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'lsg_bl_map_schalten' );
		echo '<input type="hidden" name="action" value="lsg_bl_map_schalten">';
		printf( '<input type="hidden" name="id" value="%d">', $id );
		printf(
			'<button class="button-link">%s</button>',
			esc_html( $aktiv ? __( 'abschalten', 'lsg-bestenliste' ) : __( 'einschalten', 'lsg-bestenliste' ) )
		);
		echo '</form>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'lsg_bl_map_loeschen' );
		echo '<input type="hidden" name="action" value="lsg_bl_map_loeschen">';
		printf( '<input type="hidden" name="id" value="%d">', $id );
		printf(
			'<button class="button-link lsg-bl-loeschknopf">%s</button>',
			esc_html__( 'löschen', 'lsg-bestenliste' )
		);
		echo '</form>';

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	printf(
		'<p class="description">%s</p>',
		esc_html__(
			'„gegriffen" zählt Log-Zeilen, die über eine Regel zugeordnet wurden – je Sportler, nicht je Regel: das Log führt die Regel-ID nicht mit. Bei einem Sportler mit einer Regel ist die Zahl also genau, bei zwei Regeln auf denselben Sportler die Summe.',
			'lsg-bestenliste'
		)
	);
}

/**
 * Das Regelformular.
 *
 * @param string $action new | edit.
 * @return void
 */
function lsg_bl_map_formular_anzeigen( $action ) {
	$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

	$w = array(
		'id'          => 0,
		'athletes_id' => 0,
		'born'        => 0,
		'vorname'     => '',
		'nachname'    => '',
		'modus'       => 'feld',
		'aktiv'       => true,
		'notiz'       => '',
	);

	$regel = null;
	if ( 'edit' === $action && $id > 0 ) {
		$regel = lsg_bl_map_zeile( $id );
		if ( ! $regel ) {
			printf( '<h1>%s</h1>', esc_html__( 'Regel bearbeiten', 'lsg-bestenliste' ) );
			lsg_bl_admin_notice( 'error', __( 'Diese Regel gibt es nicht (mehr).', 'lsg-bestenliste' ) );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( lsg_bl_map_url() ),
				esc_html__( 'Zurück zur Liste', 'lsg-bestenliste' )
			);
			return;
		}

		$w = array(
			'id'          => $id,
			'athletes_id' => (int) $regel['athletes_id'],
			'born'        => (int) $regel['born'],
			'vorname'     => (string) $regel['vorname'],
			'nachname'    => (string) $regel['nachname'],
			'modus'       => (string) $regel['modus'],
			'aktiv'       => ! empty( $regel['aktiv'] ),
			'notiz'       => (string) $regel['notiz'],
		);
	}

	// Die Query hat Vorrang – nach einem Fehlversuch steht dort die Eingabe.
	foreach ( array( 'athletes_id', 'born', 'vorname', 'nachname', 'modus', 'notiz' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) {
			$roh     = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
			$w[ $k ] = in_array( $k, array( 'athletes_id', 'born' ), true ) ? (int) $roh : $roh;
		}
	}
	if ( isset( $_GET['aktiv'] ) || isset( $_GET['vorname'] ) || isset( $_GET['nachname'] ) ) {
		$w['aktiv'] = ! empty( $_GET['aktiv'] );
	}

	$athlet = $w['athletes_id'] > 0 ? lsg_bl_athlet( $w['athletes_id'] ) : null;

	$angefasst = false;
	foreach ( array( 'athletes_id', 'vorname', 'nachname', 'born', 'modus' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) {
			$angefasst = true;
		}
	}

	$p      = lsg_bl_map_pruefen( $w, $athlet );
	$fehler = $angefasst ? $p['fehler'] : array();

	printf(
		'<h1>%s</h1>',
		esc_html(
			( 'edit' === $action )
				? __( 'Regel bearbeiten', 'lsg-bestenliste' )
				: __( 'Regel anlegen', 'lsg-bestenliste' )
		)
	);

	printf(
		'<p><a href="%1$s">&larr; %2$s</a></p>',
		esc_url( lsg_bl_map_url() ),
		esc_html__( 'zur Liste', 'lsg-bestenliste' )
	);

	printf( '<form method="post" action="%s" class="lsg-bl-mapform">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'lsg_bl_map' );
	echo '<input type="hidden" name="action" value="lsg_bl_map_speichern">';
	printf( '<input type="hidden" name="id" value="%d">', (int) $w['id'] );

	echo '<table class="form-table" role="presentation"><tbody>';

	/* Sportler */
	lsg_bl_best_zeile_auf(
		__( 'Sportler', 'lsg-bestenliste' ),
		'lsg-bl-map-athlet',
		isset( $fehler['athletes_id'] ) ? $fehler['athletes_id'] : '',
		function () use ( $w ) {
			// Dasselbe Select wie im Bestenlisten-Formular – eine
			// Implementierung, nicht zwei.
			lsg_bl_map_athleten_select( (int) $w['athletes_id'] );
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Auf diesen Sportler zeigt die Regel. Fehlt jemand, wird er nicht hier angelegt.', 'lsg-bestenliste' )
			);
		}
	);

	/* Nachname */
	lsg_bl_best_zeile_auf(
		__( 'Nachname in der Liste', 'lsg-bestenliste' ),
		'lsg-bl-map-nachname',
		isset( $fehler['nachname'] ) ? $fehler['nachname'] : '',
		function () use ( $w ) {
			printf(
				'<input type="text" name="nachname" id="lsg-bl-map-nachname" value="%s" class="regular-text">',
				esc_attr( $w['nachname'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Leer lassen heißt „beliebig". Groß- und Kleinschreibung, Umlaute und Bindestriche sind gleichgültig – verglichen wird normalisiert.', 'lsg-bestenliste' )
			);
		}
	);

	/* Vorname */
	lsg_bl_best_zeile_auf(
		__( 'Vorname in der Liste', 'lsg-bestenliste' ),
		'lsg-bl-map-vorname',
		isset( $fehler['vorname'] ) ? $fehler['vorname'] : '',
		function () use ( $w ) {
			printf(
				'<input type="text" name="vorname" id="lsg-bl-map-vorname" value="%s" class="regular-text">',
				esc_attr( $w['vorname'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Der typische Fall: „Harry" für einen Harald. Mindestens eines der beiden Namensfelder muss gefüllt sein.', 'lsg-bestenliste' )
			);
		}
	);

	/* Jahrgang */
	lsg_bl_best_zeile_auf(
		__( 'Jahrgang', 'lsg-bestenliste' ),
		'lsg-bl-map-born',
		isset( $fehler['born'] ) ? $fehler['born'] : '',
		function () use ( $w, $athlet ) {
			printf(
				'<input type="number" name="born" id="lsg-bl-map-born" value="%s" min="1900" max="2100" class="small-text">',
				esc_attr( $w['born'] > 0 ? (string) $w['born'] : '' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Der Jahrgang, den die Ergebnisliste nennt. Leer heißt „beliebig" – dann greift die Regel unabhängig vom Jahrgang, und der Name allein muss eindeutig genug sein.', 'lsg-bestenliste' )
			);
			if ( $athlet && ! empty( $athlet['born'] ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: Jahrgang */
							__( 'Der ausgewählte Sportler hat Jahrgang %d.', 'lsg-bestenliste' ),
							(int) $athlet['born']
						)
					)
				);
			}
		}
	);

	/* Modus */
	lsg_bl_best_zeile_auf(
		__( 'Vergleich', 'lsg-bestenliste' ),
		'lsg-bl-map-modus',
		isset( $fehler['modus'] ) ? $fehler['modus'] : '',
		function () use ( $w ) {
			foreach ( lsg_bl_map_modi() as $key => $text ) {
				printf(
					'<p><label><input type="radio" name="modus" value="%1$s"%2$s> %3$s</label></p>',
					esc_attr( $key ),
					checked( $w['modus'], $key, false ),
					esc_html( $text )
				);
			}
			printf(
				'<p class="description">%s</p>',
				esc_html__( '„egal welches Feld" ist für Quellen, die Vor- und Nachname vertauschen. Es fasst die Regel weiter – im Zweifel feldweise.', 'lsg-bestenliste' )
			);
		}
	);

	/* Notiz */
	lsg_bl_best_zeile_auf(
		__( 'Notiz', 'lsg-bestenliste' ),
		'lsg-bl-map-notiz',
		isset( $fehler['notiz'] ) ? $fehler['notiz'] : '',
		function () use ( $w ) {
			printf(
				'<input type="text" name="notiz" id="lsg-bl-map-notiz" value="%s" maxlength="255" class="large-text">',
				esc_attr( $w['notiz'] )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Warum es diese Regel gibt. In zwei Jahren ist das die einzige Antwort darauf, ob sie noch gebraucht wird.', 'lsg-bestenliste' )
			);
		}
	);

	/* Aktiv */
	lsg_bl_best_zeile_auf(
		__( 'Aktiv', 'lsg-bestenliste' ),
		'lsg-bl-map-aktiv',
		'',
		function () use ( $w ) {
			printf(
				'<label><input type="checkbox" name="aktiv" id="lsg-bl-map-aktiv" value="1"%s> %s</label>',
				checked( $w['aktiv'], true, false ),
				esc_html__( 'Diese Regel beim Import anwenden', 'lsg-bestenliste' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Abgeschaltete Regeln bleiben stehen. Das ist die vorgesehene Antwort auf zwei Regeln, die sich ins Gehege kommen.', 'lsg-bestenliste' )
			);
		}
	);

	echo '</tbody></table>';

	printf(
		'<p class="submit"><button class="button button-primary">%s</button></p>',
		esc_html__( 'Regel speichern', 'lsg-bestenliste' )
	);

	echo '</form>';
}

/**
 * Das Athleten-Select des Regelformulars.
 *
 * Eigene Funktion nur wegen des Feldnamens (`athletes_id` statt `athlet`) –
 * die Gruppierung kommt aus derselben Abfrage wie im Bestenlisten-Formular.
 *
 * @param int $gewaehlt athletes_id.
 * @return void
 */
function lsg_bl_map_athleten_select( $gewaehlt ) {
	$gruppen = lsg_bl_athleten_gruppiert();

	echo '<select name="athletes_id" id="lsg-bl-map-athlet">';
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

/* -------------------------------------------------------------------------
 * Verdrahtung
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_lsg_bl_map_speichern', 'lsg_bl_admin_map_post' );
add_action( 'admin_post_lsg_bl_map_schalten', 'lsg_bl_admin_map_schalten_post' );
add_action( 'admin_post_lsg_bl_map_loeschen', 'lsg_bl_admin_map_loeschen_post' );
