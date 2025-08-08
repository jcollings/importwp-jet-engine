<?php

use ImportWP\Common\Addon\AddonBasePanel;
use ImportWP\Common\Addon\AddonCustomFieldsApi;
use ImportWP\Common\Addon\AddonCustomFieldSaveResponse;
use ImportWP\Common\Addon\AddonInterface;
use ImportWP\Common\Addon\AddonPanelDataApi;
use ImportWP\Common\Model\ImporterModel;
use ImportWP\EventHandler;
use ImportWPAddon\JetEngine\Exporter\Mapper\PostMapper;
use ImportWPAddon\JetEngine\Exporter\Mapper\TaxMapper;
use ImportWPAddon\JetEngine\Exporter\Mapper\UserMapper;

function iwp_jet_engine_register_exporter_addon(EventHandler $event_handler)
{
    new PostMapper($event_handler);
    new TaxMapper($event_handler);
    new UserMapper($event_handler);
}

add_action('iwp/register_events', 'iwp_jet_engine_register_exporter_addon');

$addon = iwp_register_importer_addon('Jet Engine', 'iwp-jet-engine', function (AddonInterface $addon) {

    $importer_model = $addon->importer_model();
    $fields = iwp_jet_engine_fields($importer_model);

    $addon->register_custom_fields('Jet Engine', function (AddonCustomFieldsApi $api) use ($fields) {

        // exclude repeater fields
        $fields = array_filter($fields, function ($field) {
            return $field['type'] !== 'repeater';
        });

        $api->set_prefix('jet_engine_field');

        // register fields
        $api->register_fields(function (ImporterModel $importer_model) use ($api, $fields) {

            foreach ($fields as $field) {
                $api->add_field($field['name'], $field['key']);
            }
        });

        $api->save(function (AddonCustomFieldSaveResponse $response, $post_id, $key, $value) use ($fields) {

            $field = iwp_jet_engine_get_field_by_name($key, $fields);
            if (!$field) {
                return;
            }

            $value = iwp_jet_engine_process_field($response, $post_id, $field, $value);
            $response->update_meta($post_id, $field['id'], $value);
        });
    });

    $repeater_fields = array_filter($fields, function ($field) {
        return $field['type'] === 'repeater';
    });

    foreach ($repeater_fields as $repeater_group) {
        $addon->register_panel('Jet Engine - ' . $repeater_group['name'], $repeater_group['id'], function (AddonBasePanel $panel) use ($repeater_group) {
            // TODO: register sub fields
            $fields = $repeater_group['data']['repeater-fields'];
            foreach ($fields as $field) {
                switch ($field['type']) {
                    case 'gallery':
                    case 'media':
                        $panel->register_attachment_fields($field['title'], $field['name'], $field['title'] . ' Location')
                            ->save(false);
                        break;
                    default:
                        $panel->register_field($field['title'], $field['name'])
                            ->save(false);
                        break;
                }
            }

            $panel->save(function (AddonPanelDataApi $api) use ($repeater_group, $fields) {

                $meta = $api->get_meta();
                $output = [];

                foreach ($meta as $meta_field) {
                    foreach ($meta_field['value'] as $meta_index => $meta_value) {

                        $row_id = 'item-' . $meta_index;

                        if (!isset($output[$row_id])) {
                            $output[$row_id] = [];
                        }

                        $field = iwp_jet_engine_get_field_by_name($meta_field['key'], $fields, 'name');
                        if (!$field) {
                            continue;
                        }

                        $meta_value = iwp_jet_engine_process_field($api, null, ['id' => $field['name'], 'type' => $field['type'], 'data' => $field], $meta_value);
                        $output[$row_id][$meta_field['key']] = $meta_value;
                    }
                }

                $api->update_meta($repeater_group['id'], $output);
            });
        }, [
            'type' => 'repeatable',
            'row_base' => true
        ]);
    }
});

$addon->register_migrations(function ($migrate) {

    $migrate
        ->up(function (ImporterModel $importer) {

            $map = $importer->getMap();

            if (!isset($map['custom_fields._index']) || intval($map['custom_fields._index']) <= 0) {
                return;
            }

            // loop through all custom fields
            $custom_fields_index = intval($map['custom_fields._index']);

            for ($i = 0; $i < $custom_fields_index; $i++) {
                if (!isset($map['custom_fields.' . $i . '.key'])) {
                    continue;
                }

                $importer->setMap('custom_fields.' . $i . '.key', preg_replace('/^jet_engine_field::(attachment|text)::[^-]+-(.+)$/', 'jet_engine_field::$1::$2', $map['custom_fields.' . $i . '.key']));
            }

            $importer->save();
        });
});



/**
 * @param AddonCustomFieldSaveResponse $api
 * @param integer $post_id
 * @param mixed $field
 * @param mixed $value
 * @param mixed $custom_field_record
 * @param string $prefix
 * 
 * @return void
 */
function iwp_jet_engine_process_field($api, $post_id, $field, $value)
{
    $delimiter = apply_filters('iwp/value_delimiter', ',');
    $delimiter = apply_filters('iwp/jet_engine/value_delimiter', $delimiter);
    $delimiter = apply_filters('iwp/jet_engine/' . trim($field['id']) . '/value_delimiter', $delimiter);

    $field_type = $field['type'];
    switch ($field_type) {
        case 'checkbox':
            $selected = [];
            $value = array_filter(array_map('trim', explode($delimiter, $value)));
            $allow_custom = isset($field['data']['allow_custom']) && $field['data']['allow_custom'] == 1;
            $is_array = isset($field['data']['is_array']) && $field['data']['is_array'] == 1;
            $save_custom = isset($field['data']['save_custom']) && $field['data']['save_custom'] == 1;

            $exists = [];

            foreach ($field['data']['options'] as $option) {

                $i = array_search($option['key'], $value, true);
                if ($i === false) {
                    $i = array_search($option['value'], $value, true);
                }

                if ($i !== false) {
                    $exists[] = $i;
                    $selected[$option['key']] = true;
                } else {
                    $selected[$option['key']] = false;
                }
            }

            if ($allow_custom) {
                for ($i = 0; $i < count($value); $i++) {
                    if (in_array($i, $exists, true)) {
                        continue;
                    }

                    $selected[$value[$i]] = true;

                    // TODO: Save custom
                }
            }

            if ($is_array) {
                $value = serialize(array_keys($selected));
            } else {
                $value = serialize($selected);
            }
            break;
        case 'gallery':
        case 'media':
            if ($post_id) {
                $value = $api->processAttachmentField($value, $post_id, ['settings._return' => 'id-raw']);
            }

            $field_format = isset($field['data'], $field['data']['value_format']) ? $field['data']['value_format'] : null;

            // id, url, both
            switch ($field_format) {
                case 'url':
                    $value = array_reduce($value, function ($carry, $item) {
                        $carry[] = wp_get_attachment_url($item);
                        return $carry;
                    }, []);
                    $value = implode(',', $value);
                    break;
                case 'both':
                    $value = array_reduce($value, function ($carry, $item) {
                        $carry[] = ['url' => wp_get_attachment_url($item), 'id' => $item];
                        return $carry;
                    }, []);

                    $value = $field_type == 'media' && !empty($value) ? $value[0] : $value;
                    break;
                default:
                    $value = implode(',', (array)$value);
                    break;
            }
            break;
        case 'posts':
            $value = array_filter(array_map('trim', explode($delimiter, $value)));
            break;
        case 'date':
            if (!empty($value)) {
                $value = date('Y-m-d', strtotime($value));
            }
            break;
        case 'time':
            if (!empty($value)) {
                $value = date('H:i', strtotime($value));
            }
            break;
        case 'datetime':
        case 'datetime-local':
            if (!empty($value)) {
                $is_timestamp = isset($field['data']['is_timestamp']) && $field['data']['is_timestamp'] == true;
                if ($is_timestamp) {
                    $value = strtotime($value);
                } else {
                    $value = date('Y-m-d\TH:i', strtotime($value));
                }
            }
            break;
            // TODO: Process: relation_one_to_one
        case 'relation_many_to_one':
            $value = intval($value);

            // clear post_id's existing relationships
            $current_meta = $api->get_meta($post_id, $field['id']);
            if (!empty($current_meta)) {
                foreach ($current_meta as $current_m) {
                    $current_m = intval($current_m);
                    if ($current_m !== $value) {
                        $api->delete_meta($current_m, $field['id'], $post_id);
                    }
                }
            }

            // add new relationship
            if (!empty($value)) {

                // add new record if not existing
                $meta = $api->get_meta($value, $field['id']);
                $found = false;
                foreach ($meta as $m) {
                    if ($m == $post_id) {
                        $found = true;
                    }
                }

                if (!$found) {
                    $api->add_meta($value, $field['id'], $post_id);
                }
            }

            break;
        case 'relation_one_to_many':

            // need to pass object ids.
            $value = array_filter(array_map('trim', explode($delimiter, $value)));

            // clear existing on parent, and also remove from existing children
            $current_meta = $api->get_meta($post_id, $field['id']);
            if (!empty($current_meta)) {
                foreach ($current_meta as $current_m) {
                    if (!in_array($current_m, $value)) {
                        $api->delete_meta($current_m, $field['id'], $post_id);
                        $api->delete_meta($post_id, $field['id'], $current_m);
                    }
                }
            }

            // add to existing on child.
            if (!empty($value)) {
                foreach ($value as $v) {

                    // remove existing relationship and add in new one to post_id
                    $meta = $api->get_meta($v, $field['id']);
                    foreach ($meta as $m) {
                        $api->delete_meta($post_id, $field['id'], $m);
                        $api->delete_meta($m, $field['id']);
                    }

                    $api->update_meta($v, $field['id'], $post_id);
                }
            }

            break;
        case 'relation_many_to_many':

            // need to pass object ids.

            $value = array_filter(array_map('trim', explode($delimiter, $value)));

            // remove existing and clear other end
            $current_meta = $api->get_meta($post_id, $field['id']);
            if (!empty($current_meta)) {
                foreach ($current_meta as $current_m) {
                    if (!in_array($current_m, $value)) {
                        $api->delete_meta($current_m, $field['id'], $post_id);
                    }
                }
            }

            // update other end of relationshup
            if (!empty($value)) {
                foreach ($value as $v) {

                    $meta = $api->get_meta($v, $field['id']);
                    $found = false;
                    foreach ($meta as $m) {
                        if ($m == $post_id) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        $api->add_meta($v, $field['id'], $post_id);
                    }
                }
            }

            break;
    }

    $value = apply_filters('iwp/jet_engine/value', $value, $value);
    return $value;
}

function iwp_jet_engine_get_field_by_name($name, $fields, $col = 'id')
{
    $index = array_search($name, array_column($fields, $col));
    if ($index === false) {
        $index = array_search(substr($name, strlen('text-')), array_column($fields, $col));
    }

    if ($index === false) {
        return false;
    }

    return $fields[$index];
}


function iwp_jet_engine_fields($importer_model)
{
    switch ($importer_model->getTemplate()) {
        case 'user':
            $fields = iwp_jet_engine_get_fields('user', 'user');
            break;
        case 'term':
            $taxonomy = $importer_model->getSetting('taxonomy');
            $fields = iwp_jet_engine_get_fields($taxonomy, 'taxonomy');
            break;
        default:
            $post_type = $importer_model->getSetting('post_type');
            $fields = iwp_jet_engine_get_fields($post_type, 'post');
            $fields = array_merge($fields, iwp_jet_engine_get_relations($post_type));
            break;
    }

    return $fields;
}


function iwp_jet_engine_get_fields($section, $section_type = 'post')
{
    $options = [];
    $meta_boxes = [];

    // Check options field: jet_engine_meta_boxes
    $meta_boxes_options = get_option('jet_engine_meta_boxes', []);
    foreach ($meta_boxes_options as $meta_box_id => $meta_box_settings) {
        if (!isset($meta_box_settings['args'], $meta_box_settings['args']['object_type']) || $meta_box_settings['args']['object_type'] !== $section_type || empty($meta_box_settings['meta_fields'])) {
            continue;
        }

        $meta_boxes = array_merge($meta_boxes, $meta_box_settings['meta_fields']);
    }

    // TODO: Check table: wp_jet_post_types - meta_fields
    if ($section_type === 'post') {
        /**
         * @var \WPDB $wpdb
         */
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_post_types WHERE slug=%s", [$section]), ARRAY_A);
    }

    // TODO: Check table: wp_jet_taxonomies - meta_fields
    if ($section_type === 'taxonomy') {
        /**
         * @var \WPDB $wpdb
         */
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_fields FROM {$wpdb->prefix}jet_taxonomies WHERE slug=%s", [$section]), ARRAY_A);
    }

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $meta_fields = unserialize($row['meta_fields']);
            if (!is_array($meta_fields)) {
                continue;
            }

            $meta_boxes = array_merge($meta_boxes, $meta_fields);
        }
    }

    if (empty($meta_boxes)) {
        return $options;
    }

    foreach ($meta_boxes as $meta_box) {

        switch ($meta_box['type']) {
            case 'gallery':
            case 'media':
                $type = 'attachment';
                break;
            default:
                $type = 'text';
                break;
        }

        $options[] = [
            'type' => $meta_box['type'],
            'id' => $meta_box['name'],
            'name' => $meta_box['title'],
            'key' =>  $type . '::' . $meta_box['name'],
            'data' => $meta_box
        ];
    }

    return $options;
}

function iwp_jet_engine_get_relations($post_type)
{
    $options = [];
    $jet_engine_relations = get_option('jet_engine_relations', []);
    foreach ($jet_engine_relations as $relation) {

        if ($relation['post_type_1'] != $post_type && $relation['post_type_2'] != $post_type) {
            continue;
        }

        if (!in_array($relation['type'], ['many_to_many', 'one_to_many', 'many_to_one', 'one_to_one'])) {
            continue;
        }

        $relation_type = $relation['type'];

        // flip relationship if we are the second post type
        if ($relation['post_type_2'] == $post_type) {
            if ($relation_type == 'one_to_many') {
                $relation_type = 'many_to_one';
            } elseif ($relation_type == 'many_to_one') {
                $relation_type = 'one_to_many';
            }
        }

        $relation_hash = iwp_jet_engine_generate_relation_hash($relation);

        $options[] = [
            'type' => $relation['type'],
            'id' => $relation['id'],
            'name' => 'Relationship - ' . $relation['name'],
            'key' =>  'text::relation_' . $relation_type . '-' . $relation_hash,
            'data' => $relation
        ];
    }

    return $options;
}

function iwp_jet_engine_generate_relation_hash($relationship)
{
    return 'relation_' . md5($relationship['post_type_1'] . $relationship['post_type_2']);
}
