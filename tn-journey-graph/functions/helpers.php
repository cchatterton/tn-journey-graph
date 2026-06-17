<?php

if (!defined('ABSPATH')) {
    exit;
}

function tnjg_default_options(): array
{
    return array(
        'enabled' => 1,
        'required_capability' => 'manage_options',
        'processing_frequency' => 'hourly',
        'processing_batch_size' => 100,
        'inactivity_threshold_minutes' => 45,
        'hop_visibility_threshold_percent' => 5,
    );
}

function tnjg_get_options(): array
{
    $options = get_option('tnjg_options', array());
    return wp_parse_args(is_array($options) ? $options : array(), tnjg_default_options());
}

function tnjg_get_option(string $key)
{
    $options = tnjg_get_options();
    return $options[$key] ?? null;
}

function tnjg_required_capability(): string
{
    $capability = (string) tnjg_get_option('required_capability');
    return '' !== $capability ? sanitize_key($capability) : 'manage_options';
}

function tnjg_current_user_can_view(): bool
{
    return is_user_logged_in() && current_user_can(tnjg_required_capability());
}

function tnjg_is_enabled(): bool
{
    return (bool) tnjg_get_option('enabled');
}

function tnjg_table(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'tnjg_' . $name;
}

function tnjg_ia_table(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'independent_analytics_' . $name;
}

function tnjg_table_exists(string $table): bool
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
}

function tnjg_column_exists(string $table, string $column): bool
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
}

function tnjg_independent_analytics_ready(): bool
{
    return tnjg_table_exists(tnjg_ia_table('sessions'))
        && tnjg_table_exists(tnjg_ia_table('views'))
        && tnjg_table_exists(tnjg_ia_table('resources'));
}

function tnjg_status(): array
{
    return wp_parse_args(get_option('tnjg_status', array()), array(
        'last_processed_at' => '',
        'last_run_at' => '',
        'status' => 'not_run',
        'message' => __('Journey data has not been processed yet.', 'tn-journey-graph'),
        'processed_sessions' => 0,
        'queue_counts' => array('open' => 0, 'ready' => 0, 'processed' => 0, 'skipped' => 0),
    ));
}

function tnjg_update_status(array $values): void
{
    update_option('tnjg_status', wp_parse_args($values, tnjg_status()), false);
}

function tnjg_current_object_context(): array
{
    $url = home_url(add_query_arg(array(), $GLOBALS['wp']->request ?? ''));
    $path = wp_parse_url($url, PHP_URL_PATH);
    $post_id = is_singular() ? get_queried_object_id() : 0;

    return array(
        'object_id' => $post_id,
        'object_type' => $post_id ? get_post_type($post_id) : 'url',
        'label' => $post_id ? get_the_title($post_id) : wp_parse_url(home_url(add_query_arg(array(), $GLOBALS['wp']->request ?? '')), PHP_URL_PATH),
        'url' => $post_id ? get_permalink($post_id) : home_url($path ?: '/'),
    );
}

function tnjg_resolve_current_resource_id(array $context): int
{
    global $wpdb;
    $resources = tnjg_ia_table('resources');

    if (!tnjg_table_exists($resources)) {
        return 0;
    }

    if (!empty($context['object_id'])) {
        $resource_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$resources} WHERE singular_id = %d ORDER BY id DESC LIMIT 1",
            (int) $context['object_id']
        ));

        if ($resource_id > 0) {
            return $resource_id;
        }
    }

    $path = wp_parse_url((string) ($context['url'] ?? ''), PHP_URL_PATH);
    if (!$path) {
        return 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$resources} WHERE cached_url = %s OR cached_url = %s ORDER BY id DESC LIMIT 1",
        home_url($path),
        $path
    ));
}

function tnjg_sanitize_object_filter(string $filter): string
{
    if (str_starts_with($filter, 'type:')) {
        $type = sanitize_key(substr($filter, 5));
        return $type ? 'type:' . $type : 'all';
    }

    $allowed = array('all', 'pages', 'posts', 'campaigns', 'custom_post_types', 'unknown_urls', 'external', 'exit');
    return in_array($filter, $allowed, true) ? $filter : 'all';
}

function tnjg_format_datetime(?string $mysql_datetime): string
{
    if (empty($mysql_datetime)) {
        return '';
    }

    $timestamp = strtotime($mysql_datetime . ' UTC');
    if (!$timestamp) {
        return '';
    }

    return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
}

function tnjg_panel_groups(): array
{
    return array(
        array(
            'key' => 'landing',
            'title' => __('LANDING', 'tn-journey-graph'),
            'panels' => array(
                'landing_pages' => __('Pages', 'tn-journey-graph'),
                'landing_referrers' => __('Referrers', 'tn-journey-graph'),
                'landing_utm_sources' => __('UTM Sources', 'tn-journey-graph'),
                'landing_utm_channels' => __('UTM Channels', 'tn-journey-graph'),
                'landing_utm_campaigns' => __('UTM Campaigns', 'tn-journey-graph'),
                'landing_content_types' => __('Content Types', 'tn-journey-graph'),
            ),
        ),
        array(
            'key' => 'here',
            'title' => __('HERE', 'tn-journey-graph'),
            'panels' => array(
                'here_pages' => __('Pages', 'tn-journey-graph'),
                'here_referrers' => __('Referrers', 'tn-journey-graph'),
                'here_utm_sources' => __('UTM Sources', 'tn-journey-graph'),
                'here_utm_channels' => __('UTM Channels', 'tn-journey-graph'),
                'here_utm_campaigns' => __('UTM Campaigns', 'tn-journey-graph'),
                'here_content_types' => __('Content Types', 'tn-journey-graph'),
            ),
        ),
        array(
            'key' => 'exit',
            'title' => __('EXITS', 'tn-journey-graph'),
            'panels' => array(
                'exit_pages' => __('Pages', 'tn-journey-graph'),
                'exit_content_types' => __('Content Types', 'tn-journey-graph'),
            ),
        ),
    );
}

function tnjg_panel_definitions(): array
{
    $panels = array();

    foreach (tnjg_panel_groups() as $group) {
        $panels += $group['panels'];
    }

    return $panels;
}
