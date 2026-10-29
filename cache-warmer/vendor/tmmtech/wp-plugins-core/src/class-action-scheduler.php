<?php
/**
 * Shared Action Scheduler admission and duplicate-repair wrapper.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

/**
 * Coordinates Action Scheduler writes through shared-database locks.
 */
final class Action_Scheduler {

	/**
	 * Admits one recurring action or repairs exact pending duplicates.
	 *
	 * The exact hook, argument, and group namespace is protected by a shared
	 * database lock. The earliest pending action is retained and later pending
	 * duplicates are cancelled; an existing running action also prevents new
	 * admission. Lock contention and an unavailable Action Scheduler runtime
	 * leave the schedule unchanged. Work runs immediately after Action Scheduler
	 * initialization, or is deferred to that hook when initialization is pending.
	 *
	 * @param int    $timestamp           When the first instance of the job will run.
	 * @param int    $interval_in_seconds How long to wait between runs.
	 * @param string $hook                The hook to trigger.
	 * @param array  $args                Arguments to pass when the hook triggers.
	 * @param string $group               The group to assign this job to.
	 * @param bool   $unique              Whether Action Scheduler should apply its native uniqueness policy.
	 * @param int    $priority            Lower values take precedence over higher values. Defaults to 10; accepts 0-255.
	 * @return void
	 * @throws \Throwable When immediate scheduler lookup, admission, cancellation, or lock cleanup fails.
	 */
	public static function schedule_an_interval( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		if ( ! self::is_available() ) {
			return;
		}

		$function = function () use ( $timestamp, $interval_in_seconds, $hook, $args, $group, $unique, $priority ) {
			$resource = self::get_lock_resource( $hook, $args, $group );

			if ( ! Database_Lock::acquire( 'action-scheduler', $resource, 5 ) ) {
				return;
			}

			try {
				$pending = self::get_matching_actions( $hook, $args, $group, \ActionScheduler_Store::STATUS_PENDING );
				if ( ! $pending && ! self::get_matching_actions( $hook, $args, $group, \ActionScheduler_Store::STATUS_RUNNING ) ) {
					as_schedule_recurring_action(
						$timestamp,
						$interval_in_seconds,
						$hook,
						$args,
						$group,
						$unique,
						$priority
					);
				} else {
					self::remove_duplicate_actions( $pending );
				}
			} finally {
				Database_Lock::release( 'action-scheduler', $resource );
			}
		};

		if ( did_action( 'action_scheduler_init' ) ) {
			$function();
		} else {
			add_action( 'action_scheduler_init', $function );
		}
	}

	/**
	 * Admits one single action or repairs exact pending duplicates.
	 *
	 * The exact hook, argument, and group namespace is protected by a shared
	 * database lock. The earliest pending action is retained and later pending
	 * duplicates are cancelled; an existing running action also prevents new
	 * admission. Lock contention and an unavailable Action Scheduler runtime
	 * leave the schedule unchanged. Work runs immediately after Action Scheduler
	 * initialization, or is deferred to that hook when initialization is pending.
	 *
	 * @param int    $timestamp When the action will run.
	 * @param string $hook      The hook to trigger.
	 * @param array  $args      Arguments to pass when the hook triggers.
	 * @param string $group     The group to assign this job to.
	 * @param bool   $unique    Whether Action Scheduler should apply its native uniqueness policy.
	 * @param int    $priority  Lower values take precedence over higher values. Defaults to 10; accepts 0-255.
	 * @return void
	 * @throws \Throwable When immediate scheduler lookup, admission, cancellation, or lock cleanup fails.
	 */
	public static function schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		if ( ! self::is_available() ) {
			return;
		}

		$function = function () use ( $timestamp, $hook, $args, $group, $unique, $priority ) {
			$resource = self::get_lock_resource( $hook, $args, $group );

			if ( ! Database_Lock::acquire( 'action-scheduler', $resource, 5 ) ) {
				return;
			}

			try {
				$pending = self::get_matching_actions( $hook, $args, $group, \ActionScheduler_Store::STATUS_PENDING );
				if ( ! $pending && ! self::get_matching_actions( $hook, $args, $group, \ActionScheduler_Store::STATUS_RUNNING ) ) {
					as_schedule_single_action(
						$timestamp,
						$hook,
						$args,
						$group,
						$unique,
						$priority
					);
				} else {
					self::remove_duplicate_actions( $pending );
				}
			} finally {
				Database_Lock::release( 'action-scheduler', $resource );
			}
		};

		if ( did_action( 'action_scheduler_init' ) ) {
			$function();
		} else {
			add_action( 'action_scheduler_init', $function );
		}
	}

	/**
	 * Retains the earliest pending action and cancels only its exact duplicates.
	 *
	 * Never delete all work and attempt replacement admission: admission may
	 * return zero or throw, losing every existing job. Keeping the original
	 * also preserves its schedule and payload, as ordinary idempotent calls do.
	 *
	 * @param array $actions Exact pending action IDs, earliest first.
	 * @return void
	 */
	private static function remove_duplicate_actions( $actions ) {
		array_shift( $actions );
		foreach ( $actions as $action_id ) {
			\ActionScheduler::store()->cancel_action( $action_id );
		}
	}

	/**
	 * Returns action IDs in the exact namespace, including the default group.
	 *
	 * Action Scheduler treats an empty query group as a wildcard, so inspect
	 * returned groups before using results for admission or cancellation.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Action arguments.
	 * @param string $group Action group.
	 * @param string $status Action status.
	 * @return array Exact action IDs, earliest first.
	 */
	private static function get_matching_actions( $hook, $args, $group, $status ) {
		$actions  = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => $status,
				'per_page' => -1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			OBJECT
		);
		$matching = array();
		foreach ( $actions as $action_id => $action ) {
			if ( (string) $group === $action->get_group() ) {
				$matching[] = $action_id;
			}
		}
		return $matching;
	}

	/**
	 * Returns a deterministic lock resource for one logical schedule.
	 *
	 * @param string $hook  Action hook.
	 * @param array  $args  Action arguments.
	 * @param string $group Action group.
	 * @return string Lock resource.
	 */
	private static function get_lock_resource( $hook, $args, $group ) {
		// Both APIs inspect the same hook/arguments/group namespace.
		return hash( 'sha256', wp_json_encode( array( $hook, $args, $group ) ) );
	}

	/**
	 * Whether the Action Scheduler runtime API is ready.
	 *
	 * @return bool
	 */
	private static function is_available() {
		return class_exists( 'ActionScheduler' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_get_scheduled_actions' );
	}
}
