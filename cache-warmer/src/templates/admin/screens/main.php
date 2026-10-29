<?php
/**
 * Renders the Cache Warmer status screen and guarded database-maintenance panel.
 *
 * The normal screen exposes current lifecycle state, the latest formatted log,
 * and stable browser-test selectors. Maintenance actions require administrator
 * capability; destructive actions additionally require POST and an action-bound
 * nonce. The template reads and may mutate plugin options and tables only when a
 * maintenance request is explicitly accepted.
 *
 * @package Cache-Warmer
 */

use Cache_Warmer\DB;
use Cache_Warmer\Logging;
use Cache_Warmer\Admin_Menu;
use Cache_Warmer\Cache_Warmer;

/*
 * Print DB management panel when "tmm-do" URL param is set.
 */
if ( isset( $_GET['tmm-do'] ) ) {
    $tmm_do = sanitize_text_field( wp_unslash( $_GET['tmm-do'] ) );

    $maintenance_actions = [ 'apply-all-migrations', 'reset-migrations-log', 'delete-db-tables' ];
    if (
        ! current_user_can( 'manage_options' ) ||
        (
            in_array( $tmm_do, $maintenance_actions, true ) &&
            (
                'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
                ! isset( $_POST['_wpnonce'] ) ||
                ! is_string( $_POST['_wpnonce'] ) ||
                ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'cache-warmer-maintenance-' . $tmm_do )
            )
        )
    ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'This maintenance operation requires an authorized form submission.', 'cache-warmer' ) . '</p></div>';
        return;
    }

    /**
     * Prints migrations log.
     */
    $print_migrations_log = function() {
        $db_migration_log = Cache_Warmer::$options->get( 'db-migration-log' );

        ?>

        <div class="cache-warmer-row">
            last-db-success-migration-number = <?php echo esc_html( Cache_Warmer::$options->get( 'last-db-success-migration-number' ) ); ?>
        </div>
        <div class="cache-warmer-row">
            last-db-migration-success = <?php echo Cache_Warmer::$options->get( 'last-db-migration-success' ) ? 'yes' : 'no'; ?>
        </div>

        <br>
        <br>
        <b>Migrations log:</b>
        <br>
        <br>

        <table>
            <thead>
            <tr>
                <th>Migration #</th>
                <th>Query</th>
                <th>Time Before</th>
                <th>Time After</th>
                <th>Error</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $db_migration_log as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log['migration_number'] ); ?></td>
                    <td><?php echo esc_html( $log['query'] ); ?></td>
                    <td><?php echo esc_html( $log['time_before'] ); ?></td>
                    <td><?php echo esc_html( $log['time_after'] ); ?></td>
                    <td><?php echo esc_html( $log['error'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
    };

    ?>
    <div class="wrap">
        <div class="cache-warmer-container">
            <div class="cache-warmer-row">
                <a class="cache-warmer-db-debug-button <?php echo esc_attr( 'db-migrations-log' === $tmm_do ? 'active' : '' ); ?>"
                    href="<?php echo esc_attr( admin_url( 'admin.php?page=cache-warmer&tmm-do=db-migrations-log' ) ); ?>">Migrations log</a>
                <?php foreach ( [
                    'apply-all-migrations' => __( 'Apply all migrations', 'cache-warmer' ),
                    'reset-migrations-log' => __( 'Reset migrations log', 'cache-warmer' ),
                    'delete-db-tables'     => __( 'Delete plugin DB tables', 'cache-warmer' ),
                ] as $maintenance_action => $maintenance_label ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cache-warmer&tmm-do=' . $maintenance_action ) ); ?>">
                        <?php wp_nonce_field( 'cache-warmer-maintenance-' . $maintenance_action ); ?>
                        <button type="submit" class="cache-warmer-db-debug-button <?php echo esc_attr( $maintenance_action === $tmm_do ? 'active' : '' ); ?>"><?php echo esc_html( $maintenance_label ); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <br><br>
    <?php

    if ( 'db-migrations-log' === $tmm_do ) {
        $print_migrations_log();
    } elseif ( 'apply-all-migrations' === $tmm_do ) {
        Cache_Warmer::$options->delete( 'last-db-success-migration-number' );
        Cache_Warmer::$options->delete( 'last-db-migration-success' );
        DB::do_migrations();

        ?>
        <br>
        <br>
        <b>All DB migrations were applied.</b>
        <hr>
        <?php $print_migrations_log(); ?>
        <?php
    } elseif ( 'reset-migrations-log' === $tmm_do ) {
        Cache_Warmer::$options->delete( 'db-migration-log' );

        ?>
        <br>
        <br>
        <b>Migrations log was reset.</b>
        <hr>
        <?php $print_migrations_log(); ?>
        <?php
    } elseif ( 'delete-db-tables' === $tmm_do ) {
        Cache_Warmer::$options->delete( 'last-db-success-migration-number' );
        Cache_Warmer::$options->delete( 'last-db-migration-success' );

        Cache_Warmer::$options->delete( 'db-migration-log' );

        global $wpdb;

        $tables_prefix = DB::get_tables_prefix();

        foreach ( DB::get_tables() as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$tables_prefix}{$table}" ); // @codingStandardsIgnoreLine
        }

        ?>
        <br>
        <br>
        <b>All plugin DB tables were deleted.</b>
        <hr>
        <?php
    } else {
        ?>
        <b>No such a command.</b>
        <?php
    }

    ?>
        </div>
    </div>
    <?php

    return;
}

$state = Admin_Menu::get_main_screen_state();
if ( in_array( $state, array( 'updating', 'unavailable' ), true ) ) {
	?>
	<div class="wrap cache-warmer-container">
		<h1><?php echo esc_html( Cache_Warmer::$name ); ?></h1>
		<div class="notice notice-error inline"><p>
			<?php
			if ( 'updating' === $state ) {
				esc_html_e( 'The database update is not complete. Warming controls are unavailable until it succeeds.', 'cache-warmer' );
			} else {
				esc_html_e( 'Cache Warmer could not read the warm-up status. Please retry after resolving the database error.', 'cache-warmer' );
			}
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cache-warmer&tmm-do=db-migrations-log' ) ); ?>"><?php esc_html_e( 'View migration log', 'cache-warmer' ); ?></a>
		</p></div>
	</div>
	<?php
	return;
}

$was_stopped_by_hand = Cache_Warmer::$options->get( 'cache-warmer-last-warmup-was-stopped-by-hand' );

?>

<div class="wrap">
    <h1 class="cache-warmer-header"><?php esc_html_e( 'Cache Warmer', 'cache-warmer' ); ?></h1>

    <form class="cache-warmer-form">
        <div class="cache-warmer-container">
            <div class="cache-warmer-column mt-10">
                <div class="cache-warmer-progress-row mb-10 <?php echo 'in-progress' === $state ? '' : 'cache-warmer-hidden'; ?>" data-testid="cache-warmer-state-progress">
                    <h2><?php esc_html_e( 'Warm-up is in progress...', 'cache-warmer' ); ?></h2>
                </div>
                <div class="cache-warmer-done-row mb-10 <?php // @codingStandardsIgnoreLine
                echo 'complete' === $state && Logging::get_warm_ups_logs_list() && ! $was_stopped_by_hand ?
                    '' : 'cache-warmer-hidden';
               // @codingStandardsIgnoreLine ?>" data-testid="cache-warmer-state-complete">
                    <h2><?php esc_html_e( 'Warm-up completed.', 'cache-warmer' ); ?></h2>
                </div>
                <div class="cache-warmer-failed-row mb-10 <?php echo 'failed' === $state ? '' : 'cache-warmer-hidden'; ?>" data-testid="cache-warmer-state-failed">
                    <h2><?php esc_html_e( 'Warm-up failed.', 'cache-warmer' ); ?></h2>
                    <p>
                        <?php
                        echo __( 'For more information, visit <a target="_blank" href="tools.php?page=action-scheduler&amp;status=failed">failed actions</a> page and <a target="_blank" href="https://wordpress.org/support/plugin/cache-warmer/">report</a> to the plugin developer.', 'cache-warmer' );
                        ?>
                    </p>
                </div>

                <div class="cache-warmer-row cache-warmer-current-warm-up-log">
                    <div class="cache-warmer-column cache-warmer-log-content-block" data-current-page="1" data-log-name="latest" data-testid="cache-warmer-log"><?php // @codingStandardsIgnoreLine
                        echo ! $was_stopped_by_hand ? Logging::format_log_content_array_into_string(
                            Logging::get_latest_warmed_at(), Logging::get_latest_log_content() ) : ''; // @codingStandardsIgnoreLine ?></div>
                </div>

                <div class="cache-warmer-row">
                    <input type="submit"
                           class="button button-primary cache-warmer-start
                    <?php echo 'in-progress' !== $state ? '' : 'cache-warmer-hidden'; ?>"
                           value="<?php esc_html_e( 'Start a new Warm-Up', 'cache-warmer' ); // @codingStandardsIgnoreLine ?>"
                           data-testid="cache-warmer-start">
                    <input type="submit"
                           class="button button-primary cache-warmer-stop cache-warmer-button-red
                    <?php echo 'in-progress' === $state ? '' : 'cache-warmer-hidden'; ?>"
                           value="<?php esc_html_e( 'Stop Warm-Up', 'cache-warmer' ); ?>"
                           data-testid="cache-warmer-stop">
                </div>
            </div>
        </div>
        <hr class="mt-30">
        <div class="cache-warmer-container">
            <div class="cache-warmer-row">
                <a class="cache-warmer-link cache-warmer-show-debug-data-link">
                    <?php esc_html_e( 'Show debug data', 'cache-warmer' ); ?>
                </a>
            </div>
        </div>
    </form>
</div>
