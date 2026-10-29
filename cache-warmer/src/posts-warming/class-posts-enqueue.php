<?php
/**
 * Registers and drains the durable post-warming queue.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use Exception;
use Throwable;
use WP_Post;

/**
 * Persists published posts for warming and claims them from shared storage.
 */
final class Posts_Enqueue {

	/** Action Scheduler hook that drains queued post IDs. */
	const INTERVAL_HOOK_NAME = 'cache_warmer_process_posts_enqueue';

	/**
	 * Registers publication and queue-drain callbacks.
	 *
	 * Publication tracking is enabled only when the corresponding setting is
	 * active. The drain callback remains registered so already persisted work
	 * can complete after a setting change.
	 */
	public function __construct() {
		if ( Cache_Warmer::$options->get( 'cache-warmer-setting-warm-up-posts' ) ) {
			add_action( 'transition_post_status', [ $this, 'populate_enqueue' ], 10, 3 );
		}
		add_action( $this::INTERVAL_HOOK_NAME, [ $this, 'handle_enqueue' ] );
	}

	/**
	 * Adds a newly published post to the durable shared queue.
	 *
	 * Repeated transitions are deduplicated by the database queue. Transitions
	 * away from publish are ignored; the old status remains part of the
	 * WordPress hook signature.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 *
	 * @return void
	 * @throws Exception When the queue request cannot be persisted.
	 */
	public function populate_enqueue( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status ) {
			if ( ! DB::enqueue_post_for_warming( (int) $post->ID ) ) {
				throw new Exception( esc_html__( 'Could not persist the post warming request.', 'cache-warmer' ) );
			}
		}
	}

	/**
	 * Claims and warms one durable batch of published posts.
	 *
	 * A database lock serializes claims across interchangeable web nodes. The
	 * claim is acknowledged only after URL resolution and warm-up admission;
	 * failures release it for retry. The lock is always released, including
	 * when no work exists or a callback throws.
	 *
	 * @return void
	 * @throws Throwable When claiming, warming, or acknowledging the batch fails.
	 */
	public function handle_enqueue() {
		if ( ! DB::acquire_posts_queue_lock() ) {
			return;
		}

		$claim = false;

		try {
			$claim = DB::claim_posts_for_warming();
			if ( false === $claim ) {
				throw new Exception( esc_html__( 'Could not claim the post warming queue.', 'cache-warmer' ) );
			}

			if ( ! $claim['post_ids'] ) {
				return;
			}

			$posts_urls = array_values(
				array_unique(
					array_filter(
						array_map( 'get_permalink', $claim['post_ids'] )
					)
				)
			);

			if ( $posts_urls ) {
				AJAX::start_warm_up( false, true, $posts_urls );
			}

			if ( ! DB::complete_posts_queue_claim( $claim['token'] ) ) {
				throw new Exception( esc_html__( 'Could not acknowledge the post warming queue.', 'cache-warmer' ) );
			}
		} catch ( Throwable $throwable ) {
			if ( is_array( $claim ) && isset( $claim['token'] ) ) {
				DB::release_posts_queue_claim( $claim['token'] );
			}

			throw $throwable;
		} finally {
			DB::release_posts_queue_lock();
		}
	}
}
