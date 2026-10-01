<?php
/**
 * Der Torwächter vor jedem Abruf einer fremden Adresse.
 *
 * ⚠ Diese Datei braucht WordPress (wp_safe_remote_get, Transients). Sie ist
 * bewusst von den Parsern getrennt: die Adapter bekommen ihren Getter
 * injiziert und lassen sich deshalb gegen eine Fixture prüfen, ohne
 * WordPress und ohne Netz (Plan, Abschnitt 5).
 *
 * ⚠ Die Allowlist gilt für JEDE abgerufene Adresse, nicht nur für die
 * eingegebene. Bei race result ist das die entscheidende Stelle: der zweite
 * Request geht an den Host aus config.server – ein Wert, der aus der Antwort
 * eines Fremdservers stammt und tatsächlich wechselt (my4, my-us-1, …). Wer
 * nur die Eingabe-URL prüft, hat eine Allowlist, die genau den Request nicht
 * abdeckt, der die Daten holt (Plan 6.10).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-Agent des Plugins. Eigener Name mit Kontakt-URL, keine
 * Browser-Tarnung (Plan 3.5).
 *
 * @return string
 */
function lsg_bl_user_agent() {
	return 'LSG-Bestenliste/' . LSG_BL_VERSION . ' (+' . home_url( '/' ) . ')';
}

/**
 * Darf diese Adresse abgerufen werden?
 *
 * @param string $url         Zu prüfende Adresse.
 * @param string $adapter_cls Klassenname des zuständigen Adapters.
 * @return bool
 */
function lsg_bl_url_erlaubt( $url, $adapter_cls ) {
	if ( ! is_string( $adapter_cls ) || ! class_exists( $adapter_cls ) ) {
		return false;
	}

	$teile = lsg_bl_parse_url( $url );

	if ( empty( $teile['scheme'] ) || ! in_array( strtolower( $teile['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	if ( empty( $teile['host'] ) ) {
		return false;
	}

	// Die Prüfung läuft auf dem Host, nie auf der ganzen URL:
	// https://angreifer.example/?x=my.raceresult.com enthält den erlaubten
	// Namen, ist aber eine fremde Adresse.
	$host = strtolower( $teile['host'] );

	foreach ( (array) call_user_func( array( $adapter_cls, 'hosts' ) ) as $muster ) {
		$muster = strtolower( (string) $muster );
		if ( '' === $muster ) {
			continue;
		}
		if ( 0 === strpos( $muster, '*.' ) ) {
			// Das Suffix beginnt mit dem Punkt ('.raceresult.com'), sonst
			// passte auch boeseraceresult.com.
			$suffix = substr( $muster, 1 );
			if ( strlen( $host ) > strlen( $suffix )
				&& substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		} elseif ( $host === $muster ) {
			return true;
		}
	}

	return false;
}

/** Rate-Limit: so viele Abrufe pro Benutzer … */
define( 'LSG_BL_RATE_MAX', 30 );
/** … innerhalb dieser Zeitspanne (Sekunden). */
define( 'LSG_BL_RATE_FENSTER', 10 * MINUTE_IN_SECONDS );

/**
 * Rate-Limit pro Benutzer: höchstens LSG_BL_RATE_MAX Abrufe in
 * LSG_BL_RATE_FENSTER.
 *
 * Der Transient hält neben dem Zähler auch den Ablaufzeitpunkt, damit die
 * Fehlermeldung die verbleibende Wartezeit nennen kann
 * (lsg_bl_rate_limit_meldung()). Den Ablauf des Transients selbst liefert
 * WordPress nicht zuverlässig – mit Objekt-Cache gar nicht.
 *
 * @param int $user_id Benutzer, 0 = aktueller.
 * @return bool True, wenn der Abruf noch erlaubt ist.
 */
function lsg_bl_rate_limit_ok( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$key     = 'lsg_bl_rate_' . $user_id;
	$stand   = get_transient( $key );
	$zaehler = is_array( $stand ) ? (int) $stand['n'] : (int) $stand;

	if ( $zaehler >= LSG_BL_RATE_MAX ) {
		return false;
	}

	$dauer = LSG_BL_RATE_FENSTER;
	set_transient(
		$key,
		array(
			'n'   => $zaehler + 1,
			'bis' => time() + $dauer,
		),
		$dauer
	);
	return true;
}

/**
 * Sekunden, bis das Rate-Limit wieder Abrufe zulässt.
 *
 * @param int $user_id Benutzer, 0 = aktueller.
 * @return int 0, wenn nichts bekannt ist (alter Transient ohne Zeitpunkt).
 */
function lsg_bl_rate_limit_rest( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$stand   = get_transient( 'lsg_bl_rate_' . $user_id );
	if ( ! is_array( $stand ) || empty( $stand['bis'] ) ) {
		return 0;
	}
	return max( 0, (int) $stand['bis'] - time() );
}

/**
 * Hinweis für ein gegriffenes Rate-Limit, mit verbleibender Wartezeit.
 *
 * @param int $user_id Benutzer, 0 = aktueller.
 * @return string
 */
function lsg_bl_rate_limit_meldung( $user_id = 0 ) {
	$rest  = lsg_bl_rate_limit_rest( $user_id );
	$regel = sprintf(
		/* translators: 1: Anzahl Abrufe, 2: Minuten */
		__( 'Erlaubt sind %1$d Abrufe in %2$d Minuten pro Benutzer.', 'lsg-bestenliste' ),
		LSG_BL_RATE_MAX,
		(int) ( LSG_BL_RATE_FENSTER / MINUTE_IN_SECONDS )
	);

	if ( $rest <= 0 ) {
		return __( 'Zu viele Abrufe in kurzer Zeit.', 'lsg-bestenliste' ) . ' ' . $regel . ' '
			. __( 'Bitte ein paar Minuten warten – die Quelle soll nicht belastet werden.', 'lsg-bestenliste' );
	}

	if ( $rest < MINUTE_IN_SECONDS ) {
		/* translators: %d: Sekunden */
		$wartezeit = sprintf( _n( '%d Sekunde', '%d Sekunden', $rest, 'lsg-bestenliste' ), $rest );
	} else {
		$minuten = (int) ceil( $rest / MINUTE_IN_SECONDS );
		/* translators: %d: Minuten */
		$wartezeit = sprintf( _n( '%d Minute', '%d Minuten', $minuten, 'lsg-bestenliste' ), $minuten );
	}

	return __( 'Zu viele Abrufe in kurzer Zeit.', 'lsg-bestenliste' ) . ' ' . $regel . ' '
		. sprintf(
			/* translators: %s: Wartezeit, z. B. „4 Minuten" */
			__( 'Bitte noch %s warten – die Quelle soll nicht belastet werden.', 'lsg-bestenliste' ),
			$wartezeit
		);
}

/**
 * Eine fremde Adresse abrufen. Der einzige Weg nach draußen.
 *
 * - nur http/https
 * - Host muss vom Adapter beansprucht sein (Allowlist = Registry)
 * - wp_safe_remote_get() blockt private IP-Bereiche
 * - Redirects werden von Hand verfolgt, damit jeder Zwischenschritt erneut
 *   durch die Allowlist läuft
 *
 * @param string $url         Adresse.
 * @param string $adapter_cls Zuständiger Adapter.
 * @param int    $hops        Verbleibende Redirects (intern).
 * @return string Antwortkörper.
 * @throws LSG_BL_Quelle_Exception Bei jedem Fehler, mit Klartext-Meldung.
 */
function lsg_bl_http_get( $url, $adapter_cls, $hops = 3 ) {
	if ( ! lsg_bl_url_erlaubt( $url, $adapter_cls ) ) {
		throw new LSG_BL_Quelle_Exception(
			sprintf(
				/* translators: %s: URL */
				__( 'Die Adresse %s gehört zu keinem unterstützten Portal – der Abruf wird abgebrochen.', 'lsg-bestenliste' ),
				esc_url_raw( $url )
			)
		);
	}

	$res = wp_safe_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 0,          // Redirects folgen wir selbst.
			'user-agent'  => lsg_bl_user_agent(),
			'headers'     => array( 'Accept' => 'application/json, text/html;q=0.9, */*;q=0.1' ),
		)
	);

	if ( is_wp_error( $res ) ) {
		throw new LSG_BL_Quelle_Exception(
			sprintf(
				/* translators: %s: Fehlermeldung */
				__( 'Die Quelle ist nicht erreichbar: %s', 'lsg-bestenliste' ),
				$res->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $res );

	if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
		$ziel = wp_remote_retrieve_header( $res, 'location' );
		if ( $hops < 1 || '' === $ziel ) {
			throw new LSG_BL_Quelle_Exception(
				__( 'Die Quelle leitet im Kreis oder zu oft weiter.', 'lsg-bestenliste' )
			);
		}
		// Relative Weiterleitung am Ursprung auflösen.
		if ( ! preg_match( '#^https?://#i', $ziel ) ) {
			$t    = lsg_bl_parse_url( $url );
			$base = $t['scheme'] . '://' . $t['host'] . ( isset( $t['port'] ) ? ':' . $t['port'] : '' );
			$ziel = $base . ( ( '/' === substr( $ziel, 0, 1 ) ) ? $ziel : '/' . $ziel );
		}
		// Und wieder durch dieselbe Prüfung.
		return lsg_bl_http_get( $ziel, $adapter_cls, $hops - 1 );
	}

	if ( 200 !== $code ) {
		throw new LSG_BL_Quelle_Exception(
			sprintf(
				/* translators: %d: HTTP-Statuscode */
				__( 'Die Quelle antwortet mit HTTP %d.', 'lsg-bestenliste' ),
				$code
			)
		);
	}

	$body = wp_remote_retrieve_body( $res );
	if ( '' === trim( (string) $body ) ) {
		throw new LSG_BL_Quelle_Exception(
			__( 'Die Quelle hat eine leere Antwort geliefert.', 'lsg-bestenliste' )
		);
	}

	return (string) $body;
}
