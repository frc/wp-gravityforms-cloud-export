<?php

/*
Plugin Name:        Gravity Forms Cloud Export
Plugin URI:         https://www.frantic.fi/
Description:        Change the behavior of Gravity Forms Export. Use when running on heroku or gae.
Version:            0.0.1
Author:             Janne Aalto / Frantic Oy
Author URI:         https://www.frantic.fi/
*/

namespace Frc\GformsCloudExport;

require_once __DIR__ . '/autoload.php';

add_action('admin_init', [__NAMESPACE__ . '\\Plugin', 'boot']);
