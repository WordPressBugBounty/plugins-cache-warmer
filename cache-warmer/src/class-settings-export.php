<?php
/**
 * Class for Settings Export.
 *
 * Many methods here are inspired by WC_CSV_Exporter class.
 *
 * @package Cache-Warmer
 */

namespace Cache_Warmer;

use Exception;

/**
 * Settings Export Class.
 */
final class Settings_Export {

	/**
	 * Serves a settings export requested from the administrator menu.
	 *
	 * The request must carry the menu nonce and exact export action, and the
	 * current user must be able to manage options. Nonmatching requests return
	 * without output. An accepted request sends download headers and JSON before
	 * terminating execution; it does not enforce a particular HTTP method.
	 *
	 * @return void
	 * @throws Exception When an accepted settings read propagates an option-layer failure.
	 */
	public static function add_listeners() {
		if (
			current_user_can( 'manage_options' ) &&
			isset( $_GET['nonce'], $_GET['cache-warmer-action'] ) && // @codingStandardsIgnoreLine
			wp_verify_nonce( wp_unslash( $_GET['nonce'] ), 'cache-warmer-menu' ) && // @codingStandardsIgnoreLine
			'export-settings' === wp_unslash( $_GET['cache-warmer-action'] ) // @codingStandardsIgnoreLine
		) {
			self::export();
		}
	}

    /**
     * Does the export.
     *
     * @throws Exception Exception.
     */
    private static function export() {
        self::send_headers();
        self::send_content( self::get_json_data() );
        die();
    }

    /**
     * Returns JSON filename.
     *
     * @return string
     */
    private static function get_filename() {
        return 'cache-warmer-settings-' . time() . '.json';
    }

    /**
     * Sends the export headers.
     */
    private static function send_headers() {
        if ( function_exists( 'gc_enable' ) ) {
            gc_enable(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.gc_enableFound
        }
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', 1 ); // @codingStandardsIgnoreLine
        }
        @ini_set( 'zlib.output_compression', 'Off' ); // @codingStandardsIgnoreLine
        @ini_set( 'output_buffering', 'Off' ); // @codingStandardsIgnoreLine
        @ini_set( 'output_handler', '' ); // @codingStandardsIgnoreLine
        ignore_user_abort( true );
        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 0 );
        }
        if ( function_exists( 'wc_nocache_headers' ) ) {
            wc_nocache_headers();
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . self::get_filename() );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
    }

    /**
     * Gets JSON data for export.
     *
     * @return string All plugin settings options with their values.
     *
     * @throws Exception Exception.
     */
    private static function get_json_data() {
        $data = [];
        foreach ( Cache_Warmer::$options->settings_options as $option ) {
            $data[ $option ] = Cache_Warmer::$options->get( $option );
        }
        return wp_json_encode( $data );
    }

    /**
     * Sends the export content.
     *
     * @param string $json_data JSON content.
     */
    private static function send_content( $json_data ) {
        echo $json_data; // @codingStandardsIgnoreLine
    }
}
