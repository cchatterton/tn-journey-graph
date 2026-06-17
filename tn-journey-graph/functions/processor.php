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

    tnjg_maybe_upgrade_graph_schema();

    global $wpdb;
    $queue = tnjg_table('session_queue');
    $threshold = max(1, (int) tnjg_get_option('inactivity_threshold_minutes'));
    $batch_size = max(1, min(1000, (int) tnjg_get_option('processing_batch_size')));
    $now = current_time('mysql', true);

    tnjg_refresh_session_queue($threshold);

    $session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ia_session_id FROM {$queue} WHERE status = 'ready' ORDER BY last_activity_at ASC LIMIT %d",
        $batch_size
    ));
    $processed = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($session_ids as $session_id) {
        $session_id = (int) $session_id;
        if ($session_id <= 0) {
            continue;
        }

        if (tnjg_process_single_session($session_id)) {
            $processed++;
            $wpdb->update(
                $queue,
                array('status' => 'processed', 'processed_at' => $now, 'updated_at' => $now),
                array('ia_session_id' => $session_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        } else {
            if (!empty($wpdb->last_error)) {
                $failed++;
                break;
            }

            $skipped++;
            $wpdb->update(
                $queue,
                array('status' => 'skipped', 'processed_at' => $now, 'updated_at' => $now),
                array('ia_session_id' => $session_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
    }

    $queue_counts = tnjg_queue_counts();
    $status = $failed > 0 ? 'failed' : ($processed > 0 ? 'ok' : 'idle');
    tnjg_update_status(array(
        'last_run_at' => $now,
        'last_processed_at' => $processed > 0 ? $now : tnjg_status()['last_processed_at'],
        'status' => $status,
        'message' => $processed > 0
            ? sprintf(
                __('Processed %1$d completed sessions. Queue: %2$d open, %3$d ready, %4$d processed.', 'tn-journey-graph'),
                $processed,
                (int) ($queue_counts['open'] ?? 0),
                (int) ($queue_counts['ready'] ?? 0),
                (int) ($queue_counts['processed'] ?? 0)
            )
            : sprintf(
                $failed > 0
                    ? __('Processing stopped after a database error. Queue: %1$d open, %2$d ready, %3$d processed, %4$d skipped.', 'tn-journey-graph')
                    : __('No sessions were processed. Queue: %1$d open, %2$d ready, %3$d processed, %4$d skipped.', 'tn-journey-graph'),
                (int) ($queue_counts['open'] ?? 0),
                (int) ($queue_counts['ready'] ?? 0),
                (int) ($queue_counts['processed'] ?? 0),
                (int) ($queue_counts['skipped'] ?? 0)
            ),
        'processed_sessions' => (int) tnjg_status()['processed_sessions'] + $processed,
        'queue_counts' => $queue_counts,
    ));
}

function tnjg_queue_historical_sessions(): void
{
    if (!tnjg_independent_analytics_ready()) {
        return;
    }

    tnjg_maybe_upgrade_graph_schema();
    tnjg_refresh_session_queue(max(1, (int) tnjg_get_option('inactivity_threshold_minutes')));
    tnjg_update_status(array(
        'last_run_at' => current_time('mysql', true),
        'status' => 'queued',
        'message' => __('Historical Independent Analytics sessions have been queued for journey processing.', 'tn-journey-graph'),
        'queue_counts' => tnjg_queue_counts(),
    ));
}

function tnjg_refresh_session_queue(int $threshold): void
{
    global $wpdb;
    $queue = tnjg_table('session_queue');
    $sessions = tnjg_ia_table('sessions');
    $views = tnjg_ia_table('views');

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$queue} (ia_session_id, last_activity_at, status, created_at, updated_at)
        SELECT
            s.session_id,
            COALESCE(s.ended_at, MAX(v.viewed_at), s.created_at) AS last_activity_at,
            CASE
                WHEN s.ended_at IS NOT NULL THEN 'ready'
                WHEN COALESCE(MAX(v.viewed_at), s.created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE) THEN 'ready'
                ELSE 'open'
            END AS status,
            UTC_TIMESTAMP(),
            UTC_TIMESTAMP()
        FROM {$sessions} s
        LEFT JOIN {$views} v ON v.session_id = s.session_id
        LEFT JOIN {$queue} q ON q.ia_session_id = s.session_id
        WHERE s.session_id IS NOT NULL
            AND (q.status IS NULL OR q.status != 'processed')
        GROUP BY s.session_id, s.ended_at, s.created_at
        ON DUPLICATE KEY UPDATE
            last_activity_at = VALUES(last_activity_at),
            status = IF({$queue}.status = 'processed', {$queue}.status, VALUES(status)),
            updated_at = UTC_TIMESTAMP()",
        $threshold
    ));

    $wpdb->query($wpdb->prepare(
        "UPDATE {$queue} q
        JOIN (
            SELECT
                s.session_id,
                COALESCE(s.ended_at, MAX(v.viewed_at), s.created_at) AS last_activity_at
            FROM {$sessions} s
            LEFT JOIN {$views} v ON v.session_id = s.session_id
            GROUP BY s.session_id, s.ended_at, s.created_at
        ) latest ON latest.session_id = q.ia_session_id
        SET q.status = 'ready', q.last_activity_at = latest.last_activity_at, q.updated_at = UTC_TIMESTAMP()
        WHERE q.status = 'open'
            AND latest.last_activity_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
        $threshold
    ));
}

function tnjg_queue_counts(): array
{
    global $wpdb;
    $queue = tnjg_table('session_queue');
    $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$queue} GROUP BY status");
    $counts = array('open' => 0, 'ready' => 0, 'processed' => 0, 'skipped' => 0);

    foreach ($rows as $row) {
        $counts[(string) $row->status] = (int) $row->total;
    }

    return $counts;
}

function tnjg_process_single_session(int $session_id): bool
{
    global $wpdb;
    $views = tnjg_fetch_session_views($session_id);

    if (empty($views)) {
        return false;
    }

    $session = $views[0];
    $last_position = count($views) - 1;

    $wpdb->query('START TRANSACTION');

    try {
        foreach ($views as $position => $anchor) {
            tnjg_add_hop_aggregates($anchor, 0, $views, $session, $position);

            for ($offset = 1; $offset <= $position; $offset++) {
                tnjg_add_hop_aggregates($anchor, -$offset, $views, $session, $position);
            }

            for ($offset = 1; $offset <= ($last_position - $position); $offset++) {
                tnjg_add_hop_aggregates($anchor, $offset, $views, $session, $position);
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
    $wpdb->last_error = '';

    $views = tnjg_ia_table('views');
    $resources = tnjg_ia_table('resources');
    $sessions = tnjg_ia_table('sessions');
    $referrers = tnjg_ia_table('referrers');
    $campaigns = tnjg_ia_table('campaigns');
    $source_select = tnjg_campaign_select('source');
    $medium_select = tnjg_campaign_select('medium');
    $campaign_select = tnjg_campaign_select('campaign');
    $joins = tnjg_campaign_joins();
    $campaign_join = tnjg_table_exists($campaigns) ? 'LEFT JOIN ' . $campaigns . ' c ON c.campaign_id = ' . tnjg_campaign_id_sql() : '';
    $referrer_join = tnjg_table_exists($referrers) ? 'LEFT JOIN ' . $referrers . ' ref ON ref.id = s.referrer_id' : '';

    $referrer_label_select = tnjg_referrer_select($referrers, 'label');
    $referrer_url_select = tnjg_referrer_select($referrers, 'url');

    $rows = $wpdb->get_results($wpdb->prepare(
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
            {$referrer_label_select} AS referrer_domain,
            {$referrer_url_select} AS referrer_url,
            {$source_select} AS utm_source,
            {$medium_select} AS utm_medium,
            {$campaign_select} AS utm_campaign
        FROM {$views} v
        JOIN {$resources} r ON r.id = v.resource_id
        JOIN {$sessions} s ON s.session_id = v.session_id
        {$referrer_join}
        {$campaign_join}
        {$joins}
        WHERE v.session_id = %d
        ORDER BY v.viewed_at ASC, v.id ASC",
        $session_id
    ));

    return tnjg_apply_campaign_url_fallbacks(is_array($rows) ? $rows : array());
}

function tnjg_referrer_select(string $referrers_table, string $purpose): string
{
    if (!tnjg_table_exists($referrers_table)) {
        return "''";
    }

    if ('label' === $purpose) {
        if (tnjg_column_exists($referrers_table, 'referrer')) {
            return 'ref.referrer';
        }

        if (tnjg_column_exists($referrers_table, 'domain')) {
            return 'ref.domain';
        }
    }

    if (tnjg_column_exists($referrers_table, 'url')) {
        return 'ref.url';
    }

    if (tnjg_column_exists($referrers_table, 'domain')) {
        return 'ref.domain';
    }

    return "''";
}

function tnjg_campaign_select(string $field): string
{
    $campaigns = tnjg_ia_table('campaigns');
    $legacy_column = array('source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign')[$field];
    $expressions = array();

    if (!tnjg_table_exists($campaigns)) {
        return "''";
    }

    if ('source' === $field && tnjg_table_exists(tnjg_ia_table('utm_sources')) && tnjg_column_exists($campaigns, 'utm_source_id')) {
        $expressions[] = 'utm_sources.utm_source';
    }

    if ('medium' === $field && tnjg_table_exists(tnjg_ia_table('utm_mediums')) && tnjg_column_exists($campaigns, 'utm_medium_id')) {
        $expressions[] = 'utm_mediums.utm_medium';
    }

    if ('campaign' === $field && tnjg_table_exists(tnjg_ia_table('utm_campaigns')) && tnjg_column_exists($campaigns, 'utm_campaign_id')) {
        $expressions[] = 'utm_campaigns.utm_campaign';
    }

    if (tnjg_column_exists($campaigns, $legacy_column)) {
        $expressions[] = 'c.' . $legacy_column;
    }

    if (empty($expressions)) {
        return "''";
    }

    return count($expressions) > 1 ? 'COALESCE(' . implode(', ', $expressions) . ')' : $expressions[0];
}

function tnjg_campaign_joins(): string
{
    $campaigns = tnjg_ia_table('campaigns');
    $joins = array();

    if (!tnjg_table_exists($campaigns)) {
        return '';
    }

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

function tnjg_campaign_id_sql(): string
{
    $sessions = tnjg_ia_table('sessions');
    $views = tnjg_ia_table('views');

    if (tnjg_column_exists($sessions, 'campaign_id') && tnjg_column_exists($views, 'campaign_id')) {
        return 'COALESCE(s.campaign_id, v.campaign_id)';
    }

    if (tnjg_column_exists($sessions, 'campaign_id')) {
        return 's.campaign_id';
    }

    return tnjg_column_exists($views, 'campaign_id') ? 'v.campaign_id' : 'NULL';
}

function tnjg_add_hop_aggregates(object $anchor, int $offset, array $views, object $session, int $anchor_position): void
{
    $anchor_id = (int) $anchor->resource_id;
    $hop_position = $anchor_position + $offset;
    $hop = $views[$hop_position] ?? null;

    if (!$hop) {
        return;
    }

    $hop_key = tnjg_offset_key($offset);
    $landing_positions = tnjg_landing_range($offset, $anchor_position);
    $exit_positions = tnjg_exit_range($offset, $anchor_position);

    foreach ($landing_positions as $position) {
        if (isset($views[$position])) {
            $landing_subject = $views[$position];
            tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_pages', tnjg_value($landing_subject->cached_title, __('Unknown landing page', 'tn-journey-graph')), $landing_subject, true);
            tnjg_increment_label_item($anchor_id, $hop_key, 'landing_content_types', tnjg_value($landing_subject->cached_type_label, __('Unknown URL', 'tn-journey-graph')), tnjg_object_type($landing_subject));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_referrers', tnjg_value($session->referrer_domain, __('Direct', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_utm_sources', tnjg_value($session->utm_source, __('None', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_utm_channels', tnjg_value($session->utm_medium, __('None', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'landing_utm_campaigns', tnjg_value($session->utm_campaign, __('None', 'tn-journey-graph')));
        }
    }

    foreach (tnjg_before_range($offset, $anchor_position) as $position) {
        if (isset($views[$position])) {
            $before = $views[$position];
            tnjg_increment_graph_item($anchor_id, $hop_key, 'before_pages', tnjg_value($before->cached_title, __('Unknown page', 'tn-journey-graph')), $before, true);
            tnjg_increment_label_item($anchor_id, $hop_key, 'before_content_types', tnjg_value($before->cached_type_label, __('Unknown URL', 'tn-journey-graph')), tnjg_object_type($before));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'before_referrers', tnjg_value($session->referrer_domain, __('Direct', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'before_utm_sources', tnjg_value($session->utm_source, __('None', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'before_utm_channels', tnjg_value($session->utm_medium, __('None', 'tn-journey-graph')));
            tnjg_increment_graph_item($anchor_id, $hop_key, 'before_utm_campaigns', tnjg_value($session->utm_campaign, __('None', 'tn-journey-graph')));
        }
    }

    foreach (tnjg_after_range($offset, $anchor_position) as $position) {
        if (isset($views[$position])) {
            $after = $views[$position];
            tnjg_increment_graph_item($anchor_id, $hop_key, 'after_pages', tnjg_value($after->cached_title, __('Unknown page', 'tn-journey-graph')), $after, true);
            tnjg_increment_label_item($anchor_id, $hop_key, 'after_content_types', tnjg_value($after->cached_type_label, __('Unknown URL', 'tn-journey-graph')), tnjg_object_type($after));
        }
    }

    foreach ($exit_positions as $position) {
        if (isset($views[$position])) {
            $exit_subject = $views[$position];
            tnjg_increment_graph_item($anchor_id, $hop_key, 'exit_pages', tnjg_value($exit_subject->cached_title, __('Unknown exit page', 'tn-journey-graph')), $exit_subject, true);
            tnjg_increment_label_item($anchor_id, $hop_key, 'exit_content_types', tnjg_value($exit_subject->cached_type_label, __('Unknown URL', 'tn-journey-graph')), tnjg_object_type($exit_subject));
        }
    }
}

function tnjg_offset_key(int $offset): string
{
    if (0 === $offset) {
        return '0';
    }

    return $offset > 0 ? '+' . $offset : (string) $offset;
}

function tnjg_before_range(int $offset, int $anchor_position): array
{
    if ($anchor_position <= 0) {
        return array();
    }

    $end = $offset < 0 ? $anchor_position + $offset : $anchor_position;
    if ($end < 0) {
        return array();
    }

    return range(0, $end);
}

function tnjg_landing_range(int $offset, int $anchor_position): array
{
    if ($offset >= 0) {
        return array($anchor_position);
    }

    $start = $anchor_position + $offset;
    if ($start < 0) {
        return array();
    }

    return range($start, $anchor_position);
}

function tnjg_after_range(int $offset, int $anchor_position): array
{
    if ($offset <= 0) {
        return array();
    }

    return range($anchor_position + 1, $anchor_position + $offset);
}

function tnjg_exit_range(int $offset, int $anchor_position): array
{
    if ($offset <= 0) {
        return array($anchor_position);
    }

    return range($anchor_position, $anchor_position + $offset);
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

function tnjg_increment_label_item(int $anchor_id, string $hop_key, string $panel_key, string $label, string $object_type = ''): void
{
    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $item_key = md5($panel_key . '|' . $label . '|' . $object_type);

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$graph}
            (anchor_resource_id, hop_key, panel_key, item_key, item_label, item_count, item_url, object_type, object_id, metadata, updated_at)
        VALUES (%d, %s, %s, %s, %s, 1, NULL, %s, NULL, NULL, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE item_count = item_count + 1, updated_at = UTC_TIMESTAMP()",
        $anchor_id,
        $hop_key,
        $panel_key,
        $item_key,
        sanitize_text_field($label),
        $object_type
    ));
}

function tnjg_apply_campaign_url_fallbacks(array $rows): array
{
    if (empty($rows)) {
        return $rows;
    }

    $landing_url = (string) ($rows[0]->cached_url ?? '');
    $params = tnjg_utm_params_from_url($landing_url);

    if (empty($params)) {
        return $rows;
    }

    foreach ($rows as $row) {
        if (empty($row->utm_source) && !empty($params['utm_source'])) {
            $row->utm_source = $params['utm_source'];
        }

        if (empty($row->utm_medium) && !empty($params['utm_medium'])) {
            $row->utm_medium = $params['utm_medium'];
        }

        if (empty($row->utm_campaign) && !empty($params['utm_campaign'])) {
            $row->utm_campaign = $params['utm_campaign'];
        }
    }

    return $rows;
}

function tnjg_utm_params_from_url(string $url): array
{
    $query = wp_parse_url($url, PHP_URL_QUERY);
    if (!$query) {
        return array();
    }

    parse_str($query, $params);
    return array_filter(array(
        'utm_source' => isset($params['utm_source']) ? sanitize_text_field((string) $params['utm_source']) : '',
        'utm_medium' => isset($params['utm_medium']) ? sanitize_text_field((string) $params['utm_medium']) : '',
        'utm_campaign' => isset($params['utm_campaign']) ? sanitize_text_field((string) $params['utm_campaign']) : '',
    ));
}

function tnjg_maybe_upgrade_graph_schema(): void
{
    if ((string) get_option('tnjg_graph_schema_version', '') === (string) TNJG_GRAPH_SCHEMA_VERSION) {
        return;
    }

    global $wpdb;
    $graph = tnjg_table('journey_graph');
    $queue = tnjg_table('session_queue');

    if (tnjg_table_exists($graph)) {
        $wpdb->query("TRUNCATE TABLE {$graph}");
    }

    if (tnjg_table_exists($queue)) {
        $wpdb->query("TRUNCATE TABLE {$queue}");
    }

    update_option('tnjg_graph_schema_version', TNJG_GRAPH_SCHEMA_VERSION, false);
    tnjg_update_status(array(
        'last_processed_at' => '',
        'status' => 'queued',
        'message' => __('Journey aggregates were reset for the latest graph model and historical sessions will be reprocessed.', 'tn-journey-graph'),
        'processed_sessions' => 0,
        'queue_counts' => array('open' => 0, 'ready' => 0, 'processed' => 0, 'skipped' => 0),
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
