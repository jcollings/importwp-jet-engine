<?php

/**
 * Plugin Name: ImportWP - Jet Engine Importer Addon
 * Plugin URI: https://www.importwp.com
 * Description: Allow ImportWP to import Jet Engine.
 * Author: James Collings <james@jclabs.co.uk>
 * Version: 2.0.0
 * Author URI: https://www.importwp.com
 * Network: True
 */

add_action('admin_init', 'iwp_jet_engine_check');

function iwp_jet_engine_requirements_met()
{
    return false === (is_admin() && current_user_can('activate_plugins') &&  (!class_exists('Jet_Engine') || version_compare(IWP_VERSION, '2.0.23', '<')));
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
    echo '<p><strong>ImportWP - Jet Engine Importer Addon</strong> requires that you have <strong>ImportWP PRO v2.0.23 or newer</strong>, and <strong>Jet Engine</strong> installed.</p>';
    echo '</div>';
}
