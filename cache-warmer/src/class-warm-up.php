<?php
/**
 * Warm_Up handlers.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use ActionScheduler_Store;
use Automattic\WooCommerce\Blocks\Utils\MiniCartUtils;
use Exception;
use WP_Error;

/**
 * Handles Warm Up.
 */
final class Warm_Up {

    /**
     * Batch size: number of pages per one script execution.
     */
    const BATCH_SIZE = 1;

    /**
     * Returns page HTML.
     *
     * @param string $url        URL.
     * @param int    $timeout    Timeout (seconds).
     * @param string $user_agent User-Agent.
     * @param array  $cookies    Cookies.
     * @param array  $request_headers Request headers.
     *
     * @return array|WP_Error The response or WP_Error on failure.
     */
    public static function request_a_page( string $url, int $timeout, string $user_agent, array $cookies, array $request_headers ) {
        DB::assert_process_lock();
        $response = wp_remote_get(
            $url,
            [
                'timeout'    => $timeout,
                'user-agent' => $user_agent,
                'cookies'    => $cookies,
                'headers'    => array_column( $request_headers, 'value', 'name' ),
            ]
        );
        DB::assert_process_lock();
        return $response;
    }

    /**
     * Returns the phase (for logging).
     *
     * @param array $meta Meta.
     *
     * @return int The Phase. 0 - in warm-up tree. 1 - in failed to retrieve tree.
     */
    public static function get_phase( $meta ) {
        if ( ! array_key_exists( 'failed_to_retrieve_tree', $meta ) ) {
            return 0;
        } else {
            return 1;
        }
    }

	/**
	 * Mark one matching visit failed, or append a completed failure log.
	 *
	 * Matches run, URL, depth and exact JSON metadata, excluding cancelled rows.
	 * The unordered lookup can select an already-completed row. Lookup errors
	 * are not checked separately from misses; misses delegate insertion to Logging.
	 * An existing row receives the site-local completion time and failure flag
	 * under the process fence. Ownership is checked after the write; zero affected
	 * rows are not an error. Does not fetch the URL, schedule retries or acquire a
	 * transaction/lock. Earlier writes can persist when a delegated operation fails.
	 *
	 * @param int    $warmup_id Run ID owning the visit.
	 * @param string $link      Exact URL to match or log.
	 * @param int    $depth     Exact queue depth to match or log.
	 * @param array  $link_meta Metadata encoded for matching and passed to Logging.
	 * @return void
	 * @throws Exception When UPDATE fails; delegated logging and ownership exceptions also propagate.
	 */
	public static function update_failed_to_retrieve_links( int $warmup_id, string $link, int $depth, array $link_meta ) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$current_time = wp_date( 'Y-m-d H:i:s' );

		$link_meta_encoded = wp_json_encode( $link_meta );

		// Step 1: Check if the row exists.
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				'
                    SELECT id
                    FROM %i
                    WHERE list_id = %d
                      AND log_url = %s
                      AND log_depth = %d
					  AND link_meta = %s
					  AND is_cancelled = 0
                    LIMIT 1
                ',
				$table_name,
				$warmup_id,
				$link,
				$depth,
				$link_meta_encoded
			)
		);

		if ( null !== $row_id ) {
			$fence = DB::process_fence();
			// Step 2: If present, update the existing row.
			$update_result = $wpdb->query(
				$wpdb->prepare(
					"
                    UPDATE %i
                    SET warm_date = %s,
                        log_is_success = 0
                    WHERE id = %d AND $fence
                ",
					$table_name,
					$current_time,
					$row_id
				)
			);
			DB::assert_process_lock();

			if ( false === $update_result ) {
				throw new Exception( 'Failed to update warm_date for the existing row.' );
			}
		} else {
			// Step 3: If not present, insert a new row.
			Logging::log(
				$warmup_id,
				false,
				$depth,
				null,
				null,
				$link,
				'',
				0,
				$link_meta['user_agent'] ?? '',
				array(),
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				$link_meta,
				$current_time
			);
		}
	}

	/**
	 * Mark one matching visit successful, or append a completed success log.
	 *
	 * Matches run, URL, depth and exact JSON metadata, excluding cancelled rows.
	 * The unordered lookup can select an already-completed row; lookup errors
	 * are not distinguished from misses. Updates completion time and success
	 * under the process fence, then verifies ownership. A miss delegates insertion
	 * to Logging. Does not fetch the URL or acquire a lock/transaction. Zero-row
	 * updates are accepted; an exception can follow already-persisted writes.
	 *
	 * @param int    $warmup_id Run ID owning the visit.
	 * @param string $link      Exact URL to match or log.
	 * @param int    $depth     Exact queue depth to match or log.
	 * @param array  $link_meta Metadata encoded for matching and passed to Logging.
	 * @return void
	 * @throws Exception When UPDATE fails; delegated logging and ownership exceptions also propagate.
	 */
	public static function update_retrieved_links( int $warmup_id, string $link, int $depth, array $link_meta ) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$current_time = wp_date( 'Y-m-d H:i:s' );

		$link_meta_encoded = wp_json_encode( $link_meta );

		// Step 1: Check if the row exists.
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				'
                    SELECT id
                    FROM %i
                    WHERE list_id = %d
                      AND log_url = %s
                      AND log_depth = %d
					  AND link_meta = %s
					  AND is_cancelled = 0
                    LIMIT 1
                ',
				$table_name,
				$warmup_id,
				$link,
				$depth,
				$link_meta_encoded
			)
		);

		if ( null !== $row_id ) {
			$fence = DB::process_fence();
			// Step 2: If present, update the existing row.
			$update_result = $wpdb->query(
				$wpdb->prepare(
					"
                    UPDATE %i
                    SET warm_date = %s,
                        log_is_success = 1
                    WHERE id = %d AND $fence
                ",
					$table_name,
					$current_time,
					$row_id
				)
			);
			DB::assert_process_lock();

			if ( false === $update_result ) {
				throw new Exception( 'Failed to update warm_date for the existing row.' );
			}
		} else {
			// Step 3: If not present, insert a new row.
			Logging::log(
				$warmup_id,
				1,
				$depth,
				null,
				null,
				$link,
				'',
				0,
				$link_meta['user_agent'] ?? '',
				array(),
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				$link_meta,
				$current_time
			);
		}
	}

	/**
	 * Read minimum observed depths for successful, non-cancelled visits.
	 *
	 * Includes every row with a non-null completion date, regardless of phase or
	 * visit type. Keys combine an untrailed URL and serialized decoded metadata;
	 * duplicate keys retain their smallest depth without casting the database
	 * scalar. A null run ID aggregates across runs. Reads shared state without a
	 * cache or writes. No explicit database/JSON error is raised; a non-iterable
	 * read failure may warn before returning the initially empty map.
	 *
	 * @param int|null $warmup_id Restrict to this run, or null to aggregate all runs.
	 * @return array<string,string> URL/metadata keys mapped to database depth strings.
	 */
	public static function get_retrieved_links( ?int $warmup_id = null ) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$run_filter = null === $warmup_id ? '' : $wpdb->prepare( ' AND list_id = %d', $warmup_id );
		$results    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT log_url, log_depth, link_meta
				FROM %i
				WHERE log_is_success = 1 AND warm_date IS NOT NULL AND is_cancelled = 0 $run_filter",
				$table_name
			),
			ARRAY_A
		);

		$retrieved_links = array();

		foreach ( $results as $result ) {
			$depth = $result['log_depth'];

			$link_meta            = json_decode( $result['link_meta'], true );
			$link_value_with_meta = Url_Formatting::untrailingslash_a_url( $result['log_url'] ) . '|' . maybe_serialize( $link_meta );

			if (
				! isset( $retrieved_links[ $link_value_with_meta ] ) ||
				$depth < $retrieved_links[ $link_value_with_meta ] // New depth is less.
			) {
				$retrieved_links[ $link_value_with_meta ] = $depth;
			}
		}

		return $retrieved_links;
	}

	/**
	 * Read minimum observed depths for failed, non-cancelled visits.
	 *
	 * Includes non-null completion dates in all phases, including skipped visits.
	 * Keys combine an untrailed URL and serialized decoded metadata; repeated keys
	 * retain their smallest database depth scalar. A null run ID aggregates across
	 * runs. Reads current shared state without caching or mutation. Database/JSON
	 * failures are not explicitly reported; a non-iterable query result may warn
	 * before the initially empty map is returned.
	 *
	 * @param int|null $warmup_id Restrict to this run, or null to aggregate all runs.
	 * @return array<string,string> URL/metadata keys mapped to database depth strings.
	 */
	public static function get_failed_to_retrieve_links( ?int $warmup_id = null ) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$run_filter = null === $warmup_id ? '' : $wpdb->prepare( ' AND list_id = %d', $warmup_id );
		$results    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT log_url, log_depth, link_meta
				FROM %i
				WHERE log_is_success = 0 AND warm_date IS NOT NULL AND is_cancelled = 0 $run_filter",
				$table_name
			),
			ARRAY_A
		);

		$retrieved_links = array();

		foreach ( $results as $result ) {
			$depth = $result['log_depth'];

			$link_meta            = json_decode( $result['link_meta'], true );
			$link_value_with_meta = Url_Formatting::untrailingslash_a_url( $result['log_url'] ) . '|' . maybe_serialize( $link_meta );

			if (
				! isset( $retrieved_links[ $link_value_with_meta ] ) ||
				$depth < $retrieved_links[ $link_value_with_meta ] // New depth is less.
			) {
				$retrieved_links[ $link_value_with_meta ] = $depth;
			}
		}

		return $retrieved_links;
	}

	/**
	 * Retrieve a queued visit and persist discovery before acknowledging its log.
	 *
	 * Deletes an equivalent already-retrieved visit or records an exclusion
	 * without fetching. Otherwise performs the local request and observes a
	 * concurrent stop before continuing. Failed requests publish retries and
	 * persist metadata before recording failure; selected server errors modify
	 * the referenced speed-limit or pause settings.
	 *
	 * Successful requests may also contact licensed external warmers, make a
	 * second timing request and parse HTML, CSS or sitemaps. Persists discovered
	 * visits and canonical handling before the final success checkpoint, checking
	 * cancellation again before discovery. Updates diagnostic options and groups
	 * external-request arguments in the referenced metadata for later use.
	 *
	 * Expects normalized run settings and visit metadata; does not validate their
	 * complete shape. Does not acquire the process lock or start a transaction.
	 * The worker caller owns serialization. Earlier HTTP requests, metadata and
	 * discovered rows survive a later failure and may be encountered on replay.
	 * Exceptions from persistence, request and parsing dependencies propagate.
	 *
	 * @param int    $log_id    Pending log row identifying this visit.
	 * @param int    $warmup_id Numeric warm-up list ID owning the visit.
	 * @param string $link     URL to retrieve with this visit's headers and cookies.
	 * @param int    $depth    Current traversal depth, compared with the run limit.
	 * @param array  $meta     Run settings, modified by reference for throttling and external requests.
	 * @param array  $link_meta Visit identity metadata, including user_agent and optional cookie bypass.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When metadata cannot be persisted or ownership is lost.
	 */
	public static function handle_a_link(
		int $log_id,
		int $warmup_id,
		string $link,
		int $depth,
		array &$meta,
		array $link_meta
	) {
		$max_depth                            = (int) $meta['depth'];
		$warm_up_start_date                   = $meta['start_date'];
		$url_params                           = $meta['url_params'];
		$timeout                              = (int) $meta['timeout'];
		$cookies                              = $meta['cookies'];
		$visit_second_time_without_url_params = $meta['visit_second_time_without_custom_url_params'];
		$visit_second_time_without_cookies    = isset( $meta['visit_second_time_without_cookies'] ) &&
												$meta['visit_second_time_without_cookies'];
		$rewrite_to_https                     = $meta['rewrite_to_https'];
		$assets_preloading                    = $meta['assets_preloading'];
		$speed_limit                          = &$meta['speed_limit'];
		$excluded_pages                       = $meta['excluded_pages'] ?? array();
		$excluded_pages_use_regex_match       = isset( $meta['excluded_pages_use_regex_match'] ) &&
												$meta['excluded_pages_use_regex_match'];
		$exclude_pages_with_warmed_canonical  = isset( $meta['exclude_pages_with_warmed_canonical'] ) &&
												$meta['exclude_pages_with_warmed_canonical'];
		$user_agents                          = $meta['user_agents'];
		$use_external_warmer                  = $meta['use_external_warmer'];

		$user_agent = $link_meta['user_agent'];

		$visit_without_cookies =
			isset( $link_meta['visit_without_cookies'] ) &&
			$link_meta['visit_without_cookies'];

		if ( $visit_without_cookies ) {
			$cookies = array();
		}

		$add_entry_points_sites_sitemaps = $meta['add_entry_points_sites_sitemaps'];

		$request_headers = $meta['request_headers'];

		// Start.

		Cache_Warmer::$options->set( 'last-processing-link', $link );

		/*
		 * When this link was already retrieved on a lower depth, delete it.
		 */

		if ( DB::is_link_retrieved( $warmup_id, $link, $link_meta, $depth ) ||
			( ! empty( $meta['legacy_normalized_keys'] ) && DB::is_link_retrieved( $warmup_id, Url_Formatting::untrailingslash_a_url( $link ), $link_meta, $depth ) ) ) {
			DB::delete_log_item_by_id( $log_id );
			return;
		}

		$excluded_match = false;
		foreach ( $excluded_pages as $potential_excluded_match ) {
			/*
			 * Check if $potential_excluded_match starts with a quantifier, and if it does, remove the first character.
			 * The pattern ^[\*\+\?\{\}] matches any string that starts with a *, +, ?, {, or }
			 *
			 * To avoid regex issues.
			 */
			if ( $excluded_pages_use_regex_match && preg_match( '/^[\*\+\?\{\}]/', $potential_excluded_match ) ) {
				$potential_excluded_match = substr( $potential_excluded_match, 1 );
			}

			if (
				( $excluded_pages_use_regex_match && preg_match( '@' . str_replace( '@', '\@', $potential_excluded_match ) . '@', $link ) ) ||
				( ! $excluded_pages_use_regex_match && str_contains( $link, $potential_excluded_match ) )
			) {
				$excluded_match = $potential_excluded_match;
				break;
			}
		}

		if ( $excluded_match ) { // When the URL matches the excluded list.
			Logging::log_failure(
				$log_id,
				null,
				sprintf(
					/* translators: %1$s is URL, second %2$s is the exclusion match (just a string). */
					__( 'The URL matches the <a href="%1$s" target="_blank">excluded page setting</a> <b>"%2$s"</b>. Skipping.' ),
					admin_url( 'admin.php?page=cache-warmer-settings&plugin-selected-tab=excluded-pages' ),
					$excluded_match
				),
				2,
				$user_agent
			);

			return;
		}

		$time_before_fetch = microtime( true );
		$response          = self::request_a_page( $link, $timeout, $user_agent, $cookies, $request_headers );
		$fetch_time        = round( microtime( true ) - $time_before_fetch, 2 );

		$response_code = wp_remote_retrieve_response_code( $response );

		// A stop request can arrive while the outbound HTTP request is in flight.
		if ( DB::was_warmup_stopped( $warmup_id ) ) {
			return;
		}

		if (
			is_wp_error( $response ) ||
			$response_code >= 400
		) {
			Cache_Warmer::$options->set( 'last-failed-to-retrieve-link', $link );

			if ( $response_code >= 400 ) {
				/* translators: %d is server response code. */
				$custom_error = sprintf( __( 'Server response code is %d.' ), $response_code ) . '<br><br>';

				if ( in_array( $response_code, array( 403, 502, 504 ), true ) ) {
					$server_header = wp_remote_retrieve_header( $response, 'server' );
					$custom_error .=
						'cloudflare' === ( is_array( $server_header ) ? reset( $server_header ) : $server_header ) ?
							sprintf(
								/* translators: %1$s is IP, second %2$s is URL. */
								__( 'The cache warmer was blocked by Cloudflare. Please whitelist your server IP (%1$s) to your Cloudflare IP Access rules with Allow setting: <a href="%2$s">IP Access rules Cloudflare Web Application Firewall (WAF) docs</a>', 'cache-warmer' ),
								Server_IP_Detection::get_current_server_ip(),
								'https://developers.cloudflare.com/waf/tools/ip-access-rules/'
							) :
							sprintf(
								/* translators: %s is IP. */
								__( 'The cache warmer was blocked, please check your firewall settings that IP (%s) is not blocked', 'cache-warmer' ),
								Server_IP_Detection::get_current_server_ip()
							);
				} elseif (

					/*
					 * Slow-down a bit.
					 *
					 * For the first time, x2; for the second time, x8. For the third time, pause warming for an hour.
					 */
					in_array( $response_code, array( 429, 500 ), true )
				) {
					/*
					 * Base crawl speed used in the future as the reference for all speed limits for this warming.
					 *
					 * Because if I re-calculate it the second time after crawl speed was limited at least once,
					 * Then it will be lower than the real crawl speed. So only the first 'base_crawl_speed' is true (unaffected by any limits).
					 *
					 * And therefore shall be used as a reference.
					 */
					if ( ! isset( $meta['base_crawl_speed'] ) ) {
						$measured_speed           = Summary::get_warm_up_speed( $warm_up_start_date );
						$meta['base_crawl_speed'] = $measured_speed > 0 ? $measured_speed : $speed_limit;
					}

					if ( ! isset( $meta['initial_speed_limit'] ) ) {
						$meta['initial_speed_limit'] = $speed_limit;
					}

					if ( ! isset( $meta['speed_limit_divider'] ) ) {
						$meta['speed_limit_divider'] = 1;
					}

					$speed_limit_divider = &$meta['speed_limit_divider'];

					if ( $speed_limit_divider < 8 ) {
						$speed_limit_divider             = 1 === $speed_limit_divider ? 2 : 8; // First time - 2, second time - 8.
						$meta['limit_crawl_speed_up_to'] = time() + MINUTE_IN_SECONDS * 15;

						$speed_limit = max(
							round( $meta['base_crawl_speed'] / $speed_limit_divider ),
							1
						);

						$custom_error .=
							sprintf(
								/* translators: %d is the crawl rate limit. */
								__( 'Crawl rate reduced to %d pages per minute for 15 minutes.', 'cache-warmer' ),
								$speed_limit
							);
					} else {
						unset( $meta['speed_limit_divider'] );
						unset( $meta['limit_crawl_speed_up_to'] );

						$speed_limit = $meta['initial_speed_limit']; // Unset the speed limit to the default (with which warm-up was created).

						$custom_error .=
							sprintf(
								__( 'Warming paused for 60 minutes.', 'cache-warmer' ),
								$speed_limit
							);

						$meta['pause_warming_up_to'] = time() + HOUR_IN_SECONDS;
					}
				}
			}

			// Publish the retry before acknowledging the failed first attempt. If
			// the process dies between these writes, replay reuses this retry row.
			DB::enqueue_failed_retries( $warmup_id, $log_id );
			if ( ! DB::update_warm_up_meta( $warmup_id, $meta ) ) {
				throw new \RuntimeException( 'Failed to persist warm-up queue metadata.' );
			}

			Logging::log_failure(
				$log_id,
				$fetch_time ? $fetch_time : null,
				$custom_error ?? wp_json_encode( $response ),
				self::get_phase( $meta ),
				$user_agent
			);
		} else {
			/**
			 * External warmer logic.
			 */

			$external_warmer_results = array();

			$domain = wp_parse_url( $link, PHP_URL_HOST );

			if ( $domain ) {
				$external_warmer_request_args = array(
					array(
						$link,
					),
					External_Warmer::get_request_headers(
						$user_agent,
						$cookies,
						$request_headers
					),
				);

				// Add to "external_warmup_request_args" - for interval-based external warmer warmings.

				if ( ! isset( $meta['external_warmup_request_args'][ $domain ] ) ) {
					$meta['external_warmup_request_args'][ $domain ] = array();
				}
				$meta['external_warmup_request_args'][ $domain ][] = $external_warmer_request_args;

				// Warm the link from the external warmer servers.

				if ( $use_external_warmer ) {
					$external_warmer_license_key = Cache_Warmer::$options->get_dynamic(
						'cache-warmer-setting-external-warmer-license-key' . $domain
					);

					$external_warmer_last_response_code = (int) Cache_Warmer::$options->get_dynamic(
						'cache-warmer-setting-external-warmer-key-validation-endpoint-last-response-code' . $domain
					);

					$servers_to_use = Cache_Warmer::$options->get_dynamic(
						'cache-warmer-setting-external-warmer-servers-to-use' . $domain
					);

					if (
						$external_warmer_license_key &&
						200 === $external_warmer_last_response_code &&
						$servers_to_use
					) {
						// Visit with the external warmer, and prepare the log array.

						$external_warmer_response = External_Warmer::warm_the_link( ...$external_warmer_request_args );

						foreach ( $external_warmer_response as $value ) {
							$time   = 0;
							$status = '';

							if ( isset( $value['body'] ) ) {
								$body = json_decode( $value['body'], true );
								// External assistance must not abort acknowledgement
								// of a successful local visit on an invalid response.
								if (
									200 === $value['code'] &&
									is_array( $body ) &&
									isset( $body[0] ) && is_array( $body[0] ) &&
									isset( $body[0]['time'], $body[0]['status'] ) &&
									is_numeric( $body[0]['time'] ) &&
									is_finite( (float) $body[0]['time'] ) &&
									(float) $body[0]['time'] >= 0 &&
									is_string( $body[0]['status'] )
								) {
									$time   = round( (float) $body[0]['time'], 2 );
									$status = $body[0]['status'];
								} else {
									$status = __( 'Invalid external server response', 'cache-warmer' );
								}
							}

							$external_warmer_results[] = array(
								'server' => $value['server_code'],
								'code'   => $value['code'],
								'time'   => $time,
								'at'     => $value['time'],
								'status' => $status,
							);
						}
					}
				}
			}

			// The standard logic.

			Cache_Warmer::$options->set( 'last-retrieved-link', $link );

			$content_type_value  = wp_remote_retrieve_header( $response, 'content-type' );
			$content_type_header = explode( ';', is_array( $content_type_value ) ? reset( $content_type_value ) : $content_type_value )[0];

			/*
			 * When CF cache is present, we record to the:
			 *
			 * Before:
			 *
			 * MISS, EXPIRED, BYPASS, DYNAMIC
			 *
			 * After:
			 *
			 * HIT, STALE, UPDATING
			 *
			 * If CF cache is not preset, we make a second req to figure out the afterwards time.
			 */

			$headers_with_types_classification = array(
				'cf-cache-status' => array(
					'before' => array(
						'MISS',
						'EXPIRED',
						'BYPASS',
						'DYNAMIC',
					),
					'after'  => array(
						'HIT',
						'STALE',
						'UPDATING',
					),
				),
				'x-cache'         => array(
					'before' => array(
						'MISS',
						'PASS',
						'ERROR',
					),
					'after'  => array(
						'HIT',
						'GRACED',
						'RefreshHit',
					),
				),
			);

			foreach ( $headers_with_types_classification as $header => $classifications ) {
				$header_val = wp_remote_retrieve_header( $response, $header );
				$header_val = is_array( $header_val ) ? reset( $header_val ) : $header_val;

				if ( $header_val ) {
					foreach ( $classifications['before'] as $before_val ) {
						if ( 0 === stripos( $header_val, $before_val ) ) {
							$visit_type = 'before';
							break 2;
						}
					}
					foreach ( $classifications['after'] as $after_val ) {
						if ( 0 === stripos( $header_val, $after_val ) ) {
							$visit_type = 'after';
							break 2;
						}
					}
				}
			}

			if ( ! isset( $visit_type ) ) { // Get afterwards fetch type when no typing headers are present.
				if ( // When $visit_second_time_without_url_params is enabled, we don't check afterwards fetch time for the first query.
					$url_params && $visit_second_time_without_url_params &&
					isset( wp_parse_url( $link )['query'] )
				) {
					$afterwards_fetch_time = null;
				} else {
					$time_before_afterwards_fetch = microtime( true );
					self::request_a_page( $link, $timeout, $user_agent, $cookies, $request_headers );
					$afterwards_fetch_time = round( microtime( true ) - $time_before_afterwards_fetch, 2 );
				}
			}

			$response_body = wp_remote_retrieve_body( $response );

			/*
			 * Add content-types data (pages, styles, scripts, sitemaps, etc.).
			 */

			$page_content_types = array(
				'', // No header.
				'text/html',
			);

			$style_content_types = array(
				'text/css',
			);

			$sitemap_content_types = array(
				'text/xml',
				'application/xml',
			);

			/**
			 * Detect if the page has a canonical.
			 */

			$has_a_canonical = false;
			$canonical       = null;

			if ( $response_body && in_array( $content_type_header, $page_content_types, true ) ) {
				$canonical = Content_Parsing::get_canonical_from_html( $response_body );

				if ( $canonical ) {
					$canonical = Url_Formatting::convert_a_url_to_absolute( $canonical, $link );
					if ( $canonical ) {
						// Add URL params, to have a parity with the later stage filtering.
						$canonical = Url_Formatting::add_url_params( $canonical, $url_params );

						if ( $rewrite_to_https ) {
							$canonical = Url_Formatting::rewrite_url_to_https( $canonical );
						}

						if (
							Url_Formatting::untrailingslash_a_url( $canonical ) !== Url_Formatting::untrailingslash_a_url( $link ) &&
							Url_Validation::is_url_host_allowed( $canonical, $link ) // Do not add external hosts.
						) {
							$has_a_canonical = true;
						}
					}
				}
			}

			if ( DB::is_log_cancelled( $log_id ) || DB::was_warmup_stopped( $warmup_id ) ) {
				return;
			}

			$content_types_to_parse_content_for = array_merge( $page_content_types, $sitemap_content_types );

			if ( $assets_preloading['styles'] ) {
				$content_types_to_parse_content_for = array_merge( $content_types_to_parse_content_for, $style_content_types );
			}

			if ( $response_body && in_array( $content_type_header, $style_content_types, true ) ) {
				Tree::add_the_first_leaf_siblings(
					$warmup_id,
					$log_id,
					$depth,
					Leaf_Only_Subtree::get_fonts_and_images_from_stylesheet_content(
						$response_body,
						$link,
						$rewrite_to_https,
						$assets_preloading,
						$user_agents
					)
				);
			}

			if (
				$response_body &&
				in_array( $content_type_header, $content_types_to_parse_content_for, true ) &&
				$depth < $max_depth
			) {
				if ( in_array( $content_type_header, $page_content_types, true ) ) {

					// Add the leaf children from the page content.

					Tree::add_the_first_leaf_children(
						$warmup_id,
						$log_id,
						$depth,
						array_merge(
							Leaf_Only_Subtree::get_pages_links_from_html(
								$response_body,
								$link,
								$url_params,
								$visit_second_time_without_url_params,
								$visit_second_time_without_cookies,
								$rewrite_to_https,
								$user_agents
							),
							Leaf_Only_Subtree::get_assets_links_from_html(
								$response_body,
								$link,
								$rewrite_to_https,
								$assets_preloading,
								$user_agents
							),
							$add_entry_points_sites_sitemaps ?
								Leaf_Only_Subtree::get_sitemap_links_from_html(
									$response_body,
									$link,
									$rewrite_to_https,
									$user_agents
								) :
								array()
						)
					);
				} elseif ( in_array( $content_type_header, $style_content_types, true ) ) {
					Tree::add_the_first_leaf_children(
						$warmup_id,
						$log_id,
						$depth,
						Leaf_Only_Subtree::get_stylesheets_links_from_stylesheet_content(
							$response_body,
							$link,
							$rewrite_to_https,
							$assets_preloading,
							$user_agents
						)
					);
				} elseif ( in_array( $content_type_header, $sitemap_content_types, true ) ) {
					Tree::add_the_first_leaf_children(
						$warmup_id,
						$log_id,
						$depth,
						Leaf_Only_Subtree::get_children_from_sitemap(
							$response_body,
							$link,
							$user_agent,
							$url_params,
							$visit_second_time_without_url_params,
							$visit_second_time_without_cookies,
							$rewrite_to_https,
							$user_agents
						)
					);
				}
			}

			/*
			 * Add page canonical to the list of warmed pages (if "exclude pages with warmed canonical" setting is on).
			 *
			 * Otherwise, add the canonical page, to warm it after the current one.
			 */

			if ( $has_a_canonical ) {
				if ( $exclude_pages_with_warmed_canonical ) {
					self::update_retrieved_links(
						$warmup_id,
						Url_Formatting::add_url_params( $canonical, $url_params ),
						$depth,
						$link_meta
					);
				} else {
					/*
					 * Add the canonical page, to warm it also, after the current one, on the same depth.
					 */
					Tree::add_the_first_leaf_siblings(
						$warmup_id,
						$log_id,
						$depth,
						Leaf_Only_Subtree::get(
							array(
								$canonical,
							),
							$url_params,
							$visit_second_time_without_url_params,
							$visit_second_time_without_cookies,
							$rewrite_to_https,
							$user_agents
						),
						false,
						$depth
					);
				}
			}

			// A failed insert or killed worker leaves this page pending for replay.
			// Persist request metadata before the final completion checkpoint.
			if ( ! DB::update_warm_up_meta( $warmup_id, $meta ) ) {
				throw new \RuntimeException( 'Failed to persist warm-up queue metadata.' );
			}
			$phase          = self::get_phase( $meta );
			$content_length = wp_remote_retrieve_header( $response, 'content-length' );
			$cf_cache       = wp_remote_retrieve_header( $response, 'cf-cache-status' );
			$wp_cache       = wp_remote_retrieve_header( $response, 'X-WP-Super-Cache' );
			$x_cache        = wp_remote_retrieve_header( $response, 'x-cache' );
			Logging::log_success(
				$log_id,
				$fetch_time ? $fetch_time : null,
				$afterwards_fetch_time ?? null,
				$response_code,
				$phase,
				$user_agent,
				$external_warmer_results,
				$content_type_header,
				is_array( $content_length ) ? reset( $content_length ) : $content_length,
				is_array( $cf_cache ) ? reset( $cf_cache ) : $cf_cache,
				is_array( $wp_cache ) ? reset( $wp_cache ) : $wp_cache,
				is_array( $x_cache ) ? reset( $x_cache ) : $x_cache,
				$visit_type ?? '',
				$has_a_canonical ? $canonical : '',
			);
		}
	}

	/**
	 * Count non-cancelled visit completions newer than the one-minute cutoff.
	 *
	 * Uses site-local completion dates strictly greater than now minus sixty
	 * seconds. Includes all success states, phases and visit types; this is a
	 * row count, not necessarily the number of HTTP requests. Reads uncached
	 * shared state and casts the database scalar to int. A failed/empty lookup
	 * therefore returns zero rather than raising an explicit database exception.
	 *
	 * @param int $warmup_id Run ID whose completion rate is measured.
	 * @return int Matching completed row count, or zero when no scalar is returned.
	 */
	public static function how_many_fetches_were_done_for_the_warmup( int $warmup_id ) {
		global $wpdb;

		$current_time = wp_date( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'
	                SELECT COUNT(*)
	                FROM %i
	                WHERE list_id = %d AND warm_date > %s AND is_cancelled = 0;
	            ',
				$table_name,
				$warmup_id,
				$current_time
			)
		);
	}

	/**
	 * Process at most one pending visit under the site-wide process lock.
	 *
	 * Defers delivery on lock contention. Once owned, recovers incomplete queue
	 * lifecycle work, selects the next visit and applies its persisted pause and
	 * speed limits before HTTP processing. The row's phase determines whether
	 * this is a retry; newly discovered rows retain their own attempt phase.
	 *
	 * Persists updated metadata, observes concurrent scheduled-run cancellation,
	 * prepares retries and finalizes completed runs. Schedules further delivery
	 * when needed, including work belonging to other runs. These steps are not
	 * one transaction: HTTP requests and earlier writes may survive a later
	 * failure. Always releases an acquired process lock on exit.
	 *
	 * @return void
	 *
	 * @throws Exception When a database, lifecycle, HTTP-processing or scheduling
	 *                   dependency reports an exception; prior work is not rolled back.
	 */
	public static function process() {
		if ( ! DB::acquire_process_lock() ) {
			self::schedule_async_warmup( time() + 5 );
			return;
		}

		try {
			Queue_Lifecycle::recover();
			$links_to_warm = DB::get_links_to_warm( 1 );

			if ( ! isset( $links_to_warm[0] ) ) {
				return;
			}

			$link_to_warm = $links_to_warm[0];
			$meta         = (array) json_decode( $link_to_warm['meta'], true );
			$link_meta    = (array) json_decode( $link_to_warm['link_meta'], true );
			// Attempt phase belongs to the URL, not to the whole crawl: a retry can
			// discover a new URL which has not had its first attempt yet.
			unset( $meta['failed_to_retrieve_tree'] );
			if ( 1 === (int) $link_to_warm['log_phase'] ) {
				$meta['failed_to_retrieve_tree'] = true;
			}
			$log_id    = (int) $link_to_warm['id'];
			$warmup_id = (int) $link_to_warm['list_id'];
			Queue_Lifecycle::activate( $warmup_id );
			$is_unscheduled = DB::WARMUP_TYPE_UNSCHEDULED === ( $link_to_warm['warmup_type'] ?? '' );
			$speed_limit    = (int) ( $meta['speed_limit'] ?? 1 );

			if ( isset( $meta['pause_warming_up_to'] ) ) {
				if ( time() < (int) $meta['pause_warming_up_to'] ) {
					self::schedule_async_warmup( (int) $meta['pause_warming_up_to'] );
					return;
				}
				unset( $meta['pause_warming_up_to'] );
			}

			if ( isset( $meta['limit_crawl_speed_up_to'] ) && time() > $meta['limit_crawl_speed_up_to'] ) {
				unset( $meta['speed_limit_divider'], $meta['limit_crawl_speed_up_to'] );
				$meta['speed_limit'] = (int) ( $meta['initial_speed_limit'] ?? $speed_limit );
				$speed_limit         = $meta['speed_limit'];
			}

			// Scheduler delivery is not a throttle: duplicate and early actions still
			// have to obey the persisted queue policy before making an HTTP request.
			if ( self::how_many_fetches_were_done_for_the_warmup( $warmup_id ) >= max( 1, $speed_limit ) ) {
				self::schedule_async_warmup( time() + 10 );
				return;
			}

			self::handle_a_link(
				$log_id,
				$warmup_id,
				$link_to_warm['log_url'],
				(int) $link_to_warm['log_depth'],
				$meta,
				$link_meta
			);

			if ( ! $is_unscheduled && DB::was_warmup_stopped( $warmup_id ) ) {
				// Catch descendants inserted by a worker that overlapped the stop request.
				DB::mark_all_links_as_warmed( $warmup_id );

				if ( DB::get_links_to_warm( 1 ) ) {
					self::schedule_async_warmup();
				}
				return;
			}

			DB::update_warm_up_meta( $warmup_id, $meta );

			if ( ! $is_unscheduled && DB::was_warmup_stopped( $warmup_id ) ) {
				// Catch links discovered by a worker that overlapped the stop request.
				DB::mark_all_links_as_warmed( $warmup_id );

				if ( DB::get_links_to_warm( 1 ) ) {
					self::schedule_async_warmup();
				}
				return;
			}

			if ( isset( $meta['pause_warming_up_to'] ) ) {
				$pause_warming_until = (int) $meta['pause_warming_up_to'];
			}
			$speed_limit = (int) $meta['speed_limit'];

			if ( DB::warmup_has_pending_links( $warmup_id ) ) {
				if ( isset( $pause_warming_until ) ) {
					self::schedule_async_warmup( $pause_warming_until );
				} elseif ( self::how_many_fetches_were_done_for_the_warmup( $warmup_id ) >= max( 1, $speed_limit ) ) {
					self::schedule_async_warmup( time() + 10 );
				} else {
					self::schedule_async_warmup();
				}
				return;
			}

			if ( empty( $meta['retry_phase_started'] ) ) {
				$meta['retry_phase_started']     = true;
				$meta['failed_to_retrieve_tree'] = true;
				$retries                         = DB::enqueue_failed_retries( $warmup_id );
				DB::update_warm_up_meta( $warmup_id, $meta );

				if ( $retries ) {
					self::schedule_async_warmup( $pause_warming_until ?? false );
					return;
				}
			}

			Queue_Lifecycle::finish( $warmup_id, $meta, $link_to_warm['warmup_type'] );

			if ( DB::get_links_to_warm( 1 ) ) {
				self::schedule_async_warmup();
			}
		} finally {
			DB::release_process_lock();
		}
	}

	/**
	 * Requests one background action for pending warm-up work.
	 *
	 * Serializes the pending-action check with a site-scoped database advisory lock.
	 * Lock contention returns without scheduling. If an action is already pending,
	 * it is preserved regardless of the requested timestamp. Otherwise a truthy
	 * timestamp creates a single scheduled action and a false value creates an
	 * asynchronous action. The scheduling lock is released even if the scheduler
	 * call throws; scheduler failures propagate to the caller.
	 *
	 * @param int|false $timestamp Unix timestamp for delayed execution, or false for immediate execution.
	 *
	 * @return void
	 */
	public static function schedule_async_warmup( $timestamp = false ) {
		if ( ! DB::acquire_scheduling_lock() ) {
			return;
		}

		try {
			if (
				! as_get_scheduled_actions(
					array(
						'hook'   => Cache_Warmer::HOOK_NAME,
						'status' => ActionScheduler_Store::STATUS_PENDING,
					)
				)
			) {
				if ( $timestamp ) {
					as_schedule_single_action( $timestamp, Cache_Warmer::HOOK_NAME );
				} else {
					as_enqueue_async_action( Cache_Warmer::HOOK_NAME );
				}
			}
		} finally {
			DB::release_scheduling_lock();
		}
	}

	/**
	 * Cancel one scheduled run while retaining delivery for independent queues.
	 *
	 * Unschedules pending warming actions, marks the supplied run cancelled and
	 * requests lifecycle recovery. Does not delete an already executing action;
	 * its worker observes cancellation after its in-flight request. If another
	 * runnable queue exists, requests a replacement warming action.
	 *
	 * Does not write the administrator's stop-marker option or acquire a process
	 * lock. The caller owns those surrounding coordination steps. Operations are
	 * ordered but not transactional; a failure can leave earlier changes applied.
	 *
	 * @param int $warmup_id Numeric ID of the scheduled run to cancel.
	 *
	 * @return void
	 *
	 * @throws Exception When cancellation, recovery or scheduling reports failure.
	 */
	public static function stop_current_warmup( int $warmup_id ) {
		as_unschedule_all_actions( Cache_Warmer::HOOK_NAME );

		DB::mark_all_links_as_warmed( $warmup_id );
		Queue_Lifecycle::schedule_recovery();

		/*
		 * Do not delete an action that is already executing. The worker checks the
		 * persisted stop marker after its in-flight request and will not continue
		 * the cancelled queue. Leaving the action in place allows Action Scheduler
		 * to record its normal completion instead of reporting an unknown action.
		 */

		// A scheduled stop must not strand independent triggered queues.
		if ( DB::get_links_to_warm( 1 ) ) {
			self::schedule_async_warmup();
		}
	}

    /**
     * Returns failed warm-ups counter.
     *
     * @return int Failed warm-ups counter.
     */
    public static function action_scheduler_get_failed_warm_ups_counter() {
        return count(
            as_get_scheduled_actions(
                [
                    'hook'     => Cache_Warmer::HOOK_NAME,
                    'status'   => ActionScheduler_Store::STATUS_FAILED,
                    'per_page' => -1,
                ]
            )
        );
    }

	/**
	 * Derive scheduled-run status and repair missing warming delivery.
	 *
	 * Reports failure when a pending scheduled row exists and the global failed
	 * warming-action count exceeds that run's recorded counter. Otherwise reports
	 * progress while a scheduled run blocks admission, or completion when none
	 * does. This is not a status lookup for a supplied run ID or triggered queue.
	 *
	 * The read also requests an async action when scheduled work is pending but
	 * no warming action exists. Expects stored metadata to contain the failed
	 * action counter; does not validate or repair malformed metadata here.
	 *
	 * @return string One of 'failed', 'in-progress' or 'complete'.
	 *
	 * @throws Exception When an admission-state read or scheduling dependency fails.
	 */
	public static function get_last_warm_up_state() {
		$links_to_warm = DB::get_links_to_warm( 1, true );

		$is_not_finished = (bool) DB::get_active_scheduled_warmup();
		$is_failed       = $links_to_warm && self::action_scheduler_get_failed_warm_ups_counter() > json_decode( $links_to_warm[0]['meta'] )->failed_warm_ups_counter;

		if ( $is_failed ) {
			$state = 'failed';
		} elseif ( $is_not_finished ) {
			$state = 'in-progress';
		} else {
			$state = 'complete';
		}

		// Restore scheduler delivery when pending scheduled work has no action.
		if (
			$links_to_warm &&
			! as_has_scheduled_action( Cache_Warmer::HOOK_NAME )
		) {
			self::schedule_async_warmup();
		}

		return $state;
	}
}
