<?php

/**
 * Plugin Name: Import WP - Jet Engine Importer Addon
 * Plugin URI: https://www.importwp.com
 * Description: Allow Import WP to import Jet Engine.
 * Author: James Collings <james@jclabs.co.uk>
 * Version: 2.0.4
 * Author URI: https://www.importwp.com
 * Network: True
 */

define('IWP_JET_MIN', '2.4.4');
define('IWP_JET_PRO_MIN', '2.4.1');

add_action('admin_init', 'iwp_jet_engine_check');

function iwp_jet_engine_requirements_met()
{
    return false === (is_admin() && current_user_can('activate_plugins') &&  (!class_exists('Jet_Engine') || !defined('IWP_VERSION') || version_compare(IWP_VERSION, IWP_JET_MIN, '<') || !defined('IWP_PRO_VERSION') || version_compare(IWP_PRO_VERSION, IWP_JET_PRO_MIN, '<')));
}

function iwp_jet_engine_check()
{
    if (!iwp_jet_engine_requirements_met()) {

        add_action('admin_notices', 'iwp_jet_engine_notice');

        deactivate_plugins(plugin_basename(__FILE__));

        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}

function iwp_jet_engine_setup()
{
    if (!iwp_jet_engine_requirements_met()) {
        return;
    }

    $base_path = dirname(__FILE__);

    require_once $base_path . '/class/autoload.php';
    require_once $base_path . '/setup.php';
}
add_action('plugins_loaded', 'iwp_jet_engine_setup', 9);

function iwp_jet_engine_notice()
{
    echo '<div class="error">';
    echo '<p><strong>Import WP - Jet Engine Importer Addon</strong> requires that you have <strong>Import WP v' . IWP_JET_MIN . '+ and Import WP PRO v' . IWP_JET_PRO_MIN . '+</strong>, and <strong>Jet Engine</strong> installed.</p>';
    echo '</div>';
}
