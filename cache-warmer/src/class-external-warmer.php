<?php
/**
 * A class for external warmer management.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Class External_Warmer.
 */
final class External_Warmer {

    /**
     * External warmer warm-up ID (for the logs).
     */
    const WARMUP_ID = '2000-01-01';

    /**
     * Hook name for external warmer handling interval.
     */
    const INTERVAL_HOOK_NAME = 'cache_warmer_process_external_warmer';

    /**
     * Hook name for warming of URLs chunk.
     */
    const WARM_POSTS_CHUNK_ACTION_NAME = 'cache_warmer_warm_urls_chunk';

	/**
	 * Register external discovery and chunk workers for the active runtime.
	 *
	 * Deactivation cancellation is owned by the early central cleanup callback.
	 */
    public function __construct() {

        // Creates external warmer post chunks.
        add_action( self::INTERVAL_HOOK_NAME, [ $this, 'create_external_warmer_post_chunks' ] );

        // Warm post chunks.
        add_action( self::WARM_POSTS_CHUNK_ACTION_NAME, [ $this, 'warm_posts_chunk' ] );
    }

    /**
     * Creates external warmer post chunks.
     *
     * @param string $domain A domain.
     */
    public function create_external_warmer_post_chunks( string $domain ) {
        $last_success_warmup_request_args = Cache_Warmer::$options->get( 'last-success-warmup-external-warmup-request-args' );

        if ( ! $last_success_warmup_request_args || ! isset( $last_success_warmup_request_args[ $domain ] ) ) {
            return;
        }

        $arranged_by_headers = []; // Arrange by headers.

        foreach ( $last_success_warmup_request_args[ $domain ] as $request_args ) {
            $urls    = $request_args[0];
            $headers = $request_args[1];

            $headers_str = wp_json_encode( $headers );

            if ( ! isset( $arranged_by_headers[ $headers_str ] ) ) {
                $arranged_by_headers[ $headers_str ] = [];
            }

            $arranged_by_headers[ $headers_str ][] = $urls;
        }

        foreach ( $arranged_by_headers as $headers_str => $urls ) {
            $urls = array_column( $urls, 0 );

            $headers = json_decode( $headers_str, true );
            $chunks  = array_chunk( $urls, 10 );

            foreach ( $chunks as $chunk_urls ) {
                as_enqueue_async_action(
                    self::WARM_POSTS_CHUNK_ACTION_NAME,
                    [
                        [
                            'headers' => $headers,
                            'urls'    => $chunk_urls,
                        ],
                    ]
                );
            }
        }
    }

	/**
	 * Submit a URL chunk to configured external servers and append their results.
	 *
	 * Expects same-domain URLs and HTTP header lines. The transport helper may
	 * return no servers, in which case no result is logged. For each returned
	 * server, records each submitted URL: malformed/missing entries and service
	 * failures become failed results; extra response entries are ignored.
	 * A processed status is treated as success independently of the page code.
	 *
	 * Requests and log writes are not transactional. The logger's false return
	 * is not checked here; exceptions propagate and can leave earlier server
	 * requests or result rows completed. Repeated calls can append new rows.
	 *
	 * @param array $args {
	 *     Chunk payload supplied by the scheduled action.
	 *
	 *     @type string[] $urls    URLs belonging to the same domain.
	 *     @type string[] $headers Request header lines in name: value form.
	 * }
	 *
	 * @return void
	 *
	 * @throws \Exception When option access or result logging reports a persistence or lock failure.
	 */
	public function warm_posts_chunk( $args ) {
		$urls        = $args['urls'];
		$req_headers = $args['headers'];

		$external_warmer_response = self::warm_the_link( $urls, $req_headers );

		$req_headers_assoc = array();
		foreach ( $req_headers as $header ) {
			$parts = explode( ': ', $header, 2 );
			if ( count( $parts ) === 2 ) {
				$req_headers_assoc[ strtolower( $parts[0] ) ] = $parts[1];
			}
		}

		foreach ( $external_warmer_response as $value ) {
			$code        = $value['code'];
			$server_code = $value['server_code'];

			if ( 200 === $code ) {
				$body = json_decode( $value['body'], true );

				// Account for every submitted URL. A short or malformed service
				// response must not silently drop work or manufacture extra logs.
				foreach ( $urls as $i => $url ) {
					$url_visit = is_array( $body ) ? ( $body[ $i ] ?? null ) : null;
					if (
						! is_array( $url_visit ) ||
						! isset( $url_visit['status'], $url_visit['headers'] ) ||
						! is_string( $url_visit['status'] ) ||
						! is_array( $url_visit['headers'] ) ||
						count( array_filter( $url_visit['headers'], 'is_string' ) ) !== count( $url_visit['headers'] ) ||
						! is_numeric( $url_visit['time'] ?? 0 ) ||
						! is_finite( (float) ( $url_visit['time'] ?? 0 ) ) ||
						(float) ( $url_visit['time'] ?? 0 ) < 0
					) {
						Logging::log_external_result(
							false,
							$server_code . ' ' . $url,
							null,
							__( 'Invalid external server response', 'cache-warmer' ),
							$req_headers_assoc['user-agent'] ?? ''
						);
						continue;
					}

					$status = $url_visit['status'];
					$time   = $url_visit['time'] ?? 0;

					$headers       = array();
					$response_code = '';

					foreach ( $url_visit['headers'] as $header ) {
						if ( strpos( $header, 'HTTP/' ) === 0 ) {
							$parts = explode( ' ', $header );
							if ( isset( $parts[1] ) ) {
								$response_code = $parts[1];
							}
						} else {
							$parts = explode( ': ', $header, 2 );
							if ( count( $parts ) === 2 ) {
								$headers[ strtolower( $parts[0] ) ] = $parts[1];
							}
						}
					}

					if ( 'processed' === $status ) {
						$content_type_value  = $headers['content-type'] ?? '';
						$content_type_header = explode( ';', is_array( $content_type_value ) ? reset( $content_type_value ) : $content_type_value )[0];
						$content_length      = $headers['content-length'] ?? '';
						$cf_cache_status     = $headers['cf-cache-status'] ?? '';
						$wp_cache_status     = $headers['x-wp-super-cache'] ?? '';
						$x_cache_status      = $headers['x-cache'] ?? '';

						Logging::log_external_result(
							true,
							$server_code . ' ' . $url,
							round( $time, 2 ),
							$response_code,
							$req_headers_assoc['user-agent'] ?? '',
							array(),
							$content_type_header,
							is_array( $content_length ) ? reset( $content_length ) : $content_length,
							is_array( $cf_cache_status ) ? reset( $cf_cache_status ) : $cf_cache_status,
							is_array( $wp_cache_status ) ? reset( $wp_cache_status ) : $wp_cache_status,
							is_array( $x_cache_status ) ? reset( $x_cache_status ) : $x_cache_status,
						);
					} else {
						Logging::log_external_result(
							false,
							$server_code . ' ' . $url,
							round( $time, 2 ),
							sprintf(
								/* translators: %s is external server status. */
								__( 'External server status: %s', 'cache-warmer' ),
								$status
							),
							$req_headers_assoc['user-agent'] ?? ''
						);
					}
				}
			} else {
				foreach ( $urls as  $url ) {
					Logging::log_external_result(
						false,
						$server_code . ' ' . $url,
						null,
						sprintf(
							/* translators: %s is server response code. */
							__( 'Server response code: %d', 'cache-warmer' ),
							$code
						),
						$req_headers_assoc['user-agent'] ?? '',
					);
				}
			}
		}
	}

    /**
     * Returns the list of servers to use for external warmer.
     *
     * @param string $domain A domain.
     *
     * @return array
     */
    private static function get_servers_to_use( string $domain ) {
		$license_key        = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-license-key' . $domain );
		$last_response_code = (int) Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-key-validation-endpoint-last-response-code' . $domain );

        if ( $license_key && 200 === $last_response_code ) {
			return Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-servers-to-use' . $domain, [] );
        } else {
            return [];
        }
    }

    /**
     * Returns request headers.
     *
     * @param string $user_agent      User-Agent.
     * @param array  $cookies         Cookies.
     * @param array  $request_headers Request headers.
     *
     * @return array Request headers.
     */
    public static function get_request_headers( string $user_agent, array $cookies, array $request_headers ): array {
        $prepared_headers = [
            'User-Agent' => $user_agent,
        ];

        // Cookies.

        if ( ! empty( $cookies ) ) {
            $cookie_header = [];
            foreach ( $cookies as $name => $value ) {
                $cookie_header[] = $name . '=' . $value;
            }
            $prepared_headers['Cookie'] = implode( '; ', $cookie_header );
        }

        // Request headers.

        $request_headers = array_column( $request_headers, 'value', 'name' );
        foreach ( $request_headers as $header_name => $header_value ) {
            $prepared_headers[ $header_name ] = $header_value;
        }

        // Convert headers to array of strings.
        return array_map(
            function( $name, $value ) {
                return sprintf( '%s: %s', $name, str_replace( ':', '\:', $value ) ); // Escape.
            },
            array_keys( $prepared_headers ),
            $prepared_headers
        );
    }

    /**
     * Warms the URL(s), using the external warmer.
     *
     * @param string[] $urls_of_the_same_domain URLs to warm (all of them should belong to the same domain).
     * @param string[] $request_headers         Request headers.
     *
     * @return array Response data by server.
     */
    public static function warm_the_link( array $urls_of_the_same_domain, array $request_headers ): array {
        if ( ! $urls_of_the_same_domain ) {
            return [];
        }

        $domain = wp_parse_url( $urls_of_the_same_domain[0], PHP_URL_HOST );

        if ( ! $domain ) {
            return [];
        }

        // An assumption is that all of them belong to the same domain.

		$external_warmer_license_key = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-license-key' . $domain );

        if ( ! $external_warmer_license_key ) {
            return [];
        }

        $response_by_server = [];

        foreach ( self::get_servers_to_use( $domain ) as $server ) {

            // Make a request.
            $response = wp_remote_post(
                $server,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode(
                        [
                            'domain'  => $domain,
                            'key'     => $external_warmer_license_key,
                            'urls'    => $urls_of_the_same_domain,
                            'headers' => $request_headers,
                        ]
                    ),
                    'timeout' => 30,
                ]
            );

            $response_by_server[ $server ] = [
                'server_code' => self::get_server_part_by_server_full_url( $server ),
                'code'        => wp_remote_retrieve_response_code( $response ),
                'body'        => wp_remote_retrieve_body( $response ),
                'time'        => time(),
            ];
        }

        return $response_by_server;
    }

    /**
     * Returns server part by server full URL.
     *
     * E.g. for input 'https://us2.cachewarmer.xyz/' will return 'us2'.
     *
     * @param string $server_url Server full URLs.
     *
     * @return string Server part.
     */
    private static function get_server_part_by_server_full_url( string $server_url ) {
        $parsed_url = wp_parse_url( $server_url );
        $host       = $parsed_url['host'];

        $host_parts = explode( '.', $host );

        return $host_parts[0];
    }

    /**
     * Schedules the interval action if it isn't scheduled.
     */
    public static function schedule_for_all_domains() {
        foreach ( Utils::get_unique_domains_from_entry_points() as $unique_domain ) {
			$license_key    = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-license-key' . $unique_domain );
			$response_code  = (int) Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-key-validation-endpoint-last-response-code' . $unique_domain );
			$servers_to_use = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-servers-to-use' . $unique_domain );
			$interval       = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-external-warmer-interval' . $unique_domain ) * 60 * 60;

            if (
                $license_key &&
                200 === $response_code &&
                $servers_to_use &&
                $interval
            ) {
                Utils::schedule_the_undrifting_interval(
                    $interval,
                    self::INTERVAL_HOOK_NAME,
                    [
                        $unique_domain,
                    ]
                );
            }
        }
    }

    /**
     * Plugin deactivation handler.
     */
    public static function unschedule() {
        as_unschedule_all_actions( self::INTERVAL_HOOK_NAME );
        as_unschedule_all_actions( self::WARM_POSTS_CHUNK_ACTION_NAME );
    }

    /**
     * Reschedules intervals.
     */
    public static function reschedule_intervals() {
        as_unschedule_all_actions( self::INTERVAL_HOOK_NAME );
        self::schedule_for_all_domains();
    }
}
