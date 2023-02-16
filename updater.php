<?php

if (!defined('IWP_API_URL')) {
    define('IWP_API_URL', 'https://www.importwp.com');
}

class IWP_Updater
{
    private $id;
    private $core;
    private $pro;
    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $authorize_token;
    private $plugin_api_response;

    public function __construct($file, $id)
    {
        $this->id = $id;
        $this->file = $file;

        add_action('admin_init', array($this, 'set_plugin_properties'));

        return $this;
    }

    public function set_plugin_properties()
    {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
        $this->core = defined('IWP_VERSION') ? IWP_VERSION : null;
        $this->pro = defined('IWP_PRO_VERSION') ? IWP_PRO_VERSION : null;
    }

    public function authorize($token)
    {
        $this->authorize_token = $token;
    }

    private function get_repository_info()
    {
        if (is_null($this->plugin_api_response)) { // Do we have a response?
            $request_uri = sprintf(IWP_API_URL . '/api/v1/index.php?access_token=%s&action=status&plugin=%s&iwp=%s&iwp_pro=%s', $this->authorize_token, $this->id, $this->core, $this->pro);

            $response = json_decode(wp_remote_retrieve_body(wp_remote_get($request_uri)), true); // Get JSON and parse it

            $this->plugin_api_response = $response; // Set it to our property			 
        }
    }

    public function initialize()
    {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    public function modify_transient($transient)
    {
        if (is_object($transient) && property_exists($transient, 'checked') && $checked = $transient->checked) { // Did Wordpress check for updates?
            $this->get_repository_info(); // Get the repo info

            if (!is_array($this->plugin_api_response) || !isset($this->plugin_api_response['tag_name']) || empty($this->basename)) {
                return $transient;
            }

            $out_of_date = version_compare($this->plugin_api_response['tag_name'], $checked[$this->basename]); // Check if we're out of date

            if ($out_of_date) {

                $new_files = isset($this->plugin_api_response['zipball_url']) ? $this->plugin_api_response['zipball_url'] : null; // Get the ZIP

                $plugin = array( // setup our plugin info
                    'url' => $this->plugin["PluginURI"],
                    'slug' => $this->basename,
                    'package' => $new_files,
                    'new_version' => $this->plugin_api_response['tag_name']
                );

                $transient->response[$this->basename] = (object) $plugin; // Return it in response
            }
        }

        return $transient; // Return filtered transient
    }

    public function plugin_popup($result, $action, $args)
    {

        if (!empty($args->slug)) { // If there is a slug

            if ($args->slug == $this->basename) { // And it's our slug

                $this->get_repository_info(); // Get our repo info

                if (!is_array($this->plugin_api_response) || !isset($this->plugin_api_response['tag_name'])) {
                    return $result;
                }

                // Set it to an array
                $plugin = array(
                    'name'                => $this->plugin["Name"],
                    'slug'                => $this->basename,
                    'version'            => $this->plugin_api_response['tag_name'],
                    'author'            => $this->plugin["AuthorName"],
                    'author_profile'    => $this->plugin["AuthorURI"],
                    'last_updated'        => $this->plugin_api_response['published_at'],
                    'homepage'            => $this->plugin["PluginURI"],
                    'short_description' => $this->plugin["Description"],
                    'sections'            => array(
                        'Description'    => $this->plugin["Description"],
                        'Updates'        => !empty($this->plugin_api_response['body']) ? $this->plugin_api_response['body'] : $this->plugin_api_response['name'],
                    ),
                    'download_link'        => $this->plugin_api_response['zipball_url']
                );

                return (object) $plugin; // Return the data
            }
        }
        return $result; // Otherwise return default
    }

    public function after_install($response, $hook_extra, $result)
    {
        global $wp_filesystem; // Get global FS object

        $install_directory = plugin_dir_path($this->file); // Our plugin directory 
        $wp_filesystem->move($result['destination'], $install_directory); // Move files to the plugin dir
        $result['destination'] = $install_directory; // Set the destination for the rest of the stack

        if ($this->active) { // If it was active
            activate_plugin($this->basename); // Reactivate
        }

        return $result;
    }
}
