<?php
/**
 * Zachycení Mergado product cronu (/mergado/?action=productCron&token=…&zg_lang=xx):
 *  - přepne jazyk WPML na xx po celou dobu requestu,
 *  - zámek: dokud se generuje jeden jazyk, cron jiného jazyka se odmítne (HTTP 423),
 *    jinak by se dávky Mergada smíchaly,
 *  - po dokončení zkopíruje hotový XML do uploads/bombora-feeds/<nazev>-<xx>.xml.
 *
 * @package Bombora\MergadoWpml
 */

namespace Bombora\MergadoWpml;

defined( 'ABSPATH' ) || exit;

final class Cron {

	private static string $lang  = '';
	private static int $start    = 0;

	public static function init(): void {
		if ( ! self::is_mergado_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- veřejný cron s tokenem Mergada.
		$raw  = isset( $_GET[ Settings::PARAM ] ) ? (string) wp_unslash( $_GET[ Settings::PARAM ] ) : '';
		$lang = strtolower( (string) preg_replace( '/[^a-z\-]/i', '', $raw ) );
		if ( strlen( $lang ) > 7 ) {
			$lang = '';
		}

		self::guard_lock( $lang );

		if ( '' === $lang ) {
			return; // běžný cron bez jazyka – Mergado generuje výchozí jazyk, nic neměníme
		}

		self::$lang  = $lang;
		self::$start = time() - 2; // rezerva na rozlišení mtime

		// cron-job.org po timeoutu zavře spojení – generování dávky musí doběhnout.
		ignore_user_abort( true );

		// WPML jazyk během requestu znovu určuje z URL → nastavujeme ho opakovaně.
		$switch = array( __CLASS__, 'switch_language' );
		foreach ( array( 'wpml_loaded', 'plugins_loaded', 'setup_theme', 'init', 'wp_loaded' ) as $hook ) {
			add_action( $hook, $switch, PHP_INT_MAX );
		}
		foreach ( array( 'parse_request', 'wp', 'template_redirect' ) as $hook ) {
			add_action( $hook, $switch, 0 );
		}
		// Mergado generuje feed brzy (na init) – WPML tou dobou jazyk z URL znovu nastaví.
		// Proto jazyk přepneme také hned po init WPML a těsně před každým dotazem na produkty a termy.
		foreach ( array( 2, 3, 5, 9 ) as $prio ) {
			add_action( 'init', $switch, $prio );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'before_query' ), 0 );
		add_action( 'pre_get_posts', array( __CLASS__, 'diagnose_query' ), PHP_INT_MAX );
		add_action( 'pre_get_terms', $switch, 0 );

		add_filter( 'wpml_current_language', array( __CLASS__, 'filter_current_language' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'copy_finished_feeds' ), PHP_INT_MAX );
	}

	/**
	 * Těsně před dotazem: přepnout WPML a dát dotazu jazyk i jako query var (WPML ho respektuje).
	 *
	 * @param \WP_Query $q Dotaz.
	 */
	public static function before_query( $q ): void {
		if ( '' === self::$lang || ! ( $q instanceof \WP_Query ) ) {
			return;
		}
		self::switch_language();
		if ( self::is_product_query( $q ) && '' !== self::$lang ) {
			$q->set( 'lang', self::$lang );
		}
	}

	private static function is_product_query( \WP_Query $q ): bool {
		$pt = (array) $q->get( 'post_type' );
		return in_array( 'product', $pt, true ) || in_array( 'product_variation', $pt, true );
	}

	/** @var array<int,array<string,mixed>> */
	private static array $diag = array();

	/**
	 * Diagnostika: jazyk WPML v okamžiku prvních dotazů na produkty + zásobník hooků.
	 *
	 * @param \WP_Query $q Dotaz.
	 */
	public static function diagnose_query( $q ): void {
		if ( '' === self::$lang || count( self::$diag ) >= 3 || ! ( $q instanceof \WP_Query ) || ! self::is_product_query( $q ) ) {
			return;
		}
		global $sitepress, $wp_current_filter;
		self::$diag[] = array(
			'wpml'  => ( is_object( $sitepress ) && method_exists( $sitepress, 'get_current_language' ) ) ? (string) $sitepress->get_current_language() : '-',
			'qlang' => (string) $q->get( 'lang' ),
			'hooks' => implode( ' > ', array_slice( (array) $wp_current_filter, 0, 6 ) ),
			'sf'    => $q->get( 'suppress_filters' ) ? 'yes' : 'no',
		);
	}

	private static function is_mergado_cron(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? (string) wp_unslash( $_GET['action'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return 'productCron' === $action && false !== strpos( $uri, '/mergado' );
	}

	/**
	 * Zámek proti prolínání jazyků. Běh jazyka drží zámek od posledního volání
	 * po dobu „lock_minutes“, nebo dokud se feed nedokončí.
	 */
	private static function guard_lock( string $lang ): void {
		$key   = '' === $lang ? '_default' : $lang;
		$lock  = get_option( Settings::LOCK, array() );
		$ttl   = Settings::get()['lock_minutes'] * MINUTE_IN_SECONDS;
		$now   = time();

		if ( is_array( $lock ) && ! empty( $lock['lang'] ) && $lock['lang'] !== $key && ( $now - (int) $lock['hit'] ) < $ttl ) {
			if ( ! headers_sent() ) {
				status_header( 423 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=utf-8' );
			}
			echo esc_html(
				sprintf(
					'Bombora Mergado WPML: feed "%s" is still being generated (last batch %d s ago). Try again later.',
					$lock['lang'],
					$now - (int) $lock['hit']
				)
			);
			exit;
		}

		if ( '' !== $lang ) {
			update_option( Settings::LOCK, array( 'lang' => $key, 'hit' => $now ), false );
		}
	}

	public static function switch_language(): void {
		if ( '' === self::$lang || ! has_action( 'wpml_switch_language' ) ) {
			return;
		}
		$active = Settings::languages();
		if ( $active && ! isset( $active[ self::$lang ] ) ) {
			error_log( '[bombora-mergado-wpml] Unknown language ' . self::$lang . ' – ignored.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			self::$lang = '';
			delete_option( Settings::LOCK );
			return;
		}
		do_action( 'wpml_switch_language', self::$lang );
	}

	/**
	 * @param mixed $lang Jazyk z WPML.
	 * @return mixed
	 */
	public static function filter_current_language( $lang ) {
		return '' !== self::$lang ? self::$lang : $lang;
	}

	public static function copy_finished_feeds(): void {
		if ( '' === self::$lang ) {
			return;
		}

		$out    = Settings::out_dir();
		$u      = wp_upload_dir( null, false );
		$base   = rtrim( (string) $u['basedir'], '/' );
		$copied = array();

		foreach ( Settings::feed_roots() as $root ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				/** @var \SplFileInfo $file */
				$path = $file->getPathname();
				if ( 'xml' !== strtolower( $file->getExtension() ) || preg_match( '~/(tmp|temp)(/|$)~i', $path ) ) {
					continue; // jen hotové XML, ne dávkové soubory
				}
				clearstatcache( true, $path );
				if ( $file->getMTime() < self::$start || $file->getSize() < 100 ) {
					continue; // v tomto requestu nedokončeno
				}
				if ( ! is_dir( $out ) && ! wp_mkdir_p( $out ) ) {
					error_log( '[bombora-mergado-wpml] Cannot create ' . $out ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					return;
				}

				$target = $out . '/' . $file->getBasename( '.xml' ) . '-' . self::$lang . '.xml';
				$tmp    = $target . '.part';
				if ( @copy( $path, $tmp ) && @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$copied[] = array(
						'source' => str_replace( wp_normalize_path( ABSPATH ), '/', wp_normalize_path( $path ) ),
						'url'    => str_replace( $base, (string) $u['baseurl'], $target ),
						'bytes'  => (int) filesize( $target ),
						'items'  => self::count_items( $target ),
					);
				}
			}
		}

		if ( ! $copied ) {
			if ( self::$diag ) {
				$status                         = Settings::status();
				$status[ self::$lang ]['diag'] = self::$diag;
				update_option( Settings::STATUS, $status, false );
			}
			return; // dávka hotová, feed ještě ne – zámek zůstává
		}

		// Kontrola jazyka výsledku: u nevýchozího jazyka musí URL produktů obsahovat /xx/.
		$check   = 'n/a';
		$default = Settings::default_language();
		if ( $default && self::$lang !== $default ) {
			$sample = (string) @file_get_contents( $out . '/' . basename( (string) $copied[0]['url'] ), false, null, 0, 200000 ); // phpcs:ignore
			if ( preg_match_all( '~<URL>([^<]+)</URL>~i', $sample, $m ) && $m[1] ) {
				$hits  = count( array_filter( $m[1], static fn( $u ) => false !== strpos( $u, '/' . self::$lang . '/' ) ) );
				$check = $hits === count( $m[1] ) ? 'ok' : sprintf( 'mismatch %d/%d', $hits, count( $m[1] ) );
			}
		}

		$status                = Settings::status();
		$status[ self::$lang ] = array(
			'finished' => time(),
			'files'    => $copied,
			'check'    => $check,
			'diag'     => self::$diag,
		);
		update_option( Settings::STATUS, $status, false );
		delete_option( Settings::LOCK );
		error_log( '[bombora-mergado-wpml] ' . self::$lang . ' feed done: ' . wp_json_encode( $copied ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/** Počet <ITEM> bez načtení celého XML do paměti. */
	private static function count_items( string $file ): int {
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return 0;
		}
		$count = 0;
		$carry = '';
		while ( ! feof( $fh ) ) {
			$chunk  = $carry . fread( $fh, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$count += (int) preg_match_all( '~<item[\s>]~i', $chunk );
			$carry  = substr( $chunk, -5 ); // kratší než „<item>“ → nic se nezapočte dvakrát
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $count;
	}
}
