<?php
/**
 * Durable publication and completion of database-backed warm-up runs.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Keeps accepted seed graphs recoverable until all queue rows are published.
 *
 * Only the process-lock owner expands manifests or finalizes runs. Admission
 * has its own short lock, so starting a run never waits for an HTTP request.
 */
final class Queue_Lifecycle {

	/**
	 * Accepts a complete immutable seed graph in one database write.
	 *
	 * Admission is serialized independently from worker processing. The durable
	 * manifest is committed before asynchronous delivery is requested; a failed
	 * schedule or partial row expansion therefore remains recoverable.
	 *
	 * @param array<string,mixed> $meta Run configuration, including start date.
	 * @param array<string,mixed> $tree Complete seed graph in Tree format.
	 * @param string              $type Scheduled or triggered run type.
	 *
	 * @return array<string,mixed>|false Accepted run, or false when another
	 *                                   scheduled run already exists.
	 * @throws \RuntimeException When admission or durable storage is unavailable.
	 */
	public static function publish( array $meta, array $tree, string $type ) {
		if ( ! DB::acquire_admission_lock() ) {
			throw new \RuntimeException( 'Could not acquire warm-up admission lock.' );
		}
		try {
			if ( DB::WARMUP_TYPE_SCHEDULED === $type && DB::get_active_scheduled_warmup() ) {
				return false;
			}
			$run = DB::create_warm_up( $meta, $type, $meta['start_date'], $tree );
			if ( false === $run ) {
				throw new \RuntimeException( 'Could not persist the complete warm-up seed graph.' );
			}
		} finally {
			DB::release_admission_lock();
		}

		// The manifest itself survives failure to schedule or publish its rows.
		Warm_Up::schedule_async_warmup();
		if ( DB::acquire_process_lock() ) {
			try {
				self::recover();
			} finally {
				DB::release_process_lock();
			}
		}

		return $run;
	}

	/**
	 * Resume interrupted publication and completion while holding the worker lock.
	 *
	 * Publication replays the entire manifest. Queue deduplication makes a partial
	 * previous expansion safe, and building rows cannot be fetched until ready.
	 * Finalization is replayable until its durable completed marker is written.
	 *
	 * @return void
	 * @throws \RuntimeException On invalid manifests or failed persistence.
	 */
	public static function recover(): void {
		global $wpdb;
		DB::require_process_lock();
		DB::discard_published_seed_chunks();
		$table = DB::get_tables_prefix() . 'warm_ups_list';
		$logs  = DB::get_tables_prefix() . 'warm_ups_logs';
		$runs  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, queue_state, meta, warmup_type FROM %i AS runs WHERE queue_state = 'building'
				 OR (queue_state = 'cancelled' AND EXISTS (
				 SELECT 1 FROM %i WHERE list_id = runs.id AND warm_date IS NULL))
				 OR (queue_state IN ('ready', 'finalizing') AND NOT EXISTS (
				 SELECT 1 FROM %i WHERE list_id = runs.id AND warm_date IS NULL)) ORDER BY id LIMIT 20",
				$table,
				$logs,
				$logs
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not read unfinished warm-up lifecycles.' );
		}
		foreach ( $runs as $run ) {
			$id = (int) $run['id'];
			if ( 'cancelled' === $run['queue_state'] ) {
				DB::mark_all_links_as_warmed( $id );
				continue;
			}
			if ( 'building' === $run['queue_state'] ) {
				$tree = DB::read_warm_up_seed( $id );
				$phase = 0;
				if ( isset( $tree['legacy_format'] ) ) {
					$phase = $tree['retry_phase'] ? 1 : 0;
					self::restore_legacy_history( $id, $tree );
					$tree = $tree['tree'];
				}
				while ( $tree ) {
					if ( DB::was_warmup_stopped( $id ) ) {
						DB::mark_all_links_as_warmed( $id );
						continue 2;
					}
					$leaf = Tree::get_the_first_leaf_data( $tree );
					DB::create_link_to_warm( $id, $leaf['link'], $leaf['depth'], $phase, (array) $leaf['meta'] );
					Tree::delete_the_first_leaf( $tree );
				}
				self::transition( $id, 'ready', 'building', true );
				DB::discard_published_seed_chunks();
			}
			if ( ! DB::warmup_has_pending_links( $id ) ) {
				self::finish( $id, (array) json_decode( $run['meta'], true ), $run['warmup_type'] );
			}
		}
		if ( 20 === count( $runs ) ) {
			Warm_Up::schedule_async_warmup();
		}
	}

	/**
	 * Preserve the visited set and the one remaining retry of a legacy run.
	 *
	 * @param int                 $id       Destination run ID.
	 * @param array<string,mixed> $manifest Durable legacy snapshot containing
	 *                                      visited, failed and retry state.
	 *
	 * @return void
	 * @throws \RuntimeException When restored history cannot be persisted.
	 */
	private static function restore_legacy_history( int $id, array $manifest ): void {
		foreach ( $manifest['retrieved'] as $key => $depth ) {
			$leaf = Tree::get_the_first_leaf_data( [ $key => [] ] );
			$url  = $leaf['link'];
			$meta = (array) $leaf['meta'];
			if ( ! DB::is_link_retrieved( $id, $url, $meta, (int) $depth ) ) {
				$log = DB::create_link_to_warm( $id, $url, (int) $depth, 0, $meta );
				if ( ! Logging::log_success( $log, null, null, 'Imported legacy visited URL', 0, $meta['user_agent'] ?? '' ) ) {
					throw new \RuntimeException( 'Could not restore legacy visited history.' );
				}
			}
		}
		// During a retry pass the remaining tree is authoritative: the failure
		// map also contains URLs whose retry already finished and must not replay.
		if ( ! $manifest['retry_phase'] ) {
			foreach ( $manifest['failed'] as $key => $depth ) {
				$leaf = Tree::get_the_first_leaf_data( [ $key => [] ] );
				$url  = $leaf['link'];
				$meta = (array) $leaf['meta'];
				DB::create_link_to_warm( $id, $url, (int) $depth, 1, $meta );
			}
		}
	}

	/**
	 * Persist a completion summary before acknowledging the lifecycle.
	 *
	 * @param int                 $id   Run ID.
	 * @param array<string,mixed> $meta Final metadata and retry checkpoint.
	 * @param string              $type Run kind.
	 *
	 * @return void
	 * @throws \RuntimeException When retry, summary or lifecycle persistence fails.
	 */
	public static function finish( int $id, array $meta, string $type ): void {
		DB::require_process_lock();
		if ( DB::was_warmup_stopped( $id ) ) {
			DB::mark_all_links_as_warmed( $id );
			return;
		}
		if ( DB::warmup_has_pending_links( $id ) ) {
			return;
		}
		if ( empty( $meta['retry_phase_started'] ) ) {
			$retries = DB::enqueue_failed_retries( $id );
			$meta['retry_phase_started'] = true;
			if ( ! DB::update_warm_up_meta( $id, $meta ) ) {
				throw new \RuntimeException( 'Could not checkpoint warm-up finalization.' );
			}
			if ( $retries ) {
				Warm_Up::schedule_async_warmup();
				return;
			}
		}
		self::transition( $id, 'finalizing', 'ready' );
		if ( DB::WARMUP_TYPE_SCHEDULED === $type ) {
			self::write_summary( $id, 'last-success-warmup-id', $meta['start_date'] );
			if ( ! empty( $meta['external_warmup_request_args'] ) ) {
				self::write_summary( $id, 'last-external-warmup-request-args-id', $meta['start_date'] );
				self::write_summary(
					$id,
					'last-success-warmup-external-warmup-request-args',
					$meta['external_warmup_request_args']
				);
			}
		}
		self::transition( $id, 'completed', 'finalizing' );
	}

	/**
	 * Persist summary options with the same SQL-level fence as the queue.
	 *
	 * @param int    $id    Completing run ID.
	 * @param string $name  Unprefixed option name.
	 * @param mixed  $value Summary value.
	 *
	 * @return void
	 * @throws \RuntimeException When the process fence or durable write fails.
	 */
	private static function write_summary( int $id, string $name, $value ): void {
		global $wpdb;
		$name  = 'cache-warmer-' . $name;
		$fence = DB::process_fence();
		$table = DB::get_tables_prefix() . 'warm_ups_list';
		$fence .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM $table WHERE id = %d AND queue_state = 'finalizing')", $id );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 SELECT %s, %s, 'no' WHERE $fence
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$name,
				maybe_serialize( $value )
			)
		);
		DB::assert_process_lock();
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not persist warm-up completion summary.' );
		}
		if ( DB::was_warmup_stopped( $id ) ) {
			return;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		Cache_Warmer::$options->remember_added_option( $name, $value );
	}

	/**
	 * Enroll a pre-lifecycle atomic queue before its next acknowledgement.
	 *
	 * @param int $id Existing queue ID.
	 *
	 * @return void
	 * @throws \RuntimeException When the worker lock is absent or transition fails.
	 */
	public static function activate( int $id ): void {
		DB::require_process_lock();
		self::transition( $id, 'ready', 'legacy' );
	}

	/**
	 * Advance one lifecycle without resurrecting a cancelled run.
	 *
	 * @param int    $id         Run ID.
	 * @param string $next       Desired state.
	 * @param string $previous   Expected state.
	 * @param bool   $clear_seed Discard manifest only after publication succeeds.
	 *
	 * @return void
	 * @throws \RuntimeException When the process fence or durable update fails.
	 */
	private static function transition( int $id, string $next, string $previous, bool $clear_seed = false ): void {
		global $wpdb;
		$table  = DB::get_tables_prefix() . 'warm_ups_list';
		$fence  = DB::process_fence();
		$seed   = $clear_seed ? ', seed_tree = NULL' : '';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET queue_state = %s $seed WHERE id = %d AND queue_state = %s AND $fence",
				$next,
				$id,
				$previous
			)
		);
		DB::assert_process_lock();
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not persist warm-up lifecycle transition.' );
		}
	}

	/**
	 * Repairs lost lifecycle delivery without an administrator status request.
	 *
	 * Reads durable manifests and pending rows from shared MySQL. Scheduling is
	 * idempotent, so every web node may call this recovery check safely. A failed
	 * manifest lookup throws before the fallback queue read can clear the database
	 * error. Accepted manifests remain untouched for a later healthy retry; an
	 * unreadable lifecycle is never treated as proof that no recovery is needed.
	 *
	 * @return void
	 * @throws \RuntimeException When durable lifecycle state cannot be queried.
	 */
	public static function schedule_recovery(): void {
		global $wpdb;
		$table      = DB::get_tables_prefix() . 'warm_ups_list';
		$logs       = DB::get_tables_prefix() . 'warm_ups_logs';
		$unfinished = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE queue_state IN ('building', 'ready', 'finalizing')
				OR (queue_state = 'cancelled' AND EXISTS (SELECT 1 FROM %i WHERE list_id = %i.id AND warm_date IS NULL)) LIMIT 1",
				$table,
				$logs,
				$table
			)
		);
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not read warm-up recovery state.' );
		}
		if ( $unfinished || DB::get_links_to_warm( 1 ) ) {
			Warm_Up::schedule_async_warmup();
		}
	}
}
