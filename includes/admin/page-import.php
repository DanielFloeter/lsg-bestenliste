<?php
/**
 * Admin-Seite „Ergebnis-Import" – der Assistent aus Plan, Abschnitt 6.
 *
 *   Schritt 1   URL eingeben          →  Adapter automatisch erkennen
 *   Schritt 2   Wettbewerb wählen     →  ggf. Ergebnisliste wählen
 *   Schritt 3   Distanz, Datum, Ort   →  Button „Parsen"
 *                                     →  P1 lesen  →  P2 LSG filtern
 *
 * Der Ablauf ist ein Assistent mit *sichtbarem* Zwischenstand: nach jedem
 * Schritt steht auf der Seite, was erkannt bzw. geladen wurde. Kein
 * „Blackbox-Button".
 *
 * ⚠ Progressive Enhancement (Plan 6.9): die drei Schritte funktionieren als
 * normale Formular-Roundtrips über `admin-post.php`. Der Stand des
 * Assistenten steht in der Query, nicht in einer Session – dann funktioniert
 * Browser-Zurück, ein Zwischenstand ist verlinkbar, und ein abgebrochener
 * Import hinterlässt nichts außer einem ablaufenden Transient. Das
 * `assets/js/admin-import.js` macht daraus später (M6) einen Ablauf ohne
 * Reload.
 *
 * ⚠ Capability, Nonce und `check_admin_referer()` stehen in JEDEM Handler,
 * nicht nur beim Rendern des Menüs: `add_menu_page()` versteckt den Eintrag,
 * schützt aber keinen Endpunkt.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Menü
 * ---------------------------------------------------------------------- */

/**
 * Ein Top-Level-Menü, das später die weiteren Pflege-Oberflächen aufnimmt
 * (Import-Log, Zuordnungen, Bestenliste – Plan 6.2).
 *
 * Eingetragen wird nur, was es schon gibt: ein Menüpunkt, der auf eine leere
 * Seite zeigt, ist schlimmer als keiner.
 *
 * @return void
 */
function lsg_bl_admin_menu() {
	$hook = add_menu_page(
		__( 'LSG Bestenliste', 'lsg-bestenliste' ),
		__( 'LSG Bestenliste', 'lsg-bestenliste' ),
		LSG_BL_CAP,
		'lsg-bestenliste',
		'lsg_bl_admin_import_page',
		'dashicons-chart-line',
		58
	);

	add_submenu_page(
		'lsg-bestenliste',
		__( 'Ergebnis-Import', 'lsg-bestenliste' ),
		__( 'Ergebnis-Import', 'lsg-bestenliste' ),
		LSG_BL_CAP,
		'lsg-bestenliste',            // gleicher Slug: kein Doppeleintrag
		'lsg_bl_admin_import_page'
	);

	$GLOBALS['lsg_bl_import_hook'] = $hook;
}
add_action( 'admin_menu', 'lsg_bl_admin_menu' );

/**
 * Assets nur auf dieser Seite laden.
 *
 * @param string $hook Aktueller Admin-Hook.
 * @return void
 */
function lsg_bl_admin_assets( $hook ) {
	$eigen = isset( $GLOBALS['lsg_bl_import_hook'] ) ? $GLOBALS['lsg_bl_import_hook'] : '';
	if ( ! $eigen || $hook !== $eigen ) {
		return;
	}

	wp_enqueue_style(
		'lsg-bestenliste-admin',
		LSG_BL_URL . 'assets/css/admin.css',
		array(),
		LSG_BL_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'lsg_bl_admin_assets' );

/* -------------------------------------------------------------------------
 * Hilfsfunktionen der Seite
 * ---------------------------------------------------------------------- */

/**
 * URL der Import-Seite mit Zustand in der Query.
 *
 * @param array $args Zusätzliche Query-Parameter. Leere Werte fallen raus.
 * @return string
 */
function lsg_bl_import_url( array $args = array() ) {
	$args = array_filter(
		$args,
		function ( $v ) {
			return '' !== $v && null !== $v;
		}
	);
	$args['page'] = 'lsg-bestenliste';

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Eine Meldung für den nächsten Seitenaufruf hinterlegen.
 *
 * Über einen kurzlebigen Transient statt über die URL: Klartext-Meldungen
 * sind lang, und eine Fehlermeldung in der Adresszeile lädt zum Verfälschen
 * ein.
 *
 * @param string $typ  'error' | 'success' | 'warning' | 'info'.
 * @param string $text Klartext.
 * @return void
 */
function lsg_bl_admin_notice_setzen( $typ, $text ) {
	set_transient(
		'lsg_bl_notice_' . get_current_user_id(),
		array(
			'typ'  => $typ,
			'text' => $text,
		),
		2 * MINUTE_IN_SECONDS
	);
}

/**
 * Die hinterlegte Meldung holen und löschen.
 *
 * @return array{typ:string,text:string}|null
 */
function lsg_bl_admin_notice_holen() {
	$key = 'lsg_bl_notice_' . get_current_user_id();
	$n   = get_transient( $key );
	if ( ! is_array( $n ) || empty( $n['text'] ) ) {
		return null;
	}
	delete_transient( $key );
	return $n;
}

/**
 * Eine Notice ausgeben.
 *
 * @param string $typ  'error' | 'success' | 'warning' | 'info'.
 * @param string $text Klartext (wird escaped).
 * @return void
 */
function lsg_bl_admin_notice( $typ, $text ) {
	printf(
		'<div class="notice notice-%1$s"><p>%2$s</p></div>',
		esc_attr( $typ ),
		esc_html( $text )
	);
}

/* -------------------------------------------------------------------------
 * Formular-Handler
 * ---------------------------------------------------------------------- */

/**
 * Der eine Handler für alle drei Schritte.
 *
 * POST → Redirect → GET: der Seitenzustand steht danach vollständig in der
 * Query, und ein Reload wiederholt keinen Abruf.
 *
 * @return void
 */
function lsg_bl_admin_import_post() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'lsg_bl_import' );

	$schritt = isset( $_POST['schritt'] ) ? sanitize_key( wp_unslash( $_POST['schritt'] ) ) : '';

	$args = array(
		'url'     => isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '',
		'adapter' => isset( $_POST['adapter'] ) ? sanitize_key( wp_unslash( $_POST['adapter'] ) ) : '',
		'contest' => isset( $_POST['contest'] ) ? sanitize_text_field( wp_unslash( $_POST['contest'] ) ) : '',
		'list'    => isset( $_POST['list'] ) ? sanitize_text_field( wp_unslash( $_POST['list'] ) ) : '',
		'distanz' => isset( $_POST['distanz'] ) ? sanitize_text_field( wp_unslash( $_POST['distanz'] ) ) : '',
		'datum'   => isset( $_POST['datum'] ) ? sanitize_text_field( wp_unslash( $_POST['datum'] ) ) : '',
		'ort'     => isset( $_POST['ort'] ) ? sanitize_text_field( wp_unslash( $_POST['ort'] ) ) : '',
	);

	// Textfeld-Fallback: TT.MM.JJJJ, damit die Seite ohne den
	// Datepicker bedienbar bleibt (Plan 6.5.1, 6.9).
	$args['datum'] = lsg_bl_datum_eingabe_lesen( $args['datum'] );

	// Schritt 1 setzt die Auswahl zurück – eine neue URL hat mit dem alten
	// Wettbewerb nichts zu tun.
	if ( 'pruefen' === $schritt ) {
		$args['contest'] = '';
		$args['list']    = '';
		$args['distanz'] = '';
		$args['datum']   = '';
		$args['ort']     = '';
	}

	if ( 'parsen' !== $schritt ) {
		wp_safe_redirect( lsg_bl_import_url( $args ) );
		exit;
	}

	/* ---- Schritt 3: parsen ---- */

	if ( '' === $args['contest'] ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Bitte zuerst einen Wettbewerb wählen.', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_import_url( $args ) );
		exit;
	}

	$adapter_cls = lsg_bl_adapter_waehlen( $args['url'], $args['adapter'] );
	if ( ! $adapter_cls ) {
		lsg_bl_admin_notice_setzen( 'error', __( 'Für diese Adresse gibt es noch keinen Adapter.', 'lsg-bestenliste' ) );
		wp_safe_redirect( lsg_bl_import_url( $args ) );
		exit;
	}

	try {
		$ergebnis = lsg_bl_parsen(
			array(
				'adapter_cls' => $adapter_cls,
				'url'         => $args['url'],
				'contest_id'  => $args['contest'],
				'list_id'     => $args['list'],
				'distanz'     => $args['distanz'],
				'datum'       => $args['datum'],
				'ort'         => $args['ort'],
			)
		);
	} catch ( LSG_BL_Quelle_Exception $e ) {
		lsg_bl_admin_notice_setzen( 'error', $e->getMessage() );
		wp_safe_redirect( lsg_bl_import_url( $args ) );
		exit;
	}

	$args['token'] = $ergebnis['token'];
	wp_safe_redirect( lsg_bl_import_url( $args ) );
	exit;
}
add_action( 'admin_post_lsg_bl_import', 'lsg_bl_admin_import_post' );

/**
 * Datumseingabe lesen: ISO aus `<input type="date">` oder TT.MM.JJJJ aus dem
 * Textfeld-Fallback.
 *
 * ⚠ Ein unvollständiges Datum wird NICHT ergänzt – kein stiller 1. Januar
 * (Plan 6.5.1). Was hier nicht als vollständiges Datum ankommt, kommt als
 * leerer String zurück und hält den Parsen-Button gesperrt.
 *
 * @param string $eingabe Rohwert.
 * @return string 'JJJJ-MM-TT' oder ''.
 */
function lsg_bl_datum_eingabe_lesen( $eingabe ) {
	$eingabe = trim( (string) $eingabe );
	if ( '' === $eingabe ) {
		return '';
	}

	if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $eingabe, $m ) ) {
		if ( checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return '';
	}

	if ( preg_match( '/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $eingabe, $m ) ) {
		if ( checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}
	}

	return '';
}

/**
 * Welcher Adapter ist zuständig – automatisch erkannt oder von Hand gewählt?
 *
 * Die manuelle Übersteuerung ist nötig, wenn ein Portal seine URL-Struktur
 * ändert oder ein neuer Adapter dieselbe Domain bedient (Plan 6.3).
 *
 * @param string $url     Eingegebene URL.
 * @param string $gewaehlt Adapter-Schlüssel oder '' für automatisch.
 * @return string|null Klassenname.
 */
function lsg_bl_adapter_waehlen( $url, $gewaehlt = '' ) {
	if ( '' !== $gewaehlt && 'auto' !== $gewaehlt ) {
		$cls = lsg_bl_adapter_nach_key( $gewaehlt );
		if ( $cls ) {
			return $cls;
		}
	}
	return lsg_bl_adapter_fuer_url( $url );
}

/* -------------------------------------------------------------------------
 * Die Seite
 * ---------------------------------------------------------------------- */

/**
 * Render-Callback der Seite.
 *
 * @return void
 */
function lsg_bl_admin_import_page() {
	if ( ! current_user_can( LSG_BL_CAP ) ) {
		wp_die( esc_html__( 'Dafür fehlt dir die Berechtigung.', 'lsg-bestenliste' ), '', array( 'response' => 403 ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$url       = isset( $_GET['url'] ) ? esc_url_raw( trim( wp_unslash( $_GET['url'] ) ) ) : '';
	$adapter_w = isset( $_GET['adapter'] ) ? sanitize_key( wp_unslash( $_GET['adapter'] ) ) : '';
	$contest   = isset( $_GET['contest'] ) ? sanitize_text_field( wp_unslash( $_GET['contest'] ) ) : '';
	$list      = isset( $_GET['list'] ) ? sanitize_text_field( wp_unslash( $_GET['list'] ) ) : '';
	$distanz   = isset( $_GET['distanz'] ) ? sanitize_text_field( wp_unslash( $_GET['distanz'] ) ) : '';
	$datum     = isset( $_GET['datum'] ) ? sanitize_text_field( wp_unslash( $_GET['datum'] ) ) : '';
	$ort       = isset( $_GET['ort'] ) ? sanitize_text_field( wp_unslash( $_GET['ort'] ) ) : '';
	$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$fehler   = '';
	$disc     = null;
	$vorschau = null;
	$hinweise = array();

	$adapter_cls = ( '' !== $url ) ? lsg_bl_adapter_waehlen( $url, $adapter_w ) : null;
	$erkannt_cls = ( '' !== $url ) ? lsg_bl_adapter_fuer_url( $url ) : null;

	/* ---- nonce-gesicherte GET-Aktionen ---- */

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$aktion = isset( $_GET['aktion'] ) ? sanitize_key( wp_unslash( $_GET['aktion'] ) ) : '';

	if ( 'neu_laden' === $aktion && $adapter_cls ) {
		check_admin_referer( 'lsg_bl_neu_laden' );
		$event_id = method_exists( $adapter_cls, 'event_id_aus_url' )
			? (string) call_user_func( array( $adapter_cls, 'event_id_aus_url' ), $url )
			: '';
		lsg_bl_discovery_verwerfen( $adapter_cls, $event_id );
		lsg_bl_parse_verwerfen( $token );
		$token = '';
		$hinweise[] = array( 'info', __( 'Die Auswahl wird frisch von der Quelle geholt.', 'lsg-bestenliste' ) );
	}

	if ( 'alias_setzen' === $aktion ) {
		check_admin_referer( 'lsg_bl_alias' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$neu_alias = isset( $_GET['verein'] ) ? sanitize_text_field( wp_unslash( $_GET['verein'] ) ) : '';

		if ( '' === trim( $neu_alias ) || lsg_bl_ohne_verein_marke() === $neu_alias ) {
			$hinweise[] = array(
				'error',
				__( 'Eine leere Vereinsangabe lässt sich nicht als Alias aufnehmen – sie würde jede Zeile ohne Verein übernehmen.', 'lsg-bestenliste' ),
			);
		} elseif ( lsg_bl_verein_alias_hinzufuegen( $neu_alias ) ) {
			// Der Filter hat sich geändert, also ist die Vorschau überholt.
			// Sie wird verworfen, nicht heimlich weiterbenutzt – dieselbe
			// Regel wie bei Datum und Distanz (Plan 6.5.1).
			lsg_bl_parse_verwerfen( $token );
			$token      = '';
			$hinweise[] = array(
				'success',
				sprintf(
					/* translators: %s: Vereinsschreibweise */
					__( '„%s" gilt ab jetzt als LSG Karlsruhe. Die Vorschau ist damit überholt – bitte erneut parsen.', 'lsg-bestenliste' ),
					$neu_alias
				),
			);
		} else {
			$hinweise[] = array(
				'info',
				sprintf(
					/* translators: %s: Vereinsschreibweise */
					__( '„%s" stand schon in der Alias-Liste.', 'lsg-bestenliste' ),
					$neu_alias
				),
			);
		}
	}

	if ( 'alias_weg' === $aktion ) {
		check_admin_referer( 'lsg_bl_alias_weg' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$weg = isset( $_GET['verein'] ) ? sanitize_text_field( wp_unslash( $_GET['verein'] ) ) : '';
		if ( lsg_bl_verein_alias_entfernen( $weg ) ) {
			lsg_bl_parse_verwerfen( $token );
			$token      = '';
			$hinweise[] = array( 'success', __( 'Der Vereins-Alias ist entfernt. Bitte erneut parsen.', 'lsg-bestenliste' ) );
		}
	}

	/* ---- Discovery ---- */

	if ( '' !== $url && $adapter_cls ) {
		try {
			$disc = lsg_bl_discovery( $adapter_cls, $url );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$fehler = $e->getMessage();
		} catch ( Exception $e ) {
			$fehler = __( 'Die Quelle ließ sich nicht auswerten: ', 'lsg-bestenliste' ) . $e->getMessage();
		}
	}

	// Vorauswahl aus der URL, wenn der Mensch noch nichts gewählt hat.
	if ( $disc && '' === $contest && '' !== $disc['contest_id'] ) {
		$contest = $disc['contest_id'];
		if ( '' === $list ) {
			$list = $disc['list_id'];
		}
	}

	/* ---- Vorbelegung der drei Felder ---- */

	$vorbelegung = null;
	if ( $disc && '' !== $contest ) {
		$vorbelegung = lsg_bl_import_vorbelegung( $disc, $contest );

		// Nur vorbelegen, was der Mensch nicht selbst gesetzt hat. Der
		// Unterschied zwischen „noch nicht angefasst" und „bewusst geleert"
		// steht in der Query: fasst jemand das Feld an, ist der Parameter da.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['distanz'] ) ) {
			$distanz = $vorbelegung['distanz'];
		}
		if ( ! isset( $_GET['datum'] ) ) {
			$datum = $vorbelegung['datum'];
		}
		if ( ! isset( $_GET['ort'] ) ) {
			$ort = $vorbelegung['ort'];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Eine einzige Liste ist keine Wahl – Wert implizit setzen.
		$listen = lsg_bl_contest_listen( $disc, $contest );
		if ( 1 === count( $listen ) ) {
			$list = $listen[0]['id'];
		} elseif ( $listen && '' === $list ) {
			$list = $listen[0]['id'];
		} elseif ( $listen && ! lsg_bl_contest_liste( $disc, $contest, $list ) ) {
			// Wechsel des Wettbewerbs: alte Auswahl verwerfen, statt einen
			// Geisterwert mitzuschleppen (Plan 6.4).
			$list = $listen[0]['id'];
		}
	}

	/* ---- Vorschau aus dem Transient ---- */

	if ( '' !== $token && $disc ) {
		$daten = lsg_bl_parse_holen( $token );
		if ( ! $daten ) {
			$hinweise[] = array( 'warning', __( 'Die Vorschau ist abgelaufen. Bitte erneut parsen.', 'lsg-bestenliste' ) );
			$token      = '';
		} else {
			$passt = lsg_bl_parse_passt(
				$daten,
				array(
					'adapter'    => $disc['adapter'],
					'event_id'   => $disc['event_id'],
					'contest_id' => $contest,
					'list_id'    => $list,
					'distanz'    => $distanz,
					'datum'      => $datum,
				)
			);
			if ( $passt ) {
				$vorschau = $daten;
				// Der Ort geht in keinen Vergleich ein und darf die Tabelle
				// nicht wegwerfen – er wird einfach nachgeführt.
				$vorschau['ort'] = $ort;
			} else {
				$hinweise[] = array(
					'warning',
					__( 'Datum oder Distanz haben sich geändert – die Vorschau passt nicht mehr dazu und wurde verworfen. Bitte erneut parsen.', 'lsg-bestenliste' ),
				);
				$token = '';
			}
		}
	}

	$zustand = lsg_bl_import_zustand(
		array(
			'url'         => $url,
			'adapter_cls' => $adapter_cls,
			'fehler'      => $fehler,
			'contest_id'  => $contest,
			'distanz'     => $distanz,
			'datum'       => $datum,
			'vorschau'    => $vorschau,
		)
	);

	$formularwerte = array(
		'url'     => $url,
		'adapter' => $adapter_w,
		'contest' => $contest,
		'list'    => $list,
		'distanz' => $distanz,
		'datum'   => $datum,
		'ort'     => $ort,
		'token'   => $token,
	);

	/* ---- Ausgabe ---- */

	echo '<div class="wrap lsg-bl-import">';
	echo '<h1>' . esc_html__( 'Ergebnis-Import', 'lsg-bestenliste' ) . '</h1>';

	$notice = lsg_bl_admin_notice_holen();
	if ( $notice ) {
		lsg_bl_admin_notice( $notice['typ'], $notice['text'] );
	}
	foreach ( $hinweise as $h ) {
		lsg_bl_admin_notice( $h[0], $h[1] );
	}
	if ( '' !== $fehler ) {
		lsg_bl_admin_notice( 'error', $fehler );
	}

	lsg_bl_import_zustand_anzeigen( $zustand );

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="lsg_bl_import" />';
	wp_nonce_field( 'lsg_bl_import' );

	lsg_bl_import_schritt1( $formularwerte, $adapter_cls, $erkannt_cls, $disc );

	if ( $disc ) {
		lsg_bl_import_schritt2( $formularwerte, $disc );
		lsg_bl_import_schritt3( $formularwerte, $disc, $vorbelegung );
	}

	echo '</form>';

	if ( $vorschau ) {
		lsg_bl_import_vorschau_anzeigen( $vorschau, $formularwerte );
	}

	echo '</div>';
}

/**
 * Den aktuellen Zustand als Zeile über dem Formular zeigen.
 *
 * Ein Zustand, der nicht dargestellt ist, wird später als Bug gemeldet
 * (Plan 6.11).
 *
 * @param string $zustand Schlüssel aus lsg_bl_import_zustaende().
 * @return void
 */
function lsg_bl_import_zustand_anzeigen( $zustand ) {
	$alle = lsg_bl_import_zustaende();
	if ( ! isset( $alle[ $zustand ] ) ) {
		return;
	}
	printf(
		'<p class="lsg-bl-zustand"><span class="lsg-bl-zustand-punkt lsg-bl-zustand-%1$s"></span>%2$s</p>',
		esc_attr( $zustand ),
		esc_html( $alle[ $zustand ] )
	);
}

/**
 * Schritt 1: URL und Adapter.
 *
 * @param array       $w           Formularwerte.
 * @param string|null $adapter_cls Zuständiger Adapter.
 * @param string|null $erkannt_cls Automatisch erkannter Adapter.
 * @param array|null  $disc        Discovery-Daten.
 * @return void
 */
function lsg_bl_import_schritt1( array $w, $adapter_cls, $erkannt_cls, $disc ) {
	echo '<h2>' . esc_html__( '1. Ergebnisliste', 'lsg-bestenliste' ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="lsg-bl-url">' . esc_html__( 'Adresse', 'lsg-bestenliste' ) . '</label></th><td>';
	printf(
		'<input type="url" id="lsg-bl-url" name="url" value="%s" class="large-text code" placeholder="%s" />',
		esc_attr( $w['url'] ),
		esc_attr( 'https://my.raceresult.com/375768/#2_B45FAB' )
	);
	echo '<p class="description">'
		. esc_html__( 'Die Adresse der Ergebnisliste, wie sie im Browser steht. Ein angehängtes #2_B45FAB wird mitgelesen und belegt die Auswahl vor.', 'lsg-bestenliste' )
		. '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="lsg-bl-adapter">' . esc_html__( 'Portal', 'lsg-bestenliste' ) . '</label></th><td>';
	echo '<select id="lsg-bl-adapter" name="adapter">';

	$auto_label = $erkannt_cls
		? sprintf(
			/* translators: %s: Portalname */
			__( 'automatisch (erkannt: %s)', 'lsg-bestenliste' ),
			(string) call_user_func( array( $erkannt_cls, 'label' ) )
		)
		: __( 'automatisch', 'lsg-bestenliste' );

	$auto_aktiv = ( '' === $w['adapter'] || 'auto' === $w['adapter'] );
	printf(
		'<option value="auto"%1$s>%2$s</option>',
		$auto_aktiv ? ' selected="selected"' : '',
		esc_html( $auto_label )
	);

	foreach ( lsg_bl_adapter_auswahl() as $key => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $key ),
			selected( $w['adapter'], $key, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
	echo '<p class="description">'
		. esc_html__( 'Nur nötig, wenn ein Portal seine Adressen umgestellt hat.', 'lsg-bestenliste' )
		. '</p>';
	echo '</td></tr>';

	echo '</tbody></table>';

	// Kein Adapter: Klartext plus Auflistung der unterstützten Portale.
	if ( '' !== $w['url'] && ! $adapter_cls ) {
		echo '<div class="notice notice-error inline"><p>'
			. esc_html__( 'Für diese Adresse gibt es noch keinen Adapter.', 'lsg-bestenliste' )
			. ' ' . esc_html__( 'Unterstützt werden derzeit:', 'lsg-bestenliste' )
			. ' ' . esc_html( implode( ', ', lsg_bl_adapter_auswahl() ) )
			. '.</p></div>';
	}

	if ( $disc ) {
		echo '<p class="lsg-bl-erkannt">';
		printf(
			/* translators: 1: Portalname, 2: Veranstaltungsname, 3: Event-ID */
			esc_html__( 'Erkannt: %1$s – %2$s (Nr. %3$s)', 'lsg-bestenliste' ),
			'<strong>' . esc_html( $disc['adapter_label'] ) . '</strong>',
			'<strong>' . esc_html( $disc['event_name'] ? $disc['event_name'] : __( 'ohne Namen', 'lsg-bestenliste' ) ) . '</strong>',
			esc_html( $disc['event_id'] )
		);
		echo ' &middot; <a href="' . esc_url(
			wp_nonce_url(
				lsg_bl_import_url( array_merge( $w, array( 'aktion' => 'neu_laden' ) ) ),
				'lsg_bl_neu_laden'
			)
		) . '">' . esc_html__( 'neu laden', 'lsg-bestenliste' ) . '</a>';
		echo '</p>';
	}

	// ⚠ Die drei Schritte sind drei Submit-Knöpfe im EINEN Formular, alle mit
	// name="schritt" und eigenem value. Nur so geht kein Feldwert verloren,
	// wenn jemand erst das Datum tippt und dann „Parsen" drückt – und nur so
	// bleibt die Seite ohne JavaScript vollständig bedienbar (Plan 6.9).
	printf(
		'<p class="submit"><button type="submit" name="schritt" value="pruefen" class="button %1$s">%2$s</button></p>',
		esc_attr( $disc ? 'button-secondary' : 'button-primary' ),
		esc_html__( 'Quelle prüfen', 'lsg-bestenliste' )
	);
}

/**
 * Schritt 2: Wettbewerb und Ergebnisliste.
 *
 * @param array $w    Formularwerte.
 * @param array $disc Discovery-Daten.
 * @return void
 */
function lsg_bl_import_schritt2( array $w, array $disc ) {
	echo '<h2>' . esc_html__( '2. Wettbewerb', 'lsg-bestenliste' ) . '</h2>';

	if ( ! $disc['contests'] ) {
		lsg_bl_admin_notice( 'error', __( 'Die Quelle nennt keine Wettbewerbe – die Liste ist vielleicht noch nicht veröffentlicht.', 'lsg-bestenliste' ) );
		return;
	}

	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="lsg-bl-contest">' . esc_html__( 'Wettbewerb', 'lsg-bestenliste' ) . '</label></th><td>';
	echo '<select id="lsg-bl-contest" name="contest">';
	printf( '<option value="">%s</option>', esc_html__( '— bitte wählen —', 'lsg-bestenliste' ) );
	foreach ( $disc['contests'] as $c ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $c['id'] ),
			selected( $w['contest'], $c['id'], false ),
			esc_html( $c['name'] )
		);
	}
	echo '</select>';
	echo '</td></tr>';

	$listen = ( '' !== $w['contest'] ) ? lsg_bl_contest_listen( $disc, $w['contest'] ) : array();

	// Genau eine Liste → Feld ausblenden, Wert implizit setzen. Die Auswahl
	// wird nur eingeblendet, wenn es wirklich etwas zu wählen gibt (Plan 6.4).
	if ( count( $listen ) > 1 ) {
		echo '<tr><th scope="row"><label for="lsg-bl-list">' . esc_html__( 'Ergebnisliste', 'lsg-bestenliste' ) . '</label></th><td>';
		echo '<select id="lsg-bl-list" name="list">';
		foreach ( $listen as $l ) {
			$label = $l['name'];
			if ( $l['gesamtwertung'] ) {
				$label .= ' ' . __( '(Gesamtwertung)', 'lsg-bestenliste' );
			}
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $l['id'] ),
				selected( $w['list'], $l['id'], false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</td></tr>';
	} elseif ( 1 === count( $listen ) ) {
		printf( '<input type="hidden" name="list" value="%s" />', esc_attr( $listen[0]['id'] ) );
		echo '<tr><th scope="row">' . esc_html__( 'Ergebnisliste', 'lsg-bestenliste' ) . '</th><td><p class="description">'
			. esc_html(
				sprintf(
					/* translators: %s: Listenname */
					__( 'Es gibt nur eine: %s', 'lsg-bestenliste' ),
					$listen[0]['name']
				)
			)
			. '</p></td></tr>';
	}

	echo '</tbody></table>';

	printf(
		'<p class="submit"><button type="submit" name="schritt" value="auswahl" class="button button-secondary">%s</button></p>',
		esc_html__( 'Auswahl übernehmen', 'lsg-bestenliste' )
	);
	echo '<p class="description">'
		. esc_html__( 'Ohne JavaScript aktualisiert dieser Knopf die Listen- und Distanzvorschläge.', 'lsg-bestenliste' )
		. '</p>';
}

/**
 * Schritt 3: Distanz, Datum, Ort – und der Parsen-Button.
 *
 * Beide Felder – Datum und Distanz – stehen immer über der Tabelle, auch wenn
 * die Erkennung erfolgreich war. Sie sind Vorbelegungen, keine
 * Feststellungen. Und sie sind Pflicht (Plan 6.5.1).
 *
 * @param array      $w           Formularwerte.
 * @param array      $disc        Discovery-Daten.
 * @param array|null $vorbelegung Ergebnis von lsg_bl_import_vorbelegung().
 * @return void
 */
function lsg_bl_import_schritt3( array $w, array $disc, $vorbelegung ) {
	if ( '' === $w['contest'] || ! $vorbelegung ) {
		return;
	}

	echo '<h2>' . esc_html__( '3. Distanz, Datum und Ort', 'lsg-bestenliste' ) . '</h2>';

	// Zeitläufe: der Grund steht im Klartext, und der Hinweis führt
	// irgendwohin (Plan 6.5.1).
	if ( $vorbelegung['zeitlauf'] ) {
		echo '<div class="notice notice-error inline"><p>'
			. esc_html__( 'Zeitläufe werden nicht importiert – dort steht eine Strecke, keine Zeit. Bitte unter „Bestenliste" von Hand erfassen.', 'lsg-bestenliste' )
			. '</p></div>';
	}

	echo '<table class="form-table" role="presentation"><tbody>';

	/* ---- Distanz ---- */
	echo '<tr><th scope="row"><label for="lsg-bl-distanz">' . esc_html__( 'Distanz', 'lsg-bestenliste' ) . '</label></th><td>';
	echo '<select id="lsg-bl-distanz" name="distanz">';
	printf( '<option value="">%s</option>', esc_html__( '— bitte wählen —', 'lsg-bestenliste' ) );
	$map = lsg_bl_distance_map();
	foreach ( lsg_bl_import_distanzen() as $code ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $code ),
			selected( $w['distanz'], $code, false ),
			esc_html( isset( $map[ $code ] ) ? $map[ $code ]['label'] : $code )
		);
	}
	echo '</select>';

	if ( '' !== $vorbelegung['distanz'] ) {
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: Wettbewerbsname */
				__( 'Vorgeschlagen aus „%s". Das ist eine Vorbelegung, keine Entscheidung – bitte prüfen.', 'lsg-bestenliste' ),
				$vorbelegung['contest_name']
			)
		) . '</p>';
	} elseif ( ! $vorbelegung['zeitlauf'] ) {
		echo '<p class="description">' . esc_html__( 'Für diesen Wettbewerb gibt es keine passende Distanz in der Bestenliste – bitte von Hand wählen.', 'lsg-bestenliste' ) . '</p>';
	}
	echo '</td></tr>';

	/* ---- Datum ---- */
	echo '<tr><th scope="row"><label for="lsg-bl-datum">' . esc_html__( 'Veranstaltungsdatum', 'lsg-bestenliste' ) . '</label></th><td>';
	printf(
		'<input type="date" id="lsg-bl-datum" name="datum" value="%s" placeholder="TT.MM.JJJJ" pattern="\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{4}" />',
		esc_attr( $w['datum'] )
	);

	$quelle_label = lsg_bl_datum_quelle_label( $vorbelegung['datum_quelle'] );
	if ( '' !== $vorbelegung['datum'] && '' !== $quelle_label ) {
		echo ' <span class="lsg-bl-herkunft">' . esc_html( $quelle_label ) . '</span>';
	}

	echo '<p class="description">';
	if ( '' === $vorbelegung['datum'] && '' !== $vorbelegung['datum_hinweis'] ) {
		echo esc_html( $vorbelegung['datum_hinweis'] ) . ' ';
	}
	echo esc_html__( 'Das Datum des Laufs, nicht das der Erfassung. Es bestimmt das Jahr, gegen das verglichen wird. Ohne Datepicker: TT.MM.JJJJ eintippen.', 'lsg-bestenliste' );
	echo '</p>';

	// time(), nicht current_time(): lsg_bl_datum_hinweise() vergleicht gegen
	// einen UTC-Zeitstempel. current_time() waere um den Offset verschoben.
	foreach ( lsg_bl_datum_hinweise( $w['datum'], $disc['event_name'], time() ) as $hinweis ) {
		echo '<p class="lsg-bl-plausibel">⚠ ' . esc_html( $hinweis ) . '</p>';
	}
	echo '</td></tr>';

	/* ---- Ort ---- */
	echo '<tr><th scope="row"><label for="lsg-bl-ort">' . esc_html__( 'Ort', 'lsg-bestenliste' ) . '</label></th><td>';
	printf(
		'<input type="text" id="lsg-bl-ort" name="ort" value="%s" maxlength="30" class="regular-text" />',
		esc_attr( $w['ort'] )
	);
	echo '<p class="description">' . esc_html__( 'Höchstens 30 Zeichen – so lang ist die Spalte in der Bestenliste.', 'lsg-bestenliste' ) . '</p>';
	echo '</td></tr>';

	echo '</tbody></table>';

	printf( '<input type="hidden" name="token" value="%s" />', esc_attr( $w['token'] ) );

	$fehlt   = lsg_bl_import_was_fehlt( $w['distanz'], $w['datum'] );
	$gesperrt = ( '' !== $fehlt ) || $vorbelegung['zeitlauf'];

	if ( '' !== $fehlt ) {
		echo '<p class="lsg-bl-gesperrt">' . esc_html( $fehlt ) . '</p>';
	}

	printf(
		'<p class="submit"><button type="submit" name="schritt" value="parsen" class="button button-primary"%1$s>%2$s</button></p>',
		$gesperrt ? ' disabled="disabled"' : '',
		esc_html__( 'Parsen', 'lsg-bestenliste' )
	);
	echo '<p class="description">'
		. esc_html__( 'Parsen liest die Ergebnisliste einmal ab und filtert auf LSG Karlsruhe. Geschrieben wird dabei nichts.', 'lsg-bestenliste' )
		. '</p>';
}

/* -------------------------------------------------------------------------
 * Vorschau
 * ---------------------------------------------------------------------- */

/**
 * Trichter, Warnungen, Tabelle und der Block der nicht übernommenen Vereine.
 *
 * @param array $v Parse-Ergebnis.
 * @param array $w Formularwerte.
 * @return void
 */
function lsg_bl_import_vorschau_anzeigen( array $v, array $w ) {
	echo '<hr />';
	echo '<h2>' . esc_html__( 'Vorschau', 'lsg-bestenliste' ) . '</h2>';

	/* ---- Kopfzeile: was wurde gelesen ---- */
	echo '<p class="lsg-bl-quelle">';
	printf(
		/* translators: 1: Veranstaltung, 2: Wettbewerb, 3: Liste */
		esc_html__( '%1$s · %2$s · %3$s', 'lsg-bestenliste' ),
		'<strong>' . esc_html( $v['event_name'] ) . '</strong>',
		esc_html( $v['contest_name'] ),
		esc_html( $v['list_name'] )
	);
	if ( '' !== $v['zeit_typ'] ) {
		echo ' &middot; ' . esc_html(
			sprintf(
				/* translators: %s: netto oder brutto */
				__( 'Zeiten: %s', 'lsg-bestenliste' ),
				$v['zeit_typ']
			)
		);
	}
	echo ' &middot; <a href="' . esc_url( $v['quelle_url'] ) . '" target="_blank" rel="noreferrer noopener">'
		. esc_html__( 'Quelle ansehen', 'lsg-bestenliste' ) . '</a>';
	echo '</p>';

	/* ---- Der Trichter ---- */
	$stufen = lsg_bl_trichter_stufen( $v['trichter'] );
	echo '<p class="lsg-bl-trichter">';
	$erste = true;
	foreach ( $stufen as $s ) {
		if ( ! $erste ) {
			echo ' <span class="lsg-bl-pfeil">→</span> ';
		}
		printf(
			'<span class="lsg-bl-stufe lsg-bl-stufe-%1$s"><strong>%2$s</strong> %3$s</span>',
			esc_attr( $s['key'] ),
			esc_html( number_format_i18n( $s['wert'] ) ),
			esc_html( $s['label'] )
		);
		$erste = false;
	}
	echo '</p>';

	if ( 0 === (int) $v['trichter']['lsg'] ) {
		lsg_bl_admin_notice(
			'warning',
			__( 'Keine einzige Zeile für LSG Karlsruhe. Wenn das nicht stimmen kann, steht die Schreibweise vielleicht unten unter „nicht übernommene Vereine".', 'lsg-bestenliste' )
		);
	}

	foreach ( (array) $v['warnungen'] as $warnung ) {
		lsg_bl_admin_notice( 'info', $warnung );
	}

	// Gesamtsieg: erkannt und markiert, noch nicht geschrieben (Plan 6.5.5).
	$siege = 0;
	foreach ( $v['zeilen'] as $z ) {
		if ( lsg_bl_ist_gesamtsieg( $z, $v['gesamtwertung'] ) ) {
			++$siege;
		}
	}
	if ( $siege > 0 ) {
		lsg_bl_admin_notice(
			'info',
			sprintf(
				/* translators: %d: Anzahl */
				_n(
					'%d Gesamtsieg erkannt – Eintrag in die Gesamtsiege bitte noch von Hand.',
					'%d Gesamtsiege erkannt – Einträge in die Gesamtsiege bitte noch von Hand.',
					$siege,
					'lsg-bestenliste'
				),
				$siege
			)
		);
	}

	// M2 endet hier: gelesen und gefiltert, nichts geschrieben.
	echo '<div class="notice notice-info inline"><p>'
		. esc_html__( 'Zuordnung zu den Sportlern und der Abgleich mit der Bestenliste kommen im nächsten Schritt. Bis dahin ist diese Vorschau reine Anzeige – es wird nichts gespeichert.', 'lsg-bestenliste' )
		. '</p></div>';

	/* ---- Die Tabelle ---- */
	lsg_bl_import_tabelle( $v );

	/* ---- Nicht übernommene Vereine ---- */
	lsg_bl_import_abgelehnte_vereine( $v, $w );
}

/**
 * Die Vorschautabelle.
 *
 * Ohne Checkbox-Spalte: die kommt mit der Übernahme (Plan 6.6, M3). Was hier
 * steht, ist alles, was die Quelle geliefert hat.
 *
 * @param array $v Parse-Ergebnis.
 * @return void
 */
function lsg_bl_import_tabelle( array $v ) {
	if ( ! $v['zeilen'] ) {
		echo '<p>' . esc_html__( 'Nichts zu zeigen.', 'lsg-bestenliste' ) . '</p>';
		return;
	}

	echo '<table class="wp-list-table widefat fixed striped lsg-bl-vorschau">';
	echo '<thead><tr>';
	echo '<th scope="col" class="column-primary">' . esc_html__( 'Nachname, Vorname', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Jg', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Verein', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Zeit', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Platz', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Stnr', 'lsg-bestenliste' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Hinweise', 'lsg-bestenliste' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $v['zeilen'] as $z ) {
		$hinweise = array();

		if ( ! empty( $z['namen_unsicher'] ) ) {
			$hinweise[] = __( 'Vor- und Nachname geraten – die Quelle schreibt sie nicht eindeutig.', 'lsg-bestenliste' );
		}
		if ( empty( $z['jahrgang'] ) ) {
			$hinweise[] = __( 'Die Quelle nennt keinen Jahrgang. Ohne ihn lässt sich der Athlet nicht zuordnen.', 'lsg-bestenliste' );
		}
		if ( empty( $z['geschlecht'] ) ) {
			$hinweise[] = __( 'Kein Geschlecht in der Quelle.', 'lsg-bestenliste' );
		}

		$sieg = lsg_bl_ist_gesamtsieg( $z, $v['gesamtwertung'] );

		$name = trim( $z['nachname'] . ', ' . $z['vorname'], ', ' );

		echo '<tr>';
		echo '<td class="column-primary"><strong>' . esc_html( $name ) . '</strong>';

		/*
		 * Die Rohschreibweise der Quelle steht nur da, wenn sie etwas sagt:
		 * bei „FLÖTER Daniel" → „FLÖTER, Daniel" hat der Splitter nichts
		 * verändert außer dem Komma, und die Zeile zweimal zu zeigen macht
		 * die Tabelle unlesbar. Mitgeführt wird das Feld immer – im Log
		 * (`roh_teilnehmer`) steht es in jedem Fall (Plan 6.5.1).
		 */
		$roh_name = trim( (string) $z['teilnehmer'] );
		if ( '' !== $roh_name
			&& ( ! empty( $z['namen_unsicher'] )
				|| lsg_bl_text_normalisieren( $roh_name ) !== lsg_bl_text_normalisieren( $name ) )
		) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html(
				sprintf(
					/* translators: %s: Rohschreibweise der Quelle */
					__( 'Quelle: %s', 'lsg-bestenliste' ),
					$roh_name
				)
			) . '</span>';
		}
		echo '</td>';
		echo '<td>' . lsg_bl_cell( $z['jahrgang'] ? $z['jahrgang'] : '' ) . '</td>';
		echo '<td>' . lsg_bl_cell( $z['verein'] ) . '</td>';
		echo '<td><strong>' . lsg_bl_cell( $z['zeit'] ) . '</strong>';

		/*
		 * Bei der Zeit dasselbe: eine führende Null oder eine fehlende
		 * Stundenangabe zu ergänzen verliert keine Information. Zehntel
		 * aufzurunden schon – nur dann wird die Originalzeit gezeigt.
		 */
		if ( preg_match( '/[.,]\d/', (string) $z['roh_zeit'] ) ) {
			echo '<br /><span class="lsg-bl-roh">' . esc_html(
				sprintf(
					/* translators: %s: Originalzeit der Quelle */
					__( 'Quelle: %s', 'lsg-bestenliste' ),
					$z['roh_zeit']
				)
			) . '</span>';
		}
		echo '</td>';
		echo '<td>' . lsg_bl_cell( $z['platz'] ) . ( $sieg ? ' <span class="lsg-bl-sieg" title="' . esc_attr__( 'Gesamtsieg', 'lsg-bestenliste' ) . '">🏆</span>' : '' ) . '</td>';
		echo '<td>' . lsg_bl_cell( $z['startnummer'] ) . '</td>';
		echo '<td>';
		if ( $hinweise ) {
			echo '<span class="lsg-bl-warnzeile">⚠ ' . esc_html( implode( ' ', $hinweise ) ) . '</span>';
		} else {
			echo '&#8211;';
		}
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

/**
 * Der aufklappbare Block „nicht übernommene Vereine".
 *
 * Die Sicherung gegen stille Fehler: steht dort ein `LSG Ka.`, sieht man den
 * verpassten Treffer sofort, statt ihn nie zu bemerken. Aus dem Block heraus
 * lässt sich eine Schreibweise per Klick als Vereins-Alias aufnehmen
 * (Plan 6.5.2).
 *
 * @param array $v Parse-Ergebnis.
 * @param array $w Formularwerte.
 * @return void
 */
function lsg_bl_import_abgelehnte_vereine( array $v, array $w ) {
	$abgelehnt = isset( $v['abgelehnt'] ) ? (array) $v['abgelehnt'] : array();
	$nahe      = isset( $v['nahe'] ) ? (array) $v['nahe'] : array();

	echo '<details class="lsg-bl-abgelehnt"' . ( $nahe ? ' open' : '' ) . '>';
	printf(
		'<summary>%s</summary>',
		esc_html(
			sprintf(
				/* translators: 1: Anzahl Schreibweisen, 2: Anzahl Zeilen */
				__( 'Nicht übernommene Vereine: %1$d Schreibweisen, %2$d Zeilen', 'lsg-bestenliste' ),
				count( $abgelehnt ),
				array_sum( $abgelehnt )
			)
		)
	);

	if ( $nahe ) {
		echo '<div class="notice notice-warning inline"><p>'
			. esc_html__( 'Diese Schreibweisen enthalten „LSG" oder „Karlsruhe", treffen den Filter aber nicht – hier ist am ehesten eine verpasste Schreibweise zu finden. Falls eine davon doch der Verein ist: als Alias aufnehmen.', 'lsg-bestenliste' )
			. '</p></div>';
	}

	if ( ! $abgelehnt ) {
		echo '<p>' . esc_html__( 'Alle gelesenen Zeilen gehören zum Verein.', 'lsg-bestenliste' ) . '</p>';
		echo '</details>';
		return;
	}

	/*
	 * Zwei Listen, ein Zweck.
	 *
	 * Der Block soll eine VERPASSTE Schreibweise auffindbar machen. Eine
	 * Aktion braucht deshalb nur, was danach aussieht – alles mit `LSG` oder
	 * `Karlsruhe` im Namen. Die übrigen 300 Vereine einer Großveranstaltung
	 * sind nie eine Schreibweise der LSG Karlsruhe; sie bleiben vollständig
	 * sichtbar, aber als kompakte Liste.
	 *
	 * Das ist kein Schönheitsgrund: mit einem nonce-gesicherten Link je Zeile
	 * wog die Seite bei 310 Vereinen 137 kB, fast alles davon Links, die
	 * niemand anklickt.
	 */
	$mit_aktion = array();
	$rest       = array();
	foreach ( $abgelehnt as $verein => $anzahl ) {
		// „(kein Verein)" steht mit in der oberen Tabelle, obwohl es dort
		// keine Aktion gibt: es ist der einzige Eintrag, der kein Verein ist,
		// und unter 300 Vereinsnamen wäre er nicht mehr zu finden. Genau
		// diese Zeilen darf man aber nicht übersehen – dort kann ein
		// Mitglied stecken, das ohne Verein gemeldet war (Plan 6.5.2).
		if ( in_array( $verein, $nahe, true ) || lsg_bl_ohne_verein_marke() === $verein ) {
			$mit_aktion[ $verein ] = $anzahl;
		} else {
			$rest[ $verein ] = $anzahl;
		}
	}

	if ( $mit_aktion ) {
		// „(kein Verein)" ist nicht „nah dran" – die Zeilenklasse bekommt nur,
		// was wirklich nach einer verpassten Schreibweise aussieht.
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Verein laut Quelle', 'lsg-bestenliste' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Zeilen', 'lsg-bestenliste' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Aktion', 'lsg-bestenliste' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $mit_aktion as $verein => $anzahl ) {
			echo '<tr' . ( in_array( $verein, $nahe, true ) ? ' class="lsg-bl-nah"' : '' ) . '>';
			echo '<td>' . esc_html( $verein ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $anzahl ) ) . '</td>';
			echo '<td>';

			if ( lsg_bl_ohne_verein_marke() === $verein ) {
				echo '<span class="description">' . esc_html__( 'Ohne Verein – nicht als Alias möglich.', 'lsg-bestenliste' ) . '</span>';
			} else {
				// Ein nonce-gesicherter Link, kein Formular je Zeile – derselbe
				// Weg wie beim Entfernen eines Alias ein paar Zeilen weiter.
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						wp_nonce_url(
							lsg_bl_import_url(
								array_merge(
									$w,
									array(
										'aktion' => 'alias_setzen',
										'verein' => $verein,
									)
								)
							),
							'lsg_bl_alias'
						)
					),
					esc_html__( 'als LSG Karlsruhe zählen', 'lsg-bestenliste' )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	if ( $rest ) {
		echo '<p class="lsg-bl-restliste"><strong>';
		echo esc_html(
			sprintf(
				/* translators: %d: Anzahl */
				_n(
					'%d weiterer Verein – ohne „LSG" oder „Karlsruhe" im Namen, also keine Schreibweise des eigenen Vereins:',
					'%d weitere Vereine – ohne „LSG" oder „Karlsruhe" im Namen, also keine Schreibweise des eigenen Vereins:',
					count( $rest ),
					'lsg-bestenliste'
				),
				count( $rest )
			)
		);
		echo '</strong><br />';

		$teile = array();
		foreach ( $rest as $verein => $anzahl ) {
			$teile[] = esc_html( $verein ) . ' <span class="lsg-bl-anzahl">(' . esc_html( number_format_i18n( $anzahl ) ) . ')</span>';
		}
		echo wp_kses_post( implode( ' &middot; ', $teile ) );
		echo '</p>';
	}

	// Bereits gesetzte Aliasse zeigen und zurücknehmbar machen.
	$aliasse = lsg_bl_verein_aliasse();
	if ( $aliasse ) {
		echo '<p class="lsg-bl-aliasliste"><strong>' . esc_html__( 'Zusätzlich als LSG Karlsruhe gesetzt:', 'lsg-bestenliste' ) . '</strong> ';
		$teile = array();
		foreach ( $aliasse as $a ) {
			$teile[] = '<code>' . esc_html( $a ) . '</code> <a href="' . esc_url(
				wp_nonce_url(
					lsg_bl_import_url( array_merge( $w, array( 'aktion' => 'alias_weg', 'verein' => $a ) ) ),
					'lsg_bl_alias_weg'
				)
			) . '" aria-label="' . esc_attr__( 'Alias entfernen', 'lsg-bestenliste' ) . '">&times;</a>';
		}
		echo wp_kses_post( implode( ', ', $teile ) );
		echo '</p>';
	}

	echo '</details>';
}
