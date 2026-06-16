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
    if (!$selected_hop || !isset($hops[$selected_hop])) {
        $selected_hop = isset($hops['0']) ? '0' : (string) array_key_first($hops);
    }

    return rest_ensure_response(array(
        'status' => $status,
        'freshness' => tnjg_format_datetime($status['last_processed_at']),
        'hops' => array_values($hops),
        'selectedHop' => $selected_hop,
        'groups' => $selected_hop ? tnjg_get_panel_groups($resource_id, $selected_hop, tnjg_sanitize_object_filter((string) $request->get_param('filter'))) : array(),
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
        WHERE anchor_resource_id = %d AND panel_key = 'landing_pages'
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

    uasort($hops, 'tnjg_sort_hops');
    return $hops;
}

function tnjg_sort_hops(array $a, array $b): int
{
    return tnjg_hop_weight($a['key']) <=> tnjg_hop_weight($b['key']);
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

function tnjg_get_panel_items(int $resource_id, string $hop_key, string $panel_key, string $filter): array
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $limit = 5;
    $type_sql = tnjg_filter_sql($filter);
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
    $total = 0;

    foreach (is_array($rows) ? $rows : array() as $row) {
        $total += (int) $row->item_count;
    }

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

function tnjg_filter_sql(string $filter): string
{
    if ('all' === $filter) {
        return '';
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
