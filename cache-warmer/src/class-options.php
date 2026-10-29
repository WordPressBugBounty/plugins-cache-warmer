<?php
/**
 * Provides node-independent access to Cache Warmer options.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use Exception;

/**
 * Maintains site-scoped request snapshots backed by the WordPress options table.
 */
final class Options {

	/**
	 * Registered option definitions keyed by complete WordPress option name.
	 *
	 * @var array<string,array{default?:mixed,autoload?:bool,legacy?:bool}>
	 */
	public $all_options = [];

	/**
	 * Non-legacy option names exposed through settings import and export.
	 *
	 * @var string[]
	 */
	public $settings_options = [];

	/**
	 * Request-local snapshots loaded directly from each site's options table.
	 *
	 * Persistent object caches are deliberately bypassed: separate web nodes
	 * may use independent Redis instances, but Cache Warmer settings and state
	 * must remain identical whichever node serves the request.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $values = [];

	/**
	 * Loads option definitions and registers mutation/scheduling listeners.
	 */
	public function __construct() {
		$this->all_options = require CACHE_WARMER_DIR . 'data/options.php';

		$this->define_settings_options();
		$this->add_listeners();
	}

	/**
	 * Builds the public settings-option list from non-legacy definitions.
	 *
	 * @return void
	 */
	private function define_settings_options() {
		foreach ( $this->all_options as $option => $option_data ) {
			if (
				preg_match( '@^cache-warmer-setting-@', $option ) &&
				empty( $option_data['legacy'] )
			) {
				$this->settings_options[] = $option;
			}
		}
	}

	/**
	 * Reports whether an option may use a persistent object cache.
	 *
	 * This compatibility API deliberately returns false for every registered
	 * option. Cache Warmer reads shared MySQL state so nodes with independent
	 * Redis instances cannot disagree about settings or queue ownership.
	 *
	 * @param string $option_name The name of the option.
	 *
	 * @return bool Always false for a valid option.
	 *
	 * @throws Exception When the option is not registered.
	 */
	public function use_object_cache_for_option( $option_name ) {
		$this->validation_option( $option_name );

		return false;
	}

	/**
	 * Registers scheduling and request-snapshot synchronization listeners.
	 *
	 * @return void
	 */
	private function add_listeners() {
		$options_with_changes_handlers = [
			'cache-warmer-setting-interval' => [ $this, 'handle_interval_value_change' ],
			'cache-warmer-setting-posts-warming-enqueue-interval' => [ $this, 'handle_posts_warming_interval_value_change' ],
		];

		foreach ( $options_with_changes_handlers as $option => $handler ) {
			add_action( "delete_option_$option", $handler, 10, 1 );
			add_action( "add_option_$option", $handler, 10, 2 );
			add_action( "update_option_$option", $handler, 10, 3 );
		}

		add_action( 'added_option', [ $this, 'remember_added_option' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'remember_updated_option' ], 10, 3 );
		add_action( 'deleted_option', [ $this, 'forget_deleted_option' ], 10, 1 );
	}

	/**
	 * Keeps the request snapshot synchronized with direct WordPress API writes.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Added value.
	 *
	 * @return void
	 */
	public function remember_added_option( $option_name, $value ) {
		$this->remember_option( $option_name, $value );
	}

	/**
	 * Keeps the request snapshot synchronized with direct WordPress API writes.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $old_value   Previous value.
	 * @param mixed  $value       Updated value.
	 *
	 * @return void
	 */
	public function remember_updated_option( $option_name, $old_value, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->remember_option( $option_name, $value );
	}

	/**
	 * Removes a deleted option from the request snapshot.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return void
	 */
	public function forget_deleted_option( $option_name ) {
		$site_id = get_current_blog_id();
		if ( isset( $this->values[ $site_id ] ) && array_key_exists( $option_name, $this->all_options ) ) {
			unset( $this->values[ $site_id ][ $option_name ] );
		}
	}

	/**
	 * Records one plugin option in an already-loaded request snapshot.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 *
	 * @return void
	 */
	private function remember_option( $option_name, $value ) {
		$site_id = get_current_blog_id();
		if ( isset( $this->values[ $site_id ] ) && array_key_exists( $option_name, $this->all_options ) ) {
			$this->values[ $site_id ][ $option_name ] = $value;
		}
	}

	/**
	 * Loads every plugin option from MySQL in one site-scoped query.
	 *
	 * The snapshot is cached only for the current request and current blog ID.
	 * WordPress object-cache values are bypassed so another node's durable write
	 * is authoritative when a new request starts.
	 *
	 * @return void
	 * @throws Exception When the options table cannot be read.
	 */
	private function load_values() {
		global $wpdb;

		$site_id = get_current_blog_id();
		if ( array_key_exists( $site_id, $this->values ) ) {
			return;
		}

		$option_names = array_keys( $this->all_options );
		$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
		$query        = $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$option_names
		);
		$rows         = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			throw new Exception( __( 'Could not read Cache Warmer options.', 'cache-warmer' ) );
		}

		$this->values[ $site_id ] = [];
		foreach ( (array) $rows as $row ) {
			$this->values[ $site_id ][ $row['option_name'] ] = maybe_unserialize( $row['option_value'] );
		}
	}

	/**
	 * Invalidates this node's WordPress option cache before a mutation.
	 *
	 * Other nodes may retain stale Redis values, but all Cache Warmer reads use
	 * the shared database snapshot and therefore never consume those values.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return void
	 */
	private function invalidate_wordpress_cache( $option_name ) {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Reads a dynamic Cache Warmer option directly from the shared database.
	 *
	 * Dynamic per-domain options cannot be part of the fixed registry snapshot,
	 * but they must follow the same node-independent persistence contract.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Value returned when the option does not exist.
	 *
	 * @return mixed Option value.
	 *
	 * @throws Exception Exception.
	 */
	public function get_dynamic( $option_name, $default = false ) {
		global $wpdb;

		$this->validate_dynamic_option_name( $option_name );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			),
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			throw new Exception( __( 'Could not read Cache Warmer options.', 'cache-warmer' ) );
		}

		return null === $row ? $default : maybe_unserialize( $row['option_value'] );
	}

	/**
	 * Persists a dynamic Cache Warmer option in the shared database.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @param bool   $autoload    Whether WordPress should autoload the option.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function set_dynamic( $option_name, $value, $autoload = false ) {
		$this->validate_dynamic_option_name( $option_name );
		$this->write_value( $option_name, $value, $autoload );
	}

	/**
	 * Deletes a dynamic Cache Warmer option from the shared database.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function delete_dynamic( $option_name ) {
		$this->validate_dynamic_option_name( $option_name );
		$this->delete_value( $option_name );
	}

	/**
	 * Rejects arbitrary WordPress option access through the dynamic API.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	private function validate_dynamic_option_name( $option_name ) {
		if ( ! is_string( $option_name ) || 0 !== strpos( $option_name, Cache_Warmer::$slug . '-' ) ) {
			throw new Exception( Cache_Warmer::$name . ': ' . esc_html__( 'Invalid dynamic option name.', 'cache-warmer' ) );
		}
	}

	/**
	 * Handles interval value change.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 * @param string $option    Option name.
	 *
	 * @throws Exception Exception.
	 */
	public function handle_interval_value_change( $old_value = null, $value = null, $option = null ) {
		if ( null === $value ) { // delete_option_$option (1 args).
			Interval::unschedule();
		} else {
			$value = (int) $value;

			if ( null !== $option ) { // update_option_$option (3 args).
				$old_value = (int) $old_value;

				if ( $value !== $old_value ) {
					Interval::handle_interval_change( $value );
				}
			} else { // add_option_$option (2 args).
				Interval::handle_interval_change( $value );
			}
		}
	}

	/**
	 * Handles posts warming enqueue interval value change.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 * @param string $option    Option name.
	 *
	 * @throws Exception Exception.
	 */
	public function handle_posts_warming_interval_value_change( $old_value = null, $value = null, $option = null ) {
		if ( null === $value ) { // delete_option_$option (1 args).
			Posts_Warming_Interval::unschedule();
		} else {
			$value = (int) $value;

			if ( null !== $option ) { // update_option_$option (3 args).
				$old_value = (int) $old_value;

				if ( $value !== $old_value ) {
					Posts_Warming_Interval::handle_interval_change( $value );
				}
			} else { // add_option_$option (2 args).
				Posts_Warming_Interval::handle_interval_change( $value );
			}
		}
	}

	/**
	 * Validates option name, and if it does not exist, throws an exception.
	 *
	 * @param string $option_name Name of the option to validate.
	 *
	 * @throws Exception Exception.
	 */
	private function validation_option( $option_name ) {
		if ( ! array_key_exists( $option_name, $this->all_options ) ) {
			throw new Exception( Cache_Warmer::$name . ': ' . esc_html__( 'Unknown option name:', 'tmm-wp-plugins-core' ) . ' ' . esc_html( $option_name ) );
		}
	}

	/**
	 * Gets the option value. Returns the default value if the value does not exist.
	 *
	 * @param string $option_name Name of the option to get.
	 *
	 * @return mixed Option value.
	 *
	 * @throws Exception Exception.
	 */
	public function get( $option_name ) {
		try {
			$this->validation_option( $option_name );
		} catch ( Exception $e ) {
			$option_name = Cache_Warmer::$slug . '-' . $option_name;
			$this->validation_option( $option_name );
		}

		$option_data = $this->all_options[ $option_name ];
		$this->load_values();
		$site_id = get_current_blog_id();

		return array_key_exists( $option_name, $this->values[ $site_id ] )
			? $this->values[ $site_id ][ $option_name ]
			: ( array_key_exists( 'default', $option_data ) ? $option_data['default'] : false );
	}

	/**
	 * Sets the option. Update the value if the option for the given name already exists.
	 *
	 * @param string $option_name Name of the option to set.
	 * @param mixed  $value       Value to set for the option.
	 *
	 * @throws Exception Exception.
	 */
	public function set( $option_name, $value ) {
		try {
			$this->validation_option( $option_name );
		} catch ( Exception $e ) {
			$option_name = Cache_Warmer::$slug . '-' . $option_name;
			$this->validation_option( $option_name );
		}

		$option_data = $this->all_options[ $option_name ];

		$this->write_value( $option_name, $value, array_key_exists( 'autoload', $option_data ) ? $option_data['autoload'] : null );
	}

	/**
	 * Deletes the option value.
	 *
	 * @param string $option_name Name of the option to delete.
	 *
	 * @throws Exception Exception.
	 */
	public function delete( $option_name ) {
		try {
			$this->validation_option( $option_name );
		} catch ( Exception $e ) {
			$option_name = Cache_Warmer::$slug . '-' . $option_name;
			$this->validation_option( $option_name );
		}

		$this->delete_value( $option_name );
	}

	/** Read durable row presence, never filtered values or a negative cache. */
	private function stored_row( string $option_name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$option_name
		) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Could not verify Cache Warmer option persistence.' );
		}
		return $row;
	}

	/** Observe native sanitization once, including update's add fallback. */
	private function write_value( string $option_name, $value, $autoload ): void {
		global $wpdb;
		$site = get_current_blog_id();
		$expected = $value;
		$observed = false;
		$adding = false;
		$capture = static function ( $value, $name ) use ( $option_name, $site, &$expected, &$observed ) {
			if ( $name === $option_name && get_current_blog_id() === $site ) {
				$expected = $value;
				$observed = true;
			}
			return $value;
		};
		$capture_add = static function ( $name, $value ) use ( $capture, $option_name, $site, &$adding ) {
			if ( $name === $option_name && get_current_blog_id() === $site ) {
				$adding = true;
				$capture( $value, $name );
			}
		};
		add_filter( 'pre_update_option', $capture, PHP_INT_MAX, 2 );
		add_action( 'add_option', $capture_add, PHP_INT_MAX, 2 );
		$this->invalidate_wordpress_cache( $option_name );
		try {
			$changed = update_option( $option_name, $value, $autoload );
			$write_error = $wpdb->last_error;
			$row = $this->stored_row( $option_name );
			// WordPress deliberately no-ops when false equals an absent default.
			$absent_noop = ! $row && false === $changed && ! $adding && false === $expected;
			if ( $write_error || ! $observed || ( ! $absent_noop && ( ! $row || (string) $row->option_value !== (string) maybe_serialize( $expected ) ) ) ) {
				throw new \RuntimeException( 'Could not persist Cache Warmer option.' );
			}
			if ( $row ) {
				$this->remember_option( $option_name, $expected );
			} else {
				$this->forget_deleted_option( $option_name );
			}
		} catch ( \Throwable $error ) {
			unset( $this->values[ $site ] );
			$this->invalidate_wordpress_cache( $option_name );
			throw $error;
		} finally {
			remove_filter( 'pre_update_option', $capture, PHP_INT_MAX );
			remove_action( 'add_option', $capture_add, PHP_INT_MAX );
		}
	}

	/** Native false is acceptable only when the durable row is absent. */
	private function delete_value( string $option_name ): void {
		$site = get_current_blog_id();
		$this->invalidate_wordpress_cache( $option_name );
		try {
			delete_option( $option_name );
			if ( $this->stored_row( $option_name ) ) {
				throw new \RuntimeException( 'Could not delete Cache Warmer option.' );
			}
			$this->forget_deleted_option( $option_name );
		} catch ( \Throwable $error ) {
			unset( $this->values[ $site ] );
			$this->invalidate_wordpress_cache( $option_name );
			throw $error;
		}
	}
}
