<?php
/**
 * Assets for main screen
 *
 * Loads assets (JS, CSS), adds data for them.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer\Assets\Menu\Screens;

use Exception;
use Cache_Warmer\Admin_Menu;

/**
 * Assets class.
 */
final class Main {

    /**
     * Inits.
     */
    public static function init() {
        $class = __CLASS__;
        add_action(
            'admin_enqueue_scripts',
            function () use ( $class ) {
                new $class();
            }
        );
    }

    /**
     * Constructor.
     *
     * @throws Exception Exception.
     */
    public function __construct() {
        $this->styles();
        $this->scripts();
    }

    /**
     * Loads styles.
     */
    private function styles() {
        wp_register_style(
            'cache-warmer-admin-style',
            CACHE_WARMER_URL . 'assets-build/admin/index.css',
            [],
            CACHE_WARMER_VERSION
        );

        wp_enqueue_style(
            'cache-warmer-admin-main-screen-style',
            CACHE_WARMER_URL . 'assets-build/admin/screens/main.css',
            [ 'cache-warmer-admin-style' ],
            CACHE_WARMER_VERSION
        );
    }

	/**
	 * Load controls only when the queue schema and status are available.
	 *
	 * Diagnostic pages require no polling or warming handlers. The state
	 * reader contains retryable storage errors; no completion is fabricated.
	 *
	 * @return void
	 */
    private function scripts() {
		// Diagnostics and unavailable queues have no warming controls to bind.
		$state = Admin_Menu::get_main_screen_state();
		if ( isset( $_GET['tmm-do'] ) || in_array( $state, array( 'updating', 'unavailable' ), true ) ) {
			return;
		}

        // Popper + Tippy.js.

        wp_register_script(
            'cache-warmer-lib-popper',
            CACHE_WARMER_URL . 'libs/@popperjs/core/dist/umd/popper.min.js',
            [],
            '2.11.5',
            true
        );

        wp_register_script(
            'cache-warmer-lib-tippyjs',
            CACHE_WARMER_URL . 'libs/tippy.js/dist/tippy-bundle.umd.min.js',
            [ 'cache-warmer-lib-popper' ],
            '6.3.7',
            true
        );

        wp_register_script(
            'cache-warmer-admin-script',
            CACHE_WARMER_URL . 'assets-build/admin/index.js',
            [],
            CACHE_WARMER_VERSION,
            true
        );

        wp_enqueue_script(
            'cache-warmer-admin-main-screen-script',
            CACHE_WARMER_URL . 'assets-build/admin/screens/main.js',
            [
                'cache-warmer-admin-script',
                'cache-warmer-lib-tippyjs',
            ],
            CACHE_WARMER_VERSION,
            true
        );

        wp_localize_script(
            'cache-warmer-admin-script',
            'wpCacheWarmer',
            [
                'nonceToken'      => wp_create_nonce( 'cache-warmer-menu' ),
                'txt'             => Admin_Menu::get_texts(),
				'lastWarmUpState' => $state,
            ]
        );
    }
}
