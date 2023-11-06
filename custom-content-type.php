<?php

use ImportWPAddon\JetEngine\Importer\Mapper\CustomContentTypeMapper;
use ImportWPAddon\JetEngine\Importer\Template\CustomContentTypeTemplate;

add_action('jet-engine/init', function ($jet_engine) {

    if (!jet_engine()->modules->is_module_active('custom-content-types')) {
        return;
    }

    // TODO: Module is enabled
    add_action('init', function () {

        $content_types = Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->data->get_items();
        $content_types = array_map(function ($item) {

            $item['args']        = maybe_unserialize($item['args']);
            $item['meta_fields'] = maybe_unserialize($item['meta_fields']);

            return $item;
        }, $content_types);


        // Create new template with the meta_fields
    });
}, 999);

/**
 * Hook into Import WP Event handler
 *
 * @param EventHandler $event_handler
 * @return void
 */
function iwp_jet_engine_cct_register_events($event_handler)
{
    $event_handler->listen('templates.register', 'iwp_jet_engine_cct_register_templates');
    $event_handler->listen('mappers.register', 'iwp_jet_engine_cct_register_mappers');
}
add_action('iwp/register_events', 'iwp_jet_engine_cct_register_events');

/**
 * Add PropertyTemplate to list
 *
 * @param string[] $templates
 * @return string[]
 */
function iwp_jet_engine_cct_register_templates($templates)
{
    if (!jet_engine()->modules->is_module_active('custom-content-types')) {
        return $templates;
    }

    $content_types = Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->data->get_items();
    $content_types = array_map(function ($item) {

        $item['args']        = maybe_unserialize($item['args']);
        $item['meta_fields'] = maybe_unserialize($item['meta_fields']);

        return $item;
    }, $content_types);

    if (!empty($content_types)) {
        foreach ($content_types as $item) {
            $templates['jet-engine-cct'] = CustomContentTypeTemplate::class;
        }
    }

    return $templates;
}

function iwp_jet_engine_cct_register_mappers($mappers)
{
    $mappers['jet-engine-cct'] = CustomContentTypeMapper::class;
    return $mappers;
}

function iwp_jet_engine_cct_mapper_unique_fields($unique_fields, $mapper_id)
{
    if ($mapper_id == 'jet-engine-cct') {
        return ['_ID'];
    }

    return $unique_fields;
}
add_filter('iwp/mapper/unique_fields', 'iwp_jet_engine_cct_mapper_unique_fields', 10, 2);
