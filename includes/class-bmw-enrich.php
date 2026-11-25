<?php
/**
 * Doplnění feedu: Mergado Pack u některých typů produktů (např. WPC Product Bundles „woosb“)
 * vynechá SHIPPING_WEIGHT, CATEGORY a PRODUCTNO. Doplníme je z WooCommerce.
 * Položky, které údaje mají, zůstávají beze změny.
 *
 * @package Bombora\MergadoWpml
 */

namespace Bombora\MergadoWpml;

defined( 'ABSPATH' ) || exit;

final class Enrich {

	/** Maximální velikost feedu, kterou upravujeme v paměti. */
	private const MAX_BYTES = 52428800; // 50 MB

	/**
	 * @return array{weight:int,category:int,productno:int}|null Počty doplněných hodnot.
	 */
	public static function file( string $file ): ?array {
		if ( ! function_exists( 'wc_get_product' ) || (int) @filesize( $file ) > self::MAX_BYTES ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		$xml = (string) @file_get_contents( $file ); // phpcs:ignore
		if ( '' === $xml ) {
			return null;
		}

		$stats = array( 'weight' => 0, 'category' => 0, 'productno' => 0 );
		$unit  = (string) get_option( 'woocommerce_weight_unit', 'kg' );

		$new = preg_replace_callback(
			'~<ITEM>(.*?)</ITEM>~s',
			static function ( array $m ) use ( &$stats, $unit ): string {
				$body = $m[1];
				if ( ! preg_match( '~<ITEM_ID>(?:<!\[CDATA\[)?(\d+)~', $body, $id ) ) {
					return $m[0];
				}
				$product = wc_get_product( (int) $id[1] );
				if ( ! $product ) {
					return $m[0];
				}
				$add = '';

				if ( false === strpos( $body, '<SHIPPING_WEIGHT>' ) ) {
					$w = (float) $product->get_weight();
					if ( $w <= 0 && $product->get_parent_id() ) {
						$parent = wc_get_product( $product->get_parent_id() );
						$w      = $parent ? (float) $parent->get_weight() : 0;
					}
					if ( $w > 0 ) {
						$add .= '<SHIPPING_WEIGHT>' . wc_format_decimal( $w ) . ' ' . esc_html( $unit ) . '</SHIPPING_WEIGHT>';
						++$stats['weight'];
					}
				}

				if ( false === strpos( $body, '<CATEGORY>' ) ) {
					$cats = self::categories( $product );
					if ( $cats ) {
						$add .= '<CATEGORY>' . self::cdata( array_shift( $cats ) ) . '</CATEGORY>';
						foreach ( $cats as $c ) {
							$add .= '<CATEGORY_ALTERNATIVE>' . self::cdata( $c ) . '</CATEGORY_ALTERNATIVE>';
						}
						++$stats['category'];
					}
				}

				if ( false === strpos( $body, '<PRODUCTNO>' ) ) {
					$sku = (string) $product->get_sku();
					if ( '' !== $sku ) {
						$add .= '<PRODUCTNO>' . self::cdata( $sku ) . '</PRODUCTNO>';
						++$stats['productno'];
					}
				}

				return '' === $add ? $m[0] : '<ITEM>' . $body . $add . '</ITEM>';
			},
			$xml
		);

		if ( null === $new || $new === $xml ) {
			return $stats;
		}
		return false !== @file_put_contents( $file, $new ) ? $stats : null; // phpcs:ignore
	}

	/**
	 * Názvy kategorií: primární kategorie Rank Math / Yoast první, pak ostatní.
	 *
	 * @return string[]
	 */
	private static function categories( \WC_Product $product ): array {
		$pid   = $product->get_parent_id() ?: $product->get_id();
		$terms = get_the_terms( $pid, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) {
			return array();
		}
		$primary = (int) get_post_meta( $pid, 'rank_math_primary_product_cat', true );
		if ( ! $primary ) {
			$primary = (int) get_post_meta( $pid, '_yoast_wpseo_primary_product_cat', true );
		}
		usort(
			$terms,
			static fn( $a, $b ) => ( (int) ( $b->term_id === $primary ) ) <=> ( (int) ( $a->term_id === $primary ) )
		);
		return array_values( array_map( static fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), $terms ) );
	}

	private static function cdata( string $v ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $v ) . ']]>';
	}
}
