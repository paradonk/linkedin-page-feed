<?php
/**
 * Plugin Name:       LinkedIn Page Feed
 * Description:       Show the latest posts from a LinkedIn Company Page with the [linkedin_feed] shortcode.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            paradonk
 * License:           GPL-2.0-or-later
 * Text Domain:       linkedin-page-feed
 */

defined( 'ABSPATH' ) || exit;

define( 'LPF_VERSION', '0.2.0' );
define( 'LPF_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-lpf-api.php';
require_once __DIR__ . '/includes/class-lpf-media.php';
require_once __DIR__ . '/includes/class-lpf-shortcode.php';

LPF_Shortcode::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-lpf-admin.php';
	LPF_Admin::init();
}
