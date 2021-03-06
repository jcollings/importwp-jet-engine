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
        $event_handler;
        $event_handler->listen('importer.custom_fields.init', [$this, 'init']);
        $event_handler->listen('importer.custom_fields.get_fields', [$this, 'get_fields']);
        $event_handler->listen('importer.custom_fields.process_field', [$this, 'process_field']);

        add_filter('iwp/custom_field_key', [$this, 'get_custom_field_key'], 10, 3);
    }

    public function init($result, $custom_fields)
    {
        $this->custom_fields = $custom_fields;
    }

    public function get_jet_engine_relations($post_type)
    {
        $options = [];
        $jet_engine_relations = get_option('jet_engine_relations');
        if ($jet_engine_relations) {
            foreach ($jet_engine_relations as $relation) {

                if ($relation['post_type_1'] != $post_type && $relation['post_type_2'] != $post_type) {
                    continue;
                }

                if ($relation['type'] !== 'many_to_many') {
                    continue;
                }

                $relation_hash = 'relation_' . md5($relation['post_type_1'] . $relation['post_type_2']);

                $type = 'text';
                $options[] = [
                    'value' => 'jet_engine_field::' . $type . '::relation_' . $relation['type'] . '-' . $relation_hash,
                    'label' => 'Jet Engine - Relation - ' . $relation['name'],
                ];
            }
        }

        return $options;
    }

    public function get_jet_engine_fields($section, $section_type = 'post')
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

            $options[] = [
                'value' => 'jet_engine_field::' . $type . '::' . $file_type . '-' . $meta_box['name'],
                'label' => 'Jet Engine - ' . $meta_box['title'],
            ];
        }

        return $options;
    }

    public function get_fields($fields, ImporterModel $importer_model)
    {
        $template = $importer_model->getTemplate();
        switch ($template) {
            case 'user':
                $fields = array_merge($fields, $this->get_jet_engine_fields('user', 'user'));
                break;
            case 'term':
                $taxonomy = $importer_model->getSetting('taxonomy');
                $fields = array_merge($fields, $this->get_jet_engine_fields($taxonomy, 'taxonomy'));
                break;
            default:
                $post_type = $importer_model->getSetting('post_type');
                $fields = array_merge($fields, $this->get_jet_engine_fields($post_type, 'post'));
                $fields = array_merge($fields, $this->get_jet_engine_relations($post_type));
                break;
        }

        return $fields;
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

        $field_key = $this->get_custom_field_key($key);

        $delimiter = apply_filters('iwp/value_delimiter', ',');
        $delimiter = apply_filters('iwp/jet_engine/value_delimiter', $delimiter);
        $delimiter = apply_filters('iwp/jet_engine/' . trim($field_key) . '/value_delimiter', $delimiter);

        $field_type = $matches[1];

        switch ($field_type) {
            case 'gallery':
            case 'media':
                $custom_field_record[$prefix . '_return'] = 'id';
                $value = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                break;
            case 'posts':
                $value = serialize(array_filter(array_map('trim', explode($delimiter, $value))));
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
     * @param ImporterModel $importer
     * @return string
     */
    public function get_custom_field_key($key, $template = null, $importer = null)
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
