<?php

if (!defined('ABSPATH')) {
    exit;
}

function tnjg_boot(): void
{
    add_filter('cron_schedules', 'tnjg_add_cron_schedules');
    add_action('tnjg_process_sessions', 'tnjg_process_sessions');
    add_action('update_option_tnjg_options', 'tnjg_reschedule_processing_after_options_update');
    tnjg_register_github_updater();
}

function tnjg_reschedule_processing_after_options_update(): void
{
    tnjg_schedule_processing();
}

function tnjg_activate(): void
{
    add_option('tnjg_options', tnjg_default_options(), '', false);
    tnjg_create_tables();
    tnjg_schedule_processing();
}

function tnjg_deactivate(): void
{
    wp_clear_scheduled_hook('tnjg_process_sessions');
}

function tnjg_add_cron_schedules(array $schedules): array
{
    $schedules['tnjg_every_minute'] = array(
        'interval' => MINUTE_IN_SECONDS,
        'display' => __('Every minute', 'tn-journey-graph'),
    );

    return $schedules;
}

function tnjg_schedule_processing(): void
{
    wp_clear_scheduled_hook('tnjg_process_sessions');

    $frequency = (string) tnjg_get_option('processing_frequency');
    if (!in_array($frequency, array('tnjg_every_minute', 'hourly', 'twicedaily', 'daily'), true)) {
        $frequency = 'hourly';
    }

    if (!wp_next_scheduled('tnjg_process_sessions')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, $frequency, 'tnjg_process_sessions');
    }
}

function tnjg_create_tables(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $queue = tnjg_table('session_queue');
    $graph = tnjg_table('journey_graph');

    dbDelta("CREATE TABLE {$queue} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        ia_session_id BIGINT(20) UNSIGNED NOT NULL,
        last_activity_at DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY ia_session_id (ia_session_id),
        KEY status_last_activity (status, last_activity_at)
    ) {$charset_collate};");

    dbDelta("CREATE TABLE {$graph} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        anchor_resource_id BIGINT(20) UNSIGNED NOT NULL,
        hop_key VARCHAR(20) NOT NULL,
        panel_key VARCHAR(40) NOT NULL,
        item_key VARCHAR(64) NOT NULL,
        item_label VARCHAR(255) NOT NULL,
        item_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        item_url VARCHAR(2083) NULL,
        object_type VARCHAR(80) NULL,
        object_id BIGINT(20) UNSIGNED NULL,
        metadata LONGTEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY aggregate_key (anchor_resource_id, hop_key, panel_key, item_key),
        KEY lookup_key (anchor_resource_id, hop_key, panel_key),
        KEY object_type (object_type)
    ) {$charset_collate};");
}
