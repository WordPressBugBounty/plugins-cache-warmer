<?php
/**
 * A class to extend WP CLI with custom Cache Warmer commands.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use WP_CLI;

/**
 * Registers Cache Warmer commands and their deferred scheduler callbacks.
 *
 * Commands enqueue work through Action Scheduler instead of running a warm-up
 * in the CLI process. When the scheduler has not initialized yet, enqueueing is
 * deferred to its initialization hook. Failed enqueue attempts are reported as
 * command errors and never followed by a success message.
 */
final class Extend_WP_CLI {

	/**
	 * Registers commands and the internal asynchronous action handlers.
	 */
	public function __construct() {
		WP_CLI::add_command( 'cache-warmer start', [ $this, 'start' ] );
		WP_CLI::add_command( 'cache-warmer stop', [ $this, 'stop' ] );

		add_action( 'cache-warmer-start-from-cli', [ '\Cache_Warmer\AJAX', 'start_warm_up' ] );
		add_action( 'cache-warmer-stop-from-cli', [ '\Cache_Warmer\AJAX', 'stop_warm_up' ] );
	}

	/**
	 * Schedules the cache warm-up start command.
	 *
	 * The action is enqueued immediately when Action Scheduler is ready, or from
	 * its initialization hook otherwise. WP-CLI terminates the command with an
	 * error when the scheduler rejects the action.
	 *
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function start() {
		$do = function () {
			if ( ! as_enqueue_async_action( 'cache-warmer-start-from-cli', [ false ] ) ) {
				WP_CLI::error( __( 'Could not schedule the cache warm-up process to start.', 'cache-warmer' ) );
			}
			WP_CLI::success( __( 'Cache warm-up process scheduled to start.', 'cache-warmer' ) );
		};

		if ( did_action( 'action_scheduler_init' ) ) {
			$do();
		} else {
			add_action( 'action_scheduler_init', $do );
		}
	}

	/**
	 * Schedules the cache warm-up stop command.
	 *
	 * The action is enqueued immediately when Action Scheduler is ready, or from
	 * its initialization hook otherwise. WP-CLI terminates the command with an
	 * error when the scheduler rejects the action.
	 *
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function stop() {
		$do = function () {
			if ( ! as_enqueue_async_action( 'cache-warmer-stop-from-cli', [ false ] ) ) {
				WP_CLI::error( __( 'Could not schedule the cache warm-up process to stop.', 'cache-warmer' ) );
			}
			WP_CLI::success( __( 'Cache warm-up process scheduled to stop.', 'cache-warmer' ) );
		};

		if ( did_action( 'action_scheduler_init' ) ) {
			$do();
		} else {
			add_action( 'action_scheduler_init', $do );
		}
	}
}
