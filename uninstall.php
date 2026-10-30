<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-lpf-media.php';
LPF_Media::delete_all();

delete_option( 'lpf_settings' );
delete_option( 'lpf_token' );
delete_option( 'lpf_feed_cache' );
delete_option( 'lpf_orgs' );
delete_transient( 'lpf_admin_orgs' );
