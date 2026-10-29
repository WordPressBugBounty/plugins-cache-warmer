<?php
/**
 * Class for debug data.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use Exception;

/**
 * Debug class.
 */
final class Debug {

	/**
	 * Sample a diagnostic snapshot when the environment enables debugging.
	 *
	 * Normally includes one in ten calls. CACHE_WARMER_DEBUG_EACH_REQUEST removes
	 * sampling but does not enable debugging by itself. Reads the snapshot only
	 * for selected calls; does not print or persist it.
	 *
	 * @return array Diagnostic snapshot, or an empty array when disabled or skipped.
	 * @throws Exception When a selected snapshot dependency throws.
	 */
	public static function maybe_get_debug_array() {
		return self::debug_mode_enabled()
			&&
			(
				// Return only 1 / 10th (each 50s on average) due to the potentially big size.
				self::debug_each_request() || 1 === wp_rand( 1, 10 )
			)
			? self::get_debug_array() : array();
	}

	/**
	 * Collect the current server, option and warm-up log diagnostics.
	 *
	 * Reads options and queue/log tables; this is not an atomic snapshot. When
	 * latest warm-up metadata lacks a list_id, the link helpers receive null and
	 * include all runs rather than an empty latest-run result. Raw diagnostics
	 * are returned without presentation escaping or an authorization check.
	 *
	 * @return array Server address, latest link markers, latest warm-up metadata,
	 *               and failed/successful URL-metadata maps keyed by their helpers.
	 * @throws Exception When an option or diagnostic dependency throws.
	 */
	public static function get_debug_array() {
		$latest_warmup_data = DB::get_latest_warmup_data();

		$warmup_id = isset( $latest_warmup_data['list_id'] ) ? (int) $latest_warmup_data['list_id'] : null;

		$data = array(
			'current-server-ip-address'            => Server_IP_Detection::get_current_server_ip(),
			'last-processing-link'                 => Cache_Warmer::$options->get( 'last-processing-link' ),
			'last-failed-to-retrieve-link'         => Cache_Warmer::$options->get( 'last-failed-to-retrieve-link' ),
			'last-retrieved-link'                  => Cache_Warmer::$options->get( 'last-retrieved-link' ),
			'last-warmup-data'                     => $latest_warmup_data,
			'last-warmup-failed-to-retrieve-links' => Warm_Up::get_failed_to_retrieve_links( $warmup_id ),
			'last-warmup-retrieved-links'          => Warm_Up::get_retrieved_links( $warmup_id ),
		);

		return $data;
	}

	/**
	 * Check if the plugin debug mode is enabled.
	 *
	 * @return bool Whether CACHE_WARMER_DEBUG is exactly true, 1 or yes as a string.
	 */
	private static function debug_mode_enabled() {
		return isset( $_ENV['CACHE_WARMER_DEBUG'] ) && in_array( $_ENV['CACHE_WARMER_DEBUG'], array( 'true', '1', 'yes' ), true );
	}

	/**
	 * Check if needed to debug each request.
	 *
	 * @return bool Whether CACHE_WARMER_DEBUG_EACH_REQUEST is exactly true, 1 or yes as a string.
	 */
	private static function debug_each_request() {
		return isset( $_ENV['CACHE_WARMER_DEBUG_EACH_REQUEST'] ) && in_array( $_ENV['CACHE_WARMER_DEBUG_EACH_REQUEST'], array( 'true', '1', 'yes' ), true );
	}
}
