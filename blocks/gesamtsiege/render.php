<?php
/**
 * Server-Render-Callback für den Block lsg-bestenliste/gesamtsiege.
 *
 * @var array $attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo lsg_bl_render_gesamtsiege_block( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
