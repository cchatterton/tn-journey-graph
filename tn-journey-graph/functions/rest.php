<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'tnjg_register_rest_routes');

function tnjg_register_rest_routes(): void
{
    register_rest_route('tnjg/v1', '/journey', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'tnjg_rest_get_journey',
        'permission_callback' => 'tnjg_rest_permissions',
        'args' => array(
            'object_id' => array('sanitize_callback' => 'absint'),
            'object_type' => array('sanitize_callback' => 'sanitize_key'),
            'url' => array('sanitize_callback' => 'esc_url_raw'),
            'hop' => array('sanitize_callback' => 'sanitize_text_field'),
            'filter' => array('sanitize_callback' => 'sanitize_text_field'),
        ),
    ));
}

function tnjg_rest_permissions(): bool
{
    return tnjg_is_enabled() && tnjg_current_user_can_view();
}

function tnjg_rest_get_journey(WP_REST_Request $request): WP_REST_Response
{
    $context = array(
        'object_id' => (int) $request->get_param('object_id'),
        'object_type' => sanitize_key((string) $request->get_param('object_type')),
        'url' => esc_url_raw((string) $request->get_param('url')),
        'label' => '',
    );
    $resource_id = tnjg_resolve_current_resource_id($context);
    $status = tnjg_status();

    if ($resource_id <= 0) {
        return rest_ensure_response(array(
            'status' => $status,
            'freshness' => tnjg_format_datetime($status['last_processed_at']),
            'hops' => array(),
            'selectedHop' => '',
            'panels' => array(),
            'groups' => array(),
            'emptyMessage' => __('No processed journey data is available for this page yet.', 'tn-journey-graph'),
        ));
    }

    $hops = tnjg_get_hops($resource_id);
    $selected_hop = sanitize_text_field((string) $request->get_param('hop'));
    if ('' === $selected_hop || !isset($hops[$selected_hop])) {
        $selected_hop = tnjg_default_hop($hops);
    }

    return rest_ensure_response(array(
        'status' => $status,
        'freshness' => tnjg_format_datetime($status['last_processed_at']),
        'hops' => array_values($hops),
        'selectedHop' => $selected_hop,
        'contentTypes' => '' !== $selected_hop ? tnjg_get_content_type_options($resource_id, $selected_hop) : array(),
        'groups' => '' !== $selected_hop ? tnjg_get_panel_groups($resource_id, $selected_hop, tnjg_sanitize_object_filter((string) $request->get_param('filter'))) : array(),
        'emptyMessage' => empty($hops)
            ? __('No processed journey data is available for this page yet.', 'tn-journey-graph')
            : '',
    ));
}

function tnjg_get_hops(int $resource_id): array
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT hop_key, SUM(item_count) AS count
        FROM {$graph}
        WHERE anchor_resource_id = %d AND panel_key = 'here_pages'
        GROUP BY hop_key",
        $resource_id
    ));
    $hops = array();

    foreach ($rows as $row) {
        $key = (string) $row->hop_key;
        $hops[$key] = array(
            'key' => $key,
            'label' => $key,
            'count' => (int) $row->count,
        );
    }

    $hops = tnjg_filter_low_volume_hops($hops);
    uasort($hops, 'tnjg_sort_hops');
    return $hops;
}

function tnjg_filter_low_volume_hops(array $hops): array
{
    if (empty($hops)) {
        return array();
    }

    $max = max(array_map(static function (array $hop): int {
        return (int) $hop['count'];
    }, $hops));
    $percentage = max(1, min(100, (int) tnjg_get_option('hop_visibility_threshold_percent')));
    $threshold = max(2, (int) ceil($max * ($percentage / 100)));

    return array_filter($hops, static function (array $hop) use ($threshold): bool {
        return (int) $hop['count'] >= $threshold;
    });
}

function tnjg_sort_hops(array $a, array $b): int
{
    return tnjg_hop_weight($a['key']) <=> tnjg_hop_weight($b['key']);
}

function tnjg_default_hop(array $hops): string
{
    if (empty($hops)) {
        return '';
    }

    $default = null;
    foreach ($hops as $hop) {
        if ('0' === (string) $hop['key'] && count($hops) > 1) {
            continue;
        }

        if (null === $default || (int) $hop['count'] > (int) $default['count']) {
            $default = $hop;
        }
    }

    return (string) ($default['key'] ?? '');
}

function tnjg_hop_weight(string $key): int
{
    return (int) str_replace('+', '', $key);
}

function tnjg_get_panel_groups(int $resource_id, string $hop_key, string $filter): array
{
    $groups = array();

    foreach (tnjg_panel_groups() as $group) {
        $panels = array();

        foreach ($group['panels'] as $panel_key => $title) {
            $panels[] = array(
                'key' => $panel_key,
                'title' => $title,
                'items' => tnjg_get_panel_items($resource_id, $hop_key, $panel_key, $filter),
            );
        }

        $groups[] = array(
            'key' => $group['key'],
            'title' => $group['title'],
            'panels' => $panels,
        );
    }

    return $groups;
}

function tnjg_get_content_type_options(int $resource_id, string $hop_key): array
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $content_panels = array(
        'landing_content_types',
        'here_content_types',
        'exit_content_types',
    );
    $placeholders = implode(',', array_fill(0, count($content_panels), '%s'));
    $params = array_merge(array($resource_id, $hop_key), $content_panels);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT object_type, item_label, SUM(item_count) AS total_count
        FROM {$graph}
        WHERE anchor_resource_id = %d AND hop_key = %s AND panel_key IN ({$placeholders}) AND object_type <> ''
        GROUP BY object_type, item_label
        ORDER BY total_count DESC, item_label ASC",
        $params
    ));

    return array_map(static function ($row): array {
        return array(
            'value' => 'type:' . sanitize_key((string) $row->object_type),
            'label' => (string) $row->item_label,
            'count' => (int) $row->total_count,
        );
    }, is_array($rows) ? $rows : array());
}

function tnjg_get_panel_items(int $resource_id, string $hop_key, string $panel_key, string $filter): array
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $limit = max(1, min(50, (int) tnjg_get_option('max_panel_items')));
    $type_sql = tnjg_filter_sql($filter, $panel_key);
    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(item_count)
        FROM {$graph}
        WHERE anchor_resource_id = %d AND hop_key = %s AND panel_key = %s {$type_sql}",
        $resource_id,
        $hop_key,
        $panel_key
    ));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT item_label, item_count, item_url, object_type, object_id
        FROM {$graph}
        WHERE anchor_resource_id = %d AND hop_key = %s AND panel_key = %s {$type_sql}
        ORDER BY item_count DESC, item_label ASC
        LIMIT %d",
        $resource_id,
        $hop_key,
        $panel_key,
        $limit
    ));

    return array_map(static function ($row) use ($total): array {
        $count = (int) $row->item_count;
        return array(
            'label' => (string) $row->item_label,
            'count' => $count,
            'percentage' => $total > 0 ? round(($count / $total) * 100) : 0,
            'url' => $row->item_url ? esc_url_raw((string) $row->item_url) : '',
            'objectType' => (string) $row->object_type,
            'objectId' => (int) $row->object_id,
        );
    }, is_array($rows) ? $rows : array());
}

function tnjg_filter_sql(string $filter, string $panel_key): string
{
    $filterable_panels = array(
        'landing_pages',
        'landing_content_types',
        'here_pages',
        'here_content_types',
        'exit_pages',
        'exit_content_types',
    );

    if (!in_array($panel_key, $filterable_panels, true)) {
        return '';
    }

    if ('all' === $filter) {
        return '';
    }

    if (str_starts_with($filter, 'type:')) {
        $type = sanitize_key(substr($filter, 5));
        return $type ? "AND object_type = '" . esc_sql($type) . "'" : '';
    }

    $map = array(
        'pages' => "AND object_type = 'page'",
        'posts' => "AND object_type = 'post'",
        'campaigns' => "AND object_type = 'campaign'",
        'unknown_urls' => "AND object_type IN ('unknown', 'unknown_url', 'url')",
        'external' => "AND object_type = 'external'",
        'exit' => "AND object_type = 'exit'",
        'custom_post_types' => "AND object_type NOT IN ('page', 'post', 'campaign', 'unknown', 'unknown_url', 'url', 'external', 'exit')",
    );

    return $map[$filter] ?? '';
}
