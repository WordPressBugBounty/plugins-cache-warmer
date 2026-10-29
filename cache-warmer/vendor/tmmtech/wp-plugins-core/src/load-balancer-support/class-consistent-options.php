<?php
/**
 * Shared-database-authoritative WordPress options for load-balancer support.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

/**
 * Preserves the relevant Options API hooks while bypassing stale node caches.
 */
final class Consistent_Options {

	/**
	 * Reads an option directly from the shared database.
	 *
	 * This deliberately does not prime or invalidate WordPress' option cache:
	 * an unshared persistent cache can be stale, while evicting `alloptions` on
	 * every read would make otherwise cheap settings disproportionately costly.
	 * Consumers should use this only for plugin-owned coordination/settings
	 * options that must be authoritative across interchangeable web nodes.
	 *
	 * @param string    $option_name Option name.
	 * @param mixed     $default     Default value.
	 * @param bool|null $autoload    Retained for symmetry with mutation methods.
	 * @return mixed Option value.
	 * @throws \RuntimeException When the authoritative database read fails.
	 */
	public static function get( $option_name, $default = false, $autoload = null ) {
		global $wpdb;

		unset( $autoload );

		if ( is_scalar( $option_name ) ) {
			$option_name = trim( (string) $option_name );
		}

		if ( empty( $option_name ) ) {
			return false;
		}

		$pre = apply_filters( "pre_option_{$option_name}", false, $option_name, $default );
		$pre = apply_filters( 'pre_option', $pre, $option_name, $default );

		if ( false !== $pre ) {
			return $pre;
		}

		$passed_default = func_num_args() > 1;
		$row            = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not read the shared database option.' );
		}

		if ( ! is_object( $row ) ) {
			return apply_filters( "default_option_{$option_name}", $default, $option_name, $passed_default );
		}

		return apply_filters( "option_{$option_name}", maybe_unserialize( $row->option_value ), $option_name );
	}

	/**
	 * Writes an option after refreshing the cached old value used by WordPress.
	 *
	 * @param string           $option_name Option name.
	 * @param mixed            $value       New value.
	 * @param bool|string|null $autoload    Whether WordPress should autoload it.
	 * @return bool Whether the value changed or was newly added.
	 * @throws \RuntimeException When WordPress reports a database write error.
	 */
	public static function set( $option_name, $value, $autoload = null ) {
		global $wpdb;

		self::invalidate( $option_name, $autoload );

		$updated = update_option( $option_name, $value, $autoload );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not write the shared database option.' );
		}

		return $updated;
	}

	/**
	 * Deletes an option without trusting the current node's negative cache.
	 *
	 * @param string    $option_name Option name.
	 * @param bool|null $autoload    Retained for signature compatibility.
	 * @return bool Whether the option was deleted.
	 * @throws \RuntimeException When WordPress reports a database deletion error.
	 */
	public static function delete( $option_name, $autoload = null ) {
		global $wpdb;

		self::invalidate( $option_name, $autoload );

		$deleted = delete_option( $option_name );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not delete the shared database option.' );
		}

		return $deleted;
	}

	/**
	 * Atomically transforms an option under a shared-database lock.
	 *
	 * All writers to this option must use the same namespace and this method.
	 * Filters retain their normal authority to transform or veto a write. Any
	 * exception thrown by the mutator is propagated after the lock is released.
	 *
	 * @param string    $namespace   Stable plugin or component namespace.
	 * @param string    $option_name Option name.
	 * @param callable  $mutator     Receives the current value and returns the new value.
	 * @param mixed     $default     Default value.
	 * @param bool|null $autoload    Known autoload state; null is conservative.
	 * @param int       $timeout     Maximum seconds to wait for the lock.
	 * @return mixed Requested replacement value after the write completes.
	 * @throws \RuntimeException When lock acquisition or an option operation fails.
	 * @throws \Throwable When the mutator throws an exception or error.
	 */
	public static function mutate( $namespace, $option_name, callable $mutator, $default = false, $autoload = null, $timeout = 5 ) {
		return Database_Lock::with_lock(
			$namespace,
			'option:' . $option_name,
			function () use ( $option_name, $mutator, $default, $autoload ) {
				$value = $mutator( self::get( $option_name, $default, $autoload ) );
				self::set( $option_name, $value, $autoload );

				return $value;
			},
			$timeout
		);
	}

	/**
	 * Removes relevant entries from only the current node's object cache.
	 *
	 * @param string    $option_name Option name.
	 * @param bool|null $autoload    Retained for signature compatibility.
	 * @return void
	 */
	public static function invalidate( $option_name, $autoload = null ) {
		unset( $autoload );

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// The requested autoload state may differ from the cached old state.
		wp_cache_delete( 'alloptions', 'options' );
	}
}
