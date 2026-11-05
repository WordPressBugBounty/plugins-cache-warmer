<?php
/**
 * Assets for dashboard
 *
 * Loads assets (JS, CSS), adds data for them.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer\Assets;

use Cache_Warmer\DB;
use Cache_Warmer\Cache_Warmer;

/**
 * Assets class.
 */
final class Dashboard {

	/**
	 * Inits.
	 */
	public static function init() {
		$class = __CLASS__;
		add_action(
			'admin_enqueue_scripts',
			function () use ( $class ) {
				new $class();
			}
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->styles();
		$this->scripts();
	}

	/**
	 * Loads styles.
	 */
	private function styles() {
		wp_enqueue_style(
			'cache-warmer-admin-dashboard-style',
			CACHE_WARMER_URL . 'assets-build/admin/dashboard.css',
			array(),
			CACHE_WARMER_VERSION
		);
	}

	/**
	 * Read the shared saved chart and request one background refresh when stale.
	 *
	 * Never falls back to aggregation on the dashboard request. The first load
	 * displays an empty chart; subsequent loads use the last successful result.
	 * Scheduling failure does not prevent login or dashboard rendering. Failed
	 * refreshes retain the old chart and can be retried on a dashboard visit
	 * after a five-minute crash/failure cooldown. Options bypass node-local caches; this data is diagnostic only.
	 * The snapshot is outside the eager option registry, so unrelated requests
	 * do not load its payload. Uninstall explicitly removes the exact option.
	 *
	 * @return array<int,array{date:string,time:string,time_after:string}> Saved points.
	 */
	public static function get_cached_statistics(): array {
		$snapshot = array();
		try {
			$snapshot = Cache_Warmer::$options->get_dynamic( 'cache-warmer-dashboard-statistics', array() );
			if ( ! is_array( $snapshot ) ) {
				$snapshot = array();
			}
			if ( max( (int) ( $snapshot['updated'] ?? 0 ) + HOUR_IN_SECONDS, (int) ( $snapshot['retry_after'] ?? 0 ) ) <= time() &&
				function_exists( 'as_has_scheduled_action' ) &&
				! as_has_scheduled_action( 'cache_warmer_refresh_dashboard_statistics' ) ) {
				// A delayed action avoids an immediate async HTTP dispatch on login.
				as_schedule_single_action( time() + 30, 'cache_warmer_refresh_dashboard_statistics', array(), 'cache-warmer', true );
			}
		} catch ( \Throwable $error ) {
			// Preserve the last read chart even when scheduling fails.
			return isset( $snapshot['data'] ) && is_array( $snapshot['data'] ) ? $snapshot['data'] : array();
		}
		return isset( $snapshot['data'] ) && is_array( $snapshot['data'] ) ? $snapshot['data'] : array();
	}

	/**
	 * Refresh chart data outside the dashboard request under a site-specific lock.
	 *
	 * Competing workers do not duplicate the aggregate. Chart publication occurs only
	 * after a successful query. A durable five-minute retry deadline is saved
	 * first, retaining old chart data, so repeated crashes cannot create a tight
	 * retry loop. Database connection death releases ownership. This approximate
	 * chart is not queue state and never acknowledges or deletes warming work.
	 *
	 * @return void
	 * @throws \RuntimeException When locking, aggregation or durable publication fails.
	 * @throws \Exception Original runtime failure rethrown without losing its cause.
	 */
	public static function refresh_statistics(): void {
		global $wpdb;
		$lock     = 'cw-dashboard-' . sha1( $wpdb->options );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		if ( $wpdb->last_error || null === $acquired ) {
			throw new \RuntimeException( 'Could not acquire dashboard statistics lock.' );
		}
		if ( '1' !== (string) $acquired ) {
			return;
		}
		try {
			$snapshot = Cache_Warmer::$options->get_dynamic( 'cache-warmer-dashboard-statistics', array() );
			if ( is_array( $snapshot ) && max( (int) ( $snapshot['updated'] ?? 0 ) + HOUR_IN_SECONDS, (int) ( $snapshot['retry_after'] ?? 0 ) ) > time() ) {
				return;
			}
			$snapshot                = is_array( $snapshot ) ? $snapshot : array();
			$snapshot['retry_after'] = time() + 5 * MINUTE_IN_SECONDS;
			Cache_Warmer::$options->set_dynamic( 'cache-warmer-dashboard-statistics', $snapshot );
			$data = self::get_logs_avg_load_times_before_and_after_the_warmup();
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock ) ) ) {
				throw new \RuntimeException( 'Lost dashboard statistics ownership.' );
			}
			Cache_Warmer::$options->set_dynamic(
				'cache-warmer-dashboard-statistics',
				array(
					'updated' => time(),
					'data'    => $data,
				)
			);
		} catch ( \Exception $error ) {
			if ( $error instanceof \RuntimeException ) {
				throw $error;
			}
			throw new \RuntimeException( 'Could not save dashboard statistics.', 0, $error );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Aggregate before/after measurements for the last month in one grouped scan.
	 *
	 * Before samples exclude after-visits. After samples combine explicit after
	 * visits and secondary measurements of before-visits, preserving their
	 * original sample weighting. Null measurements are excluded, zero is valid.
	 * Only dates with both averages are charted. At most the latest 1,000 points
	 * are retained, returned chronologically to bound the non-autoloaded cache.
	 * Call from background processing, never the dashboard rendering path.
	 *
	 * @return array<int,array{date:string,time:string,time_after:string}> Chart points.
	 * @throws \RuntimeException When statistics cannot be read.
	 */
	public static function get_logs_avg_load_times_before_and_after_the_warmup() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT runs.warmed_at AS `date`,
                    ROUND(AVG(CASE WHEN logs.log_visit_type != 'after' THEN logs.log_time_spent END), 2) AS `time`,
                    ROUND(AVG(CASE
                        WHEN logs.log_visit_type = 'after' THEN logs.log_time_spent
                        WHEN logs.log_visit_type != 'after' AND logs.log_time_spent IS NOT NULL THEN logs.log_time_afterwards
                    END), 2) AS time_after
                FROM %i AS logs INNER JOIN %i AS runs ON logs.list_id = runs.id
                WHERE runs.warmed_at > %s
                GROUP BY runs.warmed_at
                HAVING `time` IS NOT NULL AND time_after IS NOT NULL
                ORDER BY runs.warmed_at DESC LIMIT 1000",
				DB::get_tables_prefix() . 'warm_ups_logs',
				DB::get_tables_prefix() . 'warm_ups_list',
				wp_date( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS )
			),
			ARRAY_A
		);
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Could not refresh dashboard statistics.' );
		}
		return array_reverse( $rows );
	}

	/**
	 * Loads scripts.
	 */
	private function scripts() {
		wp_register_script(
			'cache-warmer-lib-apexcharts',
			CACHE_WARMER_URL . 'libs/apexcharts/apexcharts.min.js',
			array(),
			'3.35.1',
			true
		);

		wp_register_script(
			'cache-warmer-admin-script',
			CACHE_WARMER_URL . 'assets-build/admin/index.js',
			array(),
			CACHE_WARMER_VERSION,
			true
		);

		wp_enqueue_script(
			'cache-warmer-admin-dashboard-script',
			CACHE_WARMER_URL . 'assets-build/admin/dashboard.js',
			array( 'cache-warmer-admin-script', 'jquery', 'cache-warmer-lib-apexcharts' ),
			CACHE_WARMER_VERSION,
			true
		);

		wp_localize_script(
			'cache-warmer-admin-script',
			'wpCacheWarmerChart',
			array(
				'jQuery'          => 'jQuery',
				'disable_pro'     => '1',
				'disable_banners' => '',
				'l10n'            => array(
					'time'          => __( 'Time', 'cache-warmer' ),
					'no_data'       => __( 'Statistics are prepared in the background. Reload later if no data is shown.', 'cache-warmer' ),
					'after_warm_up' => __( 'After warming', 'cache-warmer' ),
				),
				'data'            => self::get_cached_statistics(),
			)
		);
	}
}
