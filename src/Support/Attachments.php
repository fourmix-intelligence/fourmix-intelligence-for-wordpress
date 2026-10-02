<?php

namespace FourmixIntelligence\WordPress\Support;

/** 上流の添付ポリシーとWordPressの受付上限の両方を適用します。 */
final class Attachments {
	public const EXTENSIONS = array( '.png', '.jpg', '.jpeg', '.webp', '.pdf', '.txt', '.md', '.csv', '.tsv', '.docx', '.xlsx', '.pptx' );
	public static function policy( array $value ): array {
		return array(
			'enabled'       => true === ( $value['enabled'] ?? false ),
			'extensions'    => array_values( array_intersect( self::EXTENSIONS, (array) ( $value['extensions'] ?? array() ) ) ),
			'max_files'     => min( 8, max( 0, (int) ( $value['max_files'] ?? 0 ) ) ),
			'max_bytes'     => min( 20 * MB_IN_BYTES, max( 0, (int) ( $value['max_bytes'] ?? 0 ) ), max( 0, wp_max_upload_size() - 1024 ) ),
			'context_bytes' => min( 40 * MB_IN_BYTES, max( 0, (int) ( $value['context_bytes'] ?? 0 ) ) ),
		);
	}
	public static function uuid( string $value ): bool {
		return (bool) preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value );
	}
	public static function file( array $file, array $policy ): array {
		$name = sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( ! $policy['enabled'] || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_uploaded_file( $path ) || ! in_array( '.' . strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), $policy['extensions'], true ) ) {
			throw new \InvalidArgumentException( esc_html__( '対応する画像・PDF・Office文書・テキストを選択してください。', 'fourmix-intelligence' ) );
		}
		$size = filesize( $path );
		if ( ! $size || $size > $policy['max_bytes'] ) {
			throw new \InvalidArgumentException( esc_html__( 'ファイルが空、または受付サイズを超えています。', 'fourmix-intelligence' ) );
		}
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHPが受け付けた一時アップロードだけを読みます。
		if ( false === $body ) {
			throw new \RuntimeException( esc_html__( '添付を読み込めませんでした。', 'fourmix-intelligence' ) );
		}
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'png', 'jpg', 'jpeg', 'webp' ), true ) ) {
			$image    = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 不正なアップロードの警告をJSONへ混ぜず、直後に明示拒否します。
			$expected = array(
				'png'  => IMAGETYPE_PNG,
				'jpg'  => IMAGETYPE_JPEG,
				'jpeg' => IMAGETYPE_JPEG,
				'webp' => IMAGETYPE_WEBP,
			);
			if ( ! $image || $image[2] !== $expected[ $extension ] || $image[0] * $image[1] > 40000000 ) {
				throw new \InvalidArgumentException( esc_html__( '画像の形式または大きさを確認してください。', 'fourmix-intelligence' ) );
			}
		} elseif ( 'pdf' === $extension && ! str_starts_with( $body, '%PDF-' ) ) {
			throw new \InvalidArgumentException( esc_html__( 'PDFの内容を確認してください。', 'fourmix-intelligence' ) );
		} elseif ( in_array( $extension, array( 'txt', 'md', 'csv', 'tsv' ), true ) && ( str_contains( $body, "\0" ) || ! mb_check_encoding( $body, 'UTF-8' ) ) ) {
			throw new \InvalidArgumentException( esc_html__( 'テキストはUTF-8で保存してください。', 'fourmix-intelligence' ) );
		} elseif ( in_array( $extension, array( 'docx', 'xlsx', 'pptx' ), true ) && ! str_starts_with( $body, "PK\x03\x04" ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Office文書の内容を確認してください。', 'fourmix-intelligence' ) );
		}
		return array(
			'name'   => $name,
			'size'   => $size,
			'body'   => $body,
			'sha256' => hash( 'sha256', $body ),
		);
	}
}
