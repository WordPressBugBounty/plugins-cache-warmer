<?php
/**
 * A class to manage migrations.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Class Migrations.
 */
final class Migrations {

    /**
     * Constructor.
     *
     * @param int $last_handled_version_update Last handled version update.
     */
    public function __construct( $last_handled_version_update ) {
        if ( ! $last_handled_version_update ) { // No need to migrate for the fresh installation.
            return;
        }

        $methods_raw = array_values(
            array_filter(
                get_class_methods( $this ),
                function ( $method ) {
                    return str_starts_with( $method, 'v_' );
                }
            )
        );
        sort( $methods_raw );

        $versions_with_methods = [];
        foreach ( $methods_raw as $method ) {
            $version                           = str_replace( '_', '.', str_replace( 'v_', '', $method ) );
            $versions_with_methods[ $version ] = $method;
        }

        $versions_to_run_migrators_for = array_filter(
            array_keys( $versions_with_methods ),
            function ( $method ) use ( $last_handled_version_update ) {
                return 1 === version_compare( $method, $last_handled_version_update );
            }
        );

        foreach ( $versions_to_run_migrators_for as $version_to_run_migrator_for ) {
            $this->{$versions_with_methods[ $version_to_run_migrator_for ]}();
        }
    }

    /**
     * Fix intervals (run with arguments).
     */
    private function v_1_0_50() {
        add_action(
            'init',
            function() {
                $prev_interval = Cache_Warmer::$options->get( 'setting-interval' );
                Cache_Warmer::$options->delete( 'setting-interval' );
                Cache_Warmer::$options->set( 'setting-interval', $prev_interval );
            }
        );
    }

    /**
     * Migrate user agent.
     */
    private function v_1_0_55() {

        // User-Agent setting.

		$ua_setting = Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-user-agent' );

        if ( $ua_setting ) {
			Cache_Warmer::$options->set( 'cache-warmer-setting-user-agents', [ [ 'value' => $ua_setting ] ] );
        }

		Cache_Warmer::$options->delete_dynamic( 'cache-warmer-setting-user-agent' );

        /**
         * A constant migration to disable the logging.
         *
         * @see https://wordpress.org/support/topic/option-to-disable-logs/
         */

        if (
            defined( 'CACHE_WARMER_LOG_ENABLED' ) &&
            false === CACHE_WARMER_LOG_ENABLED
        ) {
			Cache_Warmer::$options->set( 'cache-warmer-setting-for-how-many-days-to-keep-the-logs', 0 );
        }
    }

    /**
     * Fix the depth.
     *
     * Increase the current depth by one.
     */
    private function v_1_1_9() {
		Cache_Warmer::$options->set(
			'cache-warmer-setting-depth',
			Cache_Warmer::$options->get( 'cache-warmer-setting-depth' ) + 1
		);
    }

    /**
     * Fix the scheduled interval.
     */
    private function v_1_3_4() {
        $function = function() {
            as_unschedule_all_actions( Cache_Warmer::INTERVAL_HOOK_NAME );
            Intervals_Scheduler::fix_missing_intervals();
        };

        if ( did_action( 'action_scheduler_init' ) ) {
            $function();
        } else {
            add_action( 'action_scheduler_init', $function );
        }
    }

    /**
     * Fix the scheduled interval.
     */
    private function v_1_3_8() {
        $function = function() {
            as_unschedule_all_actions( 'cache_warmer_fix_missing_intervals' );
        };

        if ( did_action( 'action_scheduler_init' ) ) {
            $function();
        } else {
            add_action( 'action_scheduler_init', $function );
        }
    }

	/**
	 * Persists the resumable checkpoint for the atomic queue migration.
	 *
	 * The version migration never advances by deleting source state first. A
	 * durable pending marker allows a later request to retry after Action
	 * Scheduler initialization or any transfer failure.
	 *
	 * @return void
	 * @throws \RuntimeException When the checkpoint cannot be persisted or the
	 *                            immediately available transfer fails.
	 */
	private function v_1_4_0() {
		Cache_Warmer::$options->set_dynamic( 'cache-warmer-legacy-transfer-pending', true );
		if ( ! Cache_Warmer::$options->get_dynamic( 'cache-warmer-legacy-transfer-pending' ) ) {
			throw new \RuntimeException( 'Could not persist the legacy transfer checkpoint.' );
		}
		self::resume_pending_legacy_transfer();
	}

	/**
	 * Resumes a deferred or failed transfer after the version marker advanced.
	 *
	 * The pending marker is the idempotent admission check. Transfer runs
	 * immediately when Action Scheduler is ready; otherwise this method registers
	 * one callback for the scheduler initialization boundary.
	 *
	 * @return void
	 * @throws \RuntimeException When reading the checkpoint or completing an
	 *                            immediately runnable transfer fails.
	 */
	public static function resume_pending_legacy_transfer(): void {
		if ( ! Cache_Warmer::$options->get_dynamic( 'cache-warmer-legacy-transfer-pending' ) ) {
			return;
		}
		if ( did_action( 'action_scheduler_init' ) ) {
			self::finish_legacy_transfer();
		} else {
			add_action( 'action_scheduler_init', [ __CLASS__, 'finish_legacy_transfer' ] );
		}
	}

	/**
	 * Retires legacy queues only after every durable replacement exists.
	 *
	 * Serialized warm-up state and publication-triggered post IDs are copied
	 * before their options/cache entries are removed. Recovery scheduling and
	 * pending-marker deletion occur last, so an exception leaves source state and
	 * the retry checkpoint available to a later request.
	 *
	 * @return void
	 * @throws \RuntimeException When any legacy queue or post cannot be persisted.
	 */
	public static function finish_legacy_transfer(): void {
		self::transfer_legacy_queues();
		as_unschedule_all_actions( Cache_Warmer::HOOK_NAME );

		$cached_posts = wp_cache_get( 'cache-warmer-posts-enqueue' );
		$stored_posts = Cache_Warmer::$options->get( 'cache-warmer-posts-enqueue' );
		$legacy_posts = array_unique(
			array_filter(
				array_map(
					'intval',
					array_merge(
						is_array( $cached_posts ) ? $cached_posts : [],
						is_array( $stored_posts ) ? $stored_posts : []
					)
				)
			)
		);

		foreach ( $legacy_posts as $post_id ) {
			if ( ! DB::enqueue_post_for_warming( $post_id ) ) {
				throw new \RuntimeException( 'Could not transfer a legacy post; source queue retained.' );
			}
		}

		Cache_Warmer::$options->delete( 'cache-warmer-posts-enqueue' );
		Cache_Warmer::$options->delete( 'cache-warmer-setting-use-object-cache' );
		wp_cache_delete( 'cache-warmer-posts-enqueue' );

		$legacy_options = [
			'cache-warmer-links-tree-leftovers',
			'cache-warmer-failed-to-retrieve-links',
			'cache-warmer-retrieved-links',
			'cache-warmer-unscheduled-links-tree-leftovers',
			'cache-warmer-unscheduled-failed-to-retrieve-links',
			'cache-warmer-unscheduled-retrieved-links',
		];

		foreach ( $legacy_options as $option_name ) {
			Cache_Warmer::$options->delete_dynamic( $option_name );
			wp_cache_delete( $option_name );
		}
		Queue_Lifecycle::schedule_recovery();
		Cache_Warmer::$options->delete_dynamic( 'cache-warmer-legacy-transfer-pending' );
	}

	/**
	 * Copy each available legacy run to a durable, idempotently keyed manifest.
	 *
	 * The old cache-backed mode used the local cache as its authority; the DB
	 * option is the fallback when no cache entry survives. All old workers must
	 * be drained before upgrade: another node's unavailable local cache cannot
	 * be recovered by any shared-database migration.
	 *
	 * @return void
	 * @throws \RuntimeException On malformed state or failed transfer.
	 */
	public static function transfer_legacy_queues(): void {
		if ( ! DB::acquire_admission_lock() ) {
			throw new \RuntimeException( 'Could not lock legacy queue transfer.' );
		}
		try {
			$use_cache = '1' === (string) Cache_Warmer::$options->get_dynamic( 'cache-warmer-setting-use-object-cache' );
			foreach ( [ DB::WARMUP_TYPE_SCHEDULED, DB::WARMUP_TYPE_UNSCHEDULED ] as $type ) {
				$prefix = DB::WARMUP_TYPE_UNSCHEDULED === $type ? 'cache-warmer-unscheduled-' : 'cache-warmer-';
				$read   = static function( string $suffix ) use ( $prefix, $use_cache ) {
					$cached = $use_cache ? wp_cache_get( $prefix . $suffix ) : false;
					return false !== $cached ? $cached : self::read_legacy_option( $prefix . $suffix );
				};
				$state  = $read( 'links-tree-leftovers' );
				if ( false === $state || [] === $state ) {
					continue;
				}
				if (
					! is_array( $state ) ||
					! isset( $state['tree'], $state['meta'] ) ||
					! is_array( $state['tree'] ) ||
					! is_array( $state['meta'] )
				) {
					throw new \RuntimeException( 'Invalid legacy warm-up; original serialized state retained.' );
				}
				$meta = $state['meta'];
				$original_start = $meta['start_date'] ?? '';
				$meta['start_date'] = $original_start && '0000-00-00 00:00:00' !== $original_start
					? $original_start
					: wp_date( 'Y-m-d H:i:s' );
				$meta['is_unscheduled'] = DB::WARMUP_TYPE_UNSCHEDULED === $type;
				$meta['legacy_normalized_keys'] = true;
				$meta['speed_limit'] = $meta['speed_limit'] ?? 1000;
				$manifest = [
					'legacy_format' => 1,
					'tree'          => $state['tree'],
					'retrieved'     => (array) ( $read( 'retrieved-links' ) ?: [] ),
					'failed'        => (array) ( $read( 'failed-to-retrieve-links' ) ?: [] ),
					'retry_phase'   => array_key_exists( 'failed_to_retrieve_tree', $meta ),
				];
				// Each old queue slot contains at most one crawl at cutover. A stable
				// slot receipt also rejects a stale DB fallback after cache cleanup.
				$key = hash( 'sha256', $prefix . 'pre-atomic-1.4.0' );
				if ( false === DB::create_warm_up( $meta, $type, $meta['start_date'], $manifest, $key ) ) {
					throw new \RuntimeException( 'Could not persist legacy warm-up; original state retained.' );
				}
			}
		} finally {
			DB::release_admission_lock();
		}
	}
	/**
	 * Read old serialized state without returning a packet-sized option row.
	 *
	 * Direct reads deliberately bypass node-local option caches. Legacy workers
	 * must be drained during cutover so all slices describe the same value.
	 * A failed slice is never mistaken for an absent option.
	 *
	 * @param string $name Exact legacy option name.
	 * @return mixed Decoded legacy value, or false when the option is absent.
	 * @throws \RuntimeException When the database cannot read an option slice.
	 */
	private static function read_legacy_option( string $name ) {
		global $wpdb;
		$value = '';
		for ( $offset = 1; ; $offset += 1024 ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT SUBSTRING(CAST(option_value AS BINARY), %d, 1024) FROM %i WHERE option_name = %s', $offset, $wpdb->options, $name ), ARRAY_N );
			$piece = $row[0] ?? null;
			if ( $wpdb->last_error || ( null === $piece && 1 !== $offset ) ) {
				throw new \RuntimeException( 'Could not read legacy state; source retained.' );
			}
			if ( null === $piece ) {
				return false;
			}
			$value .= $piece;
			if ( strlen( $piece ) < 1024 ) {
				return maybe_unserialize( $value );
			}
		}
	}
}
