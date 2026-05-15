<?php
/**
 * Second-phase assertions, run after `wp cavalcade run <id>` has dispatched the job.
 *
 * @package dd32\WordPress\BasicCronForActionScheduler
 */

function bc4as_die( $msg ) {
	fwrite( STDERR, "FAIL: {$msg}\n" );
	exit( 1 );
}

function bc4as_ok( $msg ) {
	fwrite( STDOUT, "ok: {$msg}\n" );
}

$action_id = (int) get_option( 'bc4as_integration_action_id' );
if ( ! $action_id ) {
	bc4as_die( 'Missing staged action id.' );
}

$status = ActionScheduler::store()->get_status( $action_id );
if ( ActionScheduler_Store::STATUS_COMPLETE !== $status ) {
	bc4as_die( "AS action {$action_id} status is `{$status}`, expected `complete`." );
}
bc4as_ok( "AS action {$action_id} completed via Cavalcade" );

if ( 1 !== (int) get_option( 'bc4as_integration_ran' ) ) {
	bc4as_die( 'Test hook callback did not run.' );
}
bc4as_ok( 'test hook callback ran' );

// Cleanup so re-runs of the workflow are idempotent.
delete_option( 'bc4as_integration_action_id' );
delete_option( 'bc4as_integration_job_id' );
delete_option( 'bc4as_integration_ran' );
