<?php
/**
 * A class for logging.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Handles logging of the warm-ups.
 */
final class Logging {

	/**
	 * Registers a User-Agent value and returns its database identifier.
	 *
	 * Uses the unique value key to reuse an existing row through LAST_INSERT_ID.
	 * The database write is independent of the subsequent log write. Query
	 * errors are not checked here; the connection's insert ID is returned as-is.
	 *
	 * @param string $value User-Agent string, truncated to its first 500 bytes.
	 *
	 * @return int Database insert ID; not an independently verified success result.
	 */
	private static function get_user_agent_id( string $value ): int {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_user_agents';

		$value = substr( $value, 0, 500 );

		$wpdb->query( // @codingStandardsIgnoreLine
			$wpdb->prepare(
				'
                    INSERT INTO %i (
                       `value`
                   )
                    VALUES (
                        %s
                    )
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
                ',
				$table_name,
				$value
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Appends a warming log row and registers its User-Agent.
	 *
	 * Resolves the URL's WordPress post ID, serializes result and link metadata,
	 * and stores the link hash used by queue admission. A null completion date
	 * leaves the row pending. The insert honors the current process fence and
	 * checks lock ownership afterward. There is no transaction here: User-Agent
	 * registration or a completed insert can persist even if a later step fails.
	 *
	 * @param int     $warmup_id                 Warmup ID.
	 * @param bool    $log_is_success            Whether the log is for successful or failed fetch.
	 * @param int     $log_depth                 The depth of the URL that was fetched.
	 * @param ?float  $log_fetch_time            Time spent to fetch the page.
	 * @param ?float  $afterwards_fetch_time     Time spent to fetch the page after the warming.
	 * @param string  $log_url                   The URL that was fetched.
	 * @param string  $log_extra                 Extra log information (for success - page code (usually 200), for failure - error description).
	 * @param int     $log_phase                 Log phase. @see Cache_Warmer\DB::get_schema() method for description of this column.
	 * @param string  $user_agent                User-agent the page was visited with.
	 * @param array   $external_warmer_results   External warmer results.
	 * @param string  $log_content_type          Content type.
	 * @param string  $log_content_length        Content length.
	 * @param string  $log_cf_cache_status       "cf-cache-status" response header.
	 * @param string  $log_wp_super_cache_status "X-WP-Super-Cache" response header.
	 * @param string  $x_cache_header            "x-cache" response header.
	 * @param string  $visit_type                There can be 3 visit types: 'before', 'after' and '' (default: for success - another request after the main is done when typing headers are lacking; for failure - nothing else is done, obviously).
	 * @param string  $canonical                 Canonical on the page, which leads to a different URL.
	 * @param ?array  $link_meta                 Link meta.
	 * @param ?string $warm_date                 Warm date.
	 * @param ?int    $parent_log_id             Parent log ID.
	 *
	 * @return int|false Newly inserted log ID, or false on failure.
	 *
	 * @throws \RuntimeException When the post-insert ownership check detects a lost lock.
	 */
	public static function log(
		int $warmup_id,
		bool $log_is_success,
		int $log_depth,
		?float $log_fetch_time,
		?float $afterwards_fetch_time,
		string $log_url,
		string $log_extra = '',
		int $log_phase = 0,
		string $user_agent = '',
		array $external_warmer_results = array(),
		string $log_content_type = '',
		string $log_content_length = '',
		string $log_cf_cache_status = '',
		string $log_wp_super_cache_status = '',
		string $x_cache_header = '',
		string $visit_type = '',
		string $canonical = '',
		?array $link_meta = array(),
		?string $warm_date = null,
		?int $parent_log_id = null
	) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$log_date       = wp_date( 'Y-m-d H:i:s' );
		$log_is_success = (int) $log_is_success;

		// Resolve the post ID.
		$post_id = url_to_postid( $log_url );

		// Convert null fetch times to 'NULL' literal for the SQL statement.
		$log_time_spent_value            = null === $log_fetch_time ? 'NULL' : $log_fetch_time;
		$log_time_spent_afterwards_value = null === $afterwards_fetch_time ? 'NULL' : $afterwards_fetch_time;
		$warm_date_value                 = null === $warm_date ? 'NULL' : "'" . esc_sql( $warm_date ) . "'";
		$parent_log_id_value             = null === $parent_log_id ? 'NULL' : $parent_log_id;
		$link_meta_json                  = wp_json_encode( $link_meta );
		$link_hash                       = DB::get_link_hash( $log_url, (array) $link_meta );

		$user_agent_id = self::get_user_agent_id( $user_agent );
		$fence         = DB::process_fence();

		$query = $wpdb->prepare(
			"
                INSERT INTO %i (
                    list_id, log_is_success, log_date, log_depth, log_url, log_post_id,
                    log_time_spent, log_time_afterwards, log_extra, log_phase, log_content_type, log_content_length,
                    log_cf_cache_status, log_wp_super_cache_status, log_x_cache_header, log_visit_type, user_agent_id,
                    canonical, external_warmer_results, link_meta, warm_date, parent_log_id, link_hash
                )
                SELECT
                    %d, %d, %s, %d, %s, %d,
                    $log_time_spent_value, $log_time_spent_afterwards_value, %s, %d, %s, %s,
                    %s, %s, %s, %s, %d,
                    %s, %s, %s, $warm_date_value, $parent_log_id_value, %s
                WHERE $fence
            ",
			$table_name,
			$warmup_id,
			$log_is_success,
			$log_date,
			$log_depth,
			$log_url,
			$post_id,
			$log_extra,
			$log_phase,
			$log_content_type,
			$log_content_length,
			$log_cf_cache_status,
			$log_wp_super_cache_status,
			$x_cache_header,
			$visit_type,
			$user_agent_id,
			$canonical,
			wp_json_encode( $external_warmer_results ),
			$link_meta_json,
			$link_hash
		);

		// Execute the query and check for failure.
		$result = $wpdb->query( $query ); // @codingStandardsIgnoreLine
		DB::assert_process_lock();

		if ( false === $result ) {
			return false;
		}

		// Return the newly inserted log row's ID.
		return $wpdb->insert_id;
	}

	/**
	 * Inserts a completed log row that was processed outside the database queue.
	 *
	 * External warmer jobs do not have a pending queue row to update. Resolves
	 * or creates their shared external warm-up list, then appends a result with
	 * depth and phase zero and the current site-local completion date. Repeated
	 * calls append separate results; this method does not deduplicate requests.
	 *
	 * List creation, User-Agent registration and result insertion are separate
	 * writes without a transaction here. Earlier writes can survive a later
	 * failure. No HTTP request is made and no pending queue row is updated.
	 *
	 * @param bool       $success                 Whether the request succeeded.
	 * @param string     $link                    Logged link, including any caller-supplied server prefix.
	 * @param float|null $fetch_time              Fetch duration, or null when unavailable.
	 * @param string     $extra                   Response code or error.
	 * @param string     $user_agent              User-Agent.
	 * @param array      $external_warmer_results External warmer results serialized as JSON by the log writer.
	 * @param string     $content_type            Content-Type header.
	 * @param string     $content_length          Content-Length header.
	 * @param string     $cf_cache_status         Cloudflare cache status.
	 * @param string     $wp_super_cache_status   WP Super Cache status.
	 * @param string     $x_cache_header          X-Cache header.
	 *
	 * @return int|false Log writer's insert ID, or false if no list ID is obtained
	 *                   or the result insert reports a database error.
	 *
	 * @throws \RuntimeException When the log writer detects lost process-lock ownership.
	 */
	public static function log_external_result(
		bool $success,
		string $link,
		?float $fetch_time,
		string $extra,
		string $user_agent = '',
		array $external_warmer_results = array(),
		string $content_type = '',
		string $content_length = '',
		string $cf_cache_status = '',
		string $wp_super_cache_status = '',
		string $x_cache_header = ''
	) {
		$warmup_id = DB::get_warm_up_id( External_Warmer::WARMUP_ID );

		if ( ! $warmup_id ) {
			$warmup_id = DB::create_or_update_a_warm_up( External_Warmer::WARMUP_ID, array() );
		}

		if ( ! $warmup_id ) {
			return false;
		}

		return self::log(
			$warmup_id,
			$success,
			0,
			$fetch_time,
			null,
			$link,
			$extra,
			0,
			$user_agent,
			$external_warmer_results,
			$content_type,
			$content_length,
			$cf_cache_status,
			$wp_super_cache_status,
			$x_cache_header,
			'',
			'',
			array(),
			wp_date( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Marks an existing, non-cancelled log row as successfully retrieved.
	 *
	 * Registers the User-Agent, writes response metadata and the current
	 * site-local completion date, and checks process-lock ownership after the
	 * fenced update. These writes are not wrapped in a transaction here.
	 *
	 * @param int    $log_id                    Log ID.
	 * @param ?float $fetch_time                Time spent to fetch the page.
	 * @param ?float $afterwards_fetch_time     Time spent to fetch the page after the warming.
	 * @param string $response_code             Response code.
	 * @param int    $log_phase                 Log phase. @see Cache_Warmer\DB::get_schema() method for description of this column.
	 * @param string $user_agent                User-agent the page was visited with.
	 * @param array  $external_warmer_results   External warmer results.
	 * @param string $log_content_type          Content type.
	 * @param string $log_content_length        Content length.
	 * @param string $log_cf_cache_status       "cf-cache-status" response header.
	 * @param string $log_wp_super_cache_status "X-WP-Super-Cache" response header.
	 * @param string $x_cache_header            "x-cache" response header.
	 * @param string $visit_type                There can be 3 visit types: 'before', 'after' and '' (default: for success - another request after the main is done when typing headers are lacking; for failure - nothing else is done, obviously).
	 * @param string $canonical                 Canonical on the page, which leads to a different URL.
	 *
	 * @return bool Whether exactly one row changed. False also covers a missing
	 *              or cancelled row and an update that changes no stored values.
	 *
	 * @throws \Exception When the database update fails, or its RuntimeException
	 *                    subclass when the post-update check detects a lost lock.
	 */
	public static function log_success(
		int $log_id,
		?float $fetch_time,
		?float $afterwards_fetch_time,
		string $response_code,
		int $log_phase = 0,
		string $user_agent = '',
		array $external_warmer_results = array(),
		string $log_content_type = '',
		string $log_content_length = '',
		string $log_cf_cache_status = '',
		string $log_wp_super_cache_status = '',
		string $x_cache_header = '',
		string $visit_type = '',
		string $canonical = ''
	) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$fetch_time            = null === $fetch_time ? 'NULL' : $fetch_time;
		$afterwards_fetch_time = null === $afterwards_fetch_time ? 'NULL' : $afterwards_fetch_time;
		$fence                 = DB::process_fence();

		$result = $wpdb->query(
			$wpdb->prepare(
				"
                    UPDATE %i
					SET `log_is_success` = 1,
						`log_time_spent` = $fetch_time,
                        `log_time_afterwards` = $afterwards_fetch_time,
                        `log_extra` = %s,
                        `log_phase` = %d,
                        `user_agent_id` = %d,
                        `external_warmer_results` = %s,
                        `log_content_type` = %s,
                        `log_content_length` = %s,
                        `log_cf_cache_status` = %s,
                        `log_wp_super_cache_status` = %s,
                        `log_x_cache_header` = %s,
                        `log_visit_type` = %s,
                        `canonical` = %s,
                        `warm_date` = %s
                    WHERE `id` = %d AND `is_cancelled` = 0 AND $fence
                ",
				$table_name,
				$response_code,
				$log_phase,
				self::get_user_agent_id( $user_agent ),
				wp_json_encode( $external_warmer_results ),
				$log_content_type,
				$log_content_length,
				$log_cf_cache_status,
				$log_wp_super_cache_status,
				$x_cache_header,
				$visit_type,
				$canonical,
				wp_date( 'Y-m-d H:i:s' ),
				$log_id
			)
		);

		DB::assert_process_lock();
		if ( false === $result ) {
			throw new \Exception( 'Failed to persist a successful warm-up result.' );
		}

		return 1 === $result;
	}

	/**
	 * Completes one active queue row with a failed retrieval result.
	 *
	 * Writes the failure metadata only when the row remains uncancelled and the
	 * current worker still owns its process fence. A zero-row update returns false
	 * for a missing, cancelled, stale, or value-identical row. Database errors and
	 * lost process ownership are promoted to exceptions; no retry row is created by
	 * this method itself.
	 *
	 * @param int        $log_id                  Numeric queue-log row ID.
	 * @param float|null $fetch_time               Request duration, or null when unavailable.
	 * @param string     $error_description        Operator-facing failure detail.
	 * @param int        $log_phase                Retry phase; see `DB::get_schema()`.
	 * @param string     $user_agent               User agent associated with the visit.
	 * @param array      $external_warmer_results External assistance results encoded as JSON.
	 *
	 * @return bool Whether exactly one active row changed.
	 *
	 * @throws \Exception When the database update fails, or its RuntimeException
	 *                    subclass when the ownership check fails.
	 */
	public static function log_failure(
		int $log_id,
		?float $fetch_time,
		string $error_description,
		int $log_phase = 0,
		string $user_agent = '',
		array $external_warmer_results = array()
	) {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_logs';

		$fetch_time = null === $fetch_time ? 'NULL' : $fetch_time;
		$fence      = DB::process_fence();

		$result = $wpdb->query(
			$wpdb->prepare(
				"
                    UPDATE %i
                    SET `log_time_spent` = $fetch_time,
                        `log_time_afterwards` = NULL,
                        `log_extra` = %s,
                        `log_phase` = %d,
                        `user_agent_id` = %d,
                        `external_warmer_results` = %s,
                        `warm_date` = %s
                    WHERE `id` = %d AND `is_cancelled` = 0 AND $fence
                ",
				$table_name,
				$error_description,
				$log_phase,
				self::get_user_agent_id( $user_agent ),
				wp_json_encode( $external_warmer_results ),
				wp_date( 'Y-m-d H:i:s' ),
				$log_id
			)
		);

		DB::assert_process_lock();
		if ( false === $result ) {
			throw new \Exception( 'Failed to persist a failed warm-up result.' );
		}

		return 1 === $result;
	}

	/**
	 * Read scheduled run identifiers, newest public start date first.
	 *
	 * Excludes triggered and external containers, without requiring completion
	 * or any logged visits. Reads current database state without a local cache.
	 * No rows and a database read failure both return an empty array; errors are
	 * not promoted to exceptions and no persistent state is changed.
	 *
	 * @return string[] Scheduled warmed_at identifiers in descending date order.
	 */
	public static function get_warm_ups_logs_list() {
		global $wpdb;

		$table_name = DB::get_tables_prefix() . 'warm_ups_list';
		$results    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT warmed_at FROM %i WHERE warmup_type = %s ORDER BY warmed_at DESC;',
				$table_name,
				DB::WARMUP_TYPE_SCHEDULED
			),
			ARRAY_A
		);

		return $results ? array_column( $results, 'warmed_at' ) : array();
	}

    /**
     * Returns logs list HTML.
     *
     * @return string Logs list HTML for the left logs column.
     */
    public static function get_logs_list_html() {
        $html = '';

        $log_files = self::get_warm_ups_logs_list();

        if ( $log_files ) {
            foreach ( $log_files as $i => $log_file ) {
                $selected_class = 0 === $i ? ' selected' : '';
                $html          .= '<span class="log-entity' . $selected_class . '">' . esc_html( $log_file ) . '</span>';
            }
        }

        return $html;
    }

    /**
     * Logs per page.
     */
    const LOGS_PER_PAGE = 100;

	/**
	 * Read one page of completed, non-cancelled visits for a run or aggregate.
	 *
	 * Zero and the zero-date sentinel aggregate all triggered runs; selectors
	 * beginning with the external date aggregate external containers. Other
	 * values select an exact public warmed_at identifier. Reads current rows
	 * in ascending log ID order, including failed and skipped visits but not
	 * pending or zero-completion-date rows. User-Agent and run fields are joined.
	 *
	 * For after-check visits, copies the measured duration to log_time_afterwards
	 * and clears log_time_spent in the returned array only. Pagination is not
	 * clamped here. An empty or failed database result ultimately yields an empty
	 * array; the existing foreach can emit a warning for a non-iterable failure.
	 * Does not cache results, throw a read-failure exception or persist changes.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @param int        $page      Intended one-based page number; no range validation is performed here.
	 * @return array[] Associative visit rows with normalized after-check timings.
	 */
	public static function get_log_content( $warmed_at, $page = 1 ) {
		global $wpdb;

		$offset = ( $page - 1 ) * self::LOGS_PER_PAGE;
		$limit  = self::LOGS_PER_PAGE;

		$logs_table        = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table        = DB::get_tables_prefix() . 'warm_ups_list';
		$user_agents_table = DB::get_tables_prefix() . 'warm_ups_user_agents';

		if ( in_array( (string) $warmed_at, array( '0', '0000-00-00 00:00:00' ), true ) ) {
			$list_condition = $wpdb->prepare( 'list.warmup_type = %s', DB::WARMUP_TYPE_UNSCHEDULED );
		} elseif ( External_Warmer::WARMUP_ID === substr( (string) $warmed_at, 0, 10 ) ) {
			$list_condition = $wpdb->prepare( 'list.warmup_type = %s', DB::WARMUP_TYPE_EXTERNAL );
		} else {
			$list_condition = $wpdb->prepare( 'list.warmed_at = %s', $warmed_at );
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
                    SELECT 
                        logs.*,
                        list.warmed_at,
                        user_agents.value AS user_agent
                    FROM %i AS logs
                    LEFT JOIN %i AS list ON logs.list_id = list.id
                    LEFT JOIN %i AS user_agents ON logs.user_agent_id = user_agents.id
                    WHERE $list_condition
                    AND logs.warm_date IS NOT NULL
                    AND logs.warm_date != '0000-00-00 00:00:00'
					AND logs.is_cancelled = 0
                    ORDER BY logs.id ASC
                    LIMIT %d OFFSET %d
                ",
				$logs_table,
				$list_table,
				$user_agents_table,
				$limit,
				$offset
			),
			ARRAY_A
		);

		foreach ( $results as &$result ) { // If log visit type is "after" then it has obviously afterwards visit time.
			if ( 'after' === $result['log_visit_type'] ) {
				$result['log_time_afterwards'] = $result['log_time_spent'];
				$result['log_time_spent']      = '';
			}
		}

		return $results ? $results : array();
	}

    /**
     * Returns the ID the latest warm-up.
     *
     * @return bool|string Latest warm-up ID or false if no warm-ups.
     */
    public static function get_latest_warmed_at() {
        $warm_ups_list = self::get_warm_ups_logs_list();
        if ( $warm_ups_list ) {
            return reset( $warm_ups_list );
        } else {
            return false;
        }
    }

    /**
     * Returns the content of the last log file.
     *
     * @param int $page       For which page to retrieve the content.
     *
     * @return array Content of the latest log file.
     */
    public static function get_latest_log_content( $page = 1 ) {
        $latest_warmed_at = self::get_latest_warmed_at();
        if ( $latest_warmed_at ) {
            return self::get_log_content( $latest_warmed_at, $page );
        } else {
            return [];
        }
    }

    /**
     * Returns the HTML string for the warm log.
     *
     * @param string $warmed_at  File name to get the log content for.
     * @param array  $log_content  Log content.
     * @param int    $current_page For which page to retrieve the content.
     *
     * @return string Log content HTML string.
     */
    public static function format_log_content_array_into_string( $warmed_at, $log_content, $current_page = 1 ) {
        if ( ! $log_content ) {
            return __( 'No data here yet.', 'cache-warmer' );
        }

        $total_visits                  = Summary::get_log_rows_count( $warmed_at );
        $successful                    = Summary::get_log_success_count( $warmed_at );
        $failed                        = Summary::get_log_fail_count( $warmed_at );
        $skipped                       = Summary::get_log_skipped_count( $warmed_at );
        $avg_page_load_time            = Summary::get_warm_up_page_average_load_time( $warmed_at );
        $avg_page_load_time_afterwards = Summary::get_warm_up_page_average_load_time_afterwards( $warmed_at );

        $warmed_at_first_part = explode( ' ', $warmed_at )[0];

        if ( ! in_array( $warmed_at_first_part, [ External_Warmer::WARMUP_ID, '0', '0000-00-00' ] ) ) {
            $duration = Summary::get_warm_up_duration( $warmed_at );
            $speed    = Summary::get_warm_up_speed( $warmed_at );
        }

        $cf_status_values_count = array_count_values( array_column( $log_content, 'log_cf_cache_status' ) );
        $has_cf_cache_statuses  = ! array_key_exists( '', $cf_status_values_count ) || count( $cf_status_values_count ) > 1;

        $wp_super_cache_status_values_count = array_count_values( array_column( $log_content, 'log_wp_super_cache_status' ) );
        $has_wp_super_cache_statuses        = ! array_key_exists( '', $wp_super_cache_status_values_count ) || count( $wp_super_cache_status_values_count ) > 1;

        $x_cache_values_count = array_count_values( array_column( $log_content, 'log_x_cache_header' ) );
        $has_x_cache_statuses = ! array_key_exists( '', $x_cache_values_count ) || count( $x_cache_values_count ) > 1;

        $content_lengths_count = array_count_values( array_column( $log_content, 'log_content_length' ) );
        $has_content_length    = count( $content_lengths_count ) > ( array_key_exists( '', $content_lengths_count ) ? 2 : 1 );

        $content_types_count = array_count_values( array_column( $log_content, 'log_content_type' ) );
        $has_content_type    = count( $content_types_count ) > ( array_key_exists( '', $content_types_count ) ? 2 : 1 );

        $has_several_user_agents = count( array_unique( array_values( array_column( $log_content, 'user_agent' ) ) ) ) > 1;

        $times_spent = array_filter(
            array_unique( array_values( array_column( $log_content, 'log_time_spent' ) ) ),
            function( $val ) {
                return ! in_array( $val, [ '0', '' ], true );
            }
        );

        $times_afterwards = array_filter(
            array_unique( array_values( array_column( $log_content, 'log_time_afterwards' ) ) ),
            function( $val ) {
                return ! in_array( $val, [ '0', '' ], true );
            }
        );

        $has_skipped_warms = array_filter(
            array_column( $log_content, 'log_phase' ),
            function( $phase ) {
                return 2 === (int) $phase;
            }
        );

        $has_retry = array_filter(
            array_column( $log_content, 'log_phase' ),
            function( $phase ) {
                return 1 === (int) $phase;
            }
        );

        ob_start();
        ?>

        <div class="cache-warmer-log-summary">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Total Visits', 'cache-warmer' ); ?></th>
                        <th><?php esc_html_e( 'Successful', 'cache-warmer' ); ?></th>
                        <th><?php esc_html_e( 'Failed', 'cache-warmer' ); ?></th>
                        <?php if ( $has_skipped_warms ) : ?>
                            <th><?php esc_html_e( 'Skipped', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $times_spent ) : ?>
                            <th><?php esc_html_e( 'Avg. page load (sec.)', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $times_afterwards && External_Warmer::WARMUP_ID !== $warmed_at_first_part ) : ?>
                            <th><?php esc_html_e( 'Avg. page load after warming (sec.)', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( ! in_array( $warmed_at_first_part, [ External_Warmer::WARMUP_ID, '0', '0000-00-00' ] ) ) : ?>
                            <th><?php esc_html_e( 'Duration', 'cache-warmer' ); ?></th>
                            <th><?php esc_html_e( 'Speed (pages / minute)', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html( $total_visits ); ?></td>
                        <td><?php echo esc_html( $successful ); ?></td>
                        <td><?php echo esc_html( $failed ); ?></td>
                        <?php if ( $has_skipped_warms ) : ?>
                            <td><?php echo esc_html( $skipped ); ?></td>
                        <?php endif; ?>
                        <?php if ( $times_spent ) : ?>
                            <td><?php echo esc_html( $avg_page_load_time ); ?></td>
                        <?php endif; ?>
                        <?php if ( $times_afterwards && External_Warmer::WARMUP_ID !== $warmed_at_first_part ) : ?>
                            <td><?php echo esc_html( $avg_page_load_time_afterwards ); ?></td>
                        <?php endif; ?>
                        <?php if ( ! in_array( $warmed_at_first_part, [ External_Warmer::WARMUP_ID, '0', '0000-00-00' ] ) ) : ?>
                            <td><?php echo esc_html( $duration ); ?></td>
                            <td><?php echo esc_html( $speed ); ?></td>
                        <?php endif; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="cache-warmer-log-content">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'cache-warmer' ); ?></th>
                        <?php
                        if ( ! in_array( $warmed_at_first_part, [ External_Warmer::WARMUP_ID, '0', '0000-00-00' ], true ) ) :
                            ?>
                            <th><?php esc_html_e( 'Depth', 'cache-warmer' ); ?></th>
                            <?php
                        endif;
                        ?>
                        <th><?php esc_html_e( 'URL', 'cache-warmer' ); ?></th>
                        <?php
                        if ( External_Warmer::WARMUP_ID === $warmed_at_first_part ) :
                            ?>
                            <th><?php esc_html_e( 'External Server', 'cache-warmer' ); ?></th>
                            <?php
                        endif;
                        ?>
                        <?php if ( $has_several_user_agents ) : ?>
                            <th><?php esc_html_e( 'User-Agent', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $times_spent ) : ?>
                            <th><?php esc_html_e( 'Loading time', 'cache-warmer' ); ?><br><?php esc_html_e( '(seconds)', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $times_afterwards && External_Warmer::WARMUP_ID !== $warmed_at_first_part ) : ?>
                            <th><?php esc_html_e( 'Loading time afterwards', 'cache-warmer' ); ?><br><?php esc_html_e( '(seconds)', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php
                        $warming_results_for_heading = array_column( $log_content, 'external_warmer_results' );
                        if ( $warming_results_for_heading ) {
                            $servers = array_values(
                                array_unique(
                                    array_column(
                                        array_merge(
                                            ...array_map(
                                                fn( $result ) => (array) json_decode( $result, true ),
                                                $warming_results_for_heading
                                            )
                                        ),
                                        'server'
                                    )
                                )
                            );

                            foreach ( $servers as $server ) {
                                echo '<th>' . esc_html__( 'External Warmer', 'cache-warmer' ) .
                                    '<br>' . esc_html__( '(seconds)', 'cache-warmer' ) .
                                    '<br><br>' . esc_html( strtoupper( $server ) ) . '<br>' .
                                    '</th>';
                            }
                        }
                        ?>
                        <th><?php echo str_replace( '/', '/<br>', esc_html__( 'Response code / Error text', 'cache-warmer' ) ); // @codingStandardsIgnoreLine ?></th>
                        <?php if ( $has_content_type ) : ?>
                            <th>content-type</th>
                        <?php endif; ?>
                        <?php if ( $has_content_length ) : ?>
                            <th>content-length</th>
                        <?php endif; ?>
                        <?php if ( $has_cf_cache_statuses ) : ?>
                            <th>cf-cache-status<br><?php esc_html_e( 'header', 'cache-warmer' ); ?><sup><i>1</i></sup></th>
                        <?php endif; ?>
                        <?php if ( $has_wp_super_cache_statuses ) : ?>
                            <th>X-WP-Super-Cache<br><?php esc_html_e( 'header', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $has_x_cache_statuses ) : ?>
                            <th>X-Cache<br><?php esc_html_e( 'header', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                        <?php if ( $has_retry ) : ?>
                            <th><?php esc_html_e( 'Retry', 'cache-warmer' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ( $log_content as $log_entity ) :
                    $external_warmer_results = (array) json_decode( $log_entity['external_warmer_results'], true );

                    ?>
                    <tr class="<?php echo ( ! $log_entity['log_is_success'] && 2 !== (int) $log_entity['log_phase'] ) ? 'cache-warmer-log-failure' : ( 2 === (int) $log_entity['log_phase'] ? 'cache-warmer-log-skipped' : '' ); ?>">
                        <td><?php echo str_replace( '-', '&#x2011;', esc_html( $log_entity['log_date'] ) ); // @codingStandardsIgnoreLine ?></td>
                        <?php
                        if ( ! in_array( $warmed_at_first_part, [ External_Warmer::WARMUP_ID, '0', '0000-00-00' ], true ) ) :
                            ?>
                            <td><?php echo esc_html( $log_entity['log_depth'] ); ?></td>
                            <?php
                        endif;
                        ?>
                        <td>
                        <a href="
                        <?php
                        echo esc_url(
                            External_Warmer::WARMUP_ID !== $warmed_at_first_part ? $log_entity['log_url'] : explode( ' ', $log_entity['log_url'] )[1]
                        );
                        ?>
                        " target="_blank">
                        <?php
                            echo '<span class="cache-warmer-link-content">' . preg_replace(
                                '@/@',
                                '/<wbr>',
                                esc_html( External_Warmer::WARMUP_ID !== $warmed_at_first_part ? $log_entity['log_url'] : explode( ' ', $log_entity['log_url'] )[1] )
                            ) . '</span>' . (
                                    $log_entity['canonical'] ?
                                        '<span class="cache-warmer-popup-message" data-popup-message="' .
                                        /* translators: %s is the canonical URL. */
                                        esc_attr( sprintf( __( 'Has a canonical: %s', 'cache-warmer' ), $log_entity['canonical'] ) ) .
                                        '"> ⚠</span>️' : ''
                                );
                        ?>
                        </a>
                        </td>
                        <?php
                        if ( External_Warmer::WARMUP_ID === $warmed_at_first_part ) :
                            ?>
                            <td><?php echo esc_html( strtoupper( explode( ' ', $log_entity['log_url'] )[0] ) ); ?></td>
                            <?php
                        endif;
                        ?>
                        <?php if ( $has_several_user_agents ) : ?>
                            <td><?php echo esc_html( $log_entity['user_agent'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $times_spent ) : ?>
                            <td><?php echo esc_html( $log_entity['log_time_spent'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $times_afterwards && External_Warmer::WARMUP_ID !== $warmed_at_first_part ) : ?>
                            <td><?php echo esc_html( $log_entity['log_time_afterwards'] ); ?></td>
                        <?php endif; ?>
                        <?php

                        $external_warmer_results_servers = array_column( $external_warmer_results, 'server' );

                        foreach ( $servers as $server ) {
                            $server_index = array_search( $server, $external_warmer_results_servers );

                            if ( false !== $server_index ) {
                                $external_warmer_result = $external_warmer_results[ $server_index ];
                                // Older versions could persist malformed status values.
                                $external_warmer_status = is_string( $external_warmer_result['status'] ?? null ) ?
                                    $external_warmer_result['status'] : __( 'Invalid external server response', 'cache-warmer' );
                                $external_warmer_succeeded = 200 === $external_warmer_result['code'] && 'processed' === $external_warmer_status;

                                echo '<td class="' . ( $external_warmer_succeeded ? '' : 'cache-warmer-log-failure' ) . '">' .
                                    '<span class="cache-warmer-popup-message" data-popup-message="' . esc_attr( wp_date( 'Y-m-d H:i:s', $external_warmer_result['at'] ) ) . '">' . esc_html(
                                        $external_warmer_succeeded ?
                                            $external_warmer_result['time'] :
                                            (
                                            $external_warmer_status ?
                                                $external_warmer_status :
                                                '-'
                                            )
                                    ) . '</span></td>';
                            } else {
                                echo '<td>-</td>';
                            }
                        }
                        ?>
                        <td>
                        <?php
                            // Keep diagnostic links and formatting without executing remote markup.
                            echo wp_kses_post( $log_entity['log_extra'] );
                        ?>
                        </td>
                        <?php if ( $has_content_type ) : ?>
                            <td><?php echo esc_html( $log_entity['log_content_type'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $has_content_length ) : ?>
                            <td><?php echo esc_html( $log_entity['log_content_length'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $has_cf_cache_statuses ) : ?>
                            <td><?php echo esc_html( $log_entity['log_cf_cache_status'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $has_wp_super_cache_statuses ) : ?>
                            <td><?php echo esc_html( $log_entity['log_wp_super_cache_status'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $has_x_cache_statuses ) : ?>
                            <td><?php echo esc_html( $log_entity['log_x_cache_header'] ); ?></td>
                        <?php endif; ?>
                        <?php if ( $has_retry ) : ?>
                            <td><?php echo esc_html( $log_entity['log_phase'] ); ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php

        $max_page = (int) ( floor( $total_visits / self::LOGS_PER_PAGE ) + ( $total_visits % self::LOGS_PER_PAGE ? 1 : 0 ) );

        if ( $total_visits > self::LOGS_PER_PAGE ) :
            /*
             * Adds pagination HTML.
             */
            $previous_page = 1 === $current_page ? 1 : $current_page - 1;

            $next_page = $current_page === $max_page ? $max_page : $current_page + 1;

            $PAGES_VIEW_RANGE = 2;

            ?>
                <div class="cache-warmer-pagination-container">
                    <div class="cache-warmer-pagination">
                        <a data-page="<?php echo esc_attr( $previous_page ); ?>" rel="prev" class="cache-warmer-pagination-element">
                            <?php echo esc_attr( __( 'Previous', 'cache-warmer' ) ); ?>
                        </a>
            <?php

            $were_three_dots_added_start = false;
            $were_three_dots_added_end   = false;

            $print_three_dots = function () {
                ?>
                <span class="cache-warmer-pagination-dots">...</span>
                <?php
            };

            $page = 1;
            for ( $remained_logs = $total_visits; $remained_logs > 0; $remained_logs -= self::LOGS_PER_PAGE ) {
                $is_page_in_start_range      = $page <= $PAGES_VIEW_RANGE;
                $is_page_around_current_page = $page >= $current_page - $PAGES_VIEW_RANGE && $page <= $current_page + $PAGES_VIEW_RANGE;
                $is_page_in_end_range        = $page > $max_page - $PAGES_VIEW_RANGE;

                if ( $is_page_in_start_range || $is_page_around_current_page || $is_page_in_end_range ) {
                    if ( $current_page === $page ) {
                        ?>
                        <a data-page="<?php echo esc_attr( $page ); ?>" class="cache-warmer-pagination-element cache-warmer-pagination-digit active">
                            <?php echo esc_attr( $page ); ?>
                        </a>
                        <?php
                    } else {
                        ?>
                        <a data-page="<?php echo esc_attr( $page ); ?>" class="cache-warmer-pagination-element cache-warmer-pagination-digit">
                            <?php echo esc_attr( $page ); ?>
                        </a>
                        <?php
                    }
                } else {
                    if ( $page < $current_page && ! $were_three_dots_added_start ) {
                        $print_three_dots();
                        $were_three_dots_added_start = true;
                    }
                    if ( $page > $current_page && ! $were_three_dots_added_end ) {
                        $print_three_dots();
                        $were_three_dots_added_end = true;
                    }
                }

                $page ++;
            }

            ?>
                    <a data-page="<?php echo esc_attr( $next_page ); ?>" rel="next" class="cache-warmer-pagination-element">
                        <?php echo esc_attr( __( 'Next', 'cache-warmer' ) ); ?>
                    </a>
                </div>
            </div>
            <?php
        endif;
        ?>

        <?php if ( $has_cf_cache_statuses ) : ?>
            <div class="cache-warmer-row mt-5">
                <p class="cache-warmer-comment">
                    <b>1</b><?php esc_html_e( ' -', 'cache-warmer' ); ?> <a href="https://developers.cloudflare.com/cache/about/default-cache-behavior/#cloudflare-cache-responses">https://developers.cloudflare.com/cache/about/default-cache-behavior/#cloudflare-cache-responses</a>
                </p>
            </div>
        <?php endif; ?>

        <?php
        return ob_get_clean();
    }
}
