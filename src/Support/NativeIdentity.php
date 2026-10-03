<?php

namespace FourmixIntelligence\WordPress\Support;

/** 共用の業務ユーザーと、監査対象の本人のログインを分離します。 */
final class NativeIdentity {
	public static function key(): string {
		return 'fmi_staff_' . hash_hmac( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . wp_get_session_token(), wp_salt( 'auth' ) );
	}
	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=fourmix_intelligence_identity_callback' );
	}
	public static function register(): void {
		add_action( 'admin_post_fourmix_intelligence_identity_start', array( self::class, 'start' ) );
		add_action( 'admin_post_fourmix_intelligence_identity_callback', array( self::class, 'callback' ) );
	}
	public static function login_url( string $return_url = '' ): string {
		$referer    = wp_get_referer();
		$return_url = '' !== $return_url ? $return_url : ( $referer ? $referer : admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) );
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'fourmix_intelligence_identity_start',
					'return' => $return_url,
				),
				admin_url( 'admin-post.php' )
			),
			'fmi_identity_start'
		);
	}
	public static function start(): void {
		check_admin_referer( 'fmi_identity_start' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'この画面を利用できません。', 'fourmix-intelligence' ), '', array( 'response' => 403 ) );
		}
		delete_transient( self::key() ); // 別の本人へのログイン開始後に以前の資格を流用しません。
		$state      = bin2hex( random_bytes( 32 ) );
		$verifier   = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
		$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return'] ?? '' ) ), admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) );
		if ( ! str_starts_with( $return_url, admin_url() ) ) {
			$return_url = admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' );
		}
		set_transient(
			self::key() . '_authorization',
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'return'   => $return_url,
			),
			5 * MINUTE_IN_SECONDS
		);
		$url         = add_query_arg(
			array(
				'tenant'                => Options::get( 'native_tenant', '' ),
				'connection'            => Options::get( 'native_connection', '' ),
				'provider'              => 'wordpress',
				'redirect_uri'          => self::callback_url(),
				'state'                 => $state,
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
				'code_challenge_method' => 'S256',
			),
			rtrim( Options::get( 'portal_url', 'https://ai.fourmix.co.jp' ), '/' ) . '/native-business-authorize'
		);
		$portal_host = wp_parse_url( $url, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', static fn( $hosts ) => array_merge( $hosts, array( $portal_host ) ) );
		wp_safe_redirect( $url );
		exit;
	}
	public static function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 一度だけ有効な state を保存済みの WordPress セッションで検証します。
		$pending = get_transient( self::key() . '_authorization' );
		delete_transient( self::key() . '_authorization' );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		if ( ! is_array( $pending ) || ! hash_equals( $pending['state'], $state ) ) {
			wp_die( esc_html__( 'ログインの確認情報が無効です。元の画面からやり直してください。', 'fourmix-intelligence' ), '', array( 'response' => 403 ) );
		}
		try {
			if ( ! empty( $_GET['error'] ) ) {
				wp_safe_redirect( add_query_arg( 'fmi_identity', 'cancelled', $pending['return'] ) );
				exit;
			}
			$data = self::platform(
				'POST',
				'/exchange',
				array(
					'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ),
					'redirect_uri'  => self::callback_url(),
					'code_verifier' => $pending['verifier'],
				)
			);
			self::save( $data );
			wp_safe_redirect( $pending['return'] );
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 403 ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	public static function save( array $data ): void {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- provider はサーバーの固定識別子です。
		if ( empty( $data['token'] ) || empty( $data['account_id'] ) || (string) ( $data['connection_id'] ?? '' ) !== (string) Options::get( 'native_connection', '' ) || 'wordpress' !== ( $data['provider'] ?? '' ) ) {
			throw new \RuntimeException( esc_html__( '本人の接続情報を確認できません。', 'fourmix-intelligence' ), 401 );
		}
		$iv        = random_bytes( 12 );
		$tag       = '';
		$encrypted = openssl_encrypt( $data['token'], 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), 0, $iv, $tag );
		if ( false === $encrypted ) {
			throw new \RuntimeException( esc_html__( '本人の接続情報を保存できません。', 'fourmix-intelligence' ) );
		}
		set_transient(
			self::key(),
			array(
				'encrypted' => $encrypted,
				'iv'        => base64_encode( $iv ),
				'tag'       => base64_encode( $tag ),
			),
			min( 900, max( 1, (int) ( $data['expires_in'] ?? 900 ) ) )
		);
	}
	public static function token(): string {
		$value = (array) get_transient( self::key() );
		$iv    = base64_decode( $value['iv'] ?? '', true );
		$tag   = base64_decode( $value['tag'] ?? '', true );
		$token = isset( $value['encrypted'] ) && is_string( $iv ) && 12 === strlen( $iv ) && is_string( $tag ) && 16 === strlen( $tag ) ? openssl_decrypt( $value['encrypted'], 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), 0, $iv, $tag ) : false;
		if ( ! $token ) {
			throw new \RuntimeException( esc_html__( 'Fourmix Intelligence にログインしてください。', 'fourmix-intelligence' ), 401 );
		}
		return $token;
	}
	public static function current( bool $write = false, ?string $token = null ): array {
		$bound_token = self::token();
		if ( null !== $token && ! hash_equals( $bound_token, $token ) ) {
			throw new \RuntimeException( esc_html__( '本人の接続が切り替わっています。画面を開き直してください。', 'fourmix-intelligence' ), 403 );
		}
		$token ??= $bound_token;
		$data    = self::platform( 'GET', '/session', null, $token );
		if ( empty( $data['account_id'] ) || empty( $data['workspace_id'] ) || (string) ( $data['connection_id'] ?? '' ) !== (string) Options::get( 'native_connection', '' ) ) {
			throw new \RuntimeException( esc_html__( 'この業務接続を利用できません。', 'fourmix-intelligence' ), 403 );
		}
		if ( $write && empty( $data['business_write'] ) ) {
			throw new \RuntimeException( esc_html__( '読み取り専用のメンバーは業務情報を更新できません。', 'fourmix-intelligence' ), 403 );
		}
		return $data;
	}
	public static function scope( array $identity ): string {
		return 'fmi_native_' . hash_hmac( 'sha256', get_current_blog_id() . ':' . $identity['workspace_id'] . ':' . $identity['account_id'] . ':' . $identity['connection_id'], wp_salt( 'auth' ) );
	}
	public static function platform( string $method, string $suffix, ?array $body = null, string $token = '' ): array {
		$path = '/session' === $suffix ? '/api/v3/native-business/session' : '/api/v3/native-business/' . rawurlencode( (string) Options::get( 'native_tenant', '' ) ) . '/' . rawurlencode( (string) Options::get( 'native_connection', '' ) ) . $suffix;
		$url  = rtrim( (string) Options::get( 'platform_url', 'https://platform.ai.fourmix.co.jp' ), '/' ) . $path;
		$args = array(
			'method'              => $method,
			'timeout'             => 20,
			'redirection'         => 0,
			'limit_response_size' => 65536,
			'headers'             => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'                => null === $body ? null : wp_json_encode( $body ),
		);
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		$local = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'FOURMIX_INTELLIGENCE_PLATFORM_URL' ) && str_starts_with( $url, rtrim( constant( 'FOURMIX_INTELLIGENCE_PLATFORM_URL' ), '/' ) . '/' ) && in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'platform', 'localhost', '127.0.0.1' ), true );
		$reply = $local ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		$code  = is_wp_error( $reply ) ? 502 : wp_remote_retrieve_response_code( $reply );
		if ( $code < 200 || $code >= 300 ) {
			if ( 401 === $code ) {
				delete_transient( self::key() );
			}
			throw new \RuntimeException( 401 === $code ? esc_html__( 'Fourmix Intelligence に再度ログインしてください。', 'fourmix-intelligence' ) : ( 403 === $code ? esc_html__( 'このワークスペースの業務助手を利用できません。', 'fourmix-intelligence' ) : esc_html__( '本人の権限を確認できません。操作を再送せず、接続を確認してください。', 'fourmix-intelligence' ) ), (int) $code );
		}
		$data = json_decode( wp_remote_retrieve_body( $reply ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( esc_html__( '本人の権限を確認できません。', 'fourmix-intelligence' ), 502 );
		}
		return $data;
	}
}
