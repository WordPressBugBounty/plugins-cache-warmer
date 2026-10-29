<?php
/**
 * Removes Cache Warmer's current-site options and database tables.
 *
 * Cleanup is intentionally fail-closed. Pending owned scheduler delivery is
 * cancelled before deleting data, including when the plugin was not loaded in
 * this request. Running workers must be drained separately; their status and
 * historical scheduler records are not deleted. After native option deletion, shared
 * MySQL is queried directly to verify durable absence before any table is
 * dropped. Each owned table drop is checked independently. An exception leaves
 * WordPress's plugin removal incomplete so an administrator can repair the
 * database and retry; earlier successful deletions are not rolled back.
 *
 * @package Cache-Warmer
 * @since 0.0.1
 */

use Cache_Warmer\DB;

// Refuse direct execution outside WordPress's native uninstall lifecycle.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/data/constants.php';

// Load only the scheduler dependency, not the plugin's runtime or migrations.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/src/class-scheduler-cleanup.php';
\Cache_Warmer\Scheduler_Cleanup::run();

// Delete all fixed plugin options plus legacy operational flags.

$all_options  = require __DIR__ . '/data/options.php';
$option_names = array_unique(
	array_merge(
		array_keys( $all_options ),
		[
			'cache-warmer-updating',
			'cache-warmer-setting-use-object-cache',
		]
	)
);

foreach ( $option_names as $option ) {
	delete_option( $option );
}

global $wpdb;

// Native false can mean absent or failed; caches may hide a failed deletion.
// Verify durable absence before permitting WordPress to remove the plugin files.
$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
$remaining    = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
		array_values( $option_names )
	)
);
if ( $wpdb->last_error || '0' !== (string) $remaining ) {
	throw new \RuntimeException( 'Cache Warmer uninstall could not verify option cleanup. Retry after resolving the database failure.' );
}

// Drop only tables declared by the plugin's database schema.

require_once __DIR__ . '/src/class-databases.php';

$tables_prefix = DB::get_tables_prefix();

foreach ( DB::get_tables() as $table ) {
	if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$tables_prefix}{$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers come from the owned schema.
		throw new \RuntimeException( 'Cache Warmer uninstall could not remove a table. Retry after resolving the database failure.' );
	}
}
