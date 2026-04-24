<?php
/**
 * Small mu-plugin used inside the .wp-env site to stop WP-Cron from running on every request,
 * which matches how a real Cavalcade deployment behaves (cron events are triggered by an external
 * runner instead). Action Scheduler should still queue actions correctly; tests and manual checks
 * then trigger events explicitly.
 */
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
