<?php

namespace Watchful\Helpers;

use WP_Error;

class PluginBackupManager
{
    /** @var array */
    private $args = array();

    /**
     * @param string $plugin_path
     * @return bool|WP_Error
     */
    public function make_backup($plugin_path)
    {
        $this->init_fs();

        $args = $this->build_args($plugin_path);
        $result = $this->copy_to_temp_backup_dir($args);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            return new WP_Error('fs_temp_backup_failed');
        }

        $this->args[] = $args;

        return true;
    }

    private function init_fs()
    {
        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            require_once ABSPATH.'/wp-admin/includes/file.php';
            WP_Filesystem();
        }
    }

    private function build_args($plugin_path)
    {
        return array(
            'slug' => dirname($plugin_path),
            'plugin_path' => $plugin_path,
            'src' => WP_PLUGIN_DIR,
            'dir' => 'plugins',
            'is_active' => is_plugin_active($plugin_path),
        );
    }

    /**
     * @param array $args
     * @return bool|WP_Error
     */
    private function copy_to_temp_backup_dir($args)
    {
        global $wp_filesystem;

        if (empty($args['slug']) || empty($args['src']) || empty($args['dir'])) {
            return false;
        }

        /*
         * Skip any plugin that has "." as its slug.
         * A slug of "." will result in a `$src` value ending in a period.
         *
         * On Windows, this will cause the 'plugins' folder to be moved,
         * and will cause a failure when attempting to call `mkdir()`.
         */
        if ('.' === $args['slug']) {
            return false;
        }

        if (!$wp_filesystem->wp_content_dir()) {
            return new WP_Error('fs_no_content_dir');
        }

        $dest_dir = $this->get_backup_dir();
        $sub_dir = $dest_dir.$args['dir'].'/';

        // Create the temporary backup directory if it does not exist.
        if (!$wp_filesystem->is_dir($sub_dir)) {
            if (!$wp_filesystem->is_dir($dest_dir)) {
                $wp_filesystem->mkdir($dest_dir, FS_CHMOD_DIR);
            }

            if (!$wp_filesystem->mkdir($sub_dir, FS_CHMOD_DIR)) {
                // Could not create the backup directory.
                return new WP_Error('fs_temp_backup_mkdir');
            }
        }

        $src_dir = $wp_filesystem->find_folder($args['src']);
        $src = trailingslashit($src_dir).$args['slug'];
        $dest = $dest_dir.trailingslashit($args['dir']).$args['slug'];

        // Delete the temporary backup directory if it already exists.
        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }

        // Move to the temporary backup directory.
        $result = copy_dir($src, $dest);
        if (is_wp_error($result)) {
            return new WP_Error('fs_temp_backup_move');
        }

        return true;
    }

    private function get_backup_dir()
    {
        $this->init_fs();
        global $wp_filesystem;

        return $wp_filesystem->wp_content_dir().'watchful-upgrade-temp-backup/';
    }

    public function restore_backup()
    {
        $this->init_fs();
        global $wp_filesystem;

        $errors = new WP_Error();

        foreach ($this->args as $args) {
            if (empty($args['slug']) || empty($args['src']) || empty($args['dir'])) {
                return false;
            }

            if (!$wp_filesystem->wp_content_dir()) {
                $errors->add('fs_no_content_dir', 'Unable to locate WordPress content directory.');

                return $errors;
            }

            $src = $this->get_backup_dir().$args['dir'].'/'.$args['slug'];
            $dest_dir = $wp_filesystem->find_folder($args['src']);
            $dest = trailingslashit($dest_dir).$args['slug'];

            if ($wp_filesystem->is_dir($src)) {
                // Cleanup.
                if ($wp_filesystem->is_dir($dest) && !$wp_filesystem->delete($dest, true)) {
                    $errors->add(
                        'fs_temp_backup_delete',
                        sprintf('Could not restore the original version of %s.', $args['slug'])
                    );
                }

                // Move it.
                $result = move_dir($src, $dest, true);
                if (is_wp_error($result)) {
                    $errors->add(
                        'fs_temp_backup_delete',
                        sprintf('Could not restore the original version of %s.', $args['slug'])
                    );
                }
            }

            if ($args['is_active']) {
                wp_cache_delete('plugins', 'plugins');
                activate_plugin($args['plugin_path'], '', false, true);
            }
        }

        $this->cleanup();

        return $errors->has_errors() ? $errors : true;
    }

    public function cleanup()
    {
        $this->init_fs();
        global $wp_filesystem;

        $errors = new WP_Error();

        $dest_dir = $this->get_backup_dir();

        if ($wp_filesystem->is_dir($dest_dir) && !$wp_filesystem->delete($dest_dir, true)) {
            $errors->add('fs_temp_backup_delete', 'Could not cleanup the temporary backup directory.');
        }

        return $errors->has_errors() ? $errors : true;
    }
}