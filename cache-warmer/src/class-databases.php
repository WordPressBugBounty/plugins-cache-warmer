<?php
/**
 * Custom database tables.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use DateTime;
use DateTimeZone;

/**
 * Manages custom DB tables.
 */
final class DB {

    /**
     * Custom tables prefix.
     */
    const TABLES_PREFIX = 'cache_warmer_';

	/**
	 * Type discriminator for scheduled warm-up runs.
	 *
	 * @var string
	 */
	const WARMUP_TYPE_SCHEDULED = 'scheduled';

	/**
	 * Type discriminator for independent content-triggered warm-up runs.
	 *
	 * @var string
	 */
	const WARMUP_TYPE_UNSCHEDULED = 'unscheduled';

	/**
	 * Type discriminator for external-warmer log containers.
	 *
	 * @var string
	 */
	const WARMUP_TYPE_EXTERNAL = 'external';

	/**
	 * Connection which owns this PHP worker's process lock.
	 *
	 * @var int|null
	 */
	private static $process_connection;

	/**
	 * Connection owning the short publication admission lock.
	 *
	 * @var int|null
	 */
	private static $admission_connection;

	/**
	 * Creates/modifies database tables for the plugin by applying DB migrations.
	 *
	 * Reconciles acknowledged but missing tables before resuming the ordered
	 * history. Existing rows are preserved; missing-table contents cannot be
	 * recovered by schema repair. Callers serialize execution with the site
	 * migration lock. SQL failures leave a retryable checkpoint and log.
	 *
	 * @return void
	 * @throws \RuntimeException When schema metadata or option persistence fails.
	 *
	 * @see WC_Install::create_tables()
	 */
    public static function do_migrations() {
        global $wpdb;
        $wpdb->hide_errors();

        // DB Migrations.

        $migrations                    = self::get_migrations();
        $last_success_migration_number = (int) Cache_Warmer::$options->get( 'last-db-success-migration-number' );

		$last_success_migration_number = self::reconcile_missing_tables( $migrations, $last_success_migration_number );

        $migrations_to_run = array_slice( $migrations, $last_success_migration_number );

        if ( $migrations_to_run ) {
            $db_migrations_log = Cache_Warmer::$options->get( 'db-migration-log' );

            $migration_number = $last_success_migration_number + 1;

            $max_retries = 5;
            $failed      = false;

            foreach ( $migrations_to_run as $migration_to_run ) {
                $retries = 0;

                $queries = explode( ';', $migration_to_run );

                while ( $retries < $max_retries ) {
                    $wpdb->query( 'START TRANSACTION' ); // @codingStandardsIgnoreLine

                    $failed = false;
                    foreach ( $queries as $query ) {
                        if ( trim( $query ) !== '' ) {
                            // Check if this query is for adding a key. Do not error out in this case, and proceed to the next migration.
                            if (
                                preg_match(
                                    '/alter\s+table\s+(.*?)\s+add\s+(?:unique\s+)?(index|constraint|key)\s+(.*?)\s+/i',
                                    $query,
                                    $matches
                                )
                            ) {
                                $table_name = trim( $matches[1], '`"\'' );
                                $type       = trim( $matches[2], '`"\'' );
                                $key_name   = trim( $matches[3], '`"\'' );

                                // Check if foreign key already exists.
                                $key_exists = $wpdb->get_var(
                                    $wpdb->prepare(
                                        '
                                        SELECT COUNT(*)
                                        FROM information_schema.STATISTICS
                                        WHERE
                                            TABLE_SCHEMA = DATABASE() AND
                                            TABLE_NAME = %s AND
                                            INDEX_NAME = %s
                                        ',
                                        $table_name,
                                        $key_name
                                    )
                                );

                                // If foreign key already exists, skip this query.
                                if ( $key_exists > 0 ) {
                                    $time_before = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
                                    $time_before->setTimezone( new DateTimeZone( 'GMT' ) );
                                    $time_before = $time_before->format( 'Y-m-d H:i:s.u' );

                                    $db_migrations_log[] = [
                                        'migration_number' => $migration_number,
                                        'query'            => $query,
                                        'time_before'      => $time_before,
                                        'time_after'       => '',
                                        'error'            => "$type '$key_name' already exists. Skipping.",
                                    ];

                                    continue;
                                }
                            } elseif ( // Check if this query is for adding a column.
                                preg_match(
                                    '/alter\s+table\s+(.*?)\s+add(?:\s+column)?\s+(.*?)\s*(\s|$)/i',
                                    $query,
                                    $matches
                                )
                            ) {
                                $table_name  = trim( $matches[1], '`"\'' );
                                $column_name = trim( $matches[2], '`"\'' );

                                // Check if column already exists.
                                $column_exists = $wpdb->get_var(
                                    $wpdb->prepare(
                                        '
                                        SELECT COLUMN_NAME 
                                        FROM INFORMATION_SCHEMA.COLUMNS 
                                        WHERE TABLE_SCHEMA = DATABASE() 
                                          AND TABLE_NAME = %s 
                                          AND COLUMN_NAME = %s
                                        ',
                                        $table_name,
                                        $column_name
                                    )
                                );

                                // If column already exists, skip this query.
                                if ( $column_exists ) {
                                    $time_before = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
                                    $time_before->setTimezone( new DateTimeZone( 'GMT' ) );
                                    $time_before = $time_before->format( 'Y-m-d H:i:s.u' );

                                    $db_migrations_log[] = [
                                        'migration_number' => $migration_number,
                                        'query'            => $query,
                                        'time_before'      => $time_before,
                                        'time_after'       => '',
                                        'error'            => "COLUMN '$column_name' already exists. Skipping.",
                                    ];

                                    continue;
                                }
                            }

                            $time_before = microtime( true );
                            $result      = $wpdb->query( $query ); // @codingStandardsIgnoreLine
                            $time_after  = microtime( true );

                            // Log.

                            $time_before = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', $time_before ) );
                            $time_before->setTimezone( new DateTimeZone( 'GMT' ) );
                            $time_before = $time_before->format( 'Y-m-d H:i:s.u' );

                            $time_after = DateTime::createFromFormat( 'U.u', sprintf( '%.6F', $time_after ) );
                            $time_after->setTimezone( new DateTimeZone( 'GMT' ) );
                            $time_after = $time_after->format( 'Y-m-d H:i:s.u' );

                            $db_migrations_log_elem = [
                                'migration_number' => $migration_number,
                                'query'            => $query,
                                'time_before'      => $time_before,
                                'time_after'       => $time_after,
                                'error'            => '',
                            ];

                            if ( false === $result ) {
                                $db_migrations_log_elem['error'] = $wpdb->last_error;
                            }

                            $db_migrations_log[] = $db_migrations_log_elem;

                            if ( false === $result ) {
                                $failed = true;
                                break;
                            }
                        }
                    }

                    if ( $failed ) {
                        $wpdb->query( 'ROLLBACK' ); // @codingStandardsIgnoreLine
                        $retries ++;
                        sleep( 1 ); // Avoid rapid-fire retries.
                    } else {
                        $wpdb->query( 'COMMIT' ); // @codingStandardsIgnoreLine
                        break;
                    }
                }

                if ( ! $failed ) {
                    $last_success_migration_number = $migration_number;
                } else {
                    break;
                }

                $migration_number ++;
            }

            Cache_Warmer::$options->set( 'last-db-success-migration-number', $last_success_migration_number );
            Cache_Warmer::$options->set( 'last-db-migration-success', (string) ( count( $migrations ) === $last_success_migration_number ) );
            Cache_Warmer::$options->set( 'db-migration-log', $db_migrations_log );
        }
    }

	/**
	 * Rewind a stale schema checkpoint to the first missing table's creation.
	 *
	 * Called under the migration lock before applying SQL. Only tables whose
	 * CREATE migration was already acknowledged are inspected. Existing tables
	 * and rows are never removed. The lowered checkpoint is durable before DDL,
	 * so a failed or interrupted rebuild can retry all later additive migrations.
	 * This restores schema availability, not data formerly held by a lost table.
	 * A failed metadata read must not be interpreted as an absent table.
	 *
	 * @param string[] $migrations Ordered schema statements.
	 * @param int      $checkpoint Number of acknowledged migrations.
	 * @return int Safe number of migrations to skip on this attempt.
	 * @throws \RuntimeException When table metadata cannot be read.
	 */
	private static function reconcile_missing_tables( array $migrations, int $checkpoint ): int {
		global $wpdb;

		foreach ( array_slice( $migrations, 0, $checkpoint ) as $index => $migration ) {
			if ( ! preg_match( '/^\s*CREATE TABLE IF NOT EXISTS ([a-zA-Z0-9_]+)/i', $migration, $matches ) ) {
				continue;
			}
			$table = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$matches[1]
				)
			);
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( 'Could not inspect Cache Warmer schema tables.' );
			}
			if ( null === $table ) {
				Cache_Warmer::$options->set( 'last-db-migration-success', '' );
				Cache_Warmer::$options->set( 'last-db-success-migration-number', $index );
				return $index;
			}
		}
		return $checkpoint;
	}

    /**
     * Return a list of Cache-Warmer tables.
     *
     * Parent tables are above children tables.
     *
     * @return array Tables list.
     */
    public static function get_tables() {
        return [
			'warm_up_seeds',
            'warm_ups_logs',
            'warm_ups_list',
            'warm_ups_user_agents',
			'posts_queue',
        ];
    }

    /**
     * Return full tables prefix.
     *
     * @return string Tables prefix.
     */
    public static function get_tables_prefix() {
        global $wpdb;
        return $wpdb->prefix . self::TABLES_PREFIX;
    }

	/**
	 * Build the ordered SQL migration history for the current WordPress site.
	 *
	 * Resolves the current table prefix and connection collation while building
	 * statements. Does not execute the returned schema or data changes. The
	 * migration runner uses array position as its persisted progress marker, so
	 * existing entries must retain their order when new migrations are appended.
	 * Statements include historical sentinel rows, queue backfills and additive
	 * reconciliation for installations whose earlier migrations already ran.
	 *
	 * @return string[] SQL migration entries in application order.
	 */
	public static function get_migrations() {
		global $wpdb;

		$collate      = '';
		$table_prefix = self::get_tables_prefix();

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		// Scope constraint names by including the current site's table prefix.
		$constraint_name_fk_list_id        = 'fk_' . $table_prefix . 'warm_ups_list_id';
		$constraint_name_fk_user_agents_id = 'fk_' . $table_prefix . 'user_agents_id';

		$migrations = array(
			"
                CREATE TABLE IF NOT EXISTS {$table_prefix}warm_ups_list (
                  id BIGINT UNSIGNED AUTO_INCREMENT,
                  warmed_at DATETIME NOT NULL UNIQUE,
                  PRIMARY KEY (id)
                ) $collate ENGINE=InnoDB
            ",
			/**
			 * - warm_ups_logs - log_phase:
			 *
			 * 0 - Default warm up.
			 * 1 - When trying to fetch failed to retrieve links for the last time.
			 * 2 - When the URL matches the excluded list.
			 */
			"
                CREATE TABLE IF NOT EXISTS {$table_prefix}warm_ups_logs (
                  id BIGINT(20) unsigned NOT NULL AUTO_INCREMENT,
                  list_id BIGINT UNSIGNED NOT NULL,
                  log_is_success TINYINT(1) unsigned NOT NULL,
                  log_date DATETIME NOT NULL,
                  log_depth TINYINT unsigned NOT NULL,
                  log_url TEXT NOT NULL,
                  log_post_id BIGINT(20) UNSIGNED NOT NULL,
                  log_time_spent FLOAT DEFAULT NULL,
                  log_time_afterwards FLOAT DEFAULT NULL,
                  log_extra TEXT NOT NULL,
                  log_phase TINYINT unsigned NOT NULL DEFAULT 0,
                  log_content_type TEXT NOT NULL,
                  log_content_length TEXT NOT NULL,
                  log_cf_cache_status TEXT NOT NULL,
                  log_wp_super_cache_status TEXT NOT NULL,
                  log_x_cache_header TEXT NOT NULL,
                  log_visit_type TEXT NOT NULL,
                  PRIMARY KEY (id)
                ) $collate ENGINE=InnoDB
            ",
			"
                INSERT IGNORE INTO {$table_prefix}warm_ups_list (warmed_at) VALUES (0)
            ",
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD CONSTRAINT {$constraint_name_fk_list_id}
                FOREIGN KEY (list_id)
                REFERENCES {$table_prefix}warm_ups_list(id) ON DELETE CASCADE
            ",
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD KEY idx_log_post_id (log_post_id)
            ",
			// Add logs user agent field.
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD `user_agent_id` BIGINT UNSIGNED NULL
            ",
			// Add meta table.
			"
            CREATE TABLE IF NOT EXISTS {$table_prefix}warm_ups_user_agents (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `value` VARCHAR(500) NOT NULL,
              PRIMARY KEY (id),              
              UNIQUE KEY uniq_value (`value`)
            ) $collate ENGINE=InnoDB
            ",
			// FK meta field to user agents table.
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD CONSTRAINT {$constraint_name_fk_user_agents_id}
                FOREIGN KEY (user_agent_id)
                REFERENCES {$table_prefix}warm_ups_user_agents(id) ON DELETE CASCADE
            ",
			/**
			 * Change DB structure.
			 *
			 * 1, 2: So log time spent and log time spent afterwards contain NULL.
			 * 3, 4: Update values from 0 to NULL.
			 */
			"
                ALTER TABLE `{$table_prefix}warm_ups_logs` CHANGE `log_time_spent` `log_time_spent` FLOAT NULL DEFAULT NULL
            ",
			"
                ALTER TABLE `{$table_prefix}warm_ups_logs` CHANGE `log_time_afterwards` `log_time_afterwards` FLOAT NULL DEFAULT NULL
            ",
			"
                UPDATE `{$table_prefix}warm_ups_logs` SET `log_time_spent` = NULL WHERE `log_time_spent` = 0
            ",
			"
                UPDATE `{$table_prefix}warm_ups_logs` SET `log_time_afterwards` = NULL WHERE `log_time_afterwards` = 0
            ",
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD `canonical` TEXT DEFAULT ''
            ",
			// Add external_warmer_results field.
			"
                ALTER TABLE `{$table_prefix}warm_ups_logs`
                ADD `external_warmer_results` TEXT DEFAULT NULL
            ",
			// Add warmed at of 1 (for external interval warms).
			"
                INSERT IGNORE INTO {$table_prefix}warm_ups_list (warmed_at) VALUES ('" . External_Warmer::WARMUP_ID . "')
            ",
			// Add a few new table columns (for new atomic warming logic).
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD COLUMN warm_date DATETIME DEFAULT CURRENT_TIMESTAMP
            ",
			// New queue rows pass NULL explicitly; future incidental inserts should also default to pending.
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ALTER COLUMN warm_date DROP DEFAULT
            ",
			"
                ALTER TABLE {$table_prefix}warm_ups_logs
                ADD COLUMN link_meta TEXT DEFAULT NULL
            ",
			"
                ALTER TABLE {$table_prefix}warm_ups_list
                ADD COLUMN meta TEXT DEFAULT NULL
            ",
			// Add parent_log_id column with a self-referencing foreign key.
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN parent_log_id BIGINT(20) unsigned DEFAULT NULL,
				ADD CONSTRAINT fk_{$table_prefix}warm_ups_logs_parent_id
				FOREIGN KEY (parent_log_id)
				REFERENCES {$table_prefix}warm_ups_logs(id) ON DELETE SET NULL
			",
			// Store an indexed fingerprint instead of scanning every completed log to detect duplicate links.
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN link_hash CHAR(64) DEFAULT NULL
			",
			"
				UPDATE {$table_prefix}warm_ups_logs
				SET link_hash = SHA2(CONCAT(log_url, '|', COALESCE(link_meta, '')), 256)
				WHERE link_hash IS NULL AND warm_date IS NULL
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD KEY idx_queue (list_id, warm_date, log_phase, log_depth, id)
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD KEY idx_link_hash (list_id, link_hash, log_is_success, warm_date)
			",
			// Ensure the parent constraint exists if an older combined column/constraint migration was partially applied.
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD CONSTRAINT fk_{$table_prefix}warm_ups_logs_parent_id
				FOREIGN KEY (parent_log_id)
				REFERENCES {$table_prefix}warm_ups_logs(id) ON DELETE SET NULL
			",

			/*
			 * Reconcile legacy tables whose original CREATE migration predates fields
			 * that were later added only to that CREATE statement. Existing sites do
			 * not rerun old CREATE migrations, so each field also needs an additive
			 * migration.
			 */
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN log_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN log_x_cache_header TEXT NOT NULL DEFAULT ''
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN log_visit_type TEXT NOT NULL DEFAULT ''
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN user_agent_id BIGINT UNSIGNED NULL
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN canonical TEXT DEFAULT ''
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD CONSTRAINT {$constraint_name_fk_user_agents_id}
				FOREIGN KEY (user_agent_id)
				REFERENCES {$table_prefix}warm_ups_user_agents(id) ON DELETE CASCADE
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				CHANGE log_time_spent log_time_spent FLOAT NULL DEFAULT NULL
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				CHANGE log_time_afterwards log_time_afterwards FLOAT NULL DEFAULT NULL
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD KEY idx_log_post_id (log_post_id)
			",
			// Classify warm-up lists explicitly; legacy sentinel rows remain readable.
			"
				ALTER TABLE {$table_prefix}warm_ups_list
				ADD COLUMN warmup_type VARCHAR(16) NOT NULL DEFAULT 'scheduled'
			",
			"
				UPDATE {$table_prefix}warm_ups_list
				SET warmup_type = 'unscheduled'
				WHERE warmed_at = '0000-00-00 00:00:00'
			",
			"
				UPDATE {$table_prefix}warm_ups_list
				SET warmup_type = 'external'
				WHERE warmed_at = '2000-01-01 00:00:00'
			",
			"
				ALTER TABLE {$table_prefix}warm_ups_list
				ADD KEY idx_warmup_type (warmup_type, id)
			",
			// Cancellation is explicit so strict SQL modes never receive a zero DATETIME.
			"
				ALTER TABLE {$table_prefix}warm_ups_logs
				ADD COLUMN is_cancelled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
			",
			"
				UPDATE {$table_prefix}warm_ups_logs
				SET is_cancelled = 1
				WHERE warm_date = '0000-00-00 00:00:00'
			",
			// Persist publication-triggered work independently of any web node or object cache.
			"
				CREATE TABLE IF NOT EXISTS {$table_prefix}posts_queue (
					post_id BIGINT(20) UNSIGNED NOT NULL,
					enqueued_at DATETIME(6) NOT NULL,
					claim_token CHAR(36) DEFAULT NULL,
					claimed_at DATETIME(6) DEFAULT NULL,
					PRIMARY KEY (post_id),
					KEY idx_claim (claim_token, claimed_at, enqueued_at)
				) $collate ENGINE=InnoDB
			",
			"ALTER TABLE {$table_prefix}warm_ups_list ADD COLUMN queue_state VARCHAR(16) NOT NULL DEFAULT 'legacy'",
			"ALTER TABLE {$table_prefix}warm_ups_list ADD COLUMN seed_tree LONGTEXT NULL",
			"ALTER TABLE {$table_prefix}warm_ups_list ADD INDEX idx_queue_state (queue_state, id)",
			"ALTER TABLE {$table_prefix}warm_ups_list ADD COLUMN legacy_key CHAR(64) NULL",
			"ALTER TABLE {$table_prefix}warm_ups_list ADD UNIQUE KEY idx_legacy_key (legacy_key)",
			"CREATE TABLE IF NOT EXISTS {$table_prefix}warm_up_seeds (
				list_id BIGINT UNSIGNED NOT NULL,
				part INT UNSIGNED NOT NULL,
				payload MEDIUMBLOB NOT NULL,
				PRIMARY KEY (list_id, part),
				FOREIGN KEY (list_id) REFERENCES {$table_prefix}warm_ups_list(id) ON DELETE CASCADE
			) ENGINE=InnoDB",

		);

		return $migrations;
	}

    /**
     * Truncates all Cache-Warmer tables.
     */
    public static function truncate_tables() {
        global $wpdb;

        $tables_prefix = self::get_tables_prefix();
        $tables        = self::get_tables();

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0;' ); // @codingStandardsIgnoreLine

        foreach ( $tables as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$tables_prefix}{$table};" ); // @codingStandardsIgnoreLine
        }

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1;' ); // @codingStandardsIgnoreLine
    }

    /**
     * Deletes a log item by its ID.
     *
     * @param int $log_id The ID of the log item to delete.
     *
     * @throws \RuntimeException If the log item cannot be deleted or lock ownership is lost.
     */
    public static function delete_log_item_by_id( int $log_id ) {
        global $wpdb;

        // Get the table name.
        $table_name = self::get_tables_prefix() . 'warm_ups_logs';

        $fence = self::process_fence();
        $result = $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE id = %d AND $fence", $log_id ) );
        self::assert_process_lock();
        if ( false === $result ) {
            throw new \RuntimeException( 'Could not remove the duplicate warm-up link.' );
        }
    }

	/**
	 * Remove eligible completed history according to the configured retention window.
	 *
	 * A zero-day policy removes other completed scheduled runs when admitting a
	 * scheduled run; nonzero policies use a date cutoff. Triggered batches and
	 * sentinel/external log cleanup use a cutoff of at least one day. Run deletion
	 * preserves active publication states, legacy-transfer receipts and runs with
	 * pending rows. Sentinel/external log deletion preserves cancelled rows.
	 *
	 * Deleting a run cascades to its logs. Leaves User-Agent dictionary rows in
	 * place to avoid racing a concurrent log insert. Does not acquire locks,
	 * start a transaction or check individual query results: completion does not
	 * certify that every deletion succeeded, and earlier deletions are not rolled
	 * back if a later statement fails.
	 *
	 * @param string $warm_up_start_date Public start-date identifier to retain under a zero-day scheduled policy.
	 * @param string $warmup_type        Type being admitted; controls the zero-day scheduled cleanup branch.
	 *
	 * @return void
	 */
	public static function delete_excess_warm_ups(
		string $warm_up_start_date,
		string $warmup_type = self::WARMUP_TYPE_SCHEDULED
	) {
		global $wpdb;

		$for_how_many_days_to_retain_warm_ups = (int) Cache_Warmer::$options->get( 'setting-for-how-many-days-to-keep-the-logs' );
		$list_table                           = self::get_tables_prefix() . 'warm_ups_list';
		$logs_table                           = self::get_tables_prefix() . 'warm_ups_logs';

		$date_threshold = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, $for_how_many_days_to_retain_warm_ups ) );

		if (
			0 === $for_how_many_days_to_retain_warm_ups &&
			self::WARMUP_TYPE_SCHEDULED === $warmup_type
		) {
			$wpdb->query( // Retain only the current one.
				$wpdb->prepare(
					"
                        DELETE FROM %i
                        WHERE warmup_type = 'scheduled' AND warmed_at != %s
                        AND queue_state NOT IN ('building', 'ready', 'finalizing')
                        AND legacy_key IS NULL
                        AND NOT EXISTS (
                            SELECT 1 FROM %i AS pending
                            WHERE pending.list_id = %i.id AND pending.warm_date IS NULL
                        )
                    ",
					$list_table,
					$warm_up_start_date,
					$logs_table,
					$list_table
				)
			);
		} elseif ( 0 !== $for_how_many_days_to_retain_warm_ups ) {
			$wpdb->query(
				$wpdb->prepare(
					"
                    DELETE FROM %i
                    WHERE warmup_type = 'scheduled' AND warmed_at < %s
                    AND queue_state NOT IN ('building', 'ready', 'finalizing')
                    AND legacy_key IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM %i AS pending
                        WHERE pending.list_id = %i.id AND pending.warm_date IS NULL
                    )
                    ",
					$list_table,
					$date_threshold,
					$logs_table,
					$list_table
				)
			);
		}

		// Triggered batches are independent queues. Remove only completed, expired batches.
		$wpdb->query(
			$wpdb->prepare(
				"
					DELETE list
					FROM %i AS list
					WHERE list.warmup_type = 'unscheduled'
					AND list.queue_state NOT IN ('building', 'ready', 'finalizing')
					AND list.legacy_key IS NULL
					AND list.warmed_at != '0000-00-00 00:00:00'
					AND list.warmed_at < %s
					AND NOT EXISTS (
						SELECT 1 FROM %i AS logs
						WHERE logs.list_id = list.id AND logs.warm_date IS NULL
					)
				",
				$list_table,
				$date_threshold,
				$logs_table
			)
		);

		// Delete unscheduled.

		$wpdb->query(
			$wpdb->prepare(
				"
					DELETE logs
					FROM %i AS logs
					INNER JOIN %i AS list ON list.id = logs.list_id
					WHERE logs.log_date < %s
					AND list.warmup_type = 'unscheduled'
					AND list.warmed_at = '0000-00-00 00:00:00'
					AND logs.warm_date IS NOT NULL
					AND logs.is_cancelled = 0
				",
				$logs_table,
				$list_table,
				$date_threshold
			)
		);

		// Delete External Warmups.

		$wpdb->query(
			$wpdb->prepare(
				"
					DELETE logs
					FROM %i AS logs
					INNER JOIN %i AS list ON list.id = logs.list_id
					WHERE logs.log_date < %s
					AND list.warmup_type = 'external'
					AND logs.warm_date IS NOT NULL
					AND logs.is_cancelled = 0
				",
				$logs_table,
				$list_table,
				$date_threshold
			)
		);

		/*
		 * Keep orphaned User-Agent dictionary rows during retention cleanup.
		 * A concurrent warm-up can create its dictionary row immediately before
		 * creating the referencing log. Deleting that temporarily unreferenced
		 * row here would make the subsequent log insert violate its foreign key.
		 * The dictionary is naturally small and full plugin cleanup still removes
		 * it with the rest of the Cache Warmer tables.
		 */
	}

	/**
	 * Admit a URL/metadata pair to a run or reuse an eligible existing log row.
	 *
	 * Matches the run and exact link hash, preferring pending rows. A completed
	 * success is eligible only at this depth or shallower. Reusing a pending row
	 * lowers its depth when necessary but does not replace its parent or metadata.
	 * Otherwise appends a pending log row with the supplied parent and phase.
	 *
	 * The lookup and write are separate operations; this method does not acquire
	 * a lock or start a transaction. Callers must serialize queue admission.
	 * Writes honor the current process fence and verify ownership afterward;
	 * failures can leave earlier User-Agent or log writes persisted.
	 *
	 * @param int      $warmup_id     Numeric warm-up list ID.
	 * @param string   $link          Exact URL used to identify the queued visit.
	 * @param int      $depth         Requested traversal depth.
	 * @param int      $log_phase     Processing phase: zero for the first pass, one for retries.
	 * @param array    $link_meta     Visit metadata included in the exact hash identity.
	 * @param int|null $parent_log_id Parent log row ID, or null for a root visit.
	 *
	 * @return int Newly inserted or equivalent log ID.
	 *
	 * @throws \RuntimeException When a queue read/write fails or process ownership is lost.
	 */
	public static function create_link_to_warm(
		int $warmup_id,
		string $link,
		int $depth,
		int $log_phase,
		array $link_meta,
		?int $parent_log_id = null
	) {
		global $wpdb;

		$logs_table = self::get_tables_prefix() . 'warm_ups_logs';
		$link_hash  = self::get_link_hash( $link, $link_meta );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				'
					SELECT id
					FROM %i
					WHERE list_id = %d
					AND link_hash = %s
					AND (
						warm_date IS NULL
						OR (log_is_success = 1 AND log_depth <= %d)
					)
					ORDER BY warm_date IS NULL DESC, log_depth ASC, id ASC
					LIMIT 1
				',
				$logs_table,
				$warmup_id,
				$link_hash,
				$depth
			)
		);

		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Failed to read the warm-up queue.' );
		}

		if ( $existing_id ) {
			$fence   = self::process_fence();
			$updated = $wpdb->query(
				$wpdb->prepare(
					"
						UPDATE %i
						SET log_depth = LEAST(log_depth, %d)
						WHERE id = %d AND warm_date IS NULL AND $fence
					",
					$logs_table,
					$depth,
					$existing_id
				)
			);
			self::assert_process_lock();
			if ( false === $updated ) {
				throw new \RuntimeException( 'Failed to update the warm-up queue depth.' );
			}

			return (int) $existing_id;
		}

		$log_id = Logging::log(
			$warmup_id,
			0,
			$depth,
			null,
			null,
			$link,
			'',
			$log_phase,
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
			null,
			$parent_log_id
		);

		if ( false === $log_id ) {
			throw new \RuntimeException( 'Failed to persist a warm-up queue link.' );
		}

		return $log_id;
	}

	/**
	 * Whether a queue row was removed or explicitly cancelled while fetching.
	 *
	 * @param int $log_id Queue row ID.
	 * @return bool Whether processing must stop.
	 * @throws \RuntimeException When cancellation cannot be checked safely.
	 */
	public static function is_log_cancelled( int $log_id ): bool {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare(
			'SELECT is_cancelled FROM ' . self::get_tables_prefix() . 'warm_ups_logs WHERE id = %d',
			$log_id
		) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Failed to read warm-up queue cancellation.' );
		}
		return null === $value || (bool) $value;
	}

	/**
	 * Creates a stable fingerprint for a URL and its request-specific metadata.
	 *
	 * Hashes the exact URL, a pipe separator and WordPress JSON output. Does not
	 * normalize URL spelling or sort metadata keys; array ordering therefore
	 * participates in identity. If JSON encoding returns false, concatenation
	 * uses an empty metadata string rather than reporting an encoding failure.
	 * No queue lookup or persistent write is performed.
	 *
	 * @param string $link      URL string used verbatim.
	 * @param array  $link_meta Request metadata in its supplied key order.
	 *
	 * @return string Lowercase, 64-character hexadecimal SHA-256 fingerprint.
	 */
	public static function get_link_hash( string $link, array $link_meta ): string {
		return hash( 'sha256', $link . '|' . wp_json_encode( $link_meta ) );
	}

	/**
	 * Returns data for the latest warmup.
	 *
	 * @return array|null An array of rows or null if no records are found.
	 */
	public static function get_latest_warmup_data() {
		global $wpdb;

		$logs_table = DB::get_tables_prefix() . 'warm_ups_logs';

		return $wpdb->get_row( "SELECT * FROM $logs_table ORDER BY id DESC LIMIT 1", ARRAY_A );
	}

	/**
	 * Returns a warm-up list ID by its public date identifier.
	 *
	 * Performs a direct database lookup without changing queue state. Does not
	 * inspect the database error; a missing or failed lookup casts to zero.
	 *
	 * @param string $warmed_at Warm-up date identifier.
	 *
	 * @return int Warm-up ID, or zero when the lookup yields no value.
	 */
	public static function get_warm_up_id( string $warmed_at ): int {
		global $wpdb;

		$table_name = self::get_tables_prefix() . 'warm_ups_list';

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE warmed_at = %s LIMIT 1', $table_name, $warmed_at )
		);
	}

	/**
	 * Select pending rows from published or legacy non-external warm-up queues.
	 *
	 * Scheduled queues precede triggered queues. Scheduled run IDs descend;
	 * triggered run IDs ascend. Within each run, phase, depth and row ID ascend.
	 * Only rows with a null completion date in legacy/ready queues are selected.
	 * This is a read, not a claim or lock: callers must establish worker ownership
	 * separately. A failed read throws before any later query can clear the
	 * database error. This method never acknowledges or deletes pending work.
	 *
	 * @param int  $limit          SQL row limit, passed through without clamping. Default 2.
	 * @param bool $scheduled_only Whether to exclude triggered queues. Default false.
	 *
	 * @return array Raw database result containing associative log rows plus
	 *               serialized run meta and warmup_type; empty array for no rows.
	 *
	 * @throws \RuntimeException When published queue rows cannot be read.
	 */
	public static function get_links_to_warm( int $limit = 2, bool $scheduled_only = false ) {
		global $wpdb;

		$logs_table = self::get_tables_prefix() . 'warm_ups_logs';
		$list_table = self::get_tables_prefix() . 'warm_ups_list';

		$scheduled_condition = $scheduled_only ? "AND list.warmup_type = 'scheduled'" : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
			            SELECT logs.*, list.meta, list.warmup_type
		            FROM %i AS logs
		            INNER JOIN %i AS list
		            ON logs.list_id = list.id
			            WHERE logs.warm_date IS NULL
			            AND list.warmup_type != 'external'
			            AND list.queue_state IN ('legacy', 'ready')
			            $scheduled_condition
			            ORDER BY
			                CASE WHEN list.warmup_type = 'scheduled' THEN 0 ELSE 1 END,
			                CASE WHEN list.warmup_type = 'scheduled' THEN list.id END DESC,
			                CASE WHEN list.warmup_type = 'unscheduled' THEN list.id END ASC,
			                logs.log_phase ASC,
			                logs.log_depth ASC,
			                logs.id ASC
			            LIMIT %d;
	            ",
				$logs_table,
				$list_table,
				$limit
			),
			ARRAY_A
		);
		if ( $wpdb->last_error || null === $rows ) {
			throw new \RuntimeException( 'Could not read pending warm-up links.' );
		}
		return $rows;
	}

	/**
	 * Cancels all queued links for one warm-up.
	 *
	 * A real timestamp plus an explicit flag works with strict SQL modes and keeps
	 * an in-flight row valid until an overlapping worker observes the stop flag.
	 *
	 * First cancels publication and clears the seed graph, then marks pending log
	 * rows cancelled. Both writes honor the current process fence; ownership is
	 * checked afterward. No lock or transaction is started here, so failure can
	 * leave publication cancelled before all pending rows have been marked.
	 * Already completed log rows are not changed.
	 *
	 * @param int $warmup_id Numeric warm-up list ID.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When either write fails or process ownership is lost.
	 */
	public static function mark_all_links_as_warmed( int $warmup_id ) {
		global $wpdb;

		$logs_table = self::get_tables_prefix() . 'warm_ups_logs';
		$list_table = self::get_tables_prefix() . 'warm_ups_list';
		$fence      = self::process_fence();
		if ( false === $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET queue_state = 'cancelled', seed_tree = NULL WHERE id = %d AND $fence",
				$list_table,
				$warmup_id
			)
		) ) {
			throw new \RuntimeException( 'Could not cancel warm-up publication.' );
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"
				UPDATE %i
				SET warm_date = %s, is_cancelled = 1
				WHERE warm_date IS NULL AND list_id = %d AND $fence
				",
				$logs_table,
				wp_date( 'Y-m-d H:i:s' ),
				$warmup_id
			)
		);
		self::assert_process_lock();
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not cancel pending warm-up rows.' );
		}
	}

	/**
	 * Delete eligible completed history for triggered or external warm-up types.
	 *
	 * Triggered cleanup removes whole run rows only when no pending visit remains,
	 * the run is not building, ready or finalizing, and no legacy receipt protects
	 * it. Associated visits follow the schema's cascade rather than an independent
	 * visited-set deletion. External cleanup first removes completed visit rows
	 * (including cancelled rows), then removes containers with no remaining visits.
	 *
	 * Unknown types are a no-op. Runs SQL against current shared state without a
	 * transaction or acquired lock. Query results are not checked: return does not
	 * certify deletion, and partial external cleanup can persist after a failure.
	 *
	 * @param string $warmup_type WARMUP_TYPE_UNSCHEDULED or WARMUP_TYPE_EXTERNAL.
	 * @return void
	 */
	public static function delete_completed_logs_by_type( string $warmup_type ) {
		global $wpdb;

		$allowed_types = array( self::WARMUP_TYPE_UNSCHEDULED, self::WARMUP_TYPE_EXTERNAL );
		if ( ! in_array( $warmup_type, $allowed_types, true ) ) {
			return;
		}

		$logs_table = self::get_tables_prefix() . 'warm_ups_logs';
		$list_table = self::get_tables_prefix() . 'warm_ups_list';

		if ( self::WARMUP_TYPE_UNSCHEDULED === $warmup_type ) {
			// Logs are also the visited set. Removing individual completed rows
			// from an active graph permits cycles and erases retry accounting.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE list FROM %i AS list
				 WHERE list.warmup_type = 'unscheduled'
				 AND list.queue_state NOT IN ('building', 'ready', 'finalizing')
				 AND list.legacy_key IS NULL
				 AND NOT EXISTS (
					 SELECT 1 FROM %i AS pending
					 WHERE pending.list_id = list.id AND pending.warm_date IS NULL
				 )",
					$list_table,
					$logs_table
				)
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'
					DELETE logs
					FROM %i AS logs
					INNER JOIN %i AS list ON list.id = logs.list_id
					WHERE list.warmup_type = %s AND logs.warm_date IS NOT NULL
				',
				$logs_table,
				$list_table,
				$warmup_type
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				'
					DELETE list
					FROM %i AS list
					WHERE list.warmup_type = %s
					AND NOT EXISTS (
						SELECT 1 FROM %i AS logs WHERE logs.list_id = list.id
					)
				',
				$list_table,
				$warmup_type,
				$logs_table
			)
		);
	}

	/**
	 * Find the newest scheduled run that blocks admission of another run.
	 *
	 * Includes building, ready and finalizing runs even before they expose queue
	 * rows, plus any scheduled run with an unfinished row. Reads shared database
	 * state without acquiring an admission lock; callers serialize admission.
	 *
	 * @return array|null Associative warm-up list row, or null when none qualifies.
	 *
	 * @throws \RuntimeException When the admission-state query reports an error.
	 */
	public static function get_active_scheduled_warmup() {
		global $wpdb;

		$logs_table = self::get_tables_prefix() . 'warm_ups_logs';
		$list_table = self::get_tables_prefix() . 'warm_ups_list';

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT list.*
					FROM %i AS list
					WHERE list.warmup_type = 'scheduled'
					AND (list.queue_state IN ('building', 'ready', 'finalizing') OR EXISTS (
						SELECT 1 FROM %i AS logs
						WHERE logs.list_id = list.id AND logs.warm_date IS NULL
					))
					ORDER BY list.id DESC
					LIMIT 1
				",
				$list_table,
				$logs_table
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not determine scheduled run admission.' );
		}
		return $result;
	}

	/**
	 * Check whether a run has at least one row without a completion date.
	 *
	 * Does not filter by run type or publication state and does not claim work.
	 * A database error throws rather than being mistaken for an empty queue.
	 *
	 * @param int $warmup_id Numeric warm-up list ID.
	 *
	 * @return bool Whether pending links exist.
	 *
	 * @throws \RuntimeException When the pending-row lookup reports an error.
	 */
	public static function warmup_has_pending_links( int $warmup_id ): bool {
		global $wpdb;

		$table_name = self::get_tables_prefix() . 'warm_ups_logs';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE list_id = %d AND warm_date IS NULL LIMIT 1',
				$table_name,
				$warmup_id
			)
		);
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not verify whether the warm-up queue is complete.' );
		}
		return (bool) $result;
	}

	/**
	 * Whether a scheduled warm-up has been stopped manually.
	 *
	 * The option is read directly from the database so a worker in another PHP
	 * process cannot observe a stale value from its local WordPress object cache.
	 *
	 * @param int $warmup_id Warm-up ID.
	 *
	 * @return bool Whether this exact warm-up was stopped.
	 *
	 * @throws \RuntimeException When either authoritative database read fails.
	 */
	public static function was_warmup_stopped( int $warmup_id ): bool {
		global $wpdb;

		$list_table = self::get_tables_prefix() . 'warm_ups_list';
		$stopped_at = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				'cache-warmer-last-stopped-by-hand-warmup-id'
			)
		);

		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not read warm-up stop marker.' );
		}

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i WHERE id = %d AND (queue_state = 'cancelled' OR warmed_at = %s) LIMIT 1",
				$list_table,
				$warmup_id,
				maybe_unserialize( $stopped_at )
			)
		);
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not read warm-up cancellation state.' );
		}
		return (bool) $result;
	}

	/**
	 * Whether an equivalent link has already been retrieved successfully.
	 *
	 * Requires the same run and exact URL/metadata hash, a successful completed
	 * uncancelled log and a recorded depth no greater than the requested depth.
	 * Does not normalize URLs, claim work or write state. Does not inspect the
	 * database error; an unsuccessful lookup is cast to false like a missing row.
	 *
	 * @param int    $warmup_id Numeric warm-up list ID.
	 * @param string $link      Exact URL included in visit identity.
	 * @param array  $link_meta Metadata included in the order-sensitive visit hash.
	 * @param int    $depth     Maximum eligible recorded traversal depth.
	 *
	 * @return bool Whether the link was retrieved at this or a lower depth.
	 */
	public static function is_link_retrieved( int $warmup_id, string $link, array $link_meta, int $depth ): bool {
		global $wpdb;

		$table_name = self::get_tables_prefix() . 'warm_ups_logs';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'
					SELECT 1 FROM %i
					WHERE list_id = %d AND link_hash = %s
					AND log_is_success = 1 AND warm_date IS NOT NULL AND is_cancelled = 0
					AND log_depth <= %d
					LIMIT 1
				',
				$table_name,
				$warmup_id,
				self::get_link_hash( $link, $link_meta ),
				$depth
			)
		);
	}

	/**
	 * Enqueues one retry for each failed first-pass link in a warm-up.
	 *
	 * Selects uncancelled failures and appends phase-one pending rows, preserving
	 * visit identity, depth, parent and User-Agent. Excludes identities with an
	 * existing retry or an earlier completed first-pass failure. Normally selects
	 * completed failures; a supplied row ID selects that failure without requiring
	 * its completion timestamp, allowing retry creation before acknowledgement.
	 *
	 * The insert honors the current process fence and checks ownership afterward.
	 * Does not acquire a lock or start a transaction; a post-write exception does
	 * not roll back inserted retries. Callers must serialize queue mutation.
	 *
	 * @param int      $warmup_id      Numeric warm-up list ID.
	 * @param int|null $pending_log_id Specific failed row to prepare, or null for completed failures.
	 *
	 * @return int Number of retry rows inserted, including zero when none qualify.
	 *
	 * @throws \RuntimeException When the insert fails or process ownership is lost.
	 */
	public static function enqueue_failed_retries( int $warmup_id, ?int $pending_log_id = null ) {
		global $wpdb;
		$fence = self::process_fence();

		$table_name       = self::get_tables_prefix() . 'warm_ups_logs';
		$failed_condition = null === $pending_log_id
			? 'failed.warm_date IS NOT NULL'
			: $wpdb->prepare( 'failed.id = %d', $pending_log_id );

		$result = $wpdb->query(
			$wpdb->prepare(
				"
					INSERT INTO %i (
						list_id, log_is_success, log_date, log_depth, log_url, log_post_id,
						log_time_spent, log_time_afterwards, log_extra, log_phase,
						log_content_type, log_content_length, log_cf_cache_status,
						log_wp_super_cache_status, log_x_cache_header, log_visit_type,
						user_agent_id, canonical, external_warmer_results, link_meta,
						warm_date, parent_log_id, link_hash
					)
					SELECT
						failed.list_id, 0, %s, failed.log_depth, failed.log_url, failed.log_post_id,
						NULL, NULL, '', 1, '', '', '', '', '', '', failed.user_agent_id,
						'', '[]', failed.link_meta, NULL, failed.parent_log_id, failed.link_hash
					FROM %i AS failed
					WHERE failed.list_id = %d
					AND failed.log_phase = 0
					AND failed.log_is_success = 0
					AND $failed_condition
					AND failed.is_cancelled = 0
					AND $fence
					AND NOT EXISTS (
						SELECT 1 FROM %i AS earlier_failure
						WHERE earlier_failure.list_id = failed.list_id
						AND earlier_failure.link_hash = failed.link_hash
						AND earlier_failure.log_phase = 0
						AND earlier_failure.log_is_success = 0
						AND earlier_failure.warm_date IS NOT NULL
						AND earlier_failure.is_cancelled = 0
						AND earlier_failure.id < failed.id
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i AS retry
						WHERE retry.list_id = failed.list_id
						AND retry.link_hash = failed.link_hash
						AND retry.log_phase = 1
					)
				",
				$table_name,
				wp_date( 'Y-m-d H:i:s' ),
				$table_name,
				$warmup_id,
				$table_name,
				$table_name
			)
		);
		self::assert_process_lock();
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to persist warm-up queue retries.' );
		}
		return $result;
	}

	/**
	 * Updates metadata using the immutable numeric warm-up identifier.
	 *
	 * Replaces the stored JSON metadata, subject to the current process fence,
	 * then verifies lock ownership. Does not merge metadata, acquire a lock, or
	 * start a transaction. A zero-row update is considered successful, including
	 * when the identifier does not exist or the stored value is unchanged.
	 *
	 * @param int   $warmup_id    Immutable warm-up row ID.
	 * @param array $warm_up_meta Complete replacement metadata, encoded as JSON.
	 *
	 * @return bool True unless the database query returns false.
	 *
	 * @throws \RuntimeException When the post-write ownership check fails. An
	 *                           earlier write is not rolled back by this check.
	 */
	public static function update_warm_up_meta( int $warmup_id, array $warm_up_meta ): bool {
		global $wpdb;

		$table  = self::get_tables_prefix() . 'warm_ups_list';
		$fence  = self::process_fence();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET `meta` = %s WHERE `id` = %d AND $fence",
				$table,
				wp_json_encode( $warm_up_meta ),
				$warmup_id
			)
		);
		self::assert_process_lock();

		return false !== $result;
	}

	/**
	 * Acquires the site-specific warming process lock.
	 *
	 * Attempts the database named lock without waiting. Rejects nested acquisition
	 * in this request so a nested worker cannot release the outer worker's lock.
	 * Records the owning connection for subsequent SQL fences and verifies it
	 * before returning. If verification throws, releases the lock and clears the
	 * recorded connection before propagating the failure.
	 *
	 * @return bool True after verified acquisition; false on contention, failed
	 *              acquisition, or an already recorded process connection.
	 *
	 * @throws \Throwable When ownership verification fails after acquisition.
	 */
	public static function acquire_process_lock(): bool {
		global $wpdb;
		// GET_LOCK is reentrant. A nested start must not release/reset the outer
		// worker's guard or publish new rows while its HTTP callback is running.
		if ( null !== self::$process_connection ) {
			return false;
		}
		if ( ! self::acquire_lock( 'process', 0 ) ) {
			return false;
		}
		self::$process_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		try {
			self::assert_process_lock();
		} catch ( \Throwable $error ) {
			self::release_process_lock();
			throw $error;
		}
		return true;
	}

	/**
	 * Releases the site-specific warming process lock.
	 *
	 * Attempts to release the database named lock and always clears this request's
	 * recorded owner connection. The database release result is not checked;
	 * returning does not certify that a lock was previously held or released.
	 * Callers must stop fenced worker writes after invoking this method.
	 *
	 * @return void
	 */
	public static function release_process_lock() {
		try {
			self::release_lock( 'process' );
		} finally {
			self::$process_connection = null;
		}
	}

	/**
	 * SQL predicate evaluated on the executing connection, including wpdb retries.
	 *
	 * A reconnected stale worker must not acknowledge or extend the queue. Keeping
	 * the original connection ID in SQL closes the check-then-reconnect window.
	 * Unlocked administrative operations retain their existing behavior.
	 *
	 * @return string SQL boolean expression, ready for an AND/WHERE clause.
	 */
	public static function process_fence(): string {
		global $wpdb;
		if ( null === self::$process_connection ) {
			return '1=1';
		}
		return $wpdb->prepare(
			'(CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID())',
			self::$process_connection,
			self::get_lock_name( 'process' )
		);
	}

	/**
	 * Verifies that the current database connection still owns the process lock.
	 *
	 * Unlocked administrative callers are accepted. A locked worker performs an
	 * authoritative connection-level check and fails before acknowledging work if
	 * a reconnect or another process has taken ownership.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When a worker lost its connection-owned lock.
	 */
	public static function assert_process_lock(): void {
		global $wpdb;
		if ( null !== self::$process_connection && '1' !== (string) $wpdb->get_var( 'SELECT ' . self::process_fence() ) ) {
			throw new \RuntimeException( 'Warm-up database lock ownership was lost; the queue remains recoverable.' );
		}
	}

	/**
	 * Requires and revalidates process-lock ownership for lifecycle work.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When lifecycle work is attempted without ownership
	 *                           or the current connection no longer owns the lock.
	 */
	public static function require_process_lock(): void {
		if ( null === self::$process_connection ) {
			throw new \RuntimeException( 'Warm-up lifecycle work requires the process lock.' );
		}
		self::assert_process_lock();
	}

	/**
	 * Acquires the short lock used to serialize scheduled-run admission.
	 *
	 * Records the owning connection so later publication can reject stale workers.
	 * A false result means the lock was not acquired; no exception is raised.
	 *
	 * @return bool Whether this process may admit a new scheduled run.
	 */
	public static function acquire_admission_lock(): bool {
		global $wpdb;
		if ( ! self::acquire_lock( 'admission', 5 ) ) {
			return false;
		}
		self::$admission_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		return true;
	}

	/**
	 * Releases serialized run admission and forgets its owning connection.
	 *
	 * @return void
	 */
	public static function release_admission_lock(): void {
		try {
			self::release_lock( 'admission' );
		} finally {
			self::$admission_connection = null;
		}
	}

	/**
	 * Acquires the lock that serializes Action Scheduler check-and-enqueue calls.
	 *
	 * Waits for at most one second. A false result means another process retained
	 * the lock or the database did not grant it; this method does not distinguish
	 * those outcomes.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	public static function acquire_scheduling_lock(): bool {
		return self::acquire_lock( 'schedule', 1 );
	}

	/**
	 * Releases the Action Scheduler enqueue lock.
	 *
	 * The database return value is intentionally not surfaced to the caller.
	 *
	 * @return void
	 */
	public static function release_scheduling_lock() {
		self::release_lock( 'schedule' );
	}

	/**
	 * Acquires the lock that serializes publication-triggered queue consumers.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	public static function acquire_posts_queue_lock(): bool {
		return self::acquire_lock( 'posts-queue', 1 );
	}

	/**
	 * Releases the publication-triggered queue consumer lock.
	 *
	 * @return void
	 */
	public static function release_posts_queue_lock() {
		self::release_lock( 'posts-queue' );
	}

	/**
	 * Acquires the lock that serializes schema and version migrations.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	public static function acquire_migration_lock(): bool {
		return self::acquire_lock( 'migrate', 0 );
	}

	/**
	 * Releases the schema and version migration lock.
	 *
	 * @return void
	 */
	public static function release_migration_lock() {
		self::release_lock( 'migrate' );
	}

	/**
	 * Atomically adds or refreshes one post in the publication-triggered queue.
	 *
	 * Republishing a post while a worker owns its previous queue row clears the
	 * claim. The old worker therefore cannot acknowledge and delete the newer
	 * publication event.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool Whether the row was persisted.
	 */
	public static function enqueue_post_for_warming( int $post_id ): bool {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return false;
		}

		$table_name = self::get_tables_prefix() . 'posts_queue';
		$result     = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table_name (post_id, enqueued_at, claim_token, claimed_at)
				 VALUES (%d, UTC_TIMESTAMP(6), NULL, NULL)
				 ON DUPLICATE KEY UPDATE
				 enqueued_at = VALUES(enqueued_at), claim_token = NULL, claimed_at = NULL",
				$post_id
			)
		);

		return false !== $result;
	}

	/**
	 * Claims queued posts for one worker.
	 *
	 * Claims older than ten minutes are recoverable after a killed worker. A
	 * token scopes acknowledgement so concurrent publications cannot be erased
	 * by an older worker. A failed post-ID read releases only this worker's token
	 * before returning false. If that release also fails, durable rows remain
	 * recoverable after lease expiry; no queued post is acknowledged or deleted.
	 *
	 * @param int $limit Maximum rows to claim. Default 10000; clamped to at least one.
	 *
	 * @return array{token:string,post_ids:int[]}|false Claim data, or false on a database failure.
	 */
	public static function claim_posts_for_warming( int $limit = 10000 ) {
		global $wpdb;

		$limit      = max( 1, $limit );
		$token      = wp_generate_uuid4();
		$table_name = self::get_tables_prefix() . 'posts_queue';
		$result     = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				 SET claim_token = %s, claimed_at = UTC_TIMESTAMP(6)
				 WHERE claim_token IS NULL
				    OR claimed_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE
				 ORDER BY enqueued_at ASC
				 LIMIT %d',
				$table_name,
				$token,
				$limit
			)
		);

		if ( false === $result ) {
			return false;
		}

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE claim_token = %s ORDER BY enqueued_at ASC',
				$table_name,
				$token
			)
		);

		if ( $wpdb->last_error || null === $post_ids ) {
			self::release_posts_queue_claim( $token );
			return false;
		}

		return array(
			'token'    => $token,
			'post_ids' => array_map( 'intval', $post_ids ),
		);
	}

	/**
	 * Acknowledges all rows that still belong to a completed claim.
	 *
	 * @param string $claim_token Claim token.
	 *
	 * @return bool Whether the delete query succeeded.
	 */
	public static function complete_posts_queue_claim( string $claim_token ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			self::get_tables_prefix() . 'posts_queue',
			[ 'claim_token' => $claim_token ],
			[ '%s' ]
		);
	}

	/**
	 * Releases all rows that still belong to a failed claim.
	 *
	 * @param string $claim_token Claim token.
	 *
	 * @return bool Whether the update query succeeded.
	 */
	public static function release_posts_queue_claim( string $claim_token ): bool {
		global $wpdb;

		$result = $wpdb->update(
			self::get_tables_prefix() . 'posts_queue',
			[
				'claim_token' => null,
				'claimed_at'  => null,
			],
			[ 'claim_token' => $claim_token ],
			[ null, null ],
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Returns all post IDs waiting in the durable queue.
	 *
	 * @return int[] Queued post IDs.
	 */
	public static function get_queued_post_ids(): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			'SELECT post_id FROM ' . self::get_tables_prefix() . 'posts_queue ORDER BY enqueued_at ASC'
		);

		return array_map( 'intval', (array) $post_ids );
	}

	/**
	 * Clears the durable publication-triggered queue.
	 *
	 * Intended for owned test/reset workflows and plugin administration.
	 *
	 * @return bool Whether the delete query succeeded.
	 */
	public static function clear_posts_queue(): bool {
		global $wpdb;

		return false !== $wpdb->query( 'DELETE FROM ' . self::get_tables_prefix() . 'posts_queue' );
	}

	/**
	 * Acquires a site-specific MariaDB advisory lock.
	 *
	 * @param string $purpose         Lock purpose.
	 * @param int    $timeout_seconds Maximum wait in seconds.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private static function acquire_lock( string $purpose, int $timeout_seconds ): bool {
		global $wpdb;

		$lock_name = self::get_lock_name( $purpose );

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout_seconds )
		);
	}

	/**
	 * Releases a site-specific MariaDB advisory lock.
	 *
	 * A release attempted by a non-owning connection is a no-op from the caller's
	 * perspective; the database result is not promoted to an exception.
	 *
	 * @param string $purpose Lock purpose.
	 *
	 * @return void
	 */
	private static function release_lock( string $purpose ) {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::get_lock_name( $purpose ) ) );
	}

	/**
	 * Returns a MariaDB-compatible lock name scoped to the current WordPress site.
	 *
	 * @param string $purpose Lock purpose.
	 *
	 * @return string Lock name.
	 */
	private static function get_lock_name( string $purpose ): string {
		$prefix = 'process' === $purpose ? 'cache-warmer:' : 'cache-warmer:' . $purpose . ':';

		return substr( $prefix . md5( self::get_tables_prefix() ), 0, 64 );
	}

	/**
	 * Creates or replaces metadata for the warm-up identified by its start date.
	 *
	 * Classifies the run as external when its date prefix matches the external
	 * sentinel; otherwise treats it as scheduled and performs retention cleanup
	 * first. On a duplicate date, preserves the row ID and replaces metadata and
	 * type. These writes are not transactional and this method acquires no lock.
	 *
	 * Suppresses database error display and restores the previous setting on the
	 * normal return path. The query result itself is not checked: the return value
	 * comes from wpdb's insert_id, not a separate persistence verification.
	 *
	 * @param string $warm_up_start_date Public start-date identifier used as the unique key.
	 * @param array  $warm_up_meta       Complete replacement metadata, encoded as JSON.
	 *
	 * @return int|false Inserted or matched row ID, or false when insert_id is zero.
	 */
	public static function create_or_update_a_warm_up( string $warm_up_start_date, array $warm_up_meta ) {
		global $wpdb;

		$prev = $wpdb->suppress_errors();

		$table_name = self::get_tables_prefix() . 'warm_ups_list';
		$type       = External_Warmer::WARMUP_ID === substr( $warm_up_start_date, 0, 10 )
			? self::WARMUP_TYPE_EXTERNAL
			: self::WARMUP_TYPE_SCHEDULED;

		// Delete excess warm-ups, according to the "For how many days to keep the logs" setting.
		if ( self::WARMUP_TYPE_SCHEDULED === $type ) {
			self::delete_excess_warm_ups( $warm_up_start_date );
		}

		$meta_json = wp_json_encode( $warm_up_meta );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (warmed_at, meta, warmup_type)
		         VALUES (%s, %s, %s)
		         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), meta = %s, warmup_type = %s;',
				$table_name,
				$warm_up_start_date,
				$meta_json,
				$type,
				$meta_json,
				$type
			)
		);

		$wpdb->suppress_errors( $prev );

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Reuse a legacy receipt or attempt allocation of an isolated warm-up run.
	 *
	 * Accepts scheduled and triggered types only. An existing legacy-key match
	 * returns its persisted identifier and decoded metadata without applying new
	 * input or retention. A failed receipt lookup rejects admission.
	 * Otherwise parses the preferred date in the site timezone and tries at most
	 * sixty consecutive seconds, starting with that timestamp. Parser-normalized
	 * dates are accepted; parse warnings are not separately rejected.
	 *
	 * Small manifests remain inline. Large manifests are split into bounded binary
	 * rows and committed with their parent and checksum descriptor in one InnoDB
	 * transaction. Callers must not wrap large-seed admission in a transaction.
	 * Connection fences reject reconnects; failed transfers roll back and legacy
	 * source options remain available. Only unique timestamp collisions retry.
	 * Error-display state is restored even when a query throws. Retention runs
	 * after successful publication; it cannot remove unfinished manifests.
	 *
	 * @param array       $warm_up_meta Metadata copied and normalized for a new run.
	 * @param string      $warmup_type  Scheduled or triggered type discriminator.
	 * @param string      $preferred_at Preferred site-local Y-m-d H:i:s identifier.
	 * @param array|null  $seed_tree    Initial graph, or null for direct/legacy callers.
	 * @param string|null $legacy_key   Stable migration receipt, or null for a new run.
	 * @throws \RuntimeException When an unfinished legacy receipt is unreadable.
	 * @return array|false ID, warmed_at and metadata; false for unsupported type, parse/JSON failure or exhausted attempts. Existing receipt metadata retains its decoded type.
	 */
	public static function create_warm_up( array $warm_up_meta, string $warmup_type, string $preferred_at, ?array $seed_tree = null, ?string $legacy_key = null ) {
		global $wpdb;

		$allowed_types = array(
			self::WARMUP_TYPE_SCHEDULED,
			self::WARMUP_TYPE_UNSCHEDULED,
		);

		if ( ! in_array( $warmup_type, $allowed_types, true ) ) {
			return false;
		}

		$table_name = self::get_tables_prefix() . 'warm_ups_list';
		if ( null !== $legacy_key ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, warmed_at, meta, queue_state FROM %i WHERE legacy_key = %s', $table_name, $legacy_key ), ARRAY_A );
			if ( $wpdb->last_error ) {
				return false;
			}
			if ( $existing ) {
				// A receipt for unfinished work is valid only while its source is intact.
				if ( 'building' === $existing['queue_state'] ) {
					self::read_warm_up_seed( (int) $existing['id'] );
				}
				return array(
					'id'        => (int) $existing['id'],
					'warmed_at' => $existing['warmed_at'],
					'meta'      => json_decode( $existing['meta'], true ),
				);
			}
		}
		$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $preferred_at, wp_timezone() );

		if ( false === $date ) {
			return false;
		}

		$timestamp = $date->getTimestamp();
		$seed_json = null === $seed_tree ? null : wp_json_encode( $seed_tree );
		if ( false === $seed_json ) {
			return false;
		}
		$packet = (int) $wpdb->get_var( 'SELECT @@SESSION.max_allowed_packet' );
		if ( $packet < 4096 || $wpdb->last_error ) {
			return false;
		}
		$chunk_size = min( 16384, (int) ( ( $packet - 2048 ) / 2 ) );
		$chunked    = null !== $seed_json && strlen( $seed_json ) > $chunk_size;
		// A JSON string is deliberately invalid to pre-1.4.4 tree readers. An old
		// in-flight worker must fail closed, never enqueue descriptor keys as URLs.
		$stored = $chunked ? wp_json_encode(
			'cw-seed-chunks:' . wp_json_encode(
				array(
					'cw_seed_chunks' => 1,
					'bytes'          => strlen( $seed_json ),
					'sha256'         => hash( 'sha256', $seed_json ),
				)
			)
		) : $seed_json;
		$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$owner = self::$admission_connection ?? 0;
		$previous    = $wpdb->suppress_errors();
		$transaction = false;
		try {
			for ( $offset = 0; $offset < 60; $offset++ ) {
				$warmed_at                  = wp_date( 'Y-m-d H:i:s', $timestamp + $offset );
				$warm_up_meta['start_date'] = $warmed_at;
				$meta_json                  = wp_json_encode( $warm_up_meta );
				if ( false === $meta_json ) {
					return false;
				}
				// SQL escaping can double byte length; reject oversized metadata early.
				if ( 2 * ( strlen( $meta_json ) + strlen( $stored ?? '' ) + strlen( $legacy_key ?? '' ) ) + 2048 >= $packet ) {
					return false;
				}
				if ( $chunked ) {
					if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
						return false;
					}
					$transaction = true;
				}
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO %i (warmed_at, meta, warmup_type, queue_state, seed_tree, legacy_key)
						 SELECT %s, %s, %s, %s, NULLIF(%s, ''), NULLIF(%s, '')
						 WHERE CONNECTION_ID() = %d AND (%d = 0 OR (CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()))",
						$table_name,
						$warmed_at,
						$meta_json,
						$warmup_type,
						null === $seed_tree ? 'legacy' : 'building',
						$stored ?? '',
						$legacy_key ?? '',
						$connection,
						$owner,
						$owner,
						self::get_lock_name( 'admission' )
					)
				);
				$id       = (int) $wpdb->insert_id;
				if ( ! $inserted ) {
					// Only timestamp collisions warrant another allocation attempt.
					$error = $wpdb->last_error;
					if ( $transaction ) {
						$wpdb->query( 'ROLLBACK' );
						$transaction = false;
					}
					if ( false === strpos( $error, 'Duplicate entry' ) || false === strpos( $error, 'warmed_at' ) ) {
						return false;
					}
					continue;
				}
				if ( $chunked ) {
					$parts = self::get_tables_prefix() . 'warm_up_seeds';
					$length = strlen( $seed_json );
					for ( $position = 0, $part = 0; $position < $length; $position += $chunk_size, $part++ ) {
						$hex = bin2hex( substr( $seed_json, $position, $chunk_size ) );
						if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (list_id, part, payload) SELECT %d, %d, UNHEX(%s) WHERE CONNECTION_ID() = %d AND (%d = 0 OR (CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()))', $parts, $id, $part, $hex, $connection, $owner, $owner, self::get_lock_name( 'admission' ) ) ) ) {
							return false;
						}
					}
					// A wpdb reconnect rolls back the old transaction; never acknowledge it.
					if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT CONNECTION_ID() = %d AND (%d = 0 OR (CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()))', $connection, $owner, $owner, self::get_lock_name( 'admission' ) ) ) || false === $wpdb->query( 'COMMIT' ) ) {
						return false;
					}
					$transaction = false;
					if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT CONNECTION_ID() = %d AND (%d = 0 OR (CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()))', $connection, $owner, $owner, self::get_lock_name( 'admission' ) ) ) ) {
						return false;
					}
				}
				self::delete_excess_warm_ups( $warmed_at, $warmup_type );
				return array(
					'id'        => $id,
					'warmed_at' => $warmed_at,
					'meta'      => $warm_up_meta,
				);
			}
		} finally {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->suppress_errors( $previous );
		}
		return false;
	}

	/**
	 * Read a durable manifest through bounded result rows, including old inline seeds.
	 *
	 * Chunk descriptors contain byte length and SHA-256 of the complete JSON. Missing,
	 * reordered or corrupted parts fail closed; callers retain the run for recovery.
	 * The decoded graph still needs to fit PHP memory, as required by the crawler.
	 *
	 * @param int $id Warm-up identifier.
	 * @return array Complete seed graph or legacy migration envelope.
	 * @throws \RuntimeException When a read fails or a manifest is incomplete.
	 */
	public static function read_warm_up_seed( int $id ): array {
		global $wpdb;
		$table = self::get_tables_prefix() . 'warm_ups_list';
		$size  = min( 16384, max( 1024, (int) $wpdb->get_var( 'SELECT @@SESSION.max_allowed_packet' ) - 2048 ) );
		$json  = '';
		for ( $offset = 1; ; $offset += $size ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT SUBSTRING(CAST(seed_tree AS BINARY), %d, %d) FROM %i WHERE id = %d', $offset, $size, $table, $id ), ARRAY_N );
			$piece = $row[0] ?? null;
			if ( null === $piece || $wpdb->last_error ) {
				throw new \RuntimeException( 'Could not read warm-up seed; retained for recovery.' );
			}
			$json .= $piece;
			if ( strlen( $piece ) < $size ) {
				break;
			}
		}
		$tree = json_decode( $json, true );
		if ( is_string( $tree ) && 0 === strpos( $tree, 'cw-seed-chunks:' ) ) {
			$tree = json_decode( substr( $tree, strlen( 'cw-seed-chunks:' ) ), true );
			if ( ! is_array( $tree ) || ! isset( $tree['cw_seed_chunks'] ) || 1 !== $tree['cw_seed_chunks'] || ! isset( $tree['bytes'], $tree['sha256'] ) || ! is_int( $tree['bytes'] ) || $tree['bytes'] < 1 || ! is_string( $tree['sha256'] ) ) {
				throw new \RuntimeException( 'Invalid chunk descriptor; retained for recovery.' );
			}
			$descriptor = $tree;
			$json       = '';
			$parts      = self::get_tables_prefix() . 'warm_up_seeds';
			// Read slices even if a server's packet limit was lowered after publication.
			for ( $part = 0, $received = 0; $received < $descriptor['bytes']; $part++ ) {
				for ( $offset = 1; ; $offset += $size ) {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT SUBSTRING(payload, %d, %d) FROM %i WHERE list_id = %d AND part = %d', $offset, $size, $parts, $id, $part ), ARRAY_N );
					$piece = $row[0] ?? null;
					if ( null === $piece || $wpdb->last_error || ( 1 === $offset && '' === $piece ) ) {
						throw new \RuntimeException( 'Incomplete warm-up seed; retained for recovery.' );
					}
					$json .= $piece;
					$received += strlen( $piece );
					if ( strlen( $piece ) < $size ) {
						break;
					}
				}
			}
			if ( strlen( $json ) !== $descriptor['bytes'] || ! hash_equals( $descriptor['sha256'], hash( 'sha256', $json ) ) ) {
				throw new \RuntimeException( 'Corrupt warm-up seed; retained for recovery.' );
			}
			$tree = json_decode( $json, true );
		}
		if ( ! is_array( $tree ) ) {
			throw new \RuntimeException( 'Invalid persisted warm-up seed graph; retained for recovery.' );
		}
		return $tree;
	}
	/**
	 * Reclaim chunks only after their manifest is durably retired.
	 *
	 * At most twenty parents are reclaimed per recovery pass. A failed deletion
	 * is safe to retry: null seed_tree proves publication or cancellation already
	 * persisted, and the worker fence prevents a stale process from deleting data.
	 * Parent retention and uninstall additionally cascade/remove this table.
	 *
	 * @return void
	 * @throws \RuntimeException When the read, deletion or ownership check fails.
	 */
	public static function discard_published_seed_chunks(): void {
		global $wpdb;
		self::require_process_lock();
		$parts = self::get_tables_prefix() . 'warm_up_seeds';
		$list  = self::get_tables_prefix() . 'warm_ups_list';
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT chunks.list_id FROM %i AS chunks INNER JOIN %i AS runs ON runs.id = chunks.list_id WHERE runs.seed_tree IS NULL LIMIT 20', $parts, $list ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not inspect retired seed chunks.' );
		}
		foreach ( $ids as $id ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE chunks FROM %i AS chunks INNER JOIN %i AS runs ON runs.id = chunks.list_id WHERE chunks.list_id = %d AND runs.seed_tree IS NULL AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()', $parts, $list, $id, self::$process_connection, self::get_lock_name( 'process' ) ) ) ) {
				throw new \RuntimeException( 'Could not reclaim retired seed chunks.' );
			}
		}
		self::assert_process_lock();
	}
}
