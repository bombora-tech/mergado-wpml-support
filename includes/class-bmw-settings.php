<?php
/**
 * Nastavení a stav (options) + pomocné funkce pro WPML a cesty.
 *
 * @package Bombora\MergadoWpml
 */

namespace Bombora\MergadoWpml;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION  = 'bmw_settings';
	public const STATUS  = 'bmw_status';
	public const LOCK    = 'bmw_lock';
	public const PARAM   = 'zg_lang';
	public const OUT_DIR = 'bombora-feeds';

	/**
	 * @return array{cron_url:string,lock_minutes:int}
	 */
	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'cron_url'     => isset( $s['cron_url'] ) ? (string) $s['cron_url'] : '',
			'lock_minutes' => isset( $s['lock_minutes'] ) ? max( 5, min( 120, (int) $s['lock_minutes'] ) ) : 20,
		);
	}

	/**
	 * @param array<string,mixed> $in Vstup z formuláře.
	 * @return array{cron_url:string,lock_minutes:int}|\WP_Error
	 */
	public static function sanitize( array $in ) {
		$url = isset( $in['cron_url'] ) ? esc_url_raw( trim( (string) $in['cron_url'] ) ) : '';

		if ( '' !== $url ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );

			if ( ! $host || strtolower( (string) $host ) !== strtolower( (string) $home ) ) {
				return new \WP_Error( 'host', __( 'The cron URL must point to this website.', 'bombora-mergado-wpml' ) );
			}
			if ( ( $q['action'] ?? '' ) !== 'productCron' || empty( $q['token'] ) ) {
				return new \WP_Error( 'url', __( 'This is not a Mergado product cron URL (action=productCron and token are missing).', 'bombora-mergado-wpml' ) );
			}
			// Jazykové parametry odstranit – přidávají se pro každý jazyk zvlášť.
			$url = remove_query_arg( array( self::PARAM, 'lang' ), $url );
		}

		return array(
			'cron_url'     => $url,
			'lock_minutes' => isset( $in['lock_minutes'] ) ? max( 5, min( 120, (int) $in['lock_minutes'] ) ) : 20,
		);
	}

	/**
	 * Poslední dokončené běhy podle jazyka.
	 *
	 * @return array<string,array{finished:int,files:array<int,array<string,mixed>>}>
	 */
	public static function status(): array {
		$s = get_option( self::STATUS, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Aktivní jazyky WPML, výchozí první. Prázdné pole = web bez WPML.
	 *
	 * @return array<string,string> kód => název
	 */
	public static function languages(): array {
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( empty( $langs ) || ! is_array( $langs ) ) {
			return array();
		}
		$out = array();
		foreach ( $langs as $code => $l ) {
			$out[ (string) $code ] = (string) ( $l['translated_name'] ?? $l['native_name'] ?? $code );
		}
		$default = (string) apply_filters( 'wpml_default_language', null );
		if ( $default && isset( $out[ $default ] ) ) {
			$out = array( $default => $out[ $default ] ) + $out;
		}
		return $out;
	}

	public static function default_language(): string {
		return (string) apply_filters( 'wpml_default_language', null );
	}

	public static function cron_url( string $lang ): string {
		$base = self::get()['cron_url'];
		return '' === $base ? '' : add_query_arg( self::PARAM, $lang, $base );
	}

	/** Složka pro kopie feedů (uploads/bombora-feeds). */
	public static function out_dir(): string {
		$u = wp_upload_dir( null, false );
		return rtrim( (string) $u['basedir'], '/' ) . '/' . self::OUT_DIR;
	}

	/**
	 * Složky, ve kterých Mergado Pack drží XML (uploads/*mergado*, wp-content/*mergado*).
	 *
	 * @return string[]
	 */
	public static function feed_roots(): array {
		$u     = wp_upload_dir( null, false );
		$roots = array_merge(
			(array) glob( rtrim( (string) $u['basedir'], '/' ) . '/*mergado*', GLOB_ONLYDIR ),
			(array) glob( WP_CONTENT_DIR . '/*mergado*', GLOB_ONLYDIR )
		);
		$out   = wp_normalize_path( self::out_dir() );
		$roots = array_filter(
			array_unique( array_filter( $roots ) ),
			static fn( $r ) => wp_normalize_path( (string) $r ) !== $out
		);
		return array_values( $roots );
	}

	/** Počet publikovaných produktů v jazyce (kontrola překladů). */
	public static function product_count( string $lang ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}icl_translations t
				 INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
				 WHERE t.element_type = 'post_product' AND t.language_code = %s AND p.post_status = 'publish'",
				$lang
			)
		);
	}
}
