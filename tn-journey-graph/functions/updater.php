<?php

if (!defined('ABSPATH')) {
    exit;
}

function tnjg_register_github_updater(): void
{
    if (!is_admin()) {
        return;
    }

    $updater = new TNJG_GitHub_Updater();
    add_filter('pre_set_site_transient_update_plugins', array($updater, 'add_update_data'));
    add_filter('site_transient_update_plugins', array($updater, 'add_update_data'));
    add_filter('plugins_api', array($updater, 'plugin_details'), 10, 3);
    add_filter('plugin_row_meta', array($updater, 'row_meta'), 10, 2);
    add_action('upgrader_process_complete', array($updater, 'clear_cache_after_upgrade'), 10, 2);
}

class TNJG_GitHub_Updater
{
    private const OWNER = 'cchatterton';
    private const REPO = 'tn-journey-graph';
    private const SLUG = 'tn-journey-graph';
    private const ASSET_NAME = 'tn-journey-graph.zip';
    private const RELEASE_TRANSIENT = 'tnjg_github_latest_release';
    private const ERROR_TRANSIENT = 'tnjg_github_latest_release_error';

    public function add_update_data($transient)
    {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : array();
        $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : array();

        $release = $this->latest_release();
        $plugin_file = TNJG_PLUGIN_BASENAME;

        if (!$release) {
            unset($transient->response[$plugin_file], $transient->no_update[$plugin_file]);
            return $transient;
        }

        $version = $this->release_version($release);
        $package = $this->asset_url($release);

        if ($version && $package && version_compare($version, TNJG_VERSION, '>')) {
            $transient->response[$plugin_file] = (object) array(
                'id' => $this->repo_url(),
                'slug' => self::SLUG,
                'plugin' => $plugin_file,
                'new_version' => $version,
                'url' => $this->repo_url(),
                'package' => $package,
                'requires' => '6.0',
                'requires_php' => '8.1',
            );
            unset($transient->no_update[$plugin_file]);
            return $transient;
        }

        unset($transient->response[$plugin_file], $transient->no_update[$plugin_file]);
        return $transient;
    }

    public function plugin_details($result, string $action, object $args)
    {
        if ('plugin_information' !== $action || empty($args->slug) || self::SLUG !== $args->slug) {
            return $result;
        }

        $release = $this->latest_release();
        if (!$release) {
            return $result;
        }

        return (object) array(
            'name' => 'Techn Journey Graph',
            'slug' => self::SLUG,
            'version' => $this->release_version($release) ?: TNJG_VERSION,
            'author' => 'Techn',
            'homepage' => $this->repo_url(),
            'download_link' => $this->asset_url($release),
            'requires' => '6.0',
            'requires_php' => '8.1',
            'sections' => array(
                'description' => __('Front-end journey exploration powered by completed Independent Analytics sessions.', 'tn-journey-graph'),
                'changelog' => wp_kses_post((string) ($release['body'] ?? '')),
            ),
        );
    }

    public function row_meta(array $links, string $file): array
    {
        if (TNJG_PLUGIN_BASENAME !== $file) {
            return $links;
        }

        $links[] = '<a href="' . esc_url($this->repo_url()) . '">' . esc_html__('GitHub', 'tn-journey-graph') . '</a>';
        $links[] = '<a href="' . esc_url($this->check_updates_url()) . '">' . esc_html__('Check for updates', 'tn-journey-graph') . '</a>';
        return $links;
    }

    public function clear_cache_after_upgrade($upgrader, array $hook_extra): void
    {
        if (($hook_extra['type'] ?? '') !== 'plugin' || empty($hook_extra['plugins']) || !in_array(TNJG_PLUGIN_BASENAME, (array) $hook_extra['plugins'], true)) {
            return;
        }

        delete_site_transient(self::RELEASE_TRANSIENT);
        delete_site_transient(self::ERROR_TRANSIENT);
    }

    private function latest_release(): ?array
    {
        if ($this->is_forced_check()) {
            delete_site_transient(self::RELEASE_TRANSIENT);
        }

        $cached = get_site_transient(self::RELEASE_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Techn-Journey-Graph/' . TNJG_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            set_site_transient(self::ERROR_TRANSIENT, array('type' => 'wp_error', 'message' => $response->get_error_message(), 'checked_at' => time()), 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            set_site_transient(self::ERROR_TRANSIENT, array('type' => 'http_error', 'code' => $code, 'checked_at' => time()), 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = json_decode($body, true);
        if (!is_array($release) || !$this->release_version($release)) {
            set_site_transient(self::ERROR_TRANSIENT, array('type' => 'json_error', 'checked_at' => time()), 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $cache_length = version_compare($this->release_version($release), TNJG_VERSION, '>') ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
        set_site_transient(self::RELEASE_TRANSIENT, $release, $cache_length);
        delete_site_transient(self::ERROR_TRANSIENT);
        return $release;
    }

    private function release_version(array $release): string
    {
        return ltrim((string) ($release['tag_name'] ?? ''), 'vV');
    }

    private function asset_url(array $release): string
    {
        foreach ((array) ($release['assets'] ?? array()) as $asset) {
            if (self::ASSET_NAME === ($asset['name'] ?? '') && !empty($asset['browser_download_url'])) {
                return esc_url_raw((string) $asset['browser_download_url']);
            }
        }

        return '';
    }

    private function repo_url(): string
    {
        return 'https://github.com/' . self::OWNER . '/' . self::REPO;
    }

    private function check_updates_url(): string
    {
        $base_url = is_multisite() ? network_admin_url('update-core.php') : admin_url('update-core.php');
        return add_query_arg('force-check', '1', $base_url);
    }

    private function is_forced_check(): bool
    {
        if (!current_user_can('update_plugins')) {
            return false;
        }

        $force = isset($_GET['force-check']) || isset($_POST['force-check']);
        $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));
        return $force || in_array($action, array('update-selected', 'upgrade-plugin', 'do-plugin-upgrade'), true);
    }
}
