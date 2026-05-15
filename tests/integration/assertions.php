<?php
/**
 * End-to-end integration assertions for:
 *   Action Scheduler + Basic Cron for Action Scheduler + Cavalcade.
 *
 * Run via `wp eval-file tests/integration/assertions.php` inside wp-env.
 *
 * @package dd32\WordPress\BasicCronForActionScheduler
 */

global $wpdb;

function bc4as_die( $msg ) {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
}

function bc4as_ok( $msg ) {
	fwrite( STDOUT, "ok: {$msg}\n" );
}

// --- 1. Required pieces are all loaded. ---
if ( ! class_exists( 'ActionScheduler' ) ) {
	bc4as_die( 'Action Scheduler not loaded.' );
}
if ( ! class_exists( 'HM\\Cavalcade\\Plugin\\Job' ) ) {
	bc4as_die( 'Cavalcade not loaded.' );
}
if ( ! has_filter( 'pre_schedule_event', array( dd32\WordPress\BasicCronForActionScheduler\Plugin::instance(), 'block_default_queue_schedule' ) ) ) {
	bc4as_die( 'Basic Cron for Action Scheduler filter not attached.' );
}
bc4as_ok( 'all three plugins active' );

// --- 2. AS does not schedule its periodic queue runner. ---
$cavalcade_table = $wpdb->base_prefix . 'cavalcade_jobs';
$queue_jobs      = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$cavalcade_table} WHERE hook = %s AND status = 'waiting'",
		'action_scheduler_run_queue'
	)
);
if ( $queue_jobs > 0 ) {
	bc4as_die( "Cavalcade has {$queue_jobs} waiting `action_scheduler_run_queue` jobs; expected 0." );
}
bc4as_ok( 'no action_scheduler_run_queue jobs in Cavalcade' );

// --- 3. Scheduling an AS action produces exactly one Cavalcade job. ---
// `bc4as_integration_test_hook` has a callback registered by the test mu-plugin
// mounted via tests/integration/wp-env.json, so AS will mark the action complete.
$test_hook = 'bc4as_integration_test_hook';
delete_option( 'bc4as_integration_ran' );

$action_id = as_schedule_single_action( time(), $test_hook );
if ( ! $action_id ) {
	bc4as_die( 'as_schedule_single_action returned 0.' );
}

$job_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, hook, args FROM {$cavalcade_table} WHERE hook = %s AND status = 'waiting' ORDER BY id DESC LIMIT 1",
		'action_scheduler_run_hook'
	),
	ARRAY_A
);
if ( ! $job_row ) {
	bc4as_die( 'No Cavalcade `action_scheduler_run_hook` job created.' );
}

$job_args = maybe_unserialize( $job_row['args'] );
if ( ! is_array( $job_args ) || (int) ( $job_args[0] ?? 0 ) !== (int) $action_id ) {
	bc4as_die( 'Cavalcade job args do not match the AS action id (' . $action_id . '); got ' . wp_json_encode( $job_args ) );
}
bc4as_ok( "Cavalcade job {$job_row['id']} created for AS action {$action_id}" );

// --- 4. Stash for the next step (run by the shell script via WP-CLI). ---
update_option( 'bc4as_integration_action_id', (int) $action_id, false );
update_option( 'bc4as_integration_job_id', (int) $job_row['id'], false );

bc4as_ok( 'staged: action_id=' . $action_id . ' job_id=' . $job_row['id'] );
