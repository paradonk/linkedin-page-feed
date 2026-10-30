<?php
// Settings → LinkedIn Feed: app credentials, OAuth connect, cache control.

defined( 'ABSPATH' ) || exit;

class LPF_Admin {
	const SLUG = 'linkedin-page-feed';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_lpf_oauth_start', array( __CLASS__, 'oauth_start' ) );
		add_action( 'admin_post_lpf_oauth_callback', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_lpf_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_lpf_clear_cache', array( __CLASS__, 'clear_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/linkedin-page-feed.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'LinkedIn Feed', 'LinkedIn Feed', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'linkedin-page-feed' ) . '</a>' );
		return $links;
	}

	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	public static function register_settings() {
		register_setting( 'lpf', 'lpf_settings', array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$old = get_option( 'lpf_settings', array() );
		$out = array(
			'client_id'     => sanitize_text_field( $in['client_id'] ?? '' ),
			'org_id'        => preg_replace( '/\D/', '', $in['org_id'] ?? '' ),
			'version'       => preg_match( '/^\d{6}$/', $in['version'] ?? '' ) ? $in['version'] : '202609',
			'cache_minutes' => max( 5, (int) ( $in['cache_minutes'] ?? 60 ) ),
			'logo_url'      => esc_url_raw( $in['logo_url'] ?? '' ),
		);
		// Blank secret field means "keep the saved one"
		$secret               = trim( $in['client_secret'] ?? '' );
		$out['client_secret'] = '' !== $secret ? $secret : ( $old['client_secret'] ?? '' );
		LPF_API::clear_cache();
		return $out;
	}

	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'linkedin-page-feed' ), 403 );
		}
		if ( $action ) {
			check_admin_referer( $action );
		}
	}

	private static function back( $notice, $type = 'success' ) {
		set_transient( 'lpf_notice_' . get_current_user_id(), array( $type, $notice ), 60 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function oauth_start() {
		self::check( 'lpf_oauth_start' );
		$state = wp_generate_password( 32, false );
		set_transient( 'lpf_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );
		wp_redirect( LPF_API::authorize_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- external OAuth host
		exit;
	}

	public static function oauth_callback() {
		self::check( null ); // CSRF is covered by the OAuth state below
		$key      = 'lpf_oauth_state_' . get_current_user_id();
		$expected = get_transient( $key );
		delete_transient( $key );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ?? '' ) );
		if ( $error ) {
			self::back( 'LinkedIn: ' . $error, 'error' );
		}
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		if ( ! $expected || ! hash_equals( $expected, $state ) ) {
			self::back( __( 'Invalid or expired OAuth state. Please try connecting again.', 'linkedin-page-feed' ), 'error' );
		}
		$t = LPF_API::exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ) );
		// phpcs:enable
		if ( is_wp_error( $t ) ) {
			self::back( $t->get_error_message(), 'error' );
		}
		LPF_API::clear_cache();
		self::back( __( 'LinkedIn connected.', 'linkedin-page-feed' ) );
	}

	public static function disconnect() {
		self::check( 'lpf_disconnect' );
		delete_option( LPF_API::TOKEN_OPT );
		LPF_API::clear_cache();
		self::back( __( 'LinkedIn disconnected.', 'linkedin-page-feed' ) );
	}

	public static function clear_cache() {
		self::check( 'lpf_clear_cache' );
		LPF_API::clear_cache();
		self::back( __( 'Feed cache cleared.', 'linkedin-page-feed' ) );
	}

	private static function action_button( $action, $label, $class = 'button' ) {
		printf(
			'<a class="%s" href="%s">%s</a> ',
			esc_attr( $class ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action ) ),
			esc_html( $label )
		);
	}

	public static function page() {
		$s      = LPF_API::settings();
		$token  = LPF_API::token();
		$notice = get_transient( 'lpf_notice_' . get_current_user_id() );
		delete_transient( 'lpf_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>LinkedIn Page Feed</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( '1. LinkedIn app', 'linkedin-page-feed' ); ?></h2>
			<p>
				<?php esc_html_e( 'Add this exact URL under Authorized redirect URLs in your app’s Auth tab:', 'linkedin-page-feed' ); ?><br>
				<code><?php echo esc_html( LPF_API::redirect_uri() ); ?></code>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'lpf' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="lpf-client-id">Client ID</label></th>
						<td><input id="lpf-client-id" class="regular-text" name="lpf_settings[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="lpf-secret">Client secret</label></th>
						<td>
							<?php if ( defined( 'LPF_CLIENT_SECRET' ) ) : ?>
								<em><?php esc_html_e( 'Set by LPF_CLIENT_SECRET in wp-config.php', 'linkedin-page-feed' ); ?></em>
							<?php else : ?>
								<input id="lpf-secret" class="regular-text" type="password" name="lpf_settings[client_secret]" autocomplete="new-password"
									placeholder="<?php echo $s['client_secret'] ? esc_attr__( 'Saved — leave blank to keep', 'linkedin-page-feed' ) : ''; ?>">
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="lpf-org">Default Page ID</label></th>
						<td>
							<input id="lpf-org" class="small-text" style="width:8em" name="lpf_settings[org_id]" value="<?php echo esc_attr( $s['org_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used when the shortcode has no org="…".', 'linkedin-page-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lpf-logo">Logo URL</label></th>
						<td>
							<input id="lpf-logo" class="regular-text" type="url" name="lpf_settings[logo_url]" value="<?php echo esc_attr( $s['logo_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Overrides the Page logo shown on each card.', 'linkedin-page-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lpf-version">API version</label></th>
						<td><input id="lpf-version" class="small-text" style="width:6em" name="lpf_settings[version]" value="<?php echo esc_attr( $s['version'] ); ?>"> <span class="description">YYYYMM</span></td>
					</tr>
					<tr>
						<th><label for="lpf-cache">Cache (minutes)</label></th>
						<td><input id="lpf-cache" class="small-text" type="number" min="5" name="lpf_settings[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( '2. Connection', 'linkedin-page-feed' ); ?></h2>
			<?php if ( ! $s['client_id'] || ! $s['client_secret'] ) : ?>
				<p><?php esc_html_e( 'Save the Client ID and secret first.', 'linkedin-page-feed' ); ?></p>
			<?php elseif ( ! $token ) : ?>
				<p><?php self::action_button( 'lpf_oauth_start', __( 'Connect LinkedIn', 'linkedin-page-feed' ), 'button button-primary' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: access token expiry date, 2: refresh token expiry date */
						esc_html__( 'Connected. Access token valid until %1$s; refreshes automatically until %2$s.', 'linkedin-page-feed' ),
						esc_html( wp_date( get_option( 'date_format' ), $token['expires_at'] ) ),
						esc_html( ! empty( $token['refresh_token_expires_at'] ) ? wp_date( get_option( 'date_format' ), $token['refresh_token_expires_at'] ) : '—' )
					);
					?>
				</p>
				<?php
				$blocked = max( LPF_API::blocked_until( 'images' ), LPF_API::blocked_until( 'videos' ) );
				if ( $blocked ) :
					?>
					<div class="notice notice-warning inline"><p>
						<?php
						printf(
							/* translators: %s: date and time the limit resets */
							esc_html__( 'LinkedIn’s daily limit for image lookups has been reached, so some posts are shown without images. They will load automatically after %s.', 'linkedin-page-feed' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $blocked ) )
						);
						?>
					</p></div>
				<?php endif; ?>
				<p>
					<?php
					self::action_button( 'lpf_oauth_start', __( 'Reconnect', 'linkedin-page-feed' ) );
					self::action_button( 'lpf_clear_cache', __( 'Clear feed cache', 'linkedin-page-feed' ) );
					self::action_button( 'lpf_disconnect', __( 'Disconnect', 'linkedin-page-feed' ) );
					?>
				</p>
				<h2><?php esc_html_e( '3. Your Pages', 'linkedin-page-feed' ); ?></h2>
				<?php self::render_orgs(); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Usage', 'linkedin-page-feed' ); ?></h2>
			<p><code>[linkedin_feed]</code> &nbsp; <code>[linkedin_feed org="1234567" count="12" cols="4"]</code></p>
		</div>
		<?php
	}

	private static function render_orgs() {
		$orgs = get_transient( 'lpf_admin_orgs' );
		if ( false === $orgs ) {
			$orgs = LPF_API::admin_orgs();
			if ( ! is_wp_error( $orgs ) ) {
				set_transient( 'lpf_admin_orgs', $orgs, HOUR_IN_SECONDS );
			}
		}
		if ( is_wp_error( $orgs ) ) {
			echo '<pre>' . esc_html( $orgs->get_error_message() ) . '</pre>';
			return;
		}
		if ( ! $orgs ) {
			echo '<p>' . esc_html__( 'No Pages found for this account.', 'linkedin-page-feed' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Page</th><th>ID</th><th>Role</th><th>Shortcode</th></tr></thead><tbody>';
		foreach ( $orgs as $o ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><code>[linkedin_feed org="%s"]</code></td></tr>',
				esc_html( $o['name'] ),
				esc_html( $o['id'] ),
				esc_html( $o['role'] ),
				esc_html( $o['id'] )
			);
		}
		echo '</tbody></table>';
	}
}
