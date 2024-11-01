<?php
/**
 * Controller for managing WP plugins.
 *
 * @version   2016-12-20 11:41 UTC+01
 * @package   Watchful WP Client
 * @author    Watchful
 * @authorUrl https://watchful.net
 * @copyright Copyright (c) 2020 watchful.net
 * @license   GNU/GPL
 */

namespace Watchful\Controller;

/**
 * WP REST API Menu routes
 */

use Watchful\Exception;
use Watchful\Helpers\Authentification;
use Watchful\Helpers\PluginManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugins implements BaseControllerInterface
{
    private $plugin_manager;

    public function __construct()
    {
        $this->plugin_manager = new PluginManager();
    }

    /**
     * Register WP REST API routes.
     */
    public function register_routes()
    {
        register_rest_route(
            'watchful/v1',
            '/plugin/install',
            array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array($this, 'install_plugin'),
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
                            'status' => array(
                                'default' => 1,
                                'sanitize_callback' => 'wp_validate_boolean',
                            ),
                        )
                    ),
                ),
            )
        );

        register_rest_route(
            'watchful/v1',
            '/plugin/update',
            array(
                array(
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => array($this, 'update_plugin'),
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
            '/plugin/activate',
            array(
                array(
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => array($this, 'activate_plugin'),
                    'permission_callback' => array('Watchful\Routes', 'authentification'),
                    'args' => array_merge(
                        Authentification::get_arguments(),
                        array(
                            'slug' => array(
                                'default' => null,
                                'sanitize_callback' => 'esc_attr',
                            ),
                            'status' => array(
                                'default' => 1,
                                'sanitize_callback' => 'wp_validate_boolean',
                            ),
                        )
                    ),
                ),
            )
        );
    }

    /**
     * Enable or disable a plugin.
     *
     * @param WP_REST_Request $request The request with the plugin info.
     *
     * @return WP_REST_Response
     * @throws Exception If plugin info is not available in request.
     */
    public function activate_plugin(WP_REST_Request $request)
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        $plugin_path = $this->plugin_manager->get_plugin_path($request->get_param('slug'));

        if ($request->get_param('status')) {
            $result = activate_plugin($plugin_path, '', false, true);
        } else {
            deactivate_plugins($plugin_path);
        }

        if (!empty($result) && is_wp_error($result)) {
            throw new Exception('plugin state could not be changed : '.$result->get_error_message(), 400);
        }

        return new WP_REST_Response(true);
    }

    /**
     * Install a plugin from his slug or from a zip.
     *
     * @param WP_REST_Request $request The request with the plugin info.
     *
     * @return WP_REST_Response
     * @throws Exception If plugin info is not available in request.
     */
    public function install_plugin(WP_REST_Request $request)
    {
        $params = $this->parse_install_update_request_params($request);

        $this->plugin_manager->install_plugin(
            $params['slug'],
            $params['zip'],
            $params['enable_maintenance_mode'],
            $params['handle_shutdown']
        );

        return new WP_REST_Response(true);
    }

    private function parse_install_update_request_params(WP_REST_Request $request)
    {
        $params = array(
            'slug' => $request->get_param('slug'),
            'zip' => $request->get_param('zip'),
            'enable_maintenance_mode' => false,
            'handle_shutdown' => false,
        );

        $body = $request->get_body();

        if (!empty($body)) {
            $post_data = json_decode($body);

            if (!empty($post_data) && !empty($post_data->package)) {
                $params['zip'] = $post_data->package;
            }

            if (!empty($post_data) && !empty($post_data->maintenance_mode)) {
                $params['enable_maintenance_mode'] = (bool)$post_data->maintenance_mode;
            }

            if (!empty($post_data) && !empty($post_data->handle_shutdown)) {
                $params['handle_shutdown'] = (bool)$post_data->handle_shutdown;
            }
        }

        return $params;
    }

    /**
     * Update a plugin from his slug.
     *
     * @param WP_REST_Request $request The request with the plugin info.
     *
     * @return WP_REST_Response
     * @throws Exception If plugin info is not available in request.
     */
    public function update_plugin(WP_REST_Request $request)
    {
        $params = $this->parse_install_update_request_params($request);

        return new WP_REST_Response(
            $this->plugin_manager->update_plugin(
                $params['slug'],
                $params['zip'],
                $params['enable_maintenance_mode'],
                $params['handle_shutdown']
            )
        );
    }
}
