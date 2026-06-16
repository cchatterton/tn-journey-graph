<?php

if (!defined('ABSPATH')) {
    exit;
}

function tnjg_process_sessions(): void
{
    if (!tnjg_independent_analytics_ready()) {
        tnjg_update_status(array(
            'last_run_at' => current_time('mysql', true),
            'status' => 'missing_ia',
            'message' => __('Independent Analytics tables are not available yet.', 'tn-journey-graph'),
        ));
        return;
    }

    global $wpdb;
    $queue = tnjg_table('session_queue');
    $sessions = tnjg_ia_table('sessions');
    $views = tnjg_ia_table('views');
    $threshold = max(1, (int) tnjg_get_option('inactivity_threshold_minutes'));
    $now = current_time('mysql', true);

    $wpdb->query(
        "INSERT INTO {$queue} (ia_session_id, last_activity_at, status, created_at, updated_at)
        SELECT s.session_id, COALESCE(MAX(v.viewed_at), s.ended_at, s.created_at), 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP()
        FROM {$sessions} s
        LEFT JOIN {$views} v ON v.session_id = s.session_id
        WHERE s.session_id IS NOT NULL
        GROUP BY s.session_id
        ON DUPLICATE KEY UPDATE
            last_activity_at = VALUES(last_activity_at),
            updated_at = UTC_TIMESTAMP()"
    );

    $wpdb->query($wpdb->prepare(
        "UPDATE {$queue} q
        JOIN {$sessions} s ON s.session_id = q.ia_session_id
        SET q.status = 'ready', q.updated_at = UTC_TIMESTAMP()
        WHERE q.status = 'open'
            AND (s.ended_at IS NOT NULL OR q.last_activity_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE))",
        $threshold
    ));

    $session_ids = $wpdb->get_col("SELECT ia_session_id FROM {$queue} WHERE status = 'ready' ORDER BY last_activity_at ASC LIMIT 100");
    $processed = 0;

    foreach ($session_ids as $session_id) {
        $session_id = (int) $session_id;
        if ($session_id > 0 && tnjg_process_single_session($session_id)) {
            $processed++;
            $wpdb->update(
                $queue,
                array('status' => 'processed', 'processed_at' => $now, 'updated_at' => $now),
                array('ia_session_id' => $session_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
    }

    $status = $processed > 0 ? 'ok' : 'idle';
    tnjg_update_status(array(
        'last_run_at' => $now,
        'last_processed_at' => $processed > 0 ? $now : tnjg_status()['last_processed_at'],
        'status' => $status,
        'message' => $processed > 0
            ? sprintf(__('Processed %d completed sessions.', 'tn-journey-graph'), $processed)
            : __('No completed sessions were ready to process.', 'tn-journey-graph'),
        'processed_sessions' => (int) tnjg_status()['processed_sessions'] + $processed,
    ));
}

function tnjg_process_single_session(int $session_id): bool
{
    global $wpdb;
    $views = tnjg_fetch_session_views($session_id);

    if (empty($views)) {
        return false;
    }

    $session = $views[0];
    $landing = $views[0];
    $last = $views[count($views) - 1];
    $max_prior = max(0, (int) tnjg_get_option('max_prior_hops'));
    $max_next = max(0, (int) tnjg_get_option('max_next_hops'));

    $wpdb->query('START TRANSACTION');

    try {
        foreach ($views as $position => $anchor) {
            tnjg_add_hop_aggregates($anchor, 'landing', $landing, $session);
            tnjg_add_hop_aggregates($anchor, 'this', $anchor, $session);
            tnjg_add_hop_aggregates($anchor, 'last', $last, $session);

            for ($offset = 1; $offset <= $max_prior; $offset++) {
                if (isset($views[$position - $offset])) {
                    tnjg_add_hop_aggregates($anchor, '-' . $offset, $views[$position - $offset], $session);
                }
            }

            for ($offset = 1; $offset <= $max_next; $offset++) {
                if (isset($views[$position + $offset])) {
                    tnjg_add_hop_aggregates($anchor, '+' . $offset, $views[$position + $offset], $session);
                }
            }
        }

        $wpdb->query('COMMIT');
        return true;
    } catch (Throwable $exception) {
        $wpdb->query('ROLLBACK');
        tnjg_update_status(array(
            'last_run_at' => current_time('mysql', true),
            'status' => 'failed',
            'message' => __('A processing run failed. The session remains queued for retry.', 'tn-journey-graph'),
        ));
        return false;
    }
}

function tnjg_fetch_session_views(int $session_id): array
{
    global $wpdb;
    $views = tnjg_ia_table('views');
    $resources = tnjg_ia_table('resources');
    $sessions = tnjg_ia_table('sessions');
    $referrers = tnjg_ia_table('referrers');
    $campaigns = tnjg_ia_table('campaigns');
    $source_select = tnjg_campaign_select('source');
    $medium_select = tnjg_campaign_select('medium');
    $campaign_select = tnjg_campaign_select('campaign');
    $joins = tnjg_campaign_joins();

    $referrer_select = tnjg_column_exists($referrers, 'domain') ? 'ref.domain' : 'ref.url';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT
            v.id AS view_id,
            v.resource_id,
            v.viewed_at,
            r.cached_title,
            r.cached_url,
            r.cached_type,
            r.cached_type_label,
            r.singular_id,
            r.resource,
            s.session_id,
            s.initial_view_id,
            {$referrer_select} AS referrer_domain,
            ref.url AS referrer_url,
            {$source_select} AS utm_source,
            {$medium_select} AS utm_medium,
            {$campaign_select} AS utm_campaign
        FROM {$views} v
        JOIN {$resources} r ON r.id = v.resource_id
        JOIN {$sessions} s ON s.session_id = v.session_id
        LEFT JOIN {$referrers} ref ON ref.id = s.referrer_id
        LEFT JOIN {$campaigns} c ON c.campaign_id = s.campaign_id
        {$joins}
        WHERE v.session_id = %d
        ORDER BY v.viewed_at ASC, v.id ASC",
        $session_id
    ));
}

function tnjg_campaign_select(string $field): string
{
    $campaigns = tnjg_ia_table('campaigns');

    if ('source' === $field && tnjg_table_exists(tnjg_ia_table('utm_sources')) && tnjg_column_exists($campaigns, 'utm_source_id')) {
        return 'utm_sources.utm_source';
    }

    if ('medium' === $field && tnjg_table_exists(tnjg_ia_table('utm_mediums')) && tnjg_column_exists($campaigns, 'utm_medium_id')) {
        return 'utm_mediums.utm_medium';
    }

    if ('campaign' === $field && tnjg_table_exists(tnjg_ia_table('utm_campaigns')) && tnjg_column_exists($campaigns, 'utm_campaign_id')) {
        return 'utm_campaigns.utm_campaign';
    }

    $legacy_column = array('source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign')[$field];
    return tnjg_column_exists($campaigns, $legacy_column) ? 'c.' . $legacy_column : "''";
}

function tnjg_campaign_joins(): string
{
    $campaigns = tnjg_ia_table('campaigns');
    $joins = array();

    if (tnjg_table_exists(tnjg_ia_table('utm_sources')) && tnjg_column_exists($campaigns, 'utm_source_id')) {
        $joins[] = 'LEFT JOIN ' . tnjg_ia_table('utm_sources') . ' utm_sources ON utm_sources.id = c.utm_source_id';
    }

    if (tnjg_table_exists(tnjg_ia_table('utm_mediums')) && tnjg_column_exists($campaigns, 'utm_medium_id')) {
        $joins[] = 'LEFT JOIN ' . tnjg_ia_table('utm_mediums') . ' utm_mediums ON utm_mediums.id = c.utm_medium_id';
    }

    if (tnjg_table_exists(tnjg_ia_table('utm_campaigns')) && tnjg_column_exists($campaigns, 'utm_campaign_id')) {
        $joins[] = 'LEFT JOIN ' . tnjg_ia_table('utm_campaigns') . ' utm_campaigns ON utm_campaigns.id = c.utm_campaign_id';
    }

    return implode("\n", $joins);
}

function tnjg_add_hop_aggregates(object $anchor, string $hop_key, object $hop, object $session): void
{
    $anchor_id = (int) $anchor->resource_id;

    tnjg_increment_graph_item($anchor_id, $hop_key, 'referrer_to', tnjg_value($session->referrer_domain, __('Direct', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'referrer_from', tnjg_value($hop->referrer_domain, __('Direct', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_source_to', tnjg_value($session->utm_source, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_source_from', tnjg_value($hop->utm_source, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_channel_to', tnjg_value($session->utm_medium, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_channel_from', tnjg_value($hop->utm_medium, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_campaign_to', tnjg_value($session->utm_campaign, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'utm_campaign_from', tnjg_value($hop->utm_campaign, __('None', 'tn-journey-graph')), $hop, false);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'content_type', tnjg_value($hop->cached_type_label, __('Unknown URL', 'tn-journey-graph')), $hop, true);
    tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_pages', tnjg_value($session->cached_title, __('Unknown landing page', 'tn-journey-graph')), $session, true);
}

function tnjg_value($value, string $fallback): string
{
    $value = is_string($value) ? trim($value) : '';
    return '' !== $value ? $value : $fallback;
}

function tnjg_increment_graph_item(int $anchor_id, string $hop_key, string $panel_key, string $label, ?object $object = null, bool $link_object = false): void
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $object_type = $object ? tnjg_object_type($object) : null;
    $object_id = $object && !empty($object->singular_id) ? (int) $object->singular_id : null;
    $url = $link_object && $object && !empty($object->cached_url) ? (string) $object->cached_url : null;
    $item_key = md5($panel_key . '|' . $label . '|' . (string) $url . '|' . (string) $object_type . '|' . (string) $object_id);

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$graph}
            (anchor_resource_id, hop_key, panel_key, item_key, item_label, item_count, item_url, object_type, object_id, metadata, updated_at)
        VALUES (%d, %s, %s, %s, %s, 1, %s, %s, %d, %s, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE item_count = item_count + 1, updated_at = UTC_TIMESTAMP()",
        $anchor_id,
        $hop_key,
        $panel_key,
        $item_key,
        sanitize_text_field($label),
        $url ? esc_url_raw($url) : null,
        $object_type,
        $object_id,
        $object ? wp_json_encode(array('resource_id' => (int) $object->resource_id, 'view_id' => (int) $object->view_id)) : null
    ));
}

function tnjg_object_type(object $object): string
{
    if (!empty($object->cached_type)) {
        return sanitize_key((string) $object->cached_type);
    }

    if (!empty($object->resource)) {
        return sanitize_key((string) $object->resource);
    }

    return 'unknown';
}
