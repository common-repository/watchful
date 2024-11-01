<?php

namespace Watchful\Helpers;

use Plugin_Upgrader;
use stdClass;
use Watchful\Exception;
use Watchful\Helpers\Files as FilesHelper;
use Watchful\Skins\SkinPluginUpgrader;

class PluginManager
{
    /**
     * @param string|null $slug
     * @param string|null $zip
     * @param bool $enable_maintenance_mode
     * @param bool $handle_shutdown
     * @return void
     * @throws Exception
     */
    public function install_plugin(
        $slug = null,
        $zip = null,
        $enable_maintenance_mode = false,
        $handle_shutdown = false
    ) {
        include_once ABSPATH.'wp-admin/includes/admin.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        if (!$slug && !$zip) {
            throw new Exception('parameter is missing. slug or zip required', 400);
        }

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            throw new Exception('file modification is disabled (DISALLOW_FILE_MODS)', 403);
        }

        if ($zip) {
            $install_path = $zip;
            $slug = $this->get_slug_from_zip($zip);
        }

        if (
            $slug &&
            $this->is_installed($slug)
        ) {
            $this->update_plugin($slug, $zip, $enable_maintenance_mode, $handle_shutdown);

            return;
        }

        if (empty($install_path)) {
            $install_path = $this->download_link_from_slug($slug);
        }

        if (empty($install_path)) {
            throw new Exception('Could not get install path', 500, [
                'slug' => $slug,
                'zip' => $zip,
            ]);
        }

        $skin = new SkinPluginUpgrader();
        $upgrader = new Plugin_Upgrader($skin);

        if ($enable_maintenance_mode) {
            WP_Filesystem();
            $upgrader->maintenance_mode(true);
        }

        try {
            $result = $upgrader->install($install_path);
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode();
            }
        } catch (\Exception $e) {
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode();
            }

            throw new Exception($e->getMessage(), 500);
        }

        if (is_wp_error($result)) {
            throw new Exception('Installation of the plugin failed', 400, [
                'error' => $result->get_error_message(),
                'slug' => $slug,
                'zip' => $zip,
                'install_path' => $install_path,
            ]);
        }

        if (is_wp_error($skin->error)) {
            throw new Exception('Installation of the plugin failed', 400, [
                'error' => $skin->error->get_error_message(),
                'slug' => $slug,
                'zip' => $zip,
                'install_path' => $install_path,
            ]);
        }

        if (false === $result) {
            throw new Exception('unknown error', 500);
        }

        activate_plugin($this->get_plugin_path($slug), '', false, true);
    }

    /**
     * Get the slug of a plugin from his zip file.
     *
     * @param string $zip The zip file.
     *
     * @return bool
     * @throws Exception
     */
    private function get_slug_from_zip($zip)
    {
        $helper = new FilesHelper();

        $potential_slugs = $helper->get_zip_directories($zip);

        return $this->get_slug_from_list($potential_slugs);
    }

    /**
     * Get the correct slug from a given list.
     *
     * @param array $list List of slugs.
     *
     * @return string|bool
     */
    private function get_slug_from_list($list)
    {
        foreach ($list as $slug) {
            if ($this->is_installed($slug)) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Check if a plugin is already installed.
     *
     * @param string $slug The plugin slug.
     *
     * @return bool
     */
    public function is_installed($slug)
    {
        wp_clean_plugins_cache();
        $plugins = get_plugins();

        foreach ($plugins as $path => $plugin) {
            if ($path === $slug || in_array($slug, explode('/', $path), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string | null $plugin_path
     * @param string | null $zip
     * @param bool $enable_maintenance_mode
     * @param bool $handle_shutdown
     * @return array
     * @throws Exception
     */
    public function update_plugin(
        $plugin_path = null,
        $zip = null,
        $enable_maintenance_mode = false,
        $handle_shutdown = false
    ) {
        include_once ABSPATH.'wp-admin/includes/admin.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        include_once ABSPATH.WPINC.'/update.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        if (empty($plugin_path) && empty($zip)) {
            throw new Exception('parameter is missing. slug or zip required', 400);
        }

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            throw new Exception('file modification is disabled (DISALLOW_FILE_MODS)', 403);
        }

        // If slug is missing we need to get it from the zip.
        if ($zip && !$plugin_path) {
            $plugin_path = $this->get_slug_from_zip($zip);
        }

        // Get the current state.
        $is_active = is_plugin_active($plugin_path);
        $is_active_network = is_plugin_active_for_network($plugin_path);

        // Force a plugin update check.
        wp_update_plugins();

        $skin = new SkinPluginUpgrader();
        $upgrader = new Plugin_Upgrader($skin);
        $plugin_backup_manager = new PluginBackupManager();

        $min_php_version = $this->next_version_info($plugin_path);

        if ($zip) {
            $this->set_transient_update_for_zip($zip, $plugin_path);
        }

        if (version_compare(phpversion(), $min_php_version) < 0) {
            throw new Exception("The minimum required PHP version for this update is ".$min_php_version, 500);
        }

        if ($enable_maintenance_mode) {
            WP_Filesystem();
            $upgrader->maintenance_mode(true);
        }

        if ($handle_shutdown) {
            $plugin_backup_manager->make_backup($plugin_path);
        }

        try {
            $result = $upgrader->upgrade($plugin_path);
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode();
            }
        } catch (\Exception $e) {
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode();
            }

            $this->handle_update_error($handle_shutdown, $plugin_path, $plugin_backup_manager, $e->getMessage());
        }

        if (is_wp_error($result)) {
            $this->handle_update_error(
                $handle_shutdown,
                $plugin_path,
                $plugin_backup_manager,
                $result->get_error_message(),
                $result->get_error_code()
            );
        }

        if (is_wp_error($skin->error)) {
            $this->handle_update_error(
                $handle_shutdown,
                $plugin_path,
                $plugin_backup_manager,
                $skin->error->get_error_message(),
                $skin->error->get_error_code()
            );
        }

        // This default Exception should not be thrown because WP_Errors should be encountered just above.
        if (false === $result || is_null($result)) {
            $this->handle_update_error($handle_shutdown, $plugin_path, $plugin_backup_manager, 'unknown error');
        }

        // Reactivate the plugin if he was active.
        if ($is_active) {
            activate_plugin($plugin_path, '', $is_active_network, true);
        }

        $plugin_backup_manager->cleanup();

        return [
            'status' => 'success',
            'version' => get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin_path)['Version'],
        ];
    }

    private function next_version_info($plugin_path)
    {
        $current = get_site_transient('update_plugins');

        if (isset($current->response[$plugin_path])) {
            return $current->response[$plugin_path]->requires_php;
        }

        return phpversion();
    }

    /**
     * Override the zip file in the plugins list used by the upgrader.
     *
     * @param string $zip The zip file.
     * @param string $plugin_path The plugin path.
     */
    private function set_transient_update_for_zip($zip, $plugin_path)
    {
        $current = get_site_transient('update_plugins');

        if (!isset($current->response[$plugin_path])) {
            $current->response[$plugin_path] = new stdClass();
        }

        $current->response[$plugin_path]->package = $zip;

        set_site_transient('update_plugins', $current);
    }

    /**
     * @param string $slug
     * @param PluginBackupManager $plugin_backup_manager
     * @return void
     * @throws Exception
     */
    private function handle_update_error(
        $handle_shutdown,
        $slug,
        $plugin_backup_manager,
        $error_message = null,
        $error_code = 500
    ) {
        $is_installed = $this->is_installed($slug);

        if ($handle_shutdown && !$is_installed) {
            add_action('shutdown', [$plugin_backup_manager, 'restore_backup'], 0, false);
        }

        throw new Exception($error_message, $error_code, [
            'plugin' => $slug,
            'is_installed' => $this->is_installed($slug),
            'handle_shutdown' => $handle_shutdown,
        ]);
    }

    private function download_link_from_slug($slug)
    {
        require_once ABSPATH.'wp-admin/includes/plugin-install.php';

        $api_args = array(
            'slug' => $slug,
            'fields' => array('sections' => false),
        );
        $api = plugins_api('plugin_information', $api_args);

        // Usually because slug is wrong.
        if (is_wp_error($api)) {
            throw new Exception('plugin not found on wordpress.org : '.$api->get_error_message(), 400);
        }

        return $api->download_link;
    }

    /**
     * Try to get the plugin path from his slug.
     *
     * @param string $slug The plugin slug.
     *
     * @return string
     *
     * @throws Exception If no path is found.
     */
    public function get_plugin_path($slug)
    {
        $plugins = get_plugins();

        foreach ($plugins as $path => $plugin) {
            if ($path === $slug || in_array($slug, explode('/', $path), true) || stristr($path, $slug)) {
                return $path;
            }
        }

        throw new Exception('could not find plugin path', 404);
    }

    /**
     * Get all plugins from the current WP site.
     *
     * @param array $plugin_data Plugin data already in Watchful.
     *
     * @return array
     */
    public function get_all_plugins($plugin_data = array())
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.WPINC.'/update.php';

        if (!is_array($plugin_data)) {
            $plugin_data = (array)$plugin_data;
        }

        // Get all plugins.
        $plugins = get_plugins();

        // Delete the transient so wp_update_plugins can get fresh data.
        if (function_exists('get_site_transient')) {
            delete_site_transient('update_plugins');
        } else {
            delete_transient('update_plugins');
        }

        // Force a plugin update check.
        wp_update_plugins();

        $current = get_site_transient('update_plugins');

        // Premium plugins that have adopted the ManageWP API report new plugins by this filter.
        $watchful_updates = apply_filters('watchfulUpdateNotification', array()
        ); // phpcs:ignore WordPress.NamingConventions.ValidHookName

        foreach ((array)$plugins as $plugin_file => $plugin) {
            $plugins[$plugin_file]['active'] = is_plugin_active($plugin_file);

            $ext_data = false;
            if (!empty($plugin_data[$plugin_file])) {
                $ext_data = !empty($plugin_data[$plugin_file]->ext_data) ? $plugin_data[$plugin_file]->ext_data : false;

                if (false !== $ext_data && !empty($ext_data->new_version)) {
                    $plugins[$plugin_file]['latest_version'] = $ext_data->new_version;
                }
            }

            if (!$ext_data) {
                $watchful_plugin_update = false;
                foreach ($watchful_updates as $watchful_update) {
                    if (!empty($watchful_update['Name']) && $plugin['Name'] === $watchful_update['Name']) {
                        $watchful_plugin_update = $watchful_update;
                    }
                }

                if ($watchful_plugin_update) {
                    $plugins[$plugin_file]['latest_version'] = $watchful_plugin_update['new_version'];
                } elseif (isset($current->response[$plugin_file])) {
                    $plugins[$plugin_file]['latest_version'] = $current->response[$plugin_file]->new_version;
                    if (isset($current->response[$plugin_file]->package)) {
                        $plugins[$plugin_file]['latest_package'] = $current->response[$plugin_file]->package;
                    }
                    if (isset($current->response[$plugin_file]->slug)) {
                        $plugins[$plugin_file]['slug'] = $current->response[$plugin_file]->slug;
                    }
                } else {
                    $plugins[$plugin_file]['latest_version'] = $plugin['Version'];
                }
            }
        }

        return $this->modify_mapping_plugin($plugins);
    }

    /**
     * Create the right mapping for Watchful API.
     *
     * @param array $plugins List of plugins.
     *
     * @return array
     */
    private function modify_mapping_plugin(&$plugins)
    {
        $output = array();

        foreach ($plugins as $key => $plugin) {
            $new_mapping = array();
            $new_mapping['name'] = $plugin['Name'];
            $new_mapping['realname'] = $key;
            $new_mapping['active'] = $plugin['active'];
            $new_mapping['authorurl'] = $plugin['PluginURI'];
            $new_mapping['version'] = $plugin['Version'];
            $new_mapping['updateVersion'] = $plugin['latest_version'];
            $new_mapping['vUpdate'] = $plugin['latest_version'] !== $plugin['Version'];
            $new_mapping['type'] = 'plugin';
            $new_mapping['network'] = $plugin['Network'];
            $new_mapping['creationdate'] = null;
            $new_mapping['updateServer'] = null;
            $new_mapping['extId'] = 0;
            $new_mapping['variant'] = null;
            $output[] = $new_mapping;
        }

        return $output;
    }
}