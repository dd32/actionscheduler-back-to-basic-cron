<?php
/**
 * Core plugin class.
 *
 * @package ActionScheduler_Back_To_Basic_Cron
 */

namespace ActionScheduler_Back_To_Basic_Cron;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * WP-Cron hook used to run a single Action Scheduler action.
	 */
	const RUN_ACTION_HOOK = 'actionscheduler_back_to_basic_cron_run_action';

	/**
	 * WP-Cron hook that Action Scheduler's default runner registers every minute.
	 */
	const AS_QUEUE_HOOK = 'action_scheduler_run_queue';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up all hooks. Safe to call more than once — WordPress dedupes add_action calls.
	 */
	public function init() {
		add_action( 'plugins_loaded', array( $this, 'disable_default_runner' ), 20 );
		add_action( 'init', array( $this, 'cleanup_default_cron' ), 2 );

		add_action( 'action_scheduler_stored_action', array( $this, 'on_stored_action' ) );
		add_action( 'action_scheduler_canceled_action', array( $this, 'on_removed_action' ) );
		add_action( 'action_scheduler_deleted_action', array( $this, 'on_removed_action' ) );
		add_action( 'action_scheduler_completed_action', array( $this, 'on_removed_action' ) );

		add_action( self::RUN_ACTION_HOOK, array( $this, 'run_action' ) );
	}

	/**
	 * Remove Action Scheduler's own `init` hook that schedules the once-a-minute queue runner.
	 *
	 * AS registers this during `plugins_loaded` priority 1, so we run at priority 20 to be safe.
	 */
	public function disable_default_runner() {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}
		$runner = \ActionScheduler::runner();
		remove_action( 'init', array( $runner, 'init' ), 1 );
	}

	/**
	 * Clear any periodic queue event that AS may have registered previously, and detach
	 * the async loopback dispatcher.
	 */
	public function cleanup_default_cron() {
		wp_clear_scheduled_hook( self::AS_QUEUE_HOOK, array( 'WP Cron' ) );
		wp_clear_scheduled_hook( self::AS_QUEUE_HOOK );

		if ( class_exists( '\ActionScheduler' ) ) {
			$runner = \ActionScheduler::runner();
			if ( method_exists( $runner, 'unhook_dispatch_async_request' ) ) {
				$runner->unhook_dispatch_async_request();
			}
		}
	}

	/**
	 * Schedule a one-shot WP-Cron event for a freshly stored AS action.
	 *
	 * Fires from `action_scheduler_stored_action` — invoked both when an action is
	 * created and when a recurring action is rescheduled.
	 *
	 * @param int $action_id The action that was just stored.
	 */
	public function on_stored_action( $action_id ) {
		$action_id = (int) $action_id;
		if ( $action_id <= 0 || ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		$store = \ActionScheduler::store();

		try {
			$status = $store->get_status( $action_id );
		} catch ( \Exception $e ) {
			return;
		}

		if ( \ActionScheduler_Store::STATUS_PENDING !== $status ) {
			return;
		}

		try {
			$timestamp = $store->get_date( $action_id )->getTimestamp();
		} catch ( \Exception $e ) {
			return;
		}

		$args = array( $action_id );
		// Clear first so that updates / reschedules don't race the 10-minute dupe window.
		wp_clear_scheduled_hook( self::RUN_ACTION_HOOK, $args );
		wp_schedule_single_event( max( time(), $timestamp ), self::RUN_ACTION_HOOK, $args );
	}

	/**
	 * Callback for canceled / deleted / completed actions — drop the paired WP-Cron event.
	 *
	 * @param int $action_id The action that was removed.
	 */
	public function on_removed_action( $action_id ) {
		$action_id = (int) $action_id;
		if ( $action_id <= 0 ) {
			return;
		}
		wp_clear_scheduled_hook( self::RUN_ACTION_HOOK, array( $action_id ) );
	}

	/**
	 * Runs a single Action Scheduler action by ID. Invoked by WP-Cron via self::RUN_ACTION_HOOK.
	 *
	 * `process_action()` handles status checks, recurring reschedule, and error logging, so
	 * if the action has already been handled elsewhere it will no-op via
	 * `action_scheduler_execution_ignored`.
	 *
	 * @param int $action_id The action to run.
	 */
	public function run_action( $action_id ) {
		$action_id = (int) $action_id;
		if ( $action_id <= 0 || ! class_exists( '\ActionScheduler' ) ) {
			return;
		}
		\ActionScheduler::runner()->process_action( $action_id, 'WP Cron' );
	}

	/**
	 * Schedule WP-Cron events for every pending AS action. Called on activation so
	 * existing queues aren't left stranded.
	 */
	public function sync_pending_actions() {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		$ids = \ActionScheduler::store()->query_actions(
			array(
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->on_stored_action( (int) $id );
		}
	}

	/**
	 * Activation: detach the default runner and schedule cron events for existing actions.
	 */
	public function on_activation() {
		$this->cleanup_default_cron();
		$this->sync_pending_actions();
	}

	/**
	 * Deactivation: clear every cron event this plugin owns so we leave no orphans.
	 */
	public function on_deactivation() {
		wp_clear_scheduled_hook( self::RUN_ACTION_HOOK );

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}
		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ self::RUN_ACTION_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ self::RUN_ACTION_HOOK ] as $event ) {
				wp_unschedule_event( $timestamp, self::RUN_ACTION_HOOK, $event['args'] );
			}
		}
	}
}
