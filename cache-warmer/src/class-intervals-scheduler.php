<?php
/**
 * Class to fix missing intervals.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use Exception;

/**
 * Fixes missing intervals on the interval and on plugin activation.
 */
final class Intervals_Scheduler {

    /**
     * Hook name of the interval.
     */
    const INTERVAL_HOOK_NAME = 'cache_warmer_fix_missing_intervals';

	/**
	 * Register activation recovery and periodic maintenance for the active plugin.
	 *
	 * Deactivation is handled once by the bootstrap's checked cleanup callback.
	 */
    public function __construct() {

        // Schedule the intervals on plugin activation.
        register_activation_hook( CACHE_WARMER_FILE, [ __CLASS__, 'activation_handler'] );
		if ( Cache_Warmer::$options instanceof Options ) {
			$this->post_activation_handler();
		} elseif ( ! did_action( 'plugins_loaded' ) ) {
			add_action( 'plugins_loaded', [ $this, 'post_activation_handler' ], 20 );
		}

        // Schedule the interval to fix missing intervals.
        add_action( 'init', [ __CLASS__, 'schedule' ] );

        // A handler to fix missing intervals.
        add_action( self::INTERVAL_HOOK_NAME, [ __CLASS__, 'fix_missing_intervals' ] );
    }

    /**
     * Schedules the interval action if it isn't scheduled.
     *
     * @throws Exception Exception.
     */
    public static function schedule() {
        as_schedule_recurring_action(
            time(),
            MINUTE_IN_SECONDS * 30,
            self::INTERVAL_HOOK_NAME,
            [],
            '',
            true
        );
    }

    /**
     * Plugin deactivation handler.
     */
    public static function unschedule() {
        as_unschedule_all_actions( self::INTERVAL_HOOK_NAME );
    }

    /**
     * Schedule intervals on plugin activation.
     */
    public static function activation_handler() {
        add_option( 'cache-warmer-intervals-scheduling-post-activation-handled', 'no' );
    }

    /**
     * Site post-activation handler.
     */
    public function post_activation_handler() {
		if ( ! Cache_Warmer::$options instanceof Options ) {
			return;
		}

        if (
            isset( $_GET['activate'] ) ||
			'no' !== Cache_Warmer::$options->get_dynamic( 'cache-warmer-intervals-scheduling-post-activation-handled' )
        ) {
            return;
        }

		Cache_Warmer::$options->delete_dynamic( 'cache-warmer-intervals-scheduling-post-activation-handled' );

        // Schedules intervals.

        if ( did_action( 'action_scheduler_init' ) ) {
            self::fix_missing_intervals();
        } else {
            add_action( 'action_scheduler_init', [ __CLASS__, 'fix_missing_intervals' ] );
        }
    }

    /**
     * Fixes missing intervals.
     *
     * @throws Exception Exception.
     */
    public static function fix_missing_intervals() {
        Interval::schedule();
        Clear_Old_Actions::schedule();
        Posts_Warming_Interval::schedule();
        External_Warmer::schedule_for_all_domains();
        Queue_Lifecycle::schedule_recovery();
    }
}
