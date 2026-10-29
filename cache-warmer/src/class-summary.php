<?php
/**
 * A class for warm-up summary.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Read aggregate statistics from persisted warm-up lists and visit logs.
 *
 * Each accessor queries current database state without caching or modifying it.
 * Separate calls do not form a consistent snapshot. Historical return values
 * preserve database scalar types; a failed read can be indistinguishable from
 * missing data and is not promoted to an exception by this class.
 */
final class Summary {

	/**
	 * Build the list predicate for one run or a legacy aggregate selector.
	 *
	 * Zero and the zero-date sentinel select every triggered run. A selector
	 * beginning with the external-warmer date selects every external run. Other
	 * selectors match the public warmed_at identifier exactly. Only constructs
	 * SQL; it does not execute a database read.
	 *
	 * @param string     $list_table Internal list-table alias used by the calling query.
	 * @param string|int $warmed_at  Public run identifier or legacy aggregate selector.
	 * @return string SQL predicate with the selector value prepared.
	 */
	private static function get_list_condition( string $list_table, $warmed_at ): string {
		global $wpdb;

		if ( in_array( (string) $warmed_at, array( '0', '0000-00-00 00:00:00' ), true ) ) {
			return $wpdb->prepare( '%i.warmup_type = %s', $list_table, DB::WARMUP_TYPE_UNSCHEDULED );
		}

		if ( External_Warmer::WARMUP_ID === substr( (string) $warmed_at, 0, 10 ) ) {
			return $wpdb->prepare( '%i.warmup_type = %s', $list_table, DB::WARMUP_TYPE_EXTERNAL );
		}

		return $wpdb->prepare( '%i.warmed_at = %s', $list_table, $warmed_at );
	}

	/**
	 * Read the mean recorded loading time before post-warming checks.
	 *
	 * Excludes pending, zero-date and cancelled visits, after-check visits and
	 * null timings. Does not restrict success or retry phase. SQL rounds the
	 * mean to two decimal places; no matching timings produce a null aggregate.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|null|false Database decimal string, null for no timings, or false when no row is returned.
	 */
	public static function get_warm_up_page_average_load_time( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT
					    ROUND(AVG(logs.log_time_spent), 2)
					FROM
					    %i AS logs
					INNER JOIN
					    %i AS list ON logs.list_id=list.id
					WHERE
					    $list_condition AND
					    logs.warm_date IS NOT NULL AND
					    logs.warm_date != '0000-00-00 00:00:00' AND
					    logs.is_cancelled = 0 AND
					    logs.log_visit_type != 'after' AND
					    logs.log_time_spent IS NOT NULL
				",
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Read the mean post-warming time across both timing sources.
	 *
	 * Combines non-after visits' log_time_afterwards with completed after
	 * visits' log_time_spent using UNION ALL. Both sources exclude cancellation;
	 * only the after-visit branch requires a nonzero completion date. Duplicate
	 * timing values remain separate observations. SQL rounds to two decimals.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|null|false Database decimal string, null for no timings, or false when no row is returned.
	 */
	public static function get_warm_up_page_average_load_time_afterwards( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT ROUND(AVG(avg_log_time), 2)
					FROM (
					    SELECT
					        logs.log_time_afterwards as avg_log_time
					    FROM
					        %i AS logs
					    INNER JOIN
					        %i AS list ON logs.list_id=list.id
					    WHERE
					        $list_condition AND
					        logs.is_cancelled = 0 AND
					        logs.log_visit_type != 'after' AND
					        logs.log_time_spent IS NOT NULL AND
					        logs.log_time_afterwards IS NOT NULL

					    UNION ALL

					    SELECT
					        logs.log_time_spent as avg_log_time
					    FROM
					        %i AS logs
					    INNER JOIN
					        %i AS list ON logs.list_id=list.id
					    WHERE
					        $list_condition AND
					        logs.warm_date IS NOT NULL AND
					        logs.warm_date != '0000-00-00 00:00:00' AND
					        logs.is_cancelled = 0 AND
					        logs.log_visit_type = 'after' AND
					        logs.log_time_spent IS NOT NULL
					) as t
				",
				$logs_table,
				$list_table,
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Count completed, non-cancelled visits, including skipped and failed rows.
	 *
	 * Pending and legacy zero-date rows are excluded. The count is returned
	 * without a numeric cast, including the database string zero for no matches.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|false Database count string, or false when no row is returned.
	 */
	public static function get_log_rows_count( $warmed_at ) {
		global $wpdb;

		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT COUNT(*)
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE
					    $list_condition AND
					    logs.warm_date IS NOT NULL AND
					    logs.warm_date != '0000-00-00 00:00:00' AND
					    logs.is_cancelled = 0
				",
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Count successful completed visits across all phases and visit types.
	 *
	 * Excludes cancellation, pending rows and legacy zero completion dates.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|false Database count string, or false when no row is returned.
	 */
	public static function get_log_success_count( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT COUNT(*)
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE
					    $list_condition AND
					    logs.warm_date IS NOT NULL AND
					    logs.warm_date != '0000-00-00 00:00:00' AND
					    logs.is_cancelled = 0 AND
					    logs.log_is_success = 1
				",
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Count failed completed visits other than phase-two skipped rows.
	 *
	 * Excludes cancellation, pending rows and legacy zero completion dates.
	 * Retry failures remain distinct rows rather than unique failed URLs.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|false Database count string, or false when no row is returned.
	 */
	public static function get_log_fail_count( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT COUNT(*)
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE
					    $list_condition AND
					    logs.warm_date IS NOT NULL AND
					    logs.warm_date != '0000-00-00 00:00:00' AND
					    logs.is_cancelled = 0 AND
					    logs.log_is_success = 0 AND
					    logs.log_phase <> 2;
				",
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Count completed phase-two failures classified as skipped visits.
	 *
	 * Excludes cancellation, pending rows and legacy zero completion dates.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return string|false Database count string, or false when no row is returned.
	 */
	public static function get_log_skipped_count( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT COUNT(*)
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE
					    $list_condition AND
					    logs.warm_date IS NOT NULL AND
					    logs.warm_date != '0000-00-00 00:00:00' AND
					    logs.is_cancelled = 0 AND
					    logs.log_is_success = 0 AND
					    logs.log_phase = 2;
				",
				$logs_table,
				$list_table
			),
			ARRAY_N
		);

		return $result ? $result[0] : false;
	}

	/**
	 * Read the newest and oldest completed visit/list rows by log_date.
	 *
	 * Returns newest first and oldest second, excluding cancelled, pending and
	 * zero-date visits. UNION ALL repeats a sole matching visit twice. Equal
	 * dates have no additional tie breaker. SELECT * retains the database's
	 * existing joined-column collision behavior; this is not a unique-ID API.
	 *
	 * @param string|int $warmed_at Public run identifier or legacy aggregate selector.
	 * @return array[]|false Associative joined rows, or false for no rows or a failed read.
	 */
	public static function get_first_and_last_log_fields( $warmed_at ) {
		global $wpdb;

		$logs_table     = DB::get_tables_prefix() . 'warm_ups_logs';
		$list_table     = DB::get_tables_prefix() . 'warm_ups_list';
		$list_condition = self::get_list_condition( 'list', $warmed_at );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
					(SELECT *
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE $list_condition AND logs.warm_date IS NOT NULL AND logs.warm_date != '0000-00-00 00:00:00' AND logs.is_cancelled = 0
					ORDER BY log_date DESC
					LIMIT 1)

					UNION ALL

					(SELECT *
					FROM %i AS logs
					INNER JOIN %i AS list ON logs.list_id=list.id
					WHERE $list_condition AND logs.warm_date IS NOT NULL AND logs.warm_date != '0000-00-00 00:00:00' AND logs.is_cancelled = 0
					ORDER BY log_date ASC
					LIMIT 1)
				",
				$logs_table,
				$list_table,
				$logs_table,
				$list_table
			),
			ARRAY_A
		);

		return $results ? $results : false;
	}

	/**
	 * Calculates elapsed time between the newest and oldest completed visits.
	 *
	 * Uses the current selector semantics of `get_first_and_last_log_fields()`.
	 * Empty or incomplete results produce zero. A single matching visit is repeated
	 * by that reader and therefore also produces zero. Formatting is timezone-neutral
	 * and switches to a zero-based day count at 24 hours.
	 *
	 * @param string|int $warmed_at     Public run identifier or legacy aggregate selector.
	 * @param bool       $get_timestamp Whether to return elapsed seconds instead of formatted text.
	 *
	 * @return int|string Non-negative elapsed seconds or an `H:i:s`/`z H:i:s` string.
	 */
	public static function get_warm_up_duration( $warmed_at, $get_timestamp = false ) {
		$first_and_last_rows = self::get_first_and_last_log_fields( $warmed_at );

		if ( ! $first_and_last_rows || count( $first_and_last_rows ) < 2 ) {
			return $get_timestamp ? 0 : '00:00:00';
		}
		$diff = strtotime( $first_and_last_rows[0]['log_date'] ) - strtotime( $first_and_last_rows[1]['log_date'] );
		return $get_timestamp ? $diff : ( $diff < 86400 ? gmdate( 'H:i:s', $diff ) : gmdate( 'z H:i:s', $diff ) );
	}

    /**
     * Get warm-up speed.
     *
     * @param string $warmed_at Warm-up to get the data for.
     *
     * @return float Warm-up speed (pages/minute).
     */
    public static function get_warm_up_speed( $warmed_at ) {
        $rows_count       = self::get_log_rows_count( $warmed_at ) - self::get_log_skipped_count( $warmed_at );
        $duration_minutes = self::get_warm_up_duration( $warmed_at, true ) / 60;
        return round( $duration_minutes ? ( $rows_count / $duration_minutes ) : $rows_count, 2 );
    }
}
