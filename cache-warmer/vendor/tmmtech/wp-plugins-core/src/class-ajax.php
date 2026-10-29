<?php
/**
 * AJAX handlers.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

use Exception;

/**
 * Manages AJAX.
 */
final class AJAX {

    /**
     * Main plugin.
     *
     * @var WP_Plugins_Core
     */
    public $core;

    /**
     * Adds the menu and inits assets loading for it.
     *
     * @param WP_Plugins_Core $core Plugin core.
     */
    public function __construct( $core ) {
        $this->core = $core;

        $this->add_ajax_events();
    }

    /**
     * Loads AJAX handlers.
     */
    private function add_ajax_events() {
        $admin_ajax_events = [
            'save_viewed_notifications',
        ];

        foreach ( $admin_ajax_events as $ajax_event ) {
            add_action( "wp_ajax_wp_plugins_core_$ajax_event", [ $this, $ajax_event ] );
        }
    }

	/**
	 * Validates and persists notification dismissals for the addressed consumer.
	 *
	 * The endpoint requires an administrator nonce, accepts at most 100 visible
	 * dismissible IDs, and merges them under the shared option lock. WordPress'
	 * JSON response helpers terminate successful and rejected requests.
	 *
	 * @return void
	 * @throws Exception When option lookup fails before a JSON response is sent.
	 */
	public function save_viewed_notifications() {
		// Every consumer registers this shared hook. Only the addressed owner
		// may respond; otherwise the first plugin would dismiss another's IDs.
		if ( isset( $_REQUEST['pluginSlug'] ) ) {
			if ( ! is_string( $_REQUEST['pluginSlug'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid plugin identity.', 'tmm-wp-plugins-core' ) ), 400 );
			}
			$plugin_slug = wp_unslash( $_REQUEST['pluginSlug'] );
			if ( sanitize_key( $plugin_slug ) !== $plugin_slug || $this->core->plugin_slug !== $plugin_slug ) {
				return;
			}
		} elseif ( ! $this->is_unambiguous_legacy_request() ) {
			wp_send_json_error( array( 'message' => __( 'A plugin identity is required.', 'tmm-wp-plugins-core' ) ), 400 );
		}

		check_ajax_referer( 'tmm-wp-plugins-core', 'nonceToken' );

		// Dismissals are shared by the entire site, not private user metadata.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to dismiss site notifications.', 'tmm-wp-plugins-core' ) ), 403 );
		}

		if ( ! isset( $_REQUEST['ids'] ) || ! is_string( $_REQUEST['ids'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notification IDs.', 'tmm-wp-plugins-core' ) ), 400 );
		}

		// Decode objects as objects: associative decoding would accept {} or
		// {"0":17} as a list and erase the JSON input-type boundary.
		$notification_ids = json_decode( wp_unslash( $_REQUEST['ids'] ) );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $notification_ids ) || count( $notification_ids ) > 100
			|| ( $notification_ids && array_keys( $notification_ids ) !== range( 0, count( $notification_ids ) - 1 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notification IDs.', 'tmm-wp-plugins-core' ) ), 400 );
		}

		try {
			$notifications = $this->core->options->get( 'notifications' );
			$ids           = array();
			foreach ( $notification_ids as $id ) {
				if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! is_array( $notifications )
					|| ! array_key_exists( $id, $notifications ) || ! is_array( $notifications[ $id ] ) ) {
					wp_send_json_error( array( 'message' => __( 'Unknown notification ID.', 'tmm-wp-plugins-core' ) ), 400 );
				}
				$notification = $notifications[ $id ];
				if ( ! isset( $notification['roles'], $notification['type'] ) || ! is_array( $notification['roles'] )
					|| count( array_filter( $notification['roles'], 'is_string' ) ) !== count( $notification['roles'] )
					|| ! array_intersect( wp_get_current_user()->roles, $notification['roles'] )
					|| ! in_array( $notification['type'], array( 'dismissable', 'drip' ), true ) ) {
					wp_send_json_error( array( 'message' => __( 'This notification cannot be dismissed.', 'tmm-wp-plugins-core' ) ), 400 );
				}
				// Match PHP's array-key normalization so integer IDs still
				// compare strictly with persisted numeric notification keys.
				$key   = array( $id => true );
				$ids[] = key( $key );
			}

			$this->core->options->mutate(
				'viewed-notifications',
				function ( $viewed ) use ( $ids ) {
					if ( ! is_array( $viewed ) ) {
						throw new \RuntimeException( 'Invalid persisted notification dismissals.' );
					}
					$merged = array();
					foreach ( array_merge( $viewed, $ids ) as $id ) {
						if ( ! is_int( $id ) && ! is_string( $id ) ) {
							throw new \RuntimeException( 'Invalid persisted notification dismissal ID.' );
						}
						$merged[ $id ] = true;
					}
					return array_keys( $merged );
				}
			);
		} catch ( \RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => __( 'Notification dismissals could not be saved. Please retry.', 'tmm-wp-plugins-core' ) ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Determines whether an unaddressed legacy request has one possible owner.
	 *
	 * @return bool True when this consumer is the only Core AJAX handler owner.
	 */
	private function is_unambiguous_legacy_request() {
		global $wp_filter;

		$hook  = $wp_filter['wp_ajax_wp_plugins_core_save_viewed_notifications'] ?? null;
		$slugs = array();
		if ( $hook instanceof \WP_Hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$handler = $callback['function'];
					if ( is_array( $handler ) && $handler[0] instanceof self ) {
						$slugs[ $handler[0]->core->plugin_slug ] = true;
					}
				}
			}
		}

		return 1 === count( $slugs ) && isset( $slugs[ $this->core->plugin_slug ] );
	}
}
