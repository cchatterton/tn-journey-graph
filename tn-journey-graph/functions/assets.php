<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', 'tnjg_enqueue_frontend_assets');
add_action('wp_footer', 'tnjg_render_frontend_shell');

function tnjg_enqueue_frontend_assets(): void
{
    if (!tnjg_is_enabled() || !tnjg_current_user_can_view()) {
        return;
    }

    wp_enqueue_style(
        'tnjg-frontend',
        TNJG_PLUGIN_URL . 'styles/tn-journey-graph.css',
        array(),
        TNJG_VERSION
    );

    wp_enqueue_script(
        'tnjg-frontend',
        TNJG_PLUGIN_URL . 'scripts/tn-journey-graph.js',
        array(),
        TNJG_VERSION,
        true
    );

    wp_localize_script('tnjg-frontend', 'TNJG', array(
        'restUrl' => esc_url_raw(rest_url('tnjg/v1/journey')),
        'nonce' => wp_create_nonce('wp_rest'),
        'context' => tnjg_current_object_context(),
        'labels' => array(
            'button' => __('Journey Explorer', 'tn-journey-graph'),
            'close' => __('Close Journey Explorer', 'tn-journey-graph'),
            'loading' => __('Loading journey data…', 'tn-journey-graph'),
            'empty' => __('No processed journey data is available for this page yet.', 'tn-journey-graph'),
            'error' => __('Journey data could not be loaded.', 'tn-journey-graph'),
            'filter' => __('Content type filter', 'tn-journey-graph'),
        ),
    ));
}

function tnjg_render_frontend_shell(): void
{
    if (!tnjg_is_enabled() || !tnjg_current_user_can_view()) {
        return;
    }
    ?>
    <button class="tnjg-launch" type="button" aria-expanded="false" aria-controls="tnjg-panel">
        <?php echo esc_html__('Journey Explorer', 'tn-journey-graph'); ?>
    </button>
    <section id="tnjg-panel" class="tnjg-panel" aria-live="polite" aria-hidden="true">
        <div class="tnjg-panel__inner"></div>
    </section>
    <?php
}
