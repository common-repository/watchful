<?php


namespace Watchful\Helpers\BackupPlugins;


use DateTime;

interface BackupPluginInterface
{
    /** @return DateTime | false */
    public function get_last_backup_date();

    public function get_backup_list();
}
