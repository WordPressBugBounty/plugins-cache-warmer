<?php
/**
 * Shared-database advisory locking for load-balancer support.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

/**
 * Coordinates correctness-critical work through WordPress' primary database.
 */
final class Database_Lock {

	/**
	 * Maximum MySQL advisory-lock name length.
	 *
	 * @var int
	 */
	const MAX_NAME_LENGTH = 64;

	/**
	 * Executes a callback while holding a site-scoped advisory lock.
	 *
	 * The callback result is returned unchanged. Its exceptions and errors are
	 * propagated after a best-effort release attempt on the same connection.
	 * A false release result does not replace a successful callback result.
	 *
	 * @param string   $namespace Stable plugin or component namespace.
	 * @param string   $resource  Operation-specific resource identity.
	 * @param callable $callback  Protected work.
	 * @param int      $timeout   Maximum seconds to wait.
	 * @return mixed Callback result.
	 * @throws \RuntimeException When the lock cannot be acquired.
	 */
	public static function with_lock( $namespace, $resource, callable $callback, $timeout = 5 ) {
		if ( ! self::acquire( $namespace, $resource, $timeout ) ) {
			throw new \RuntimeException( 'Could not acquire the shared database lock.' );
		}

		try {
			return $callback();
		} finally {
			self::release( $namespace, $resource );
		}
	}

	/**
	 * Attempts to acquire a site-scoped advisory lock.
	 *
	 * @param string $namespace Stable plugin or component namespace.
	 * @param string $resource  Operation-specific resource identity.
	 * @param int    $timeout   Maximum seconds to wait.
	 * @return bool Whether the lock was acquired. False covers contention,
	 *              timeout, and database-query failure.
	 */
	public static function acquire( $namespace, $resource, $timeout = 0 ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				self::get_name( $namespace, $resource ),
				max( 0, (int) $timeout )
			)
		);

		return '1' === (string) $result;
	}

	/**
	 * Releases a lock held by the current database connection.
	 *
	 * @param string $namespace Stable plugin or component namespace.
	 * @param string $resource  Operation-specific resource identity.
	 * @return bool Whether this connection owned and released the lock. False
	 *              also covers database-query failure.
	 */
	public static function release( $namespace, $resource ) {
		global $wpdb;

		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				self::get_name( $namespace, $resource )
			)
		);
	}

	/**
	 * Builds a collision-resistant name scoped to one WordPress database/site.
	 *
	 * MySQL advisory locks are server-wide rather than database-wide, so the
	 * database name and table prefix are part of the private digest. The current
	 * blog ID separates multisite tenants, and the result is truncated to the
	 * server's 64-byte lock-name limit.
	 *
	 * @param string $namespace Stable plugin or component namespace.
	 * @param string $resource  Operation-specific resource identity.
	 * @return string Advisory-lock name.
	 */
	public static function get_name( $namespace, $resource ) {
		global $wpdb;

		$readable_namespace = preg_replace( '/[^a-z0-9_-]/', '-', strtolower( (string) $namespace ) );
		$readable_namespace = trim( substr( $readable_namespace, 0, 12 ), '-' );
		$identity           = serialize(
			array(
				defined( 'DB_NAME' ) ? DB_NAME : '',
				isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : $wpdb->prefix,
				get_current_blog_id(),
				(string) $namespace,
				(string) $resource,
			)
		);
		$name               = 'wpc:' . ( $readable_namespace ? $readable_namespace : 'lock' ) . ':' . hash( 'sha256', $identity );

		return substr( $name, 0, self::MAX_NAME_LENGTH );
	}
}
