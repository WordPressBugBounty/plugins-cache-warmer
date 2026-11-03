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
			'run-metadata-storage'                 => DB::get_run_metadata_storage(),
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
	 * and numeric value; unsupported values expose their type only. Byte length,
	 * SHA-256 and JSON decoder status describe the exact stored bytes
	 * without returning their contents. The TEXT-limit flag is evidence of possible
	 * truncation, not proof of its cause. No defaults are applied, so diagnostics
	 * distinguish missing fields from explicit null. Recovery reports member names
	 * only; retained backup bytes are represented by their length and checksum.
	 * Unsupported truncation reports bounded structural rejection reasons and
	 * offsets so administrators can investigate without exporting private values.
	 *
	 * @param array|null $run Active database row with id, queue_state and JSON meta.
	 * @return array|null Bounded diagnostic fields, or null when no run is active.
	 */
	public static function summarize_run_configuration( ?array $run ): ?array {
		if ( null === $run ) {
			return null;
		}
		$raw    = (string) $run['meta'];
		$meta   = json_decode( $raw, true );
		$error  = json_last_error_msg();
		$result = array(
			'id'                 => (int) $run['id'],
			'queue_state'        => $run['queue_state'],
			'metadata_is_object' => is_array( $meta ),
			'metadata_bytes'     => strlen( $raw ),
			'metadata_sha256'    => hash( 'sha256', $raw ),
			'json_error'         => $error,
			'at_text_byte_limit' => 65535 === strlen( $raw ),
		);
		if ( isset( $run['meta_recovery_backup'] ) ) {
			$result['recovery_backup_bytes']  = strlen( $run['meta_recovery_backup'] );
			$result['recovery_backup_sha256'] = hash( 'sha256', $run['meta_recovery_backup'] );
		}
		if ( is_array( $meta ) && 'current-settings' === ( $meta['speed_limit_recovery_source'] ?? null ) ) {
			$result['speed_limit_recovery_source'] = 'current-settings';
		}
		if ( is_array( $meta ) && 'current-settings' === ( $meta['metadata_recovery_source'] ?? null ) ) {
			$result['recovery_source']  = 'current-settings';
			$result['recovered_fields'] = array_values( array_intersect( Metadata_Recovery::required_settings(), (array) ( $meta['metadata_recovered_fields'] ?? array() ) ) );
		}
		$recovery_diagnostic = null;
		$inspection          = Metadata_Recovery::inspect( $raw, $recovery_diagnostic );
		if ( 65535 === strlen( $raw ) && 'No error' !== $error ) {
			$result['recovery_inspection'] = $recovery_diagnostic;
		}
		if ( null !== $inspection ) {
			$result['recovery'] = array(
				'complete_members'  => array_keys( $inspection['fields'] ),
				'incomplete_member' => $inspection['incomplete_field'],
				'missing_settings'  => array_values( array_diff( Metadata_Recovery::required_settings(), array_keys( $inspection['fields'] ) ) ),
			);
		}
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
