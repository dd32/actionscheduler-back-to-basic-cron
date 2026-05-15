<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package dd32\WordPress\BasicCronForActionScheduler
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load Action Scheduler and this plugin before WP boots.
 */
function _manually_load_plugin() {
	$as_candidates = array(
		dirname( __DIR__, 2 ) . '/action-scheduler/action-scheduler.php',
		WP_CONTENT_DIR . '/plugins/action-scheduler/action-scheduler.php',
		dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php',
	);
	foreach ( $as_candidates as $as_path ) {
		if ( file_exists( $as_path ) ) {
			require_once $as_path;
			break;
		}
	}

	require dirname( __DIR__ ) . '/basic-cron-for-action-scheduler.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
