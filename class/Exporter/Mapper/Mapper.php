<?php

namespace ImportWPAddon\JetEngine\Exporter\Mapper;

use ImportWPAddon\JetEngine\Util\Helper;

class Mapper
{
    /**
     * @var \ImportWP\EventHandler
     */
    protected $event_handler;

    /**
     * @var string
     */
    protected $jet_engine_type;

    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler, $filter_type)
    {
        $this->event_handler = $event_handler;

        add_filter('iwp/exporter/' . $filter_type . '/fields', [$this, 'modify_fields'], 10, 2);
        add_filter('iwp/exporter/' . $filter_type . '/setup_data', [$this, 'load_data'], 10, 2);
    }

    function process_field_list($fields)
    {
        $tmp = [];

        foreach ($fields as $field) {

            switch ($field['type']) {
                case 'gallery':
                case 'media':
                    $tmp[] = $field['name'];
                    $tmp[] = $field['name'] . '::id';
                    $tmp[] = $field['name'] . '::url';
                    break;
                default:
                    $tmp[] = $field['name'];
                    break;
            }
        }

        return $tmp;
    }

    function modify_fields($fields, $template_args)
    {
        $addon_fields = Helper::get_fields($this->jet_engine_type, $template_args);

        $default_fields = array_filter($addon_fields, function ($item) {
            return $item['type'] !== 'repeater';
        });

        foreach ($default_fields as $field) {
            $fields['children']['custom_fields']['fields'] = array_filter($fields['children']['custom_fields']['fields'], function ($item) use ($field) {
                return $item !== $field['name'];
            });
        }

        $fields['children']['jetengine'] = [
            'key' => 'jetengine',
            'label' => 'JetEngine Fields',
            'loop' => false,
            'fields' => $this->process_field_list($default_fields),
            'children' => []
        ];

        return $fields;
    }

    function load_field_data($default_fields, $meta)
    {
        $output = [];

        foreach ($default_fields as $field) {

            $field_id = $field['name'];
            switch ($field['type']) {
                case 'gallery':

                    $tmp = [
                        'id' => [],
                        'url' => []
                    ];

                    if (isset($meta[$field_id], $meta[$field_id][0])) {

                        if ($field['value_format'] === 'url') {

                            $parts = explode(',', $meta[$field_id][0]);
                            foreach ($parts as $part) {

                                $attachment_id = attachment_url_to_postid($part);
                                $tmp['id'][] = $attachment_id;
                                $tmp['url'][] = $part;
                            }
                        } elseif ($field['value_format'] === 'id') {

                            $parts = explode(',', $meta[$field_id][0]);
                            foreach ($parts as $part) {
                                $tmp['id'][] = $part;
                                $tmp['url'][] = wp_get_attachment_url($part);
                            }
                        } elseif ($field['value_format'] === 'both') {

                            $serialized = (array)maybe_unserialize($meta[$field_id][0]);
                            if (!empty($serialized)) {

                                foreach ($serialized as $part) {
                                    $tmp['id'][] = $part['id'];
                                    $tmp['url'][] = $part['url'];
                                }
                            }
                        }
                    }

                    $output[$field['name']] =  $tmp['id'];
                    $output[$field['name'] . '::id'] = $tmp['id'];
                    $output[$field['name'] . '::url'] = $tmp['url'];

                    break;
                case 'media':

                    $output[$field['name']] = '';
                    $output[$field['name'] . '::id'] = '';
                    $output[$field['name'] . '::url'] = '';

                    if (isset($meta[$field_id], $meta[$field_id][0])) {

                        if ($field['value_format'] === 'url') {

                            $attachment_id = attachment_url_to_postid($meta[$field_id][0]);
                            $output[$field['name']] = $attachment_id;
                            $output[$field['name'] . '::id'] = $attachment_id;
                            $output[$field['name'] . '::url'] = $meta[$field_id][0];
                        } elseif ($field['value_format'] === 'id') {

                            $attachment_id = $meta[$field_id][0];
                            $output[$field['name']] = $attachment_id;
                            $output[$field['name'] . '::id'] = $attachment_id;
                            $output[$field['name'] . '::url'] = wp_get_attachment_url($meta[$field_id][0]);
                        } elseif ($field['value_format'] === 'both') {

                            $serialized = (array)maybe_unserialize($meta[$field_id][0]);
                            if (!empty($serialized)) {
                                $output[$field['name']] = $serialized['id'];
                                $output[$field['name'] . '::id'] = $serialized['id'];
                                $output[$field['name'] . '::url'] = $serialized['url'];
                            }
                        }
                    }

                    break;
                default:
                    if (isset($meta[$field_id]) && is_array($meta[$field_id])) {
                        $output[$field['name']] = $meta[$field_id][0];
                    } elseif (isset($meta[$field_id])) {
                        $output[$field['name']] = $meta[$field_id];
                    } else {
                        $output[$field['name']] = '';
                    }
                    break;
            }
        }

        return $output;
    }

    function load_data($record, $template_args)
    {
        $addon_fields = Helper::get_fields($this->jet_engine_type, $template_args);

        $default_fields = array_filter($addon_fields, function ($item) {
            return $item['type'] !== 'repeater';
        });

        $record['jetengine'] = $this->load_field_data($default_fields, $record['custom_fields']);
        return $record;
    }
}
