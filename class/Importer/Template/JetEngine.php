<?php

namespace ImportWPAddon\JetEngine\Importer\Template;

use ImportWP\Common\Model\ImporterModel;
use ImportWP\EventHandler;

class JetEngine
{

    /**
     * @var CustomFields $custom_fields
     */
    private $custom_fields;

    public function __construct(EventHandler $event_handler)
    {
        $event_handler->listen('importer.custom_fields.init', [$this, 'init']);
        $event_handler->listen('importer.custom_fields.get_fields', [$this, 'get_fields']);
        $event_handler->listen('importer.custom_fields.process_field', [$this, 'process_field']);

        add_filter('iwp/custom_field_key', [$this, 'get_custom_field_key'], 10);
    }

    public function init($result, $custom_fields)
    {
        $this->custom_fields = $custom_fields;
    }

    public function get_relation_hash($relationship)
    {
        return 'relation_' . md5($relationship['post_type_1'] . $relationship['post_type_2']);
    }

    public function get_jet_engine_relations($post_type, $raw = false)
    {
        $options = [];
        $jet_engine_relations = get_option('jet_engine_relations');
        if ($jet_engine_relations) {
            foreach ($jet_engine_relations as $relation) {

                if ($relation['post_type_1'] != $post_type && $relation['post_type_2'] != $post_type) {
                    continue;
                }

                if (!in_array($relation['type'], ['many_to_many', 'one_to_many', 'many_to_one'])) {
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

                $relation_hash = $this->get_relation_hash($relation);

                $type = 'text';
                $option = [
                    'value' => 'jet_engine_field::' . $type . '::relation_' . $relation_type . '-' . $relation_hash,
                    'label' => 'Jet Engine - Relation - ' . $relation['name'],
                ];

                if ($raw) {
                    $option['data'] = $relation;
                }

                $options[] = $option;
            }
        }

        return $options;
    }

    public function get_jet_engine_fields($section, $section_type = 'post', $raw = false)
    {
        $options = [];
        $meta_boxes = [];

        // Check options field: jet_engine_meta_boxes
        $meta_boxes_options = get_option('jet_engine_meta_boxes');
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
                $meta_boxes = array_merge($meta_boxes, unserialize($row['meta_fields']));
            }
        }

        if (empty($meta_boxes)) {
            return $options;
        }

        foreach ($meta_boxes as $meta_box) {

            $file_type = $meta_box['type'];
            switch ($meta_box['type']) {
                case 'gallery':
                case 'media':
                    $type = 'attachment';
                    break;
                default:
                    $type = 'text';
                    break;
            }

            $option = [
                'value' => 'jet_engine_field::' . $type . '::' . $file_type . '-' . $meta_box['name'],
                'label' => 'Jet Engine - ' . $meta_box['title'],
            ];

            if ($raw) {
                $option['data'] = $meta_box;
            }

            $options[] = $option;
        }

        return $options;
    }

    public function get_fields($fields, ImporterModel $importer_model, $raw = false)
    {
        $template = $importer_model->getTemplate();
        switch ($template) {
            case 'user':
                $fields = array_merge($this->get_jet_engine_fields('user', 'user', $raw), $fields);
                break;
            case 'term':
                $taxonomy = $importer_model->getSetting('taxonomy');
                $fields = array_merge($this->get_jet_engine_fields($taxonomy, 'taxonomy', $raw), $fields);
                break;
            default:
                $post_type = $importer_model->getSetting('post_type');
                $fields = array_merge($this->get_jet_engine_fields($post_type, 'post', $raw), $fields);
                $fields = array_merge($this->get_jet_engine_relations($post_type, $raw), $fields);
                break;
        }

        return $fields;
    }

    public function get_field($id, $fields = [])
    {
        foreach ($fields as $field) {
            if ($field['value'] === $id) {
                return $field;
            }
        }

        return false;
    }

    public function process_field($result, $post_id, $key, $value, $custom_field_record, $prefix, $importer_model, $custom_field)
    {
        if (strpos($key, 'jet_engine_field::') !== 0) {
            return $result;
        }

        $field_key_last = substr($key, strrpos($key, '::') + strlen('::'));
        $matches = [];
        if (preg_match('/^([^-]+)-/', $field_key_last, $matches) === false) {
            return $result;
        }

        $field_list = $this->get_fields([], $importer_model, true);
        $field = $this->get_field($key, $field_list);
        if (!$field) {
            return $result;
        }

        $field_key = $this->get_custom_field_key($key);
        $field_key = isset($field['data'], $field['data']['name']) ? $field['data']['name'] : $field_key;

        $delimiter = apply_filters('iwp/value_delimiter', ',');
        $delimiter = apply_filters('iwp/jet_engine/value_delimiter', $delimiter);
        $delimiter = apply_filters('iwp/jet_engine/' . trim($field_key) . '/value_delimiter', $delimiter);

        // check real jet engine fields

        $field_type = $matches[1];
        switch ($field_type) {
            case 'checkbox':
                $selected = [];
                $value = array_filter(array_map('trim', explode($delimiter, $value)));
                $allow_custom = $field['data']['allow_custom'] == 1;
                $is_array = $field['data']['is_array'] == 1;
                $save_custom = $field['data']['save_custom'] == 1;

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
                $custom_field_record[$prefix . 'settings._return'] = 'id';
                $value = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                break;
            case 'posts':
                $value = serialize(array_filter(array_map('trim', explode($delimiter, $value))));
                break;
            case 'date':
                $value = date('Y-m-d', strtotime($value));
                break;
            case 'time':
                $value = date('H:i', strtotime($value));
                break;
            case 'datetime':
            case 'datetime-local':
                $value = date('Y-m-d\TH:i', strtotime($value));
                break;
                // TODO: Process: relation_one_to_one
            case 'relation_many_to_one':
                $value = intval($value);

                // clear post_id's existing relationships
                $current_meta = get_post_meta($post_id, $field_key);
                if (!empty($current_meta)) {
                    foreach ($current_meta as $current_m) {
                        $current_m = intval($current_m);
                        if ($current_m !== $value) {
                            delete_post_meta($current_m, $field_key, $post_id);
                        }
                    }
                }

                // add new relationship
                if (!empty($value)) {

                    // add new record if not existing
                    $meta = get_post_meta($value, $field_key);
                    $found = false;
                    foreach ($meta as $m) {
                        if ($m == $post_id) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        add_post_meta($value, $field_key, $post_id);
                    }
                }

                break;
            case 'relation_one_to_many':

                // need to pass object ids.
                $value = array_filter(array_map('trim', explode($delimiter, $value)));

                // clear existing on parent, and also remove from existing children
                $current_meta = get_post_meta($post_id, $field_key);
                if (!empty($current_meta)) {
                    foreach ($current_meta as $current_m) {
                        if (!in_array($current_m, $value)) {
                            delete_post_meta($current_m, $field_key, $post_id);
                            delete_post_meta($post_id, $field_key, $current_m);
                        }
                    }
                }

                // add to existing on child.
                if (!empty($value)) {
                    foreach ($value as $v) {

                        // remove existing relationship and add in new one to post_id
                        $meta = get_post_meta($v, $field_key);
                        foreach ($meta as $m) {
                            delete_post_meta($post_id, $field_key, $m);
                            delete_post_meta($m, $field_key);
                        }

                        update_post_meta($v, $field_key, $post_id);
                    }
                }

                break;
            case 'relation_many_to_many':

                // need to pass object ids.

                $value = array_filter(array_map('trim', explode($delimiter, $value)));

                // remove existing and clear other end
                $current_meta = get_post_meta($post_id, $field_key);
                if (!empty($current_meta)) {
                    foreach ($current_meta as $current_m) {
                        if (!in_array($current_m, $value)) {
                            delete_post_meta($current_m, $field_key, $post_id);
                        }
                    }
                }

                // update other end of relationshup
                if (!empty($value)) {
                    foreach ($value as $v) {

                        $meta = get_post_meta($v, $field_key);
                        $found = false;
                        foreach ($meta as $m) {
                            if ($m == $post_id) {
                                $found = true;
                            }
                        }

                        if (!$found) {
                            add_post_meta($v, $field_key, $post_id);
                        }
                    }
                }

                break;
        }


        $result[$field_key] = apply_filters('iwp/jet_engine/value', $value, $field_key);

        return $result;
    }

    /**
     * @param string $key
     * @param TemplateInterface $template
     * @return string
     */
    public function get_custom_field_key($key)
    {
        if (strpos($key, 'jet_engine_field::') !== 0) {
            return $key;
        }

        $field_key = substr($key, strrpos($key, '::') + strlen('::'));

        $matches = [];
        if (preg_match('/^[^-]+-(.*?)$/', $field_key, $matches) !== false) {
            $field_key = $matches[1];
        }

        return $field_key;
    }
}
