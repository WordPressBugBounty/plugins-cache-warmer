<?php
/**
 * Checked current-site scheduler cleanup for deactivation and inactive uninstall.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Cancels pending delivery without deleting queue data or scheduler history.
 *
 * This class deliberately does not depend on the main plugin bootstrap, options,
 * or migrations. Uninstall can load it while Cache Warmer is inactive.
 */
final class Scheduler_Cleanup {

	/**
	 * Cancel all pending actions belonging to the plugin on the current site.
	 *
	 * Accepts the native DBStore or the shipped HybridStore with native DB/post
	 * backends. Unknown stores are rejected before discovery or mutation. Native
	 * discovery may migrate legacy rows; cancellation uses a conditional UPDATE
	 * against the current site's native action table. Arguments and groups are
	 * unrestricted for each exact owned hook. Claimed/running jobs, unknown hooks
	 * and terminal history are retained, including claims won after discovery.
	 * Each cancellation and subsequent lookup is checked; an exception prevents
	 * normal lifecycle completion but does not roll back earlier cancellations.
	 * Already-running requests must be drained separately before plugin removal.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the scheduler is unavailable, its lookup fails,
	 *                          or a pending action cannot be cancelled.
	 */
	public static function run(): void {
		global $wpdb;

		if ( ! class_exists( '\ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			throw new \RuntimeException( 'Cache Warmer could not initialize scheduler cleanup. Retry after resolving the scheduler failure.' );
		}

		$hooks           = array(
			'cache_warmer_process',
			'cache_warmer_process_interval',
			'cache_warmer_process_posts_enqueue',
			'cache_warmer_fix_missing_intervals',
			'cache_warmer_clear_old_actions',
			'cache_warmer_process_external_warmer',
			'cache_warmer_warm_urls_chunk',
			'cache-warmer-start-from-cli',
			'cache-warmer-stop-from-cli',
		);
		$query_seen      = false;
		$database_failed = false;
		/**
		 * Observe the preceding SQL result without changing the incoming query.
		 *
		 * Captures the current wpdb connection and updates the shared query-seen
		 * and failure flags by reference. The first invocation excludes errors
		 * predating this observer; later invocations latch the previous query's
		 * error before the incoming query can clear last_error. This includes
		 * intermediate HybridStore and callback queries. The caller also checks
		 * the final SQL result after each store operation or cancellation.
		 * Registration is scoped to this cleanup and removed in finally. The
		 * observer executes no SQL and returns the supplied query unchanged.
		 *
		 * @param string $query SQL passed through the WordPress query filter.
		 * @return string Unmodified SQL for normal database execution.
		 */
		$observe_query = static function ( string $query ) use ( $wpdb, &$query_seen, &$database_failed ): string {
			if ( $query_seen && $wpdb->last_error ) {
				$database_failed = true;
			}
			$query_seen = true;
			return $query;
		};
		add_filter( 'query', $observe_query, PHP_INT_MAX );

		try {
			$store = \ActionScheduler::store();
			self::assert_supported_store( $store );
			foreach ( $hooks as $hook ) {
				do {
					$actions = $store->query_actions(
						array(
							'hook'     => $hook,
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'claimed'  => false,
							'per_page' => 100,
						)
					);
					if ( $database_failed || $wpdb->last_error ) {
						throw new \RuntimeException( 'Scheduler lookup failed.' );
					}

					foreach ( $actions as $action_id ) {
						self::cancel_pending_action( (int) $action_id, $hook );
						if ( $database_failed || $wpdb->last_error ) {
							throw new \RuntimeException( 'Scheduler cancellation failed.' );
						}
					}
				} while ( $actions );
			}
		} catch ( \Exception $error ) {
			throw new \RuntimeException( 'Cache Warmer could not complete scheduler cleanup. Retry after resolving the scheduler failure.', 0, $error );
		} finally {
			remove_filter( 'query', $observe_query, PHP_INT_MAX );
		}
	}

	/**
	 * Refuse stores for which the native conditional cancellation is not valid.
	 *
	 * HybridStore does not expose its backend instances publicly. Inspect only
	 * the two known properties of the exact shipped class; do not assume a new
	 * Controller configuration describes an already constructed store. Changed
	 * internals or custom subclasses/backends fail closed without querying jobs.
	 * The native controller uses DBStoreMigrator as its destination. That exact
	 * class only extends save_action(), retaining DBStore's query/claim semantics;
	 * allow it inside HybridStore without admitting arbitrary DBStore subclasses.
	 *
	 * @param \ActionScheduler_Store $store Selected scheduler store instance.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the store/current-site table is unsupported
	 *                          or the native HybridStore layout has changed.
	 */
	private static function assert_supported_store( \ActionScheduler_Store $store ): void {
		global $wpdb;
		if ( $wpdb->prefix . 'actionscheduler_actions' !== $wpdb->actionscheduler_actions ) {
			throw new \RuntimeException( 'Scheduler cleanup requires the current-site native action table.' );
		}
		if ( \ActionScheduler_DBStore::class === get_class( $store ) ) {
			return;
		}
		if ( \ActionScheduler_HybridStore::class === get_class( $store ) ) {
			$expected = array(
				'primary_store'   => array( \ActionScheduler_DBStore::class, \ActionScheduler_DBStoreMigrator::class ),
				'secondary_store' => array( \ActionScheduler_wpPostStore::class ),
			);
			try {
				foreach ( $expected as $name => $classes ) {
					$property = new \ReflectionProperty( \ActionScheduler_HybridStore::class, $name );
					$property->setAccessible( true );
					if ( ! in_array( get_class( $property->getValue( $store ) ), $classes, true ) ) {
						throw new \RuntimeException( 'Scheduler cleanup cannot safely cancel a custom hybrid backend.' );
					}
				}
			} catch ( \ReflectionException $error ) {
				throw new \RuntimeException( 'Scheduler cleanup cannot inspect this hybrid layout.', 0, $error );
			}
			return;
		}
		throw new \RuntimeException( 'Scheduler cleanup cannot safely cancel this custom store.' );
	}

	/**
	 * Cancel one still-unclaimed native action using an atomic state predicate.
	 *
	 * A worker may claim or start the job after discovery. In that case the
	 * conditional write leaves its row alone and no cancellation callback fires.
	 * Already removed/terminal rows are successful no-ops. An unclaimed pending
	 * row remaining after the write is a failure, preventing a non-progress loop.
	 * Does not start or commit a transaction owned by the caller.
	 *
	 * Like native DBStore cancellation, changes only status, retains the action
	 * row and history, and uses action_scheduler_canceled_action for the active
	 * logger and other listeners. Neither path releases claims or directly
	 * invalidates an object cache. Unlike native cancellation, this path only
	 * notifies after a changed row is read back as cancelled, not after a no-op.
	 * A readback or listener failure can leave a persisted cancellation without
	 * complete notification/logging; retries do not replay terminal actions.
	 *
	 * @param int    $action_id Native action ID returned by supported discovery.
	 * @param string $hook      Exact owned hook being cleaned.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the conditional update or readback fails.
	 */
	private static function cancel_pending_action( int $action_id, string $hook ): void {
		global $wpdb;
		$changed = $wpdb->update(
			$wpdb->actionscheduler_actions,
			array( 'status' => \ActionScheduler_Store::STATUS_CANCELED ),
			array(
				'action_id' => $action_id,
				'hook'      => $hook,
				'status'    => \ActionScheduler_Store::STATUS_PENDING,
				'claim_id'  => 0,
			),
			array( '%s' ),
			array( '%d', '%s', '%s', '%d' )
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Scheduler conditional cancellation failed.' );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, claim_id FROM %i WHERE action_id = %d AND hook = %s',
				$wpdb->actionscheduler_actions,
				$action_id,
				$hook
			)
		);
		if ( $wpdb->last_error || ( $row && \ActionScheduler_Store::STATUS_PENDING === $row->status && 0 === (int) $row->claim_id ) ) {
			throw new \RuntimeException( 'Scheduler cancellation could not be verified.' );
		}
		if ( $changed && $row && \ActionScheduler_Store::STATUS_CANCELED === $row->status ) {
			/**
			 * Preserve the native notification only for an action cancelled here.
			 *
			 * @param int $action_id Cancelled native action identifier.
			 */
			do_action( 'action_scheduler_canceled_action', $action_id );
		}
	}
}
