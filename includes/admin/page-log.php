<?php
/**
 * Admin-Seite „Import-Log" (Plan 6.8).
 *
 * Zwei Ebenen: die Übersicht der Vorgänge, und dahinter die Zeilen eines
 * Vorgangs. Der direkte Einstieg in die Zeilensuche geht über das Suchfeld –
 * die Frage, die das Log beantworten soll, lautet ja „warum steht bei X diese
 * Zeit", nicht „was ist am 3. Mai passiert".
 *
 * ⚠ Aufbewahrung: unbegrenzt. Bei wenigen hundert Zeilen pro Jahr ist
 * Aufräumen unnötiger Aufwand – und ein Cron-Job, der unbemerkt Historie
 * wegwirft, wäre das Gegenteil von dem, wofür es dieses Log gibt.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render-Callback der Seite.
 *
 * @return void
 */
function lsg_bl_admin_log_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$run      = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;
	$suche    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$aktion   = isset( $_GET['aktion'] ) ? sanitize_key( wp_unslash( $_GET['aktion'] ) ) : '';
	$distanz  = isset( $_GET['distanz'] ) ? sanitize_text_field( wp_unslash( $_GET['distanz'] ) ) : '';
	$jahr     = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0;
	$adapter  = isset( $_GET['adapter'] ) ? sanitize_key( wp_unslash( $_GET['adapter'] ) ) : '';
	$seite    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	echo '<div class="wrap lsg-bl-log">';
	echo '<h1>' . esc_html__( 'Import-Log', 'lsg-bestenliste' ) . '</h1>';

	if ( ! lsg_bl_tabelle_da( lsg_bl_table( 'lsg_import_run' ) ) ) {
		lsg_bl_admin_notice(
			'warning',
			__( 'Die Log-Tabellen gibt es noch nicht. Sie entstehen beim nächsten Aufruf des Backends von selbst.', 'lsg-bestenliste' )
		);
		echo '</div>';
		return;
	}

	// Eine Zeilensuche oder ein Filter führt in die Zeilenebene, auch ohne
	// gewählten Vorgang: „warum steht bei X diese Zeit" ist die häufigere
	// Frage als „was ist in Vorgang 12 passiert".
	$zeilenebene = ( $run > 0 || '' !== $suche || '' !== $aktion || '' !== $distanz || $jahr > 0 );

	if ( $zeilenebene ) {
		lsg_bl_log_zeilenansicht(
			array(
				'run_id'   => $run,
				'suche'    => $suche,
				'aktion'   => $aktion,
				'distance' => $distanz,
				'jahr'     => $jahr,
				'adapter'  => $adapter,
				'paged'    => $seite,
			)
		);
	} else {
		lsg_bl_log_vorgangsansicht( $seite, $adapter );
	}

	echo '</div>';
}

/**
 * URL der Log-Seite mit Zustand in der Query.
 *
 * @param array $args Query-Parameter. Leere Werte fallen raus.
 * @return string
 */
function lsg_bl_log_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v && 0 !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste-log';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Eine einfache Seitennavigation.
 *
 * WP_List_Table wäre der Standardweg, bringt hier aber vor allem
 * Bulk-Actions, Spaltensortierung und Screen-Options mit, von denen das Log
 * keins braucht. Was es braucht – Filter, Suche, Paginierung – sind diese
 * dreißig Zeilen.
 *
 * @param int   $gesamt Gesamtzahl.
 * @param int   $seite  Aktuelle Seite, 1-basiert.
 * @param int   $pro    Zeilen je Seite.
 * @param array $args   Query-Parameter für die Links.
 * @return void
 */
function lsg_bl_log_pagination( $gesamt, $seite, $pro, array $args ) {
	$seiten = (int) ceil( $gesamt / max( 1, $pro ) );
	if ( $seiten < 2 ) {
		printf(
			'<div class="tablenav"><div class="tablenav-pages one-page"><span class="displaying-num">%s</span></div></div>',
			esc_html(
				sprintf(
					/* translators: %s: Anzahl */
					_n( '%s Eintrag', '%s Einträge', $gesamt, 'lsg-bestenliste' ),
					number_format_i18n( $gesamt )
				)
			)
		);
		return;
	}

	echo '<div class="tablenav"><div class="tablenav-pages">';
	printf(
		'<span class="displaying-num">%s</span> ',
		esc_html(
			sprintf(
				/* translators: %s: Anzahl */
				_n( '%s Eintrag', '%s Einträge', $gesamt, 'lsg-bestenliste' ),
				number_format_i18n( $gesamt )
			)
		)
	);

	echo '<span class="pagination-links">';
	if ( $seite > 1 ) {
		printf(
			'<a class="prev-page button" href="%s">&lsaquo;</a> ',
			esc_url( lsg_bl_log_url( array_merge( $args, array( 'paged' => $seite - 1 ) ) ) )
		);
	}
	printf(
		'<span class="paging-input">%1$s / %2$s</span> ',
		esc_html( number_format_i18n( $seite ) ),
		esc_html( number_format_i18n( $seiten ) )
	);
	if ( $seite < $seiten ) {
		printf(
			'<a class="next-page button" href="%s">&rsaquo;</a>',
			esc_url( lsg_bl_log_url( array_merge( $args, array( 'paged' => $seite + 1 ) ) ) )
		);
	}
	echo '</span></div></div>';
}

/**
 * Ebene 1: die Vorgänge.
 *
 * @param int    $seite   Seite.
 * @param string $adapter Filter auf den Adapter.
 * @return void
 */
function lsg_bl_log_vorgangsansicht( $seite, $adapter = '' ) {
	$pro = 20;

	$daten = lsg_bl_log_vorgaenge(
		array(
			'limit'   => $pro,
			'offset'  => ( $seite - 1 ) * $pro,
			'adapter' => $adapter,
		)
	);

	lsg_bl_log_suchformular( array( 'adapter' => $adapter ) );
	lsg_bl_log_quellenfilter( $adapter );

	if ( ! $daten['zeilen'] ) {
		echo '<p>' . esc_html__( 'Noch kein Import protokolliert.', 'lsg-bestenliste' ) . '</p>';
		return;
	}

	lsg_bl_log_pagination( $daten['gesamt'], $seite, $pro, array( 'adapter' => $adapter ) );

	echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
	echo '<th scope="col" class="column-primary">' . esc_html__( 'Veranstaltung', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Distanz', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Trichter', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Ergebnis', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Wann, von wem', 'lsg-bestenliste' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $daten['zeilen'] as $r ) {
		echo '<tr>';

		echo '<td class="column-primary"><strong><a href="'
			. esc_url( lsg_bl_log_url( array( 'run' => (int) $r['id'] ) ) ) . '">'
			. esc_html( lsg_bl_log_vorgang_titel( $r ) )
			. '</a></strong>';
		if ( '' !== $r['contest_name'] ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html( $r['contest_name'] );
			if ( '' !== $r['list_name'] ) {
				echo ' · ' . esc_html( $r['list_name'] );
			}
			echo '</span>';
		}
		if ( '' !== $r['source_url'] ) {
			echo '<br /><span class="lsg-bl-roh"><a href="' . esc_url( $r['source_url'] ) . '" target="_blank" rel="noreferrer noopener">'
				. esc_html__( 'Quelle', 'lsg-bestenliste' ) . '</a></span>';
		}
		echo '</td>';

		echo '<td>' . esc_html( lsg_bl_distance_label( $r['distance'] ) );
		if ( $r['event_date'] ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html( lsg_bl_format_date( $r['event_date'] ) );
			if ( '' !== $r['datum_quelle'] ) {
				echo ' (' . esc_html( $r['datum_quelle'] ) . ')';
			}
			echo '</span>';
		}
		echo '</td>';

		printf(
			'<td>%1$s &rarr; %2$s &rarr; %3$s<br /><span class="lsg-bl-roh">%4$s</span></td>',
			esc_html( number_format_i18n( $r['cnt_gelesen'] ) ),
			esc_html( number_format_i18n( $r['cnt_lsg'] ) ),
			esc_html( number_format_i18n( $r['cnt_zugeordnet'] ) ),
			esc_html__( 'gelesen · LSG · zugeordnet', 'lsg-bestenliste' )
		);

		echo '<td>';
		$bilanz = array();
		if ( $r['cnt_angelegt'] > 0 ) {
			/* translators: %s: Anzahl */
			$bilanz[] = sprintf( __( '%s angelegt', 'lsg-bestenliste' ), number_format_i18n( $r['cnt_angelegt'] ) );
		}
		if ( $r['cnt_aktualisiert'] > 0 ) {
			/* translators: %s: Anzahl */
			$bilanz[] = sprintf( __( '%s aktualisiert', 'lsg-bestenliste' ), number_format_i18n( $r['cnt_aktualisiert'] ) );
		}
		if ( $r['cnt_geloescht'] > 0 ) {
			/* translators: %s: Anzahl */
			$bilanz[] = sprintf( __( '%s gelöscht', 'lsg-bestenliste' ), number_format_i18n( $r['cnt_geloescht'] ) );
		}
		if ( $r['cnt_uebersprungen'] > 0 ) {
			/* translators: %s: Anzahl */
			$bilanz[] = sprintf( __( '%s übersprungen', 'lsg-bestenliste' ), number_format_i18n( $r['cnt_uebersprungen'] ) );
		}
		if ( $r['cnt_fehler'] > 0 ) {
			/* translators: %s: Anzahl */
			$bilanz[] = sprintf( __( '%s mit Fehler', 'lsg-bestenliste' ), number_format_i18n( $r['cnt_fehler'] ) );
		}
		echo esc_html( $bilanz ? implode( ', ', $bilanz ) : __( 'nichts geschrieben', 'lsg-bestenliste' ) );
		if ( ! empty( $r['note'] ) ) {
			echo '<br /><span class="lsg-bl-warnzeile">' . esc_html( $r['note'] ) . '</span>';
		}
		echo '</td>';

		$user = get_userdata( (int) $r['user_id'] );
		printf(
			'<td>%1$s<br /><span class="lsg-bl-roh">%2$s</span></td>',
			esc_html( lsg_bl_format_date( $r['tstamp'] ) ),
			esc_html( $user ? $user->display_name : __( 'unbekannt', 'lsg-bestenliste' ) )
		);

		echo '</tr>';
	}

	echo '</tbody></table>';
	lsg_bl_log_pagination( $daten['gesamt'], $seite, $pro, array( 'adapter' => $adapter ) );
}

/**
 * Womit ein Vorgang in der Liste überschrieben ist.
 *
 * ⚠ Von Hand erfasste Vorgänge haben keinen Veranstaltungsnamen – der Import
 * bringt ihn mit, ein Mensch am Formular nicht (7.5). Bis M7 stand dort
 * deshalb „ohne Namen", und mit der Sportlerpflege wären es Zeilen geworden,
 * die gar nichts mehr sagen. Der Titel wird jetzt aus dem abgeleitet, was die
 * Zeile ohnehin trägt: ein manueller Vorgang ohne Distanz und ohne
 * Veranstaltungsdatum kann nur Sportlerpflege sein (11.4), einer mit beidem
 * ist eine von Hand erfasste Leistung.
 *
 * ⚠ Erfunden wird nichts. Der Name des Sportlers gehört nicht in eine Spalte,
 * die „Veranstaltung" heißt – er steht eine Ebene tiefer, in den Zeilen.
 *
 * @param array $r Zeile aus lsg_import_run.
 * @return string
 */
function lsg_bl_log_vorgang_titel( array $r ) {
	if ( '' !== (string) $r['event_name'] ) {
		return (string) $r['event_name'];
	}

	if ( 'manuell' === (string) $r['adapter'] ) {
		return ( '' === (string) $r['distance'] && empty( $r['event_date'] ) )
			? __( 'Sportler gepflegt', 'lsg-bestenliste' )
			: __( 'Von Hand erfasst', 'lsg-bestenliste' );
	}

	return __( 'ohne Namen', 'lsg-bestenliste' );
}

/**
 * Ebene 2: die Zeilen.
 *
 * @param array $args run_id, suche, aktion, distance, jahr, adapter, paged.
 * @return void
 */
function lsg_bl_log_zeilenansicht( array $args ) {
	$pro   = 50;
	$seite = max( 1, (int) $args['paged'] );

	$vorgang = $args['run_id'] ? lsg_bl_log_vorgang( $args['run_id'] ) : null;

	echo '<p><a href="' . esc_url( lsg_bl_log_url() ) . '">&larr; '
		. esc_html__( 'alle Vorgänge', 'lsg-bestenliste' ) . '</a></p>';

	if ( $vorgang ) {
		echo '<div class="lsg-bl-quelle"><strong>' . esc_html( $vorgang['event_name'] ) . '</strong> &middot; '
			. esc_html( $vorgang['contest_name'] ) . ' &middot; '
			. esc_html( lsg_bl_distance_label( $vorgang['distance'] ) );
		if ( $vorgang['event_date'] ) {
			echo ' &middot; ' . esc_html( lsg_bl_format_date( $vorgang['event_date'] ) );
		}
		if ( '' !== $vorgang['town'] ) {
			echo ' &middot; ' . esc_html( $vorgang['town'] );
		}
		if ( '' !== $vorgang['zeit_typ'] ) {
			echo ' &middot; ' . esc_html(
				sprintf(
					/* translators: %s: netto oder brutto */
					__( 'Zeiten: %s', 'lsg-bestenliste' ),
					$vorgang['zeit_typ']
				)
			);
		}
		echo '</div>';
	}

	$filter_args = array(
		'run'     => (int) $args['run_id'],
		's'       => (string) $args['suche'],
		'aktion'  => (string) $args['aktion'],
		'distanz' => (string) $args['distance'],
		'jahr'    => (int) $args['jahr'],
		'adapter' => (string) $args['adapter'],
	);

	lsg_bl_log_suchformular( $filter_args );
	lsg_bl_log_zeilenfilter( $filter_args );

	$daten = lsg_bl_log_zeilen(
		array_merge(
			$args,
			array(
				'limit'  => $pro,
				'offset' => ( $seite - 1 ) * $pro,
			)
		)
	);

	if ( ! $daten['zeilen'] ) {
		echo '<p>' . esc_html__( 'Keine Log-Zeile passt zu dieser Auswahl.', 'lsg-bestenliste' ) . '</p>';
		return;
	}

	lsg_bl_log_pagination( $daten['gesamt'], $seite, $pro, $filter_args );

	$aktionen = lsg_bl_log_aktionen();
	$typen    = lsg_bl_match_types();

	echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
	echo '<th scope="col" class="column-primary">' . esc_html__( 'Sportler', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Jg', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Distanz / AK', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Zeit', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Aktion', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Meldung', 'lsg-bestenliste' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $daten['zeilen'] as $l ) {
		echo '<tr>';

		echo '<td class="column-primary">';
		if ( $l['athletes_id'] > 0 && $l['athlet_name'] ) {
			echo '<strong>' . esc_html( trim( $l['athlet_name'] . ', ' . $l['athlet_firstname'], ', ' ) ) . '</strong>';
		} else {
			echo '<strong>' . esc_html( trim( $l['roh_name'] . ', ' . $l['roh_vorname'], ', ' ) ) . '</strong>';
		}
		// ⚠ Die Rohschreibweise steht immer da: das Log soll auch dann noch
		// verständlich sein, wenn der Athlet inzwischen umbenannt wurde.
		$roh = array();
		if ( '' !== $l['roh_teilnehmer'] ) {
			$roh[] = sprintf( __( 'roh: „%s"', 'lsg-bestenliste' ), $l['roh_teilnehmer'] );
		}
		if ( '' !== $l['roh_verein'] ) {
			$roh[] = $l['roh_verein'];
		}
		if ( '' !== $l['roh_startnr'] ) {
			$roh[] = sprintf( __( 'Stnr %s', 'lsg-bestenliste' ), $l['roh_startnr'] );
		}
		if ( '' !== $l['roh_platz'] ) {
			$roh[] = sprintf( __( 'Platz %s', 'lsg-bestenliste' ), $l['roh_platz'] );
		}
		if ( $roh ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html( implode( ' · ', $roh ) ) . '</span>';
		}
		if ( '' !== $l['match_type'] && isset( $typen[ $l['match_type'] ] ) ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html( $typen[ $l['match_type'] ] ) . '</span>';
		}
		echo '</td>';

		// ⚠ NULL heißt „die Quelle nannte keinen Jahrgang" und ist von
		// „Jahrgang 0" zu unterscheiden.
		echo '<td>' . ( null === $l['roh_jahrgang'] ? '&#8211;' : esc_html( (int) $l['roh_jahrgang'] ) ) . '</td>';

		echo '<td>' . esc_html( lsg_bl_distance_label( $l['distance'] ) );
		if ( '' !== $l['ak'] ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html( $l['ak'] ) . '</span>';
		}
		echo '</td>';

		echo '<td>';
		if ( '' !== $l['time_alt'] ) {
			echo '<span class="lsg-bl-roh">' . esc_html( $l['time_alt'] ) . '</span> &rarr; ';
		}
		echo '<strong>' . esc_html( $l['time_neu'] ) . '</strong>';
		if ( '' !== $l['roh_zeit'] && $l['roh_zeit'] !== $l['time_neu'] ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html(
				sprintf(
					/* translators: %s: Originalzeit */
					__( 'Quelle: %s', 'lsg-bestenliste' ),
					$l['roh_zeit']
				)
			) . '</span>';
		}
		echo '</td>';

		printf(
			'<td><span class="lsg-bl-resultat lsg-bl-resultat-%1$s">%2$s</span></td>',
			esc_attr( $l['aktion'] ),
			esc_html( isset( $aktionen[ $l['aktion'] ] ) ? $aktionen[ $l['aktion'] ] : $l['aktion'] )
		);

		echo '<td>' . esc_html( $l['meldung'] ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	lsg_bl_log_pagination( $daten['gesamt'], $seite, $pro, $filter_args );
}

/**
 * Das Suchfeld.
 *
 * @param array $args Beizubehaltende Query-Parameter.
 * @return void
 */
function lsg_bl_log_suchformular( array $args ) {
	echo '<form method="get" class="search-form lsg-bl-logsuche">';
	echo '<input type="hidden" name="page" value="lsg-bestenliste-log" />';
	foreach ( $args as $k => $v ) {
		if ( 's' === $k || '' === $v || 0 === $v ) {
			continue;
		}
		printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $k ), esc_attr( $v ) );
	}
	printf(
		'<label class="screen-reader-text" for="lsg-bl-logsuche">%s</label>',
		esc_html__( 'Log durchsuchen', 'lsg-bestenliste' )
	);
	printf(
		'<input type="search" id="lsg-bl-logsuche" name="s" value="%1$s" placeholder="%2$s" />',
		esc_attr( isset( $args['s'] ) ? $args['s'] : '' ),
		esc_attr__( 'Name, Vereinsschreibweise …', 'lsg-bestenliste' )
	);
	printf( ' <button type="submit" class="button">%s</button>', esc_html__( 'Suchen', 'lsg-bestenliste' ) );
	echo '</form>';
}

/**
 * Filter „von Hand erfasst" / nach Portal (Plan 7.5).
 *
 * @param string $aktiv Aktiver Adapter-Schlüssel.
 * @return void
 */
function lsg_bl_log_quellenfilter( $aktiv ) {
	$auswahl = array( '' => __( 'Alle', 'lsg-bestenliste' ) ) + lsg_bl_adapter_auswahl();
	// `manuell` steht schon jetzt im Filter, obwohl die Seite „Bestenliste"
	// erst mit M5 kommt – dann trägt der Filter sofort (Plan 7.5).
	$auswahl['manuell'] = __( 'von Hand erfasst', 'lsg-bestenliste' );

	$teile = array();
	foreach ( $auswahl as $key => $label ) {
		$teile[] = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( lsg_bl_log_url( array( 'adapter' => $key ) ) ),
			( (string) $key === (string) $aktiv ) ? ' class="current"' : '',
			esc_html( $label )
		);
	}

	echo '<ul class="subsubsub"><li>' . wp_kses_post( implode( ' |</li><li>', $teile ) ) . '</li></ul><div class="clear"></div>';
}

/**
 * Filter über den Zeilen: Aktion, Distanz, Jahr.
 *
 * @param array $args Aktuelle Parameter.
 * @return void
 */
function lsg_bl_log_zeilenfilter( array $args ) {
	echo '<form method="get" class="lsg-bl-logfilter">';
	echo '<input type="hidden" name="page" value="lsg-bestenliste-log" />';
	if ( ! empty( $args['run'] ) ) {
		printf( '<input type="hidden" name="run" value="%d" />', (int) $args['run'] );
	}
	if ( ! empty( $args['s'] ) ) {
		printf( '<input type="hidden" name="s" value="%s" />', esc_attr( $args['s'] ) );
	}

	echo '<select name="aktion">';
	printf( '<option value="">%s</option>', esc_html__( 'Aktion: alle', 'lsg-bestenliste' ) );
	foreach ( lsg_bl_log_aktionen() as $key => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $key ),
			selected( $args['aktion'], $key, false ),
			esc_html( $label )
		);
	}
	echo '</select> ';

	$distanzen = lsg_bl_log_distanzen();
	if ( $distanzen ) {
		echo '<select name="distanz">';
		printf( '<option value="">%s</option>', esc_html__( 'Distanz: alle', 'lsg-bestenliste' ) );
		foreach ( $distanzen as $d ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $d ),
				selected( $args['distanz'], $d, false ),
				esc_html( lsg_bl_distance_label( $d ) )
			);
		}
		echo '</select> ';
	}

	$jahre = lsg_bl_log_jahre();
	if ( $jahre ) {
		echo '<select name="jahr">';
		printf( '<option value="">%s</option>', esc_html__( 'Jahr: alle', 'lsg-bestenliste' ) );
		foreach ( $jahre as $j ) {
			printf(
				'<option value="%1$d"%2$s>%1$d</option>',
				(int) $j,
				selected( (int) $args['jahr'], (int) $j, false )
			);
		}
		echo '</select> ';
	}

	printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filtern', 'lsg-bestenliste' ) );
	echo '</form>';
}
