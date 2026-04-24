<?php
/**
 * Plugin Name:       Action Scheduler: Back to Basic Cron
 * Description:       Makes Action Scheduler schedule each action as its own WP-Cron event instead of running a periodic queue. Designed to play nicely with Cavalcade.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            dd32
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       actionscheduler-back-to-basic-cron
 *
 * @package ActionScheduler_Back_To_Basic_Cron
 */

namespace ActionScheduler_Back_To_Basic_Cron;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance()->init();

register_activation_hook( __FILE__, array( Plugin::instance(), 'on_activation' ) );
register_deactivation_hook( __FILE__, array( Plugin::instance(), 'on_deactivation' ) );
