<?php
/**
 * Cross-node database migration coordination for load-balancer support.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

/**
 * Serializes an idempotent consumer migration and double-checks its version.
 */
final class Migration_Guard {

	/**
	 * Runs a pending migration once under a deployment-wide lock.
	 *
	 * The migration callback owns persistence of the target version. This
	 * keeps plugin-specific schema/version storage outside the shared library.
	 *
	 * @param string   $namespace      Stable plugin namespace.
	 * @param string   $target_version Target schema version.
	 * @param callable $read_version   Returns the freshly persisted version.
	 * @param callable $migrate        Receives current and target versions.
	 * @param int      $timeout        Maximum seconds to wait for the lock.
	 * @return bool True when migration work ran; false when the target was already current.
	 * @throws \RuntimeException When the lock cannot be acquired or the target version is not persisted.
	 * @throws \Throwable When a version reader or migration callback throws an exception or error.
	 */
	public static function run( $namespace, $target_version, callable $read_version, callable $migrate, $timeout = 30 ) {
		return Database_Lock::with_lock(
			$namespace,
			'schema-migration',
			function () use ( $target_version, $read_version, $migrate ) {
				$current_version = (string) $read_version();

				$comparable_current_version = $current_version ? $current_version : '0';
				if ( version_compare( $comparable_current_version, (string) $target_version, '>=' ) ) {
					return false;
				}

				$migrate( $current_version, (string) $target_version );

				$persisted_version            = (string) $read_version();
				$comparable_persisted_version = $persisted_version ? $persisted_version : '0';
				if ( version_compare( $comparable_persisted_version, (string) $target_version, '<' ) ) {
					throw new \RuntimeException( 'The guarded migration did not persist its target version.' );
				}

				return true;
			},
			$timeout
		);
	}
}
