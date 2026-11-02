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
			'active-run-configuration'             => self::summarize_run_configuration( DB::get_active_scheduled_warmup() ),
			'last-warmup-failed-to-retrieve-links' => Warm_Up::get_failed_to_retrieve_links( $warmup_id ),
			'last-warmup-retrieved-links'          => Warm_Up::get_retrieved_links( $warmup_id ),
		);

		return $data;
	}

	/**
	 * Summarize active configuration without exposing headers, cookies or agents.
	 *
	 * Reads only the supplied row. Numeric settings include their persisted type
	 * and numeric value; unsupported values expose their type only. No defaults
	 * are applied, so diagnostics distinguish missing fields from explicit null.
	 *
	 * @param array|null $run Active database row with id, queue_state and JSON meta.
	 * @return array|null Bounded diagnostic fields, or null when no run is active.
	 */
	public static function summarize_run_configuration( ?array $run ): ?array {
		if ( null === $run ) {
			return null;
		}
		$meta   = json_decode( $run['meta'], true );
		$result = array(
			'id'                 => (int) $run['id'],
			'queue_state'        => $run['queue_state'],
			'metadata_is_object' => is_array( $meta ),
		);
		foreach ( array( 'depth', 'timeout', 'speed_limit', 'start_date', 'user_agents', 'worker_retry_after', 'worker_error_type' ) as $key ) {
			$present                  = is_array( $meta ) && array_key_exists( $key, $meta );
			$value                    = $present ? $meta[ $key ] : null;
			$result['fields'][ $key ] = array(
				'present'       => $present,
				'type'          => gettype( $value ),
				'numeric_value' => is_numeric( $value ) ? $value + 0 : null,
				'item_count'    => is_array( $value ) ? count( $value ) : null,
			);
		}
		return $result;
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
