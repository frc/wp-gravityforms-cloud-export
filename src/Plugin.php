<?php

namespace Frc\GformsCloudExport;

use function is_admin;

class Plugin {

    public static function boot() {

        if (is_admin()) {
            $admin = new Admin\Admin();
            $admin->load();
        }
    }
}
