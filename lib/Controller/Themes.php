<?php
/**
 * Controller for managing WP themes.
 *
 * @version   2016-12-20 11:41 UTC+01
 * @package   Watchful WP Client
 * @author    Watchful
 * @authorUrl https://watchful.net
 * @copyright Copyright (c) 2020 watchful.net
 * @license   GNU/GPL
 */

namespace Watchful\Controller;

use Theme_Upgrader;
use Watchful\Exception;
use Watchful\Helpers\Authentification;
use Watchful\Helpers\Files as FilesHelper;
use Watchful\Skins\SkinThemeUpgrader;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Theme;

/**
 * WP REST API Menu routes
 *
 * @package WP_API_Menus
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Watchful Themes controller class.
 */
class Themes implements BaseControllerInterface
{

    /**
     * Register watchful routes for WP API v2.
     *
     * @since  1.2.0
     */
    public function register_routes()
    {
        register_rest_route(
            'watchful/v1',
            '/theme/install',
            array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'install_theme'),
                    'permission_callback' => array('Watchful\Routes', 'authentification'),
                    'args' => array_merge(
                        Authentification::get_arguments(),
                        array(
                            'slug' => array(
                                'default' => null,
                                'sanitize_callback' => 'esc_attr',
                            ),
                            'zip' => array(
                                'default' => null,
                                'sanitize_callback' => 'esc_url',
                            ),
                        )
                    ),
                ),
            )
        );

        register_rest_route(
            'watchful/v1',
            '/theme/update',
            array(
                array(
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => array($this, 'update_theme'),
                    'permission_callback' => array('Watchful\Routes', 'authentification'),
                    'args' => array_merge(
                        Authentification::get_arguments(),
                        array(
                            'slug' => array(
                                'default' => null,
                                'sanitize_callback' => 'esc_attr',
                            ),
                        )
                    ),
                ),
            )
        );
    }

    /**
     * Update a theme from his slug.
     *
     * @param WP_REST_Request $request The WP request object.
     *
     * @return mixed
     *
     * @throws Exception If the slug is not valid.
     */
    public function update_theme(WP_REST_Request $request)
    {
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.WPINC.'/theme.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';

        $body = $request->get_body();

        $enable_maintenance_mode = false;
        $new_version = null;
        if (!empty($body)) {
            $post_data = json_decode($body);

            if (!empty($post_data) && !empty($post_data->package)) {
                $zip = $post_data->package;
            }

            if (!empty($post_data) && !empty($post_data->maintenance_mode)) {
                $enable_maintenance_mode = (bool)$post_data->maintenance_mode;
            }

            if (!empty($post_data) && !empty($post_data->new_version)) {
                $new_version = $post_data->new_version;
            }
        }

        $slug = $request->get_param('slug');

        if (empty($zip)) {
            // Has this if coming from install route with zip parameter.
            $zip = $request->get_param('zip');
        }

        if (empty($slug) && empty($zip)) {
            throw new Exception('parameter is missing. slug required or zip', 400);
        }

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            throw new Exception('file modification is disabled (DISALLOW_FILE_MODS)', 403);
        }

        // If slug is missing we need to get it from the zip.
        if ($zip && !$slug) {
            $slug = $this->get_slug_from_zip($zip);
        }

        // Force a theme update check.
        wp_update_themes();
        $skin = new SkinThemeUpgrader();
        $upgrader = new Theme_Upgrader($skin);

        $min_php_version = $this->next_version_info($slug);

        if ($zip) {
            $this->update_from_zip($zip, $slug, $new_version);
        }

        if (version_compare(phpversion(), $min_php_version) < 0) {
            throw new Exception("The minimum required PHP version for this update is ".$min_php_version, 500);
        }

        if ($enable_maintenance_mode) {
            WP_Filesystem();
            $upgrader->maintenance_mode(true);
        }

        try {
            $result = $upgrader->upgrade($slug);
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode(false);
            }
        } catch (Exception $e) {
            if ($enable_maintenance_mode) {
                $upgrader->maintenance_mode(false);
            }
            throw new Exception(
                $e->getMessage(),
                500,
                [
                    'theme' => $slug,
                    'is_installed' => $this->is_installed($slug),
                ]
            );
        }

        if (is_wp_error($result)) {
            throw new Exception(
                $result->get_error_code(),
                500,
                [
                    'wp_error_data' => $result->get_error_message(),
                    'theme' => $slug,
                    'is_installed' => $this->is_installed($slug),
                ]
            );
        }
        if (is_wp_error($skin->error)) {
            throw new Exception(
                $skin->error->get_error_code(),
                500,
                [
                    'wp_error_data' => $skin->error->get_error_message(),
                    'theme' => $slug,
                    'is_installed' => $this->is_installed($slug),
                ]
            );
        }

        // This default Exception should not be thrown because WP_Errors should be encountered just above.
        if (false === $result || is_null($result)) {
            throw new Exception(
                'unknown error',
                400,
                [
                    'theme' => $slug,
                    'is_installed' => $this->is_installed($slug),
                ]
            );
        }

        return new WP_REST_Response([
                                        'status' => 'success',
                                        'version' => wp_get_theme($slug)['Version'],
                                    ]);
    }

    /**
     * Get the slug of a theme from his zip file.
     *
     * @param string $zip The zip file.
     *
     * @return bool
     */
    private function get_slug_from_zip($zip)
    {
        $helper = new FilesHelper();

        $this->potential_slugs = $helper->get_zip_directories($zip);

        return $this->get_slug_from_list($this->potential_slugs);
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
     * Check if a theme is already installed.
     *
     * @param string $slug The theme slug.
     *
     * @return bool
     */
    public function is_installed($slug)
    {
        $themes = wp_get_themes();

        foreach ($themes as $path => $theme) {
            if ($path === $slug || in_array($slug, explode('/', $path), true)) {
                return true;
            }
        }

        return false;
    }

    private function next_version_info($slug)
    {
        $current = get_site_transient('update_themes');

        if (isset($current->response[$slug])) {
            return $current->response[$slug]['requires_php'];
        }

        return phpversion();
    }

    /**
     * Override the zip file in the themes list used by the upgrader.
     *
     * @param string $zip The zip file.
     * @param string $slug The theme path.
     */
    private function update_from_zip($zip, $slug, $new_version = null)
    {
        $current = get_site_transient('update_themes');

        if (!isset($current->response[$slug]) && !$new_version) {
            return;
        }

        $theme = wp_get_theme($slug);
        if (empty($theme)) {
            return;
        }

        add_filter('pre_set_site_transient_update_themes', function ($value) use ($theme, $zip, $slug, $new_version) {
            $template = $theme->get_template();
            if (!isset($value->response[$template])) {
                $value->response[$template] = [
                    'theme' => $template,
                    'new_version' => $new_version,
                    'url' => $theme->get('ThemeURI'),
                ];
            }

            $value->response[$slug]['package'] = $zip;

            return $value;
        });

        set_site_transient('update_themes', $current);
    }

    /**
     * Install a theme from his slug or url to zip.
     *
     * @param WP_REST_Request $request The WP request object.
     *
     * @return mixed
     *
     * @throws Exception If the slug and zip are missing.
     */
    public function install_theme(WP_REST_Request $request)
    {
        require_once ABSPATH.'wp-admin/includes/admin.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.WPINC.'/theme.php';

        $slug = $request->get_param('slug');
        $zip = $request->get_param('zip');

        if (!$slug && !$zip) {
            throw new Exception('parameter is missing. slug or zip required', 400);
        }

        // Install from name.
        if ($slug) {
            $install_path = $this->download_link_from_slug($slug);
        }

        // Install from url.
        if ($zip) {
            $install_path = $this->download_link_from_zip($zip);
        }

        $skin = new SkinThemeUpgrader();
        $upgrader = new Theme_Upgrader($skin);

        $result = $upgrader->install($install_path);

        if (is_wp_error($result)) {
            throw new Exception('installation of the theme failed : '.$result->get_error_message(), 400);
        }

        if (!$result) {
            throw new Exception('unknown error', 500);
        }

        return $result;
    }

    /**
     * Check that the slug is valid and get the download link.
     *
     *
     * @param string $slug The theme slug.
     *
     * @return mixed|string
     *
     * @throws Exception If the theme is already installed.
     */
    private function download_link_from_slug($slug)
    {
        if (wp_get_theme($slug)->exists()) {
            throw new Exception('theme is already installed', 400);
        }

        $api_args = array(
            'slug' => $slug,
            'fields' => array('sections' => false),
        );

        $api = themes_api('theme_information', $api_args);

        // Usually because slug is wrong.
        if (is_wp_error($api)) {
            throw new Exception('theme not found on wordpress.org : '.$api->get_error_message(), 400);
        }

        return $api->download_link;
    }

    /**
     * Check that the zip is valid.
     *
     * @param string $zip The theme zip file.
     *
     * @return mixed|string
     *
     * @throws Exception If the theme is already installed.
     */
    private function download_link_from_zip($zip)
    {
        $helper = new FilesHelper();

        $potential_slugs = $helper->get_zip_directories($zip);
        $is_installed = false;

        foreach ($potential_slugs as $slug) {
            $is_installed = wp_get_theme($slug)->exists();

            if ($is_installed) {
                break;
            }
        }

        if ($is_installed) {
            throw new Exception('theme is already installed', 400);
        }

        return $zip;
    }

    /**
     * Get Themes.
     *
     * @return array
     */
    public function get_themes($plugin_data = array())
    {
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.WPINC.'/theme.php';
        require_once ABSPATH.WPINC.'/update.php';

        // Get all themes.
        $themes = wp_get_themes();

        // Get the active theme.
        $active = get_template();

        // Delete the transient so wp_update_themes can get fresh data.
        delete_site_transient('update_themes');

        // Force a theme update check.
        wp_update_themes();

        // Different versions of wp store the updates in different places.
        $current = get_site_transient('update_themes');

        $themes = array_filter($themes, function ($theme) use ($current) {
            return is_object($theme) && is_a($theme, 'WP_Theme');
        });

        $parsed_themes = [];

        foreach ($themes as $key => $theme) {
            /**
             * The WP_Theme object.
             *
             * @var WP_Theme $theme
             */
            $new_version = isset(
                $current->response[$theme->get_stylesheet()]
            ) ? $current->response[$theme->get_stylesheet()]['new_version'] : $theme->get('Version');

            if (isset($plugin_data->$key->ext_data->new_version)) {
                $new_version = $plugin_data->$key->ext_data->new_version;
            }

            $current_version = $theme->get('Version');

            $parsed_theme = array(
                'name' => $theme->get('Name'),
                'realname' => $key,
                'active' => $active === $key,
                'authorurl' => $theme->get('AuthorURI'),
                'version' => $current_version,
                'updateVersion' => $new_version,
                'vUpdate' => $current_version !== $new_version,
                'type' => 'theme',
                'creationdate' => null,
                'updateServer' => null,
                'extId' => 0,
                'variant' => null,
            );

            $parsed_themes[] = $parsed_theme;
        }

        return $parsed_themes;
    }
}
