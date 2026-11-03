<?php
/**
 * Conservative parsing of configurations truncated at the old TEXT boundary.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Extracts complete top-level fields without guessing the truncated suffix.
 */
final class Metadata_Recovery {

	/**
	 * Lists settings which must be known before retained requests can resume.
	 *
	 * Runtime counters are deliberately excluded. Identity comes from the same
	 * run row. Request settings must come from complete members or explicit
	 * administrator input or the explicit automatic current-settings policy.
	 *
	 * @return string[] Required configuration member names.
	 */
	public static function required_settings(): array {
		return array(
			'depth',
			'timeout',
			'speed_limit',
			'cookies',
			'request_headers',
			'url_params',
			'excluded_pages',
			'assets_preloading',
			'user_agents',
			'visit_second_time_without_custom_url_params',
			'visit_second_time_without_cookies',
			'rewrite_to_https',
			'add_entry_points_sites_sitemaps',
			'excluded_pages_use_regex_match',
			'exclude_pages_with_warmed_canonical',
			'use_external_warmer',
		);
	}

	/**
	 * Reads current site settings only for members lost from recognized damage.
	 *
	 * Uses the same option shapes as new-run admission, via shared-database
	 * consistent option reads. Complete persisted members win even when current
	 * settings differ. Values are validated by candidate() before any write.
	 * There is no fixed recovery timeout or cached cross-request configuration.
	 *
	 * @param string $raw Exact damaged metadata.
	 * @return array Missing run-field names mapped to current option values.
	 * @throws \RuntimeException When the damage or option shape is unsupported.
	 */
	public static function missing_current_settings( string $raw ): array {
		$inspection = self::inspect( $raw );
		if ( null === $inspection ) {
			throw new \RuntimeException( 'Invalid warm-up metadata JSON; stored data retained. Automatic recovery does not recognize this damage.' );
		}
		$options  = Cache_Warmer::$options;
		$booleans = array(
			'visit_second_time_without_custom_url_params' => 'setting-visit-second-time-without-url-params',
			'visit_second_time_without_cookies'           => 'setting-visit-second-time-without-cookies',
			'rewrite_to_https'                            => 'setting-rewrite-to-https',
			'add_entry_points_sites_sitemaps'             => 'setting-add-entry-point-sites-sitemaps',
			'excluded_pages_use_regex_match'              => 'setting-excluded-pages-use-regex-match',
			'exclude_pages_with_warmed_canonical'         => 'setting-exclude-pages-with-warmed-canonical',
			'use_external_warmer'                         => 'setting-use-external-warmer-servers-during-the-warming',
		);
		$missing  = array();
		foreach ( array_diff( self::required_settings(), array_keys( $inspection['fields'] ) ) as $key ) {
			if ( isset( $booleans[ $key ] ) ) {
				$missing[ $key ] = '1' === $options->get( $booleans[ $key ] );
			} elseif ( 'assets_preloading' === $key ) {
				$missing[ $key ] = array();
				foreach ( array( 'scripts', 'styles', 'fonts', 'images' ) as $asset ) {
					$missing[ $key ][ $asset ] = '1' === $options->get( 'setting-assets-preloading-' . $asset );
				}
			} else {
				$value = $options->get( 'setting-' . str_replace( '_', '-', $key ) );
				if ( in_array( $key, array( 'cookies', 'user_agents', 'excluded_pages' ), true ) && ! is_array( $value ) ) {
					throw new \RuntimeException( 'Invalid current recovery setting: ' . esc_html( $key ) );
				}
				if ( 'user_agents' === $key ) {
					$value = array_column( $value, 'value' );
				} elseif ( 'cookies' === $key ) {
					$value = array_combine( array_column( $value, 'name' ), array_column( $value, 'value' ) );
				} elseif ( 'excluded_pages' === $key ) {
					$value = array_values( array_filter( $value ) );
				}
				$missing[ $key ] = $value;
			}
		}
		return $missing;
	}

	/**
	 * Builds an explicitly reviewed recovery candidate from complete members.
	 *
	 * Supplied settings may fill missing required members only; they cannot
	 * overwrite retained values or arbitrary worker state. External processing
	 * is refused because its truncated request history cannot be reconstructed.
	 * The original bytes must be backed up atomically by the caller before this
	 * candidate becomes authoritative. Incomplete external history is marked so
	 * finalization cannot publish a partial reusable external crawl.
	 *
	 * @param string $raw Exact damaged JSON.
	 * @param string $warmed_at Immutable identity from the original database row.
	 * @param array  $missing Explicit values for missing required members.
	 * @return array Complete normalized candidate metadata.
	 * @throws \RuntimeException When damage or supplied recovery is unsupported.
	 */
	public static function candidate( string $raw, string $warmed_at, array $missing ): array {
		$inspection = self::inspect( $raw );
		if ( null === $inspection ) {
			throw new \RuntimeException( 'Metadata damage cannot be safely recovered by this operation.' );
		}
		$meta     = $inspection['fields'];
		$required = self::required_settings();
		foreach ( $missing as $key => $value ) {
			if ( ! in_array( $key, $required, true ) || array_key_exists( $key, $meta ) ) {
				throw new \RuntimeException( 'Recovery may only supply missing request settings.' );
			}
			$meta[ $key ] = $value;
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				throw new \RuntimeException( 'Recovery requires explicit values for every missing request setting.' );
			}
		}
		if ( isset( $meta['start_date'] ) && $meta['start_date'] !== $warmed_at ) {
			throw new \RuntimeException( 'Recovery identity does not match the stored run.' );
		}
		$meta['start_date'] = $warmed_at;
		$meta               = Warm_Up::normalize_run_meta( $meta );
		if ( $meta['use_external_warmer'] ) {
			throw new \RuntimeException( 'External warming requires separate request-history recovery.' );
		}
		if ( null !== $inspection['incomplete_field'] ) {
			$meta['external_history_incomplete'] = true;
		}
		return $meta;
	}

	/**
	 * Extracts complete JSON members before a truncated request-history field.
	 *
	 * Only the known 65535-byte TEXT boundary is considered. Complete members
	 * must individually decode and have unique keys. The incomplete member must
	 * be external_warmup_request_args, or the final byte must be the opening
	 * quote of the next member name. In that boundary case no name or value is
	 * recovered; only the complete prefix survives. Other damage is refused.
	 * Returned fields may contain secrets and must never be rendered directly in diagnostics.
	 * This pure method neither fills missing configuration nor modifies storage.
	 *
	 * @param string     $raw Exact persisted metadata bytes.
	 * @param array|null $diagnostic Optional output with rejection reason, offsets
	 *                               and bounded structural member names only.
	 *                               Never contains configuration values.
	 * @return array|null Complete fields and incomplete field name (null for a member
	 *                    boundary), or null if the corruption shape is unsupported.
	 */
	public static function inspect( string $raw, ?array &$diagnostic = null ): ?array {
		$diagnostic = array(
			'reason'           => 'unsupported_boundary',
			'root_byte_hex'    => bin2hex( substr( $raw, 0, 1 ) ),
			'complete_members' => array(),
		);
		if ( 65535 !== strlen( $raw ) ) {
			return null;
		}
		if ( '{' !== $raw[0] ) {
			$diagnostic['reason'] = 'unsupported_root';
			return null;
		}
		json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			$diagnostic['reason'] = 'already_valid';
			return null;
		}
		$length  = strlen( $raw );
		$fields  = array();
		$start   = 1;
		$stack   = array();
		$string  = false;
		$escaped = false;
		for ( $i = 1; $i < $length; ++$i ) {
			$char = $raw[ $i ];
			if ( $string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( '"' === $char ) {
					$string = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$string = true;
			} elseif ( '{' === $char || '[' === $char ) {
				$stack[] = $char;
			} elseif ( '}' === $char || ']' === $char ) {
				$opening = array_pop( $stack );
				if ( ( '}' === $char ? '{' : '[' ) !== $opening ) {
					$diagnostic['reason'] = 'mismatched_delimiter';
					$diagnostic['offset'] = $i;
					return null;
				}
			} elseif ( ',' === $char && ! $stack ) {
				$member = json_decode( '{' . substr( $raw, $start, $i - $start ) . '}', true );
				if ( ! is_array( $member ) || 1 !== count( $member ) ) {
					$diagnostic['reason']     = 'invalid_complete_member';
					$diagnostic['offset']     = $start;
					$diagnostic['json_error'] = json_last_error_msg();
					return null;
				}
				$key = key( $member );
				if ( array_key_exists( $key, $fields ) ) {
					$diagnostic['reason'] = 'duplicate_member';
					$diagnostic['offset'] = $start;
					return null;
				}
				if ( count( $diagnostic['complete_members'] ) < 64 && is_string( $key ) && preg_match( '/^[a-z_]{1,64}$/D', $key ) ) {
					$diagnostic['complete_members'][] = $key;
				}
				$fields[ $key ] = $member[ $key ];
				$start          = $i + 1;
			}
		}
		$tail                 = substr( $raw, $start );
		$diagnostic['offset'] = $start;
		$diagnostic['reason'] = 'unsupported_incomplete_member';
		if ( '"' === $tail && $fields ) {
			$diagnostic['reason'] = 'supported_member_boundary';
			return array(
				'fields'           => $fields,
				'incomplete_field' => null,
			);
		}
		if ( preg_match( '/^\s*"([a-z_]{1,64})"\s*:\s*(.)/s', $tail, $matches ) ) {
			$diagnostic['incomplete_member']     = $matches[1];
			$value_types                         = array(
				'{' => 'object',
				'[' => 'array',
				'"' => 'string',
				't' => 'boolean',
				'f' => 'boolean',
				'n' => 'null',
			);
			$diagnostic['incomplete_value_type'] = $value_types[ $matches[2] ] ?? ( preg_match( '/[0-9-]/', $matches[2] ) ? 'number' : 'other' );
		}
		if ( ! preg_match( '/^\s*"external_warmup_request_args"\s*:\s*\{/', $tail ) || array_key_exists( 'external_warmup_request_args', $fields ) ) {
			return null;
		}
		$diagnostic['reason'] = 'supported';
		return array(
			'fields'           => $fields,
			'incomplete_field' => 'external_warmup_request_args',
		);
	}
}
