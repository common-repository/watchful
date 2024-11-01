<?php

namespace Watchful\Helpers\Sso;

use Watchful\Init;
use WP_Error;
use WP_User;

class Authenticator
{
    const MAX_NUMBER_OF_FAILED_REQUESTS = 3;
    const MAX_TIME_BETWEEN_REQUESTS = 900;

    /** @var Client */
    private $client;

    /** @var bool */
    private $sso_enabled;

    /** @var bool */
    private $sso_adminonly;

    /** @var UserManager */
    private $user_manager;

    public function __construct()
    {
        $this->client = new Client();
        $this->user_manager = new UserManager();

        $settings = get_option('watchfulSettings', Init::get_default_settings());
        $this->sso_enabled = isset($settings['watchful_sso_authentication']) && $settings['watchful_sso_authentication'];
        $this->sso_adminonly = isset($settings['watchful_sso_authentication_adminonly']) && $settings['watchful_sso_authentication_adminonly'];
    }

    /**
     * @param WP_User|WP_Error|null $user
     * @param string $username
     * @param string $password
     * @return WP_Error|WP_User
     */
    public function authenticate($user, $username, $password)
    {
        if ($user instanceof WP_User) {
            return $user;
        }

        if ($this->sso_adminonly && !is_admin()) {
            return $user;
        }

        if (empty($username) || empty($password) || !$this->sso_enabled) {
            return $user;
        }

        if (
            (!isset($_ENV['WATCHFUL_SSO_FORCE_AUTHENTICATION']) || !$_ENV['WATCHFUL_SSO_FORCE_AUTHENTICATION']) &&
            get_option('watchful_last_login_time') &&
            get_option('watchful_last_login_time') > time() - self::MAX_TIME_BETWEEN_REQUESTS &&
            get_option('watchful_last_login_error_counter', 0) > self::MAX_NUMBER_OF_FAILED_REQUESTS
        ) {
            return $user;
        }

        $user_data = $this->client->perform_api_authentication($username, $password);

        update_option('watchful_last_login_time', time());

        if (is_wp_error($user_data)) {
            update_option('watchful_last_login_error_counter', get_option('watchful_last_login_error_counter', 0) + 1);

            return $user_data;
        }

        update_option('watchful_last_login_error_counter', 0);

        return $this->user_manager->get_wp_user_by_data($user_data);
    }
}
