<?php
/**
 * A class for notifications.
 *
 * @package WP-Plugins-Core
 */

namespace WP_Plugins_Core;

use Exception;

/**
 * Notifications class.
 */
final class Notifications {

    /**
     * Notifications URL.
     *
     * @var string
     */
    public $notifications_url;

    /**
     * Interval hook name to fetch notification for the plugin.
     *
     * @var string
     */
    public $interval_hook_name;

    /**
     * Interval to fetch notification for the plugin.
     *
     * @var int
     */
    const INTERVAL = HOUR_IN_SECONDS;

    /**
     * Main plugin.
     *
     * @var WP_Plugins_Core
     */
    public $core;

    /**
     * Constructor.
     *
     * @param WP_Plugins_Core $core Plugins core.
     */
    public function __construct( WP_Plugins_Core $core ) {
        $this->core = $core;

        $this->notifications_url = 'https://wpplugins-midlayer.tmm-technology.com/api/notifications/' . $this->core->plugin_slug . '/' . $this->core->plugin_version;

        $this->interval_hook_name = $this->core->plugin_slug . '_fetch_notifications';

        // Interval action.
		add_action( $this->interval_hook_name, array( $this, 'fetch_scheduled_notifications' ) );

        // Schedule the action.
        add_action( 'init', [ $this, 'schedule_interval' ] );

        // Unschedule interval action on plugin deactivation.
        register_deactivation_hook( $this->core->plugin_file, [ $this, 'unschedule' ] );

        // Show notifications.
        add_action( 'admin_notices', [ $this, 'show_notifications' ] );
    }

	/**
	 * Schedules one recurring notification fetch through the shared wrapper.
	 *
	 * A missing series starts at least one hour in the future. In particular,
	 * rebuilding a series stopped by Action Scheduler must not create due work
	 * on every init. Existing pending work retains its original schedule.
	 *
	 * @return void
	 */
	public function schedule_interval() {
		Action_Scheduler::schedule_an_interval(
			time() + self::INTERVAL,
			self::INTERVAL,
			$this->interval_hook_name,
			array(),
			'',
			true
		);
	}

    /**
     * Plugin deactivation handler.
     */
    public function unschedule() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $this->interval_hook_name );
        }
    }

	/**
	 * Fetches notifications only after atomically reserving a durable retry window.
	 *
	 * Each consumer has an independent non-autoloaded shared-database option.
	 * Attempts reserve exponential backoff before HTTP: one, two, four, eight,
	 * sixteen, then twenty-four hours. A crash therefore retains its cooldown.
	 * Duplicate delivery during cooldown succeeds without HTTP or feed mutation.
	 * Success resets backoff to hourly. No database lock is held during HTTP;
	 * a token prevents a delayed worker from resetting a newer reservation.
	 * Deactivation deliberately retains the window; no history is deleted.
	 *
	 * @return void
	 * @throws \Throwable When reservation, transport, validation or persistence fails.
	 */
	public function fetch_scheduled_notifications() {
		$token = wp_generate_uuid4();
		$now   = time();
		$state = $this->core->options->mutate(
			'notification-fetch-state',
			static function ( $previous ) use ( $now, $token ) {
				if ( is_array( $previous ) && isset( $previous['next_attempt'] ) && $previous['next_attempt'] > $now ) {
					return $previous;
				}
				$attempt = is_array( $previous ) && isset( $previous['attempt'] ) ? min( 6, max( 0, (int) $previous['attempt'] ) + 1 ) : 1;
				return array(
					'attempt'      => $attempt,
					'next_attempt' => $now + min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** ( $attempt - 1 ) ) ),
					'token'        => $token,
				);
			}
		);
		if ( ! is_array( $state ) || ! isset( $state['token'] ) || $token !== $state['token'] ) {
			return;
		}

		$this->get_notifications();
		$this->core->options->mutate(
			'notification-fetch-state',
			static function ( $current ) use ( $token ) {
				if ( is_array( $current ) && isset( $current['token'] ) && $token === $current['token'] ) {
					$current['attempt']      = 0;
					$current['next_attempt'] = time() + HOUR_IN_SECONDS;
				}
				return $current;
			}
		);
	}

	/**
	 * Fetches, validates, sanitizes, and stores the current notification feed.
	 *
	 * Existing stored notifications remain untouched when transport, status,
	 * JSON, or record validation fails.
	 *
	 * @return void
	 * @throws Exception When the remote response cannot safely replace the feed.
	 */
	public function get_notifications() {
		$response = wp_remote_get(
			$this->notifications_url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 262144,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = substr( $response->get_error_message(), 0, 256 );
			$error_object  = array(
				'Description'   => "Can't connect to the Notifications API server.",
				'Endpoint'      => $this->notifications_url,
				'Error message' => $error_message,
			);
			throw new Exception( wp_json_encode( array( 'error' => $error_object ) ) );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$response_code = wp_remote_retrieve_response_code( $response );
			$error_object  = array(
				'Description'   => 'Notifications API Response Code is not 200.',
				'Endpoint'      => $this->notifications_url,
				'Response Code' => $response_code,
			);
			throw new Exception( wp_json_encode( array( 'error' => $error_object ) ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			throw new Exception( 'No response body.' );
		}

		$notifications = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $notifications ) ) {
			throw new Exception( 'Invalid notifications JSON.' );
		}

		foreach ( $notifications as $notification ) {
			if ( ! self::is_valid_notification( $notification ) ) {
				throw new Exception( 'Invalid notification record.' );
			}
		}

		$this->core->options->set( 'notifications', Sanitize::sanitize_array( $notifications ) );
	}

	/**
	 * Validates the shape consumed by rendering and dismissal authorization.
	 *
	 * @param mixed $notification Candidate remote or persisted record.
	 * @return bool True when all required scalar fields and role strings exist.
	 */
	private static function is_valid_notification( $notification ) {
		if ( ! is_array( $notification ) || ! isset( $notification['message'], $notification['type'], $notification['level'], $notification['roles'] )
			|| ! is_string( $notification['message'] ) || ! is_string( $notification['type'] )
			|| ! is_string( $notification['level'] ) || ! is_array( $notification['roles'] ) ) {
			return false;
		}

		foreach ( $notification['roles'] as $role ) {
			if ( ! is_string( $role ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Renders valid, visible notifications for the current user's roles.
	 *
	 * Corrupt persisted records are skipped, viewed IDs are excluded, and every
	 * notice carries the consumer slug required by the shared browser handler.
	 *
	 * @return void
	 * @throws Exception When reading a configured Core option fails.
	 */
	public function show_notifications() {
		$notifications        = $this->core->options->get( 'notifications' );
		$viewed_notifications = $this->core->options->get( 'viewed-notifications' );

		$notifications_to_show = array();

		if ( ! is_array( $notifications ) ) {
			return;
		}
		$viewed_notifications = is_array( $viewed_notifications ) ? $viewed_notifications : array();

		foreach ( $notifications as $notification_id => $notification ) {
			if (
				self::is_valid_notification( $notification ) &&
				! in_array( $notification_id, $viewed_notifications, true ) &&
				count( array_intersect( wp_get_current_user()->roles, $notification['roles'] ) )
			) {
				$notifications_to_show[ $notification_id ] = $notification;
			}
		}

		$print_notification = function ( $notification_id, $notification ) {
			?>
			<div class="<?php echo 'drip' === $notification['type'] ? 'drip ' : ''; ?>wp-plugins-core-notification
				notice notice-<?php echo esc_attr( 'notice' === $notification['level'] ? 'info' : $notification['level'] ); ?>
				<?php echo 'dismissable' === $notification['type'] ? 'is-dismissible' : ''; ?>"
				data-notification-id="<?php echo esc_attr( $notification_id ); ?>"
				data-plugin-slug="<?php echo esc_attr( $this->core->plugin_slug ); ?>">
				<p>
					<b><?php echo esc_html( $this->core->plugin_name ); ?>: </b><?php echo esc_html( $notification['message'] ); ?>
				</p>
			</div>
			<?php
		};

		// Prints non-drip notifications.
		foreach ( $notifications_to_show as $notification_id => $notification ) {
			$print_notification( $notification_id, $notification );
		}
	}
}
