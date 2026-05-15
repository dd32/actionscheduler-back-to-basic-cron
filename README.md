# Basic Cron for Action Scheduler

A WordPress plugin that makes [Action Scheduler](https://actionscheduler.org/) schedule each action as its own WP-Cron event, instead of running its own queue and ticking a once-a-minute polling job on every site — load that compounds across a Multisite network and overwhelms external cron runners like [Cavalcade](https://github.com/humanmade/Cavalcade).

No options. No UI. Activate it and forget it.

## The problem

Action Scheduler ships with its own queue runner. By default it registers a single `action_scheduler_run_queue` WP-Cron event scheduled `every_minute`, then on each tick it claims a batch of due actions and runs them in-process (and may also fire an async loopback request on `shutdown` to keep the batch flowing).

That design assumes WP-Cron is the unreliable, request-coupled "spawn loopback HTTP requests on every page load" cron that ships with WordPress. Polling once a minute and burning through a batch is the right shape for that environment.

But if you're running a **reliable, externally-driven cron system** — for example [Cavalcade](https://github.com/humanmade/Cavalcade) — that assumption is wrong, and counterproductive:

- Cavalcade is built around **one job per cron event**: it picks up a due event, hands it to a worker, the worker runs it, exits. That gives you isolation, accurate timing, parallelism, and per-event observability.
- Action Scheduler's runner subverts that. Cavalcade sees one event (`action_scheduler_run_queue`) firing every minute, and AS multiplexes an arbitrary number of jobs through it inside a single process. Cavalcade can't see the individual jobs, can't run them in parallel, can't time them, and can't restart failed ones independently.
- You also pay a runner-of-runners tax: Cavalcade fires a worker every minute, the worker boots WordPress just to ask AS "anything to do?", AS says no, the worker exits. That's a wasted process every minute on every site.
- And if AS *does* have work, the in-process batch can drag on, blocking that Cavalcade slot.

## By the numbers

Take a WordPress Multisite of **1,000 sites**, each running a single hourly Action Scheduler job.

| Setup | Cron events Cavalcade dispatches per hour | Of those, doing real work |
|---|---:|---:|
| Default Action Scheduler | **60,000** (per-minute poll × 60 minutes × 1,000 sites) | 1,000 |
| With this plugin | **1,000** (one event per scheduled action, fired at its due time) | 1,000 |

A 60× reduction in dispatched events, and over 98% of what the default runner fires under that load is pure overhead — Cavalcade boots WordPress just for Action Scheduler to look at an empty queue and exit. Multiply by however many AS jobs are actually in flight (a single active WooCommerce site easily has dozens) and the gap widens. With this plugin, cron load scales with the *jobs you actually need to run*, not with the size of your network.

## What this plugin does

It removes Action Scheduler's periodic queue runner entirely and replaces it with one-shot WP-Cron events — one per pending action, scheduled at the action's actual due time.

Concretely:

1. **Disables the default runner.** Removes the `init` callback that schedules `action_scheduler_run_queue` every minute, and detaches the async shutdown dispatcher.
2. **Mirrors every pending action into WP-Cron.** Hooks `action_scheduler_stored_action` and calls `wp_schedule_single_event( $when, 'action_scheduler_run_hook', [ $action_id ] )`.
3. **Runs the action when its event fires.** The cron callback delegates to `ActionScheduler::runner()->process_action( $action_id, 'WP Cron' )`, which gives you AS's full lifecycle — `before_execute` / `after_execute`, logging, error handling, and `schedule_next_instance` for recurring actions.
4. **Cleans up.** When an action is canceled, deleted, or completed, the matching WP-Cron event is cleared.
5. **Backfills on activation.** Existing pending actions get WP-Cron events scheduled immediately, so nothing stalls when you switch over.

Because each action is its own event, Cavalcade (or any sensible cron runner) can dispatch them individually: one worker per action, with proper timing, isolation, and parallelism — exactly what a reliable cron system is for.

Recurring actions, both fixed-interval (`as_schedule_recurring_action`) and cron-expression (`as_schedule_cron_action`), work transparently. After each run, AS's `schedule_next_instance()` stores a new pending action for the next occurrence, which fires `action_scheduler_stored_action` again, which schedules the next WP-Cron event.

## Installation

Install this plugin **network-wide**, or — preferably — as an mu-plugin. Action Scheduler runs per-site, so the queue-runner replacement has to be active on every site in your network; activating it on a single Multisite site leaves the others polling.

The simplest deployment is to drop `basic-cron-for-action-scheduler.php` straight into `wp-content/mu-plugins/` (no activation step, always loaded). Alternatively, place it in `wp-content/plugins/` and Network Activate. On first request to each site the plugin backfills WP-Cron events for any AS actions already in the queue, so switchover is automatic on every install path.

Action Scheduler must already be present (either standalone or bundled by WooCommerce / another consumer). There is nothing to configure.

## When *not* to use this

If you are running default WordPress cron — i.e. WP-Cron events fire via HTTP loopback requests triggered by site visits — this plugin will make things worse, not better. Default WP-Cron has no real scheduler; events are only dispatched when a request happens to come in, and Action Scheduler's batch-every-minute design is a deliberate workaround for exactly that. Stick with the default queue runner.

This plugin is for sites with a real cron driver: Cavalcade, an external `wp-cron.php` runner on a system cron, a queue-backed implementation, etc. Anything that treats WP-Cron events as first-class jobs rather than opportunistic side-effects.

## Local development

A `.wp-env.json` is included that boots a local site with Action Scheduler installed and `DISABLE_WP_CRON` set, so the environment behaves like a Cavalcade-style deployment (events don't fire on their own — you trigger them).

```sh
wp-env start
composer install
composer test:env
```

To run the test suite directly against a local WordPress install (without wp-env):

```sh
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
composer install
composer test
```

The test suite covers: the default runner being unhooked, single actions producing WP-Cron events, past-due timestamps being floored to "now", cancellation / deletion / completion clearing the event, the cron callback actually executing the AS action and marking it complete, fixed-interval recurring actions self-propagating into WP-Cron, cron-expression recurring actions doing the same, the per-site initial sync running exactly once, and a manual `sync_pending_actions()` backfill.

## License

GPL-2.0-or-later.
