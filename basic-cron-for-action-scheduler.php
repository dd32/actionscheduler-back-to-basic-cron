<?php
/**
 * Plugin Name:       Basic Cron for Action Scheduler
 * Plugin URI:        https://github.com/dd32/basic-cron-for-action-scheduler
 * Description:       Makes Action Scheduler schedule each action as its own WP-Cron event instead of running a periodic queue. Designed to play nicely with Cavalcade.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            dd32
 * Author URI:        https://github.com/dd32
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/dd32/basic-cron-for-action-scheduler
 * Primary Branch:    trunk
 *
 * @package dd32\WordPress\BasicCronForActionScheduler
 */

namespace dd32\WordPress\BasicCronForActionScheduler;

use ActionScheduler;
use ActionScheduler_Store;
use Exception;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/** WP-Cron hook used to run a single Action Scheduler action. */
	const RUN_ACTION_HOOK = 'action_scheduler_run_hook';

	/** WP-Cron hook that Action Scheduler's default runner registers every minute. */
	const AS_QUEUE_HOOK = 'action_scheduler_run_queue';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Per-site option flag set once we've backfilled WP-Cron events for existing pending actions. */
	const SYNCED_OPTION = 'actionscheduler_basic_cron_synced';

	public function init() {
		add_action( 'plugins_loaded', array( $this, 'disable_default_runner' ), 20 );
		add_action( 'action_scheduler_init', array( $this, 'maybe_initial_sync' ), 100 );

		add_action( 'action_scheduler_stored_action', array( $this, 'on_stored_action' ) );
		add_action( 'action_scheduler_canceled_action', array( $this, 'on_removed_action' ) );
		add_action( 'action_scheduler_deleted_action', array( $this, 'on_removed_action' ) );
		add_action( 'action_scheduler_completed_action', array( $this, 'on_removed_action' ) );

		add_action( self::RUN_ACTION_HOOK, array( $this, 'run_action' ) );
	}

	/**
	 * Remove Action Scheduler's own `init` hook that schedules the once-a-minute queue runner.
	 *
	 * AS registers this during `plugins_loaded` priority 1, so we run at priority 20.
	 */
	public function disable_default_runner() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}
		remove_action( 'init', array( ActionScheduler::runner(), 'init' ), 1 );
	}

	/**
	 * Schedule a one-shot WP-Cron event for a freshly stored AS action.
	 *
	 * Fires from `action_scheduler_stored_action` on both create and recurring reschedule.
	 */
	public function on_stored_action( $action_id ) {
		$action_id = (int) $action_id;
		$store     = ActionScheduler::store();

		try {
			if ( ActionScheduler_Store::STATUS_PENDING !== $store->get_status( $action_id ) ) {
				return;
			}
			$timestamp = $store->get_date( $action_id )->getTimestamp();
		} catch ( Exception $e ) {
			return;
		}

		$args = array( $action_id );
		// Clear first so reschedules don't race the 10-minute dupe window.
		wp_clear_scheduled_hook( self::RUN_ACTION_HOOK, $args );
		wp_schedule_single_event( max( time(), $timestamp ), self::RUN_ACTION_HOOK, $args );
	}

	/**
	 * Callback for canceled / deleted / completed actions — drop the paired WP-Cron event.
	 */
	public function on_removed_action( $action_id ) {
		wp_clear_scheduled_hook( self::RUN_ACTION_HOOK, array( (int) $action_id ) );
	}

	/**
	 * Runs a single Action Scheduler action by ID. Invoked by WP-Cron via self::RUN_ACTION_HOOK.
	 */
	public function run_action( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}
		ActionScheduler::runner()->process_action( (int) $action_id, 'WP Cron' );
	}

	/**
	 * Schedule WP-Cron events for every pending AS action. Called on activation so
	 * existing queues aren't left stranded.
	 */
	public function sync_pending_actions() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		$ids = ActionScheduler::store()->query_actions(
			array(
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->on_stored_action( (int) $id );
		}
	}

	/**
	 * One-shot per-site backfill: runs sync_pending_actions() once per site and remembers it.
	 *
	 * This replaces register_activation_hook because that hook doesn't fire for mu-plugins, and
	 * for Network Activate it only fires once in the network admin context — neither of which
	 * is enough to backfill an existing AS queue across a Multisite network. Hooking
	 * action_scheduler_init runs once per site on its first request after install, which is
	 * what we actually want.
	 */
	public function maybe_initial_sync() {
		if ( get_option( self::SYNCED_OPTION ) ) {
			return;
		}

		// Drop any periodic queue event AS had previously scheduled. Once cleared it stays
		// cleared because disable_default_runner() stops AS re-registering it.
		wp_clear_scheduled_hook( self::AS_QUEUE_HOOK, array( 'WP Cron' ) );
		wp_clear_scheduled_hook( self::AS_QUEUE_HOOK );

		$this->sync_pending_actions();
		update_option( self::SYNCED_OPTION, 1 );
	}
}

Plugin::instance()->init();
