<?php
/**
 * SEO bridge — reads/writes SEO title + meta description for posts and terms
 * across the common WordPress SEO plugins (Yoast, Rank Math, All in One SEO),
 * so catalog sync can carry `seoTitle` / `seoDescription` in both directions.
 *
 * @package WafiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopify_Pulse_Seo {

	/**
	 * Read SEO for a post (product). Returns [] when nothing is set.
	 *
	 * @param int $post_id
	 * @return array{seoTitle?:string,seoDescription?:string}
	 */
	public static function get_post_seo( $post_id ) {
		$title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		$desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( '' === (string) $title ) {
			$title = get_post_meta( $post_id, 'rank_math_title', true );
		}
		if ( '' === (string) $desc ) {
			$desc = get_post_meta( $post_id, 'rank_math_description', true );
		}
		if ( '' === (string) $title ) {
			$title = get_post_meta( $post_id, '_aioseo_title', true );
		}
		if ( '' === (string) $desc ) {
			$desc = get_post_meta( $post_id, '_aioseo_description', true );
		}
		return self::pack( $title, $desc );
	}

	/**
	 * Read SEO for a term (category/brand).
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return array{seoTitle?:string,seoDescription?:string}
	 */
	public static function get_term_seo( $term_id, $taxonomy ) {
		// Rank Math + newer Yoast store term SEO in term meta.
		$title = get_term_meta( $term_id, 'rank_math_title', true );
		$desc  = get_term_meta( $term_id, 'rank_math_description', true );
		if ( '' === (string) $title ) {
			$title = get_term_meta( $term_id, '_yoast_wpseo_title', true );
		}
		if ( '' === (string) $desc ) {
			$desc = get_term_meta( $term_id, '_yoast_wpseo_metadesc', true );
		}
		// Older Yoast keeps term SEO in a single option keyed by taxonomy+term.
		if ( '' === (string) $title || '' === (string) $desc ) {
			$opt = get_option( 'wpseo_taxonomy_meta' );
			if ( is_array( $opt ) && isset( $opt[ $taxonomy ][ $term_id ] ) ) {
				$row = $opt[ $taxonomy ][ $term_id ];
				if ( '' === (string) $title && ! empty( $row['wpseo_title'] ) ) {
					$title = $row['wpseo_title'];
				}
				if ( '' === (string) $desc && ! empty( $row['wpseo_desc'] ) ) {
					$desc = $row['wpseo_desc'];
				}
			}
		}
		return self::pack( $title, $desc );
	}

	/**
	 * Write SEO onto a post (used by the outbound/pull direction). Writes to
	 * whichever SEO plugin is active; falls back to writing all known keys.
	 */
	public static function set_post_seo( $post_id, $title, $desc ) {
		if ( '' !== (string) $title ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			update_post_meta( $post_id, 'rank_math_title', $title );
		}
		if ( '' !== (string) $desc ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}
	}

	/** Write SEO onto a term (used by the outbound/pull direction). */
	public static function set_term_seo( $term_id, $taxonomy, $title, $desc ) {
		if ( '' !== (string) $title ) {
			update_term_meta( $term_id, 'rank_math_title', $title );
			update_term_meta( $term_id, '_yoast_wpseo_title', $title );
		}
		if ( '' !== (string) $desc ) {
			update_term_meta( $term_id, 'rank_math_description', $desc );
			update_term_meta( $term_id, '_yoast_wpseo_metadesc', $desc );
		}
		// Older Yoast option store.
		if ( '' !== (string) $title || '' !== (string) $desc ) {
			$opt = get_option( 'wpseo_taxonomy_meta' );
			$opt = is_array( $opt ) ? $opt : array();
			if ( ! isset( $opt[ $taxonomy ] ) ) {
				$opt[ $taxonomy ] = array();
			}
			$row = isset( $opt[ $taxonomy ][ $term_id ] ) ? $opt[ $taxonomy ][ $term_id ] : array();
			if ( '' !== (string) $title ) {
				$row['wpseo_title'] = $title;
			}
			if ( '' !== (string) $desc ) {
				$row['wpseo_desc'] = $desc;
			}
			$opt[ $taxonomy ][ $term_id ] = $row;
			update_option( 'wpseo_taxonomy_meta', $opt );
		}
	}

	private static function pack( $title, $desc ) {
		$out = array();
		if ( '' !== (string) $title ) {
			$out['seoTitle'] = substr( wp_strip_all_tags( (string) $title ), 0, 255 );
		}
		if ( '' !== (string) $desc ) {
			$out['seoDescription'] = substr( wp_strip_all_tags( (string) $desc ), 0, 320 );
		}
		return $out;
	}
}
