<?php
/**
 * Assets
 *
 * Loads assets (JS, CSS), adds data for them.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer\Assets;

/**
 * Assets class.
 */
final class Assets {

	/**
	 * Register background chart refresh on every request, then screen-specific assets.
	 *
	 * The refresh callback must also exist in cron/CLI requests without an admin
	 * screen. Registration does not query logs or schedule a refresh.
	 */
	public function __construct() {
		add_action( 'cache_warmer_refresh_dashboard_statistics', array( Dashboard::class, 'refresh_statistics' ) );
        add_action(
            'current_screen',
            function () {
                $screen = get_current_screen();

                // Dashboard assets.

                if ( 'dashboard' === $screen->id ) {
                    Dashboard::init();
                }

                // Admin publish-box assets.

                if ( 'post' === $screen->base ) {
                    new Menu\Screens\Post;
                }
            }
        );
    }
}
