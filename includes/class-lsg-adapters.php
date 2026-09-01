<?php
/**
 * Adapter-Registry.
 *
 * Serverseitig entscheidet keine if/else-Kette über den Host, sondern eine
 * Registry: jeder Adapter beantwortet selbst, ob er eine URL bedienen kann
 * (Plan 6.3).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alle registrierten Adapter-Klassen.
 *
 * @return string[] Klassennamen, die LSG_BL_Ergebnis_Quelle implementieren.
 */
function lsg_bl_adapter_registry() {
	$adapter = array(
		'LSG_BL_RaceResult_Adapter',
	);

	if ( class_exists( 'LSG_BL_Runtix_Adapter' ) ) {
		$adapter[] = 'LSG_BL_Runtix_Adapter';
	}

	/**
	 * Filter: weitere Adapter registrieren – auch aus einem anderen Plugin,
	 * ohne dieses hier anzufassen.
	 *
	 * @param string[] $adapter Klassennamen.
	 */
	$adapter = (array) apply_filters( 'lsg_bl_ergebnis_adapter', $adapter );

	// Nur, was es wirklich gibt und was die Schnittstelle erfüllt.
	return array_values(
		array_filter(
			$adapter,
			function ( $cls ) {
				return is_string( $cls )
					&& class_exists( $cls )
					&& in_array( 'LSG_BL_Ergebnis_Quelle', class_implements( $cls ), true );
			}
		)
	);
}

/**
 * Welcher Adapter beansprucht diese URL am sichersten?
 *
 * @param string $url Eingegebene URL.
 * @return string|null Klassenname oder null, wenn keiner zuständig ist.
 */
function lsg_bl_adapter_fuer_url( $url ) {
	$best  = null;
	$score = 0;

	foreach ( lsg_bl_adapter_registry() as $cls ) {
		$s = (int) call_user_func( array( $cls, 'erkennt' ), $url );
		if ( $s > $score ) {
			$score = $s;
			$best  = $cls;
		}
	}

	return ( $score > 0 ) ? $best : null;
}

/**
 * Adapter-Klasse zu einem Schlüssel ('raceresult'), für die manuelle
 * Übersteuerung im Select und für die REST-Routen.
 *
 * @param string $key Adapter-Schlüssel.
 * @return string|null
 */
function lsg_bl_adapter_nach_key( $key ) {
	foreach ( lsg_bl_adapter_registry() as $cls ) {
		if ( (string) $key === (string) call_user_func( array( $cls, 'key' ) ) ) {
			return $cls;
		}
	}
	return null;
}

/**
 * Schlüssel → Anzeigename, für Selects und Fehlermeldungen
 * („Für diese Adresse gibt es noch keinen Adapter." plus Auflistung).
 *
 * @return array<string,string>
 */
function lsg_bl_adapter_auswahl() {
	$out = array();
	foreach ( lsg_bl_adapter_registry() as $cls ) {
		$out[ (string) call_user_func( array( $cls, 'key' ) ) ] =
			(string) call_user_func( array( $cls, 'label' ) );
	}
	return $out;
}
