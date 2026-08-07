<?php
/**
 * Server-Render-Callback für den Block lsg-bestenliste/ewige-bestenliste.
 *
 * @var array $attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo lsg_bl_render_ewige_block( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
