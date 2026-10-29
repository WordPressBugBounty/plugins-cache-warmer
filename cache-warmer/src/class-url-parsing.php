<?php
/**
 * A class for parsing of URLs.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Provides deterministic URL path parsing helpers for crawl discovery.
 */
final class Url_Parsing {

	/**
	 * Returns the lowercase extension from the URL path's final filename.
	 *
	 * Hostnames, dotted parent directories, query strings and fragments do not
	 * contribute to the result. URLs with no parseable path or no final filename
	 * extension return false.
	 *
	 * @param string $link Absolute or relative URL to inspect.
	 * @return string|false Lowercase extension without a dot, or false when absent.
	 */
	public static function get_extension( $link ) {
		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		$filename  = basename( untrailingslashit( $path ) );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		return '' === $extension ? false : strtolower( $extension );
	}
}
