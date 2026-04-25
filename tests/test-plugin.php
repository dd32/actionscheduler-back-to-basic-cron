<?php
/**
 * Integration tests for the plugin, run against a real Action Scheduler install.
 *
 * @package ActionScheduler_Back_To_Basic_Cron
 */

use ActionScheduler_Back_To_Basic_Cron\Plugin;

class Test_Plugin extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'ActionScheduler' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}

		// Remove any stray events from prior tests.
		_set_cron_array( array() );

		// Cancel every stored action so each test starts clean.
		$ids = ActionScheduler::store()->query_actions(
			array( 'per_page' => -1 )
		);
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}

		delete_option( Plugin::SYNCED_OPTION );

		// Apply cleanup explicitly — in the test harness init has already fired.
		Plugin::instance()->cleanup_default_cron();
	}

	public function test_default_queue_event_is_cleared() {
		wp_schedule_event( time(), 'hourly', Plugin::AS_QUEUE_HOOK, array( 'WP Cron' ) );
		$this->assertNotFalse( wp_next_scheduled( Plugin::AS_QUEUE_HOOK, array( 'WP Cron' ) ) );

		Plugin::instance()->cleanup_default_cron();

		$this->assertFalse( wp_next_scheduled( Plugin::AS_QUEUE_HOOK, array( 'WP Cron' ) ) );
		$this->assertFalse( wp_next_scheduled( Plugin::AS_QUEUE_HOOK ) );
	}

	public function test_default_runner_init_action_is_unhooked() {
		// Re-run the disable; subsequent calls should be idempotent.
		Plugin::instance()->disable_default_runner();

		$runner = ActionScheduler::runner();
		$this->assertFalse(
			has_action( 'init', array( $runner, 'init' ) ),
			'Action Scheduler queue runner should not be hooked to init any more.'
		);
	}

	public function test_scheduling_an_action_creates_wp_cron_event() {
		$hook       = 'abbtc_test_hook_' . wp_generate_password( 6, false );
		$target_ts  = time() + 300;
		$action_id  = as_schedule_single_action( $target_ts, $hook );

		$this->assertGreaterThan( 0, $action_id );
		$scheduled = wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) );
		$this->assertNotFalse( $scheduled, 'WP-Cron event should exist for the new action.' );
		$this->assertEqualsWithDelta( $target_ts, $scheduled, 2 );
	}

	public function test_scheduling_past_action_uses_current_time_floor() {
		$hook      = 'abbtc_past_' . wp_generate_password( 6, false );
		$past_ts   = time() - 3600;
		$action_id = as_schedule_single_action( $past_ts, $hook );

		$scheduled = wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThanOrEqual( time() - 5, $scheduled, 'Event must not be scheduled in the past.' );
	}

	public function test_canceling_action_removes_wp_cron_event() {
		$hook      = 'abbtc_cancel_' . wp_generate_password( 6, false );
		$action_id = as_schedule_single_action( time() + 600, $hook );
		$this->assertNotFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		ActionScheduler::store()->cancel_action( $action_id );

		$this->assertFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'WP-Cron event should be cleared after cancellation.'
		);
	}

	public function test_deleting_action_removes_wp_cron_event() {
		$hook      = 'abbtc_delete_' . wp_generate_password( 6, false );
		$action_id = as_schedule_single_action( time() + 600, $hook );
		$this->assertNotFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		ActionScheduler::store()->delete_action( $action_id );

		$this->assertFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );
	}

	public function test_run_action_processes_the_action_callback() {
		$hook      = 'abbtc_run_' . wp_generate_password( 6, false );
		$counter   = new ArrayObject( array( 'n' => 0 ) );
		$callback  = function () use ( $counter ) {
			$counter['n']++;
		};
		add_action( $hook, $callback );

		$action_id = as_schedule_single_action( time() - 10, $hook );

		Plugin::instance()->run_action( $action_id );

		$this->assertSame( 1, $counter['n'], 'AS callback should have run exactly once.' );
		$this->assertSame(
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler::store()->get_status( $action_id )
		);
		$this->assertFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'The completed-action handler should have cleared the cron event.'
		);

		remove_action( $hook, $callback );
	}

	public function test_recurring_action_reschedules_next_instance_as_wp_cron() {
		$hook      = 'abbtc_recurring_' . wp_generate_password( 6, false );
		$callback  = function () {};
		add_action( $hook, $callback );

		$action_id = as_schedule_recurring_action( time() - 10, 3600, $hook );
		$this->assertNotFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		Plugin::instance()->run_action( $action_id );

		$this->assertFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'Old recurring action should be complete and unscheduled.'
		);

		// A new AS action should have been stored for the next interval, with its own WP-Cron event.
		$pending = ActionScheduler::store()->query_actions(
			array(
				'hook'     => $hook,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);
		$this->assertNotEmpty( $pending, 'Recurring action should have scheduled a follow-up.' );
		$next_id = (int) $pending[0];
		$this->assertNotFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $next_id ) ),
			'Follow-up recurring action should have its own WP-Cron event.'
		);

		remove_action( $hook, $callback );
	}

	public function test_cron_expression_action_reschedules_next_instance_as_wp_cron() {
		$hook     = 'abbtc_cron_' . wp_generate_password( 6, false );
		$callback = function () {};
		add_action( $hook, $callback );

		// "every minute" — cron expression scheduling, not fixed interval.
		$action_id = as_schedule_cron_action( time() - 10, '* * * * *', $hook );
		$this->assertNotFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		Plugin::instance()->run_action( $action_id );

		$this->assertSame(
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler::store()->get_status( $action_id )
		);

		$pending = ActionScheduler::store()->query_actions(
			array(
				'hook'     => $hook,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);
		$this->assertNotEmpty( $pending, 'Cron-scheduled action should have scheduled a follow-up.' );
		$next_id = (int) $pending[0];
		$this->assertNotFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $next_id ) ),
			'Follow-up cron-scheduled action should have its own WP-Cron event.'
		);

		remove_action( $hook, $callback );
	}

	public function test_maybe_initial_sync_runs_once_per_site() {
		$hook      = 'abbtc_initial_' . wp_generate_password( 6, false );
		$action_id = as_schedule_single_action( time() + 900, $hook );

		// Simulate the mu-plugin / Network Activate scenario: the plugin starts running over an
		// existing AS queue with no WP-Cron events scheduled and no synced flag set.
		wp_clear_scheduled_hook( Plugin::RUN_ACTION_HOOK, array( $action_id ) );
		delete_option( Plugin::SYNCED_OPTION );
		$this->assertFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		Plugin::instance()->maybe_initial_sync();

		$this->assertNotFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'First call should backfill cron events for existing pending actions.'
		);
		$this->assertSame( '1', (string) get_option( Plugin::SYNCED_OPTION ) );

		// Second call should be a no-op: tear the cron event down again and verify it stays gone.
		wp_clear_scheduled_hook( Plugin::RUN_ACTION_HOOK, array( $action_id ) );
		Plugin::instance()->maybe_initial_sync();
		$this->assertFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'Subsequent calls must not re-run the backfill.'
		);
	}

	public function test_sync_pending_actions_schedules_cron_events_for_existing_pending() {
		$hook      = 'abbtc_sync_' . wp_generate_password( 6, false );
		$action_id = as_schedule_single_action( time() + 900, $hook );

		// Drop the cron event to simulate a freshly-installed plugin over an existing AS queue.
		wp_clear_scheduled_hook( Plugin::RUN_ACTION_HOOK, array( $action_id ) );
		$this->assertFalse( wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ) );

		Plugin::instance()->sync_pending_actions();

		$this->assertNotFalse(
			wp_next_scheduled( Plugin::RUN_ACTION_HOOK, array( $action_id ) ),
			'sync_pending_actions should backfill cron events for existing pending actions.'
		);
	}
}
