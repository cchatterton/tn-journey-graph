<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'tnjg_add_admin_page');
add_action('admin_init', 'tnjg_register_settings');
add_action('admin_post_tnjg_manual_process', 'tnjg_handle_manual_process');

function tnjg_add_admin_page(): void
{
    add_options_page(
        __('Techn Journey Graph', 'tn-journey-graph'),
        __('Journey Graph', 'tn-journey-graph'),
        'manage_options',
        'tn-journey-graph',
        'tnjg_render_admin_page'
    );
}

function tnjg_register_settings(): void
{
    register_setting('tnjg_settings', 'tnjg_options', array(
        'type' => 'array',
        'sanitize_callback' => 'tnjg_sanitize_options',
        'default' => tnjg_default_options(),
    ));
}

function tnjg_sanitize_options(array $input): array
{
    $defaults = tnjg_default_options();
    $post_types = get_post_types(array('public' => true), 'names');
    $frequency = sanitize_key((string) ($input['processing_frequency'] ?? $defaults['processing_frequency']));

    return array(
        'enabled' => empty($input['enabled']) ? 0 : 1,
        'required_capability' => sanitize_key((string) ($input['required_capability'] ?? $defaults['required_capability'])),
        'processing_frequency' => in_array($frequency, array('tnjg_every_minute', 'hourly', 'twicedaily', 'daily'), true) ? $frequency : 'hourly',
        'inactivity_threshold_minutes' => max(1, absint($input['inactivity_threshold_minutes'] ?? $defaults['inactivity_threshold_minutes'])),
        'max_prior_hops' => max(0, min(20, absint($input['max_prior_hops'] ?? $defaults['max_prior_hops']))),
        'max_next_hops' => max(0, min(20, absint($input['max_next_hops'] ?? $defaults['max_next_hops']))),
        'histogram_items' => max(1, min(50, absint($input['histogram_items'] ?? $defaults['histogram_items']))),
        'enabled_object_types' => array_values(array_intersect(array_map('sanitize_key', (array) ($input['enabled_object_types'] ?? array())), $post_types)),
        'retention_days' => max(30, absint($input['retention_days'] ?? $defaults['retention_days'])),
    );
}

function tnjg_handle_manual_process(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to process journey data.', 'tn-journey-graph'));
    }

    check_admin_referer('tnjg_manual_process');
    tnjg_process_sessions();
    wp_safe_redirect(add_query_arg(array('page' => 'tn-journey-graph', 'tnjg_processed' => '1'), admin_url('options-general.php')));
    exit;
}

function tnjg_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $options = tnjg_get_options();
    $status = tnjg_status();
    $post_types = get_post_types(array('public' => true), 'objects');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Techn Journey Graph', 'tn-journey-graph'); ?></h1>
        <?php if (!empty($_GET['tnjg_processed'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Journey processing run completed.', 'tn-journey-graph'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('tnjg_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Enable Journey Explorer', 'tn-journey-graph'); ?></th>
                    <td><label><input type="checkbox" name="tnjg_options[enabled]" value="1" <?php checked($options['enabled']); ?>> <?php echo esc_html__('Show the front-end explorer to authorised users.', 'tn-journey-graph'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnjg-required-capability"><?php echo esc_html__('Required capability', 'tn-journey-graph'); ?></label></th>
                    <td><input id="tnjg-required-capability" class="regular-text" type="text" name="tnjg_options[required_capability]" value="<?php echo esc_attr($options['required_capability']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tnjg-frequency"><?php echo esc_html__('Processing frequency', 'tn-journey-graph'); ?></label></th>
                    <td>
                        <select id="tnjg-frequency" name="tnjg_options[processing_frequency]">
                            <?php foreach (array('tnjg_every_minute' => __('Every minute', 'tn-journey-graph'), 'hourly' => __('Hourly', 'tn-journey-graph'), 'twicedaily' => __('Twice daily', 'tn-journey-graph'), 'daily' => __('Daily', 'tn-journey-graph')) as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($options['processing_frequency'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php tnjg_number_row('inactivity_threshold_minutes', __('Inactivity threshold minutes', 'tn-journey-graph'), $options); ?>
                <?php tnjg_number_row('max_prior_hops', __('Maximum prior hops', 'tn-journey-graph'), $options); ?>
                <?php tnjg_number_row('max_next_hops', __('Maximum next hops', 'tn-journey-graph'), $options); ?>
                <?php tnjg_number_row('histogram_items', __('Default histogram items', 'tn-journey-graph'), $options); ?>
                <?php tnjg_number_row('retention_days', __('Data retention days', 'tn-journey-graph'), $options); ?>
                <tr>
                    <th scope="row"><?php echo esc_html__('Enabled post types', 'tn-journey-graph'); ?></th>
                    <td>
                        <?php foreach ($post_types as $post_type) : ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="tnjg_options[enabled_object_types][]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $options['enabled_object_types'], true)); ?>>
                                <?php echo esc_html($post_type->labels->singular_name); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2><?php echo esc_html__('Processing status', 'tn-journey-graph'); ?></h2>
        <table class="widefat striped" style="max-width:760px;">
            <tbody>
                <tr><th><?php echo esc_html__('Status', 'tn-journey-graph'); ?></th><td><?php echo esc_html($status['status']); ?></td></tr>
                <tr><th><?php echo esc_html__('Message', 'tn-journey-graph'); ?></th><td><?php echo esc_html($status['message']); ?></td></tr>
                <tr><th><?php echo esc_html__('Last run', 'tn-journey-graph'); ?></th><td><?php echo esc_html(tnjg_format_datetime($status['last_run_at'])); ?></td></tr>
                <tr><th><?php echo esc_html__('Last processed', 'tn-journey-graph'); ?></th><td><?php echo esc_html(tnjg_format_datetime($status['last_processed_at'])); ?></td></tr>
                <tr><th><?php echo esc_html__('Processed sessions', 'tn-journey-graph'); ?></th><td><?php echo esc_html((string) $status['processed_sessions']); ?></td></tr>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
            <?php wp_nonce_field('tnjg_manual_process'); ?>
            <input type="hidden" name="action" value="tnjg_manual_process">
            <?php submit_button(__('Process completed sessions now', 'tn-journey-graph'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function tnjg_number_row(string $key, string $label, array $options): void
{
    ?>
    <tr>
        <th scope="row"><label for="tnjg-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
        <td><input id="tnjg-<?php echo esc_attr($key); ?>" class="small-text" type="number" min="0" name="tnjg_options[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $options[$key]); ?>"></td>
    </tr>
    <?php
}
