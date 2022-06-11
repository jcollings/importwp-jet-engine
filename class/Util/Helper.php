<?php

namespace ImportWPAddon\JetEngine\Util;

class Helper
{
    public static function get_fields($template, $template_arg = [])
    {
        $fields = [];
        switch ($template) {
            case 'user':
                $fields = self::get_jet_engine_fields('user', 'user');
                break;
            case 'term':
                $taxonomies = (array)$template_arg;
                foreach ($taxonomies as $taxonomy) {
                    $fields = self::get_jet_engine_fields('taxonomy', $taxonomy);
                }
                break;
            default:
                // Handle templates with multiple post_types
                $post_types = (array)$template_arg;
                foreach ($post_types as $post_type) {
                    $fields = self::get_jet_engine_fields('post', $post_type);
                }
                break;
        }
        return $fields;
    }

    public static function get_jet_engine_fields($section_type, $section)
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
                $meta_boxes = array_merge($meta_boxes, unserialize($row['meta_fields']));
            }
        }

        if (empty($meta_boxes)) {
            return $options;
        }

        foreach ($meta_boxes as $meta_box) {

            $options[] = $meta_box;
        }

        return $options;
    }
}
