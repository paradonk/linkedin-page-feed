<?php
// LinkedIn REST API client: OAuth tokens, Page posts, and the feed cache.

defined( 'ABSPATH' ) || exit;

class LPF_API {
	const SCOPES     = 'r_organization_social rw_organization_admin';
	const CACHE_OPT  = 'lpf_feed_cache'; // "orgId:count" -> [ 'at' => time, 'posts' => [...] ]
	const TOKEN_OPT  = 'lpf_token';
	const MAX_COUNT  = 100;

	public static function settings() {
		$s = wp_parse_args(
			get_option( 'lpf_settings', array() ),
			array(
				'client_id'     => '',
				'client_secret' => '',
				'org_id'        => '',
				'version'       => '202609',
				'cache_minutes' => 60,
				'logo_url'      => '',
			)
		);
		// Allow keeping the secret out of the database
		if ( defined( 'LPF_CLIENT_SECRET' ) ) {
			$s['client_secret'] = LPF_CLIENT_SECRET;
		}
		return $s;
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=lpf_oauth_callback' );
	}

	public static function authorize_url( $state ) {
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => self::settings()['client_id'],
				'redirect_uri'  => self::redirect_uri(),
				'state'         => $state,
				'scope'         => self::SCOPES,
			),
			'https://www.linkedin.com/oauth/v2/authorization'
		);
	}

	public static function token() {
		$t = get_option( self::TOKEN_OPT );
		return is_array( $t ) && ! empty( $t['access_token'] ) ? $t : null;
	}

	public static function exchange_code( $code ) {
		return self::request_token(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
			)
		);
	}

	private static function request_token( $params ) {
		$s   = self::settings();
		$res = wp_remote_post(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'timeout' => 15,
				'body'    => $params + array(
					'client_id'     => $s['client_id'],
					'client_secret' => $s['client_secret'],
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$t = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $t['access_token'] ) ) {
			$msg = $t['error_description'] ?? $t['error'] ?? wp_remote_retrieve_body( $res );
			return new WP_Error( 'lpf_token', 'LinkedIn token request failed: ' . $msg );
		}
		$t['obtained_at'] = time();
		// A refresh response may omit the refresh token; keep the one we have
		$old = self::token();
		if ( empty( $t['refresh_token'] ) && $old && ! empty( $old['refresh_token'] ) ) {
			$t['refresh_token']            = $old['refresh_token'];
			$t['refresh_token_expires_at'] = $old['refresh_token_expires_at'] ?? 0;
		} elseif ( ! empty( $t['refresh_token_expires_in'] ) ) {
			$t['refresh_token_expires_at'] = time() + (int) $t['refresh_token_expires_in'];
		}
		$t['expires_at'] = time() + (int) $t['expires_in'];
		update_option( self::TOKEN_OPT, $t, false );
		return $t;
	}

	// Returns a usable token, refreshing it when it expires within a day
	private static function access_token() {
		$t = self::token();
		if ( ! $t ) {
			return new WP_Error( 'lpf_no_token', 'LinkedIn is not connected.' );
		}
		if ( $t['expires_at'] - time() > DAY_IN_SECONDS ) {
			return $t['access_token'];
		}
		$can_refresh = ! empty( $t['refresh_token'] ) && ( $t['refresh_token_expires_at'] ?? 0 ) > time();
		if ( $can_refresh ) {
			$new = self::request_token(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $t['refresh_token'],
				)
			);
			if ( ! is_wp_error( $new ) ) {
				return $new['access_token'];
			}
		}
		return $t['expires_at'] > time()
			? $t['access_token']
			: new WP_Error( 'lpf_expired', 'LinkedIn token expired. Reconnect in Settings → LinkedIn Feed.' );
	}

	public static function get( $path ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_get(
			'https://api.linkedin.com/rest' . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'LinkedIn-Version'          => self::settings()['version'],
					'X-Restli-Protocol-Version' => '2.0.0',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'lpf_api', "LinkedIn API {$code} {$path}\n{$body}" );
		}
		return json_decode( $body, true );
	}

	public static function admin_orgs() {
		$acls = self::get( '/organizationAcls?q=roleAssignee&state=APPROVED' );
		if ( is_wp_error( $acls ) ) {
			return $acls;
		}
		$orgs = array();
		foreach ( $acls['elements'] ?? array() as $el ) {
			$id     = substr( strrchr( $el['organization'], ':' ), 1 );
			$org    = self::get( '/organizations/' . $id );
			$orgs[] = array(
				'id'   => $id,
				'name' => ( ! is_wp_error( $org ) && ! empty( $org['localizedName'] ) ) ? $org['localizedName'] : $el['organization'],
				'role' => $el['role'],
			);
		}
		return $orgs;
	}

	// LinkedIn "little text" -> plain text: hashtags, mentions, backslash escapes
	public static function plain_text( $s ) {
		$s = preg_replace( '/\{hashtag\|\\\\?#\|([^}]+)\}/', '#$1', (string) $s );
		$s = preg_replace( '/@\[([^\]]+)\]\([^)]*\)/', '$1', $s );
		return preg_replace( '/\\\\([\\\\|{}@\[\]()<>#*_~])/', '$1', $s );
	}

	private static function ids_list( $urns ) {
		return 'List(' . implode( ',', array_map( 'rawurlencode', $urns ) ) . ')';
	}

	// Remote URLs for image and video URNs that aren't stored locally yet.
	// Batch calls only: the image endpoint has a low daily limit.
	private static function media_urls( $image_urns, $video_urns ) {
		$urls = array();
		if ( $image_urns ) {
			$data = self::get( '/images?ids=' . self::ids_list( $image_urns ) );
			if ( is_wp_error( $data ) ) {
				error_log( 'LinkedIn Page Feed: image lookup failed: ' . $data->get_error_message() );
			} else {
				$urls += array_filter( wp_list_pluck( $data['results'] ?? array(), 'downloadUrl' ) );
			}
		}
		if ( $video_urns ) {
			$data = self::get( '/videos?ids=' . self::ids_list( $video_urns ) );
			if ( is_wp_error( $data ) ) {
				error_log( 'LinkedIn Page Feed: video lookup failed: ' . $data->get_error_message() );
			} else {
				$urls += array_filter( wp_list_pluck( $data['results'] ?? array(), 'thumbnail' ) );
			}
		}
		return $urls;
	}

	// Like / comment counts per post URN (needs rw_organization_admin)
	private static function stats( $org_id, $post_urns ) {
		$ugc    = array_filter( $post_urns, fn( $u ) => 0 === strpos( $u, 'urn:li:ugcPost:' ) );
		$shares = array_filter( $post_urns, fn( $u ) => 0 === strpos( $u, 'urn:li:share:' ) );
		if ( ! $ugc && ! $shares ) {
			return array();
		}
		$path = '/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity='
			. rawurlencode( 'urn:li:organization:' . $org_id )
			. ( $ugc ? '&ugcPosts=' . self::ids_list( $ugc ) : '' )
			. ( $shares ? '&shares=' . self::ids_list( $shares ) : '' );
		$data = self::get( $path );
		if ( is_wp_error( $data ) ) {
			error_log( 'LinkedIn Page Feed: stats lookup failed: ' . $data->get_error_message() );
			return array();
		}
		$out = array();
		foreach ( $data['elements'] ?? array() as $el ) {
			$s = $el['totalShareStatistics'] ?? array();
			$out[ $el['ugcPost'] ?? $el['share'] ?? '' ] = array(
				'likes'    => (int) ( $s['likeCount'] ?? 0 ),
				'comments' => (int) ( $s['commentCount'] ?? 0 ),
			);
		}
		return $out;
	}

	// Page name and logo, kept for a week
	public static function org( $org_id ) {
		$all = get_option( 'lpf_orgs', array() );
		$hit = $all[ $org_id ] ?? null;
		if ( $hit && time() - $hit['at'] < WEEK_IN_SECONDS ) {
			return $hit;
		}
		$data = self::get( '/organizations/' . $org_id );
		if ( is_wp_error( $data ) ) {
			return $hit ?: array( 'name' => '', 'logo' => '' );
		}
		$logo     = '';
		$logo_urn = ! empty( $data['logoV2']['original'] ) ? 'urn:li:image:' . substr( strrchr( $data['logoV2']['original'], ':' ), 1 ) : '';
		if ( $logo_urn ) {
			$logo = LPF_Media::url( $logo_urn );
			if ( ! $logo ) {
				$remote = self::media_urls( array( $logo_urn ), array() );
				$logo   = LPF_Media::store( $remote )[ $logo_urn ] ?? '';
			}
		}
		$all[ $org_id ] = array(
			'at'   => $logo || ! $logo_urn ? time() : time() - WEEK_IN_SECONDS + HOUR_IN_SECONDS, // retry a missing logo in an hour
			'name' => $data['localizedName'] ?? '',
			'logo' => $logo,
		);
		update_option( 'lpf_orgs', $all, false );
		return $all[ $org_id ];
	}

	private static function fetch_posts( $org_id, $count ) {
		$author = rawurlencode( 'urn:li:organization:' . $org_id );
		$data   = self::get( "/posts?q=author&author={$author}&count={$count}" );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$items = array();
		foreach ( $data['elements'] ?? array() as $p ) {
			$c        = $p['content'] ?? array();
			$urns     = array();
			$media_id = $c['media']['id'] ?? '';
			if ( 0 === strpos( $media_id, 'urn:li:image:' ) || 0 === strpos( $media_id, 'urn:li:video:' ) ) {
				$urns[] = $media_id; // a video URN stands for its thumbnail
			}
			foreach ( $c['multiImage']['images'] ?? array() as $img ) {
				$urns[] = $img['id'];
			}
			if ( ! empty( $c['article']['thumbnail'] ) ) {
				$urns[] = $c['article']['thumbnail'];
			}
			$items[] = array( $p, $c, $urns );
		}

		$missing = array_values( array_unique( array_filter(
			array_merge( array(), ...array_column( $items, 2 ) ),
			fn( $u ) => ! LPF_Media::url( $u )
		) ) );
		$is_video = fn( $u ) => 0 === strpos( $u, 'urn:li:video:' );
		$remote   = self::media_urls(
			array_values( array_filter( $missing, fn( $u ) => ! $is_video( $u ) ) ),
			array_values( array_filter( $missing, $is_video ) )
		);
		// Stored copies never expire; unstored remote URLs are used as a fallback
		$urls  = LPF_Media::store( $remote ) + $remote;
		$stats = self::stats( $org_id, array_map( fn( $i ) => $i[0]['id'], $items ) );

		$posts = array();
		foreach ( $items as list( $p, $c, $urns ) ) {
			$posts[] = array(
				'url'      => 'https://www.linkedin.com/feed/update/' . $p['id'],
				'text'     => self::plain_text( $p['commentary'] ?? '' ),
				'date'     => (int) ( ( $p['publishedAt'] ?? $p['createdAt'] ) / 1000 ),
				'images'   => array_values( array_filter( array_map( fn( $u ) => LPF_Media::url( $u ) ?: ( $urls[ $u ] ?? null ), $urns ) ) ),
				'isVideo'  => 0 === strpos( $c['media']['id'] ?? '', 'urn:li:video:' ),
				'article'  => isset( $c['article'] ) ? array(
					'title'  => $c['article']['title'] ?? '',
					'source' => $c['article']['source'] ?? '',
				) : null,
				'likes'    => $stats[ $p['id'] ]['likes'] ?? null,
				'comments' => $stats[ $p['id'] ]['comments'] ?? null,
			);
		}
		return $posts;
	}

	// Cached posts; on API failure falls back to the last good result
	public static function posts( $org_id, $count ) {
		$key   = $org_id . ':' . $count;
		$cache = get_option( self::CACHE_OPT, array() );
		$hit   = $cache[ $key ] ?? null;
		$ttl   = max( 5, (int) self::settings()['cache_minutes'] ) * MINUTE_IN_SECONDS;
		if ( $hit && time() - $hit['at'] < $ttl ) {
			return $hit['posts'];
		}

		$posts = self::fetch_posts( $org_id, $count );
		if ( is_wp_error( $posts ) ) {
			error_log( 'LinkedIn Page Feed: ' . $posts->get_error_message() );
			if ( ! $hit ) {
				return $posts;
			}
			// Serve stale posts and retry in 5 minutes rather than on every page view
			$cache[ $key ]['at'] = time() - $ttl + 5 * MINUTE_IN_SECONDS;
			update_option( self::CACHE_OPT, $cache, false );
			return $hit['posts'];
		}
		$cache[ $key ] = array(
			'at'    => time(),
			'posts' => $posts,
		);
		update_option( self::CACHE_OPT, $cache, false );
		return $posts;
	}

	public static function clear_cache() {
		delete_option( self::CACHE_OPT );
		delete_option( 'lpf_orgs' );
		delete_transient( 'lpf_admin_orgs' );
	}
}
