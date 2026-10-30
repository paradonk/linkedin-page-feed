<?php
// [linkedin_feed org="1234567" count="24" cols="3"]

defined( 'ABSPATH' ) || exit;

class LPF_Shortcode {
	const ICONS = array(
		'clock'   => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 4.5V8l2.5 1.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
		'like'    => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 7v7H2.5V7H5zm0 0 2.6-5c.9 0 1.6.8 1.4 1.7L8.6 6H13c.8 0 1.4.8 1.2 1.6l-1.3 5.2c-.2.7-.8 1.2-1.5 1.2H5" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>',
		'comment' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 3.5C2 2.7 2.7 2 3.5 2h9c.8 0 1.5.7 1.5 1.5v6c0 .8-.7 1.5-1.5 1.5H7l-3.5 3v-3h0c-.8 0-1.5-.7-1.5-1.5v-6z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>',
		'play'    => '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="#0006" stroke="#fff" stroke-width="3"/><path d="M19 15l14 9-14 9z" fill="#fff"/></svg>',
	);

	public static function init() {
		add_shortcode( 'linkedin_feed', array( __CLASS__, 'render' ) );
		add_action(
			'wp_enqueue_scripts',
			function () {
				wp_register_style( 'lpf-feed', LPF_URL . 'assets/feed.css', array(), LPF_VERSION );
			}
		);
	}

	public static function render( $atts ) {
		$s     = LPF_API::settings();
		$atts  = shortcode_atts(
			array(
				'org'   => $s['org_id'],
				'count' => 24,
				'cols'  => 3,
			),
			$atts,
			'linkedin_feed'
		);
		$org   = (string) $atts['org'];
		$count = min( LPF_API::MAX_COUNT, max( 1, (int) $atts['count'] ) );
		$cols  = min( 6, max( 1, (int) $atts['cols'] ) );

		if ( ! ctype_digit( $org ) ) {
			return self::error( __( 'LinkedIn Feed: set a Page ID in Settings → LinkedIn Feed or use org="…".', 'linkedin-page-feed' ) );
		}
		$posts = LPF_API::posts( $org, $count );
		if ( is_wp_error( $posts ) ) {
			return self::error( 'LinkedIn Feed: ' . $posts->get_error_message() );
		}

		wp_enqueue_style( 'lpf-feed' );
		if ( ! $posts ) {
			return '<p class="lpf-empty">' . esc_html__( 'No posts yet.', 'linkedin-page-feed' ) . '</p>';
		}
		$info = LPF_API::org( $org );
		$name = $info['name'] ?: 'LinkedIn';
		$logo = $s['logo_url'] ?: $info['logo'];

		ob_start();
		?>
		<div class="lpf-grid" data-cols="<?php echo esc_attr( $cols ); ?>" style="--lpf-cols:<?php echo esc_attr( $cols ); ?>">
			<?php foreach ( $posts as $p ) : ?>
				<a class="lpf-card" href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener">
					<div class="lpf-head">
						<span class="lpf-author">
							<?php if ( $logo ) : ?>
								<img class="lpf-logo" src="<?php echo esc_url( $logo ); ?>" alt="">
							<?php else : ?>
								<span class="lpf-logo lpf-logo-text"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
							<?php endif; ?>
							<span class="lpf-name"><?php echo esc_html( $name ); ?></span>
						</span>
						<time class="lpf-date" datetime="<?php echo esc_attr( gmdate( 'c', $p['date'] ) ); ?>">
							<?php echo self::ICONS['clock']; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), $p['date'] ) ); ?>
						</time>
					</div>
					<div class="lpf-text<?php echo $p['images'] ? '' : ' lpf-text-only'; ?>"><?php echo esc_html( trim( preg_replace( '/\s+/u', ' ', $p['text'] ?: ( $p['article']['title'] ?? '' ) ) ) ); ?></div>
					<?php if ( $p['images'] ) : ?>
						<div class="lpf-media">
							<img src="<?php echo esc_url( $p['images'][0] ); ?>" alt="" loading="lazy">
							<?php if ( $p['isVideo'] ) : ?>
								<span class="lpf-play"><?php echo self::ICONS['play']; // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?></span>
							<?php endif; ?>
						</div>
					<?php elseif ( $p['article'] && $p['article']['title'] && $p['text'] ) : ?>
						<div class="lpf-article">🔗 <?php echo esc_html( $p['article']['title'] ); ?></div>
					<?php endif; ?>
					<div class="lpf-foot">
						<?php if ( null !== $p['likes'] ) : ?>
							<span title="<?php esc_attr_e( 'Reactions', 'linkedin-page-feed' ); ?>"><?php echo self::ICONS['like']; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( number_format_i18n( $p['likes'] ) ); ?></span>
							<span title="<?php esc_attr_e( 'Comments', 'linkedin-page-feed' ); ?>"><?php echo self::ICONS['comment']; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( number_format_i18n( $p['comments'] ) ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'View on LinkedIn', 'linkedin-page-feed' ); ?></span>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// Errors are only visible to admins; visitors see nothing
	private static function error( $msg ) {
		return current_user_can( 'manage_options' ) ? '<pre class="lpf-error">' . esc_html( $msg ) . '</pre>' : '';
	}
}
