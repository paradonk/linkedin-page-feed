<?php
// Local copies of LinkedIn images in uploads/linkedin-page-feed/.
// LinkedIn download URLs expire and the lookup API is rate limited, so each URN is fetched once.

defined( 'ABSPATH' ) || exit;

class LPF_Media {
	const OPT = 'lpf_media'; // urn -> file name

	private static function dir() {
		$u = wp_upload_dir( null, false );
		return array( $u['basedir'] . '/linkedin-page-feed', $u['baseurl'] . '/linkedin-page-feed' );
	}

	public static function url( $urn ) {
		$file = get_option( self::OPT, array() )[ $urn ] ?? null;
		return $file ? self::dir()[1] . '/' . $file : null;
	}

	// Downloads urn => remote URL; returns urn => local URL for the ones saved
	public static function store( $remote ) {
		if ( ! $remote ) {
			return array();
		}
		list( $dir ) = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return array();
		}
		$map = get_option( self::OPT, array() );
		$out = array();
		$exts = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		foreach ( $remote as $urn => $src ) {
			$res = wp_remote_get( $src, array( 'timeout' => 20 ) );
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				continue;
			}
			$body = wp_remote_retrieve_body( $res );
			$info = getimagesizefromstring( $body );
			$ext  = $info ? ( $exts[ $info['mime'] ] ?? null ) : null;
			if ( ! $ext ) {
				continue;
			}
			$file = sanitize_file_name( str_replace( ':', '-', $urn ) ) . '.' . $ext;
			if ( false === file_put_contents( $dir . '/' . $file, $body ) ) {
				continue;
			}
			$map[ $urn ] = $file;
			$out[ $urn ] = self::dir()[1] . '/' . $file;
		}
		update_option( self::OPT, $map, false );
		return $out;
	}

	public static function delete_all() {
		list( $dir ) = self::dir();
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		delete_option( self::OPT );
	}
}
