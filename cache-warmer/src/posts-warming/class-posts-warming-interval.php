<?php
/**
 * Maintain delivery of the durable post-warming queue.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

/**
 * Continue post batches using current settings and shared scheduler admission.
 */
final class Posts_Warming_Interval {

	/**
	 * Successfully executed post actions awaiting their durable completion hook.
	 *
	 * This request-local dispatch optimization avoids reading every unrelated
	 * action. It owns no queue data: periodic maintenance recovers delivery if
	 * a process dies before completion or scheduling.
	 *
	 * @var array<int,bool>
	 */
	private static $awaiting_completion = array();

	/**
	 * Register completion and failure continuations for this queue only.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'action_scheduler_after_execute', array( __CLASS__, 'after_execute' ), 10, 2 );
		add_action( 'action_scheduler_completed_action', array( __CLASS__, 'after_complete' ) );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'after_failure' ) );
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'after_failure' ) );
	}

	/**
	 * Identify our successfully executed action without an extra database read.
	 *
	 * Admission must wait until mark_complete finishes: the shared scheduler
	 * helper intentionally refuses duplicates while the current job is running.
	 *
	 * @param int                     $action_id Executed action ID.
	 * @param \ActionScheduler_Action $action Executed action object.
	 * @return void
	 */
	public static function after_execute( $action_id, $action ): void {
		if ( Posts_Enqueue::INTERVAL_HOOK_NAME === $action->get_hook() ) {
			self::$awaiting_completion[ (int) $action_id ] = true;
		}
	}

	/**
	 * Schedule the successor after successful durable completion, including empty batches.
	 *
	 * Reads current shared settings, so changing or disabling the interval during
	 * execution cannot resurrect the previous cadence. Existing pending delivery
	 * is preserved by the shared helper. Unrelated actions require no SQL here.
	 *
	 * @param int $action_id Completed action ID.
	 * @return void
	 * @throws \Throwable When continuation admission fails; maintenance can retry.
	 */
	public static function after_complete( $action_id ): void {
		if ( ! isset( self::$awaiting_completion[ (int) $action_id ] ) ) {
			return;
		}
		unset( self::$awaiting_completion[ (int) $action_id ] );
		self::schedule();
	}

	/**
	 * Recover failed or timed-out deliveries without a rapid failure loop.
	 *
	 * Queue payloads and failure history remain untouched. Failures use at least
	 * a thirty-minute interval; the next successful drain restores normal cadence.
	 * The failure hooks lack an action object, so fetch only on this error path.
	 *
	 * @param int $action_id Failed action ID.
	 * @return void
	 * @throws \Throwable When action lookup or retry admission fails.
	 */
	public static function after_failure( $action_id ): void {
		unset( self::$awaiting_completion[ (int) $action_id ] );
		$action = \ActionScheduler::store()->fetch_action( (int) $action_id );
		if ( Posts_Enqueue::INTERVAL_HOOK_NAME !== $action->get_hook() ) {
			return;
		}
		$minutes = (int) Cache_Warmer::$options->get( 'cache-warmer-setting-posts-warming-enqueue-interval' );
		if ( $minutes > 0 ) {
			self::schedule( max( 30, $minutes ) );
		}
	}

	/**
	 * Ensure one single delivery at the configured cadence.
	 *
	 * Core serializes admission in the shared database and preserves pending or
	 * running work. Completion hooks chain deliveries, including legacy single
	 * actions; maintenance recovers interrupted scheduling. Non-positive values
	 * disable admission instead of falling back to an enabled default.
	 *
	 * @param int|false $value Interval in minutes, or false to read current settings.
	 * @return void
	 * @throws \Throwable When shared scheduler lookup or admission fails.
	 */
	public static function schedule( $value = false ) {
		$minutes = false === $value ? (int) Cache_Warmer::$options->get( 'cache-warmer-setting-posts-warming-enqueue-interval' ) : (int) $value;
		if ( $minutes > 0 ) {
			Utils::schedule_the_undrifting_interval( $minutes * MINUTE_IN_SECONDS, Posts_Enqueue::INTERVAL_HOOK_NAME );
		}
	}

	/**
	 * Cancel pending post-queue deliveries without removing queued posts.
	 *
	 * @return void
	 */
	public static function unschedule() {
		as_unschedule_all_actions( Posts_Enqueue::INTERVAL_HOOK_NAME );
	}

	/**
	 * Replace the pending schedule after an explicit interval setting change.
	 *
	 * @param int $value New interval in minutes; non-positive values disable it.
	 * @return void
	 * @throws \Throwable When replacing the schedule fails.
	 */
	public static function handle_interval_change( $value ) {
		self::unschedule();
		self::schedule( $value );
	}
}
