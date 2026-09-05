<?php
/**
 * Testbootstrap. Laedt je nach Suite nur die Parser oder das ganze WordPress.
 *
 * Welche Suite laeuft, verraet die Umgebungsvariable LSG_BL_SUITE bzw. das
 * Vorhandensein von WP_TESTS_DIR. Ohne beides wird die Unit-Lage geladen –
 * das ist der Normalfall waehrend der Entwicklung.
 *
 * @package lsg-bestenliste
 */

define( 'LSG_BL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'LSG_BL_TESTS_DIR', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';

$lsg_bl_wp_tests = getenv( 'WP_TESTS_DIR' );
$lsg_bl_suite    = getenv( 'LSG_BL_SUITE' );

if ( 'integration' === $lsg_bl_suite && $lsg_bl_wp_tests ) {
	/*
	 * Integrationslage: die WordPress-Testsuite hochfahren und das Plugin
	 * als Muss-Plugin laden.
	 */
	require_once rtrim( $lsg_bl_wp_tests, '/' ) . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		function () {
			require LSG_BL_PLUGIN_DIR . 'lsg-bestenliste.php';
		}
	);

	require rtrim( $lsg_bl_wp_tests, '/' ) . '/includes/bootstrap.php';
	return;
}

/*
 * Unit-Lage: kein WordPress.
 *
 * ABSPATH wird definiert, weil jede Plugin-Datei mit dem ueblichen
 * Direktzugriffs-Riegel beginnt. Das laedt kein WordPress – es hebt nur den
 * Riegel. Geladen werden ausschliesslich Dateien, die ohne WordPress
 * auskommen: die Normalisierung, die Value-Objects und die Adapter. Alles
 * mit $wpdb, Transients oder wp_safe_remote_get() bleibt draussen.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', LSG_BL_PLUGIN_DIR );
}

require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-normalize.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-pipeline.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-helpers.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-leistung.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-athlet-form.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/class-lsg-win-form.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/adapters/interface-ergebnis-quelle.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/adapters/class-event-ref.php';
require_once LSG_BL_PLUGIN_DIR . 'includes/adapters/class-raceresult-adapter.php';

if ( file_exists( LSG_BL_PLUGIN_DIR . 'includes/adapters/class-runtix-adapter.php' ) ) {
	require_once LSG_BL_PLUGIN_DIR . 'includes/adapters/class-runtix-adapter.php';
}

/**
 * Eine Fixture einlesen.
 *
 * @param string $name Dateiname unter tests/fixtures/.
 * @return string
 */
function lsg_bl_fixture( $name ) {
	$pfad = LSG_BL_TESTS_DIR . 'fixtures/' . $name;
	if ( ! is_readable( $pfad ) ) {
		throw new RuntimeException(
			'Fixture fehlt: ' . $pfad . ' – siehe Plan, Abschnitt 10 (Testdaten).'
		);
	}
	return (string) file_get_contents( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Einen Adapter mit einem Getter bauen, der statt des Netzes eine Fixture
 * liefert. Genau dafuer ist der Getter injizierbar (Plan, Abschnitt 5).
 *
 * @param array $antworten URL-Teilstring => Fixture-Inhalt.
 * @return callable
 */
function lsg_bl_fake_getter( array $antworten ) {
	return function ( $url ) use ( $antworten ) {
		foreach ( $antworten as $muster => $inhalt ) {
			if ( false !== strpos( $url, $muster ) ) {
				return $inhalt;
			}
		}
		throw new LSG_BL_Quelle_Exception( 'Kein Fixture fuer ' . $url );
	};
}
