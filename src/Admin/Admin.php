<?php

namespace Frc\GformsCloudExport\Admin;

use GFForms;
use function add_submenu_page;
use function current_user_can;
use function in_array;
use function remove_action;
use function remove_filter;
use function remove_submenu_page;

class Admin {

    protected $CloudExportClass = '\\Frc\\GformsCloudExport\\Export\\CloudExport';

    public function load() {

        if (!static::gFormsActive()){
            return;
        }

        // Maybe running in heroku or gae, not really a fool proof method to check this, but will do for now.. shouldn't break things even if running somewhere else
        // Export doesn't work when running with more than one dynos (servers),
        // because load balancer routes the ajax calls to a random server and creates a temp file.
        // Second call might be to a different server so the file would not be there.
        // or when using gcloud storage https://cloud.google.com/storage/docs/key-terms#immutability (gcs plugin)
        if (static::s3Active() || static::gcsActive()) {
            static::removeGFExport();
        } else {
            return;
        }

        add_filter('wp_ajax_gf_process_export', [$this, 'ajaxProcessExport']);

        $has_full_access = current_user_can('gform_full_access');
        add_submenu_page('gf_edit_forms', __('Import/Export', 'gravityforms'), __('Import/Export', 'gravityforms'), $has_full_access ? 'gform_full_access' : 'gravityforms_export_entries', 'gf_export', [
            $this,
            'exportPage'
        ]);
    }

    public function exportPage() {
        if (GFForms::maybe_display_wizard()) {
            return;
        };
        $this->CloudExportClass::export_page();
    }

    public function ajaxProcessExport() {
        $this->CloudExportClass::ajax_process_export();
    }

    public static function removeGFExport() {
        remove_filter('wp_ajax_gf_process_export', ['GFForms', 'ajax_process_export']);
        remove_submenu_page('gf_edit_forms', 'gf_export');
        remove_action('lomakkeet_page_gf_export', ['GFForms', 'export_page']);
        remove_action('forms_page_gf_export', ['GFForms', 'export_page']);
        remove_action('formular_page_gf_export', ['GFForms', 'export_page']);
    }

    public static function gFormsActive() {
        $plugins = apply_filters('active_plugins', get_option('active_plugins'));
        return in_array('gravityforms/gravityforms.php', $plugins);
    }

    public static function s3Active() {
        $plugins = apply_filters('active_plugins', get_option('active_plugins'));
        return (in_array('wp-amazon-s3-and-cloudfront/wordpress-s3.php', $plugins) || in_array('amazon-s3-and-cloudfront/wordpress-s3.php', $plugins));
    }

    public static function gcsActive() {
        $plugins = apply_filters('active_plugins', get_option('active_plugins'));
        return in_array('gcs/gcs.php', $plugins);
    }

}
