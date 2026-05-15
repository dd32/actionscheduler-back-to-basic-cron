<?php
/**
 * Test helper mu-plugin, mounted via .wp-env.override.json for the integration workflow.
 *
 * Provides a known callback for the action that the integration test schedules, so
 * Action Scheduler can mark it `complete` rather than `failed`.
 *
 * @package dd32\WordPress\BasicCronForActionScheduler
 */

add_action( 'bc4as_integration_test_hook', function () {
	update_option( 'bc4as_integration_ran', 1, false );
} );
