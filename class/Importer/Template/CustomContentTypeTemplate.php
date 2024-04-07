<?php

namespace ImportWPAddon\JetEngine\Importer\Template;

use ImportWP\Common\Importer\ParsedData;
use ImportWP\Common\Importer\Template\Template;
use ImportWP\Common\Importer\TemplateInterface;
use ImportWP\Common\Model\ImporterModel;
use ImportWP\Container;

class CustomContentTypeTemplate extends Template implements TemplateInterface
{
    protected $name = 'JetEngine Content Type';
    protected $mapper = 'jet-engine-cct';

    /**
     * @var \Jet_Engine\Modules\Custom_Content_Types\Factory
     */
    public $content_type;

    public function __construct($event_handler)
    {
        parent::__construct($event_handler);

        $event_handler->listen('template.fields', [$this, 'register_template_fields']);

        // $this->field_options = array_merge($this->field_options, [
        //     'advanced._parent.parent' => [$this, 'get_post_parent_options'],
        // ]);
    }

    public function register_settings()
    {
    }

    public function register_options()
    {
        $content_types = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->data->get_items();
        $options = array_map(function ($item) {

            $args = maybe_unserialize($item['args']);

            return [
                'value' => $item['slug'],
                'label' => $args['name']
            ];
        }, $content_types);

        $options = array_merge([['value' => '', 'label' => 'Choose a Content Type']], $options);

        return [
            $this->register_field('Content Type', 'content_type', [
                'options' => $options
            ])
        ];
    }

    /**
     * Register template fields
     *
     * @param array $fields
     * @param CustomContentTypeTemplate $template
     * @param ImporterModel $importer_model
     * @return void
     */
    public function register_template_fields($fields, $template, $importer_model)
    {
        $content_type = $importer_model->getSetting('content_type');
        $content_type_data = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types($content_type);
        $content_type_fields = $content_type_data->get_formatted_fields();

        $group_fields = [];
        $tmp = [];
        foreach ($content_type_fields as $field) {

            switch ($field['type']) {
                case 'repeater':

                    $sub_fields = [];
                    foreach ($field['repeater-fields'] as $sub_field) {
                        $sub_fields[] = $template->register_field($sub_field['title'], $sub_field['name']);
                    }

                    $tmp[] = $template->register_group($field['title'], $field['name'], $sub_fields, [
                        'type' => 'repeatable',
                        'row_base' => true
                    ]);

                    break;
                case 'gallery':
                case 'media':
                    $group_fields[] = $template->register_attachment_fields($field['title'], $field['name'], $field['title'] . ' Location', [
                        'core' => isset($field['is_required']) && $field['is_required'] === true ? true : true
                    ]);
                    break;
                case 'posts':

                    $group_fields[] = $template->register_group($field['title'] . ' Settings', $field['name'], [
                        $template->register_field($field['title'], 'parent', [
                            'default' => '',
                            'tooltip' => __('Set this for the post it belongs to', 'importwp')
                        ]),
                        $template->register_field('Field Type', '_parent_type', [
                            'default' => 'id',
                            'options' => [
                                ['value' => 'id', 'label' => 'ID'],
                                ['value' => 'slug', 'label' => 'Slug'],
                                ['value' => 'name', 'label' => 'Name']
                            ],
                            'type' => 'select',
                            'tooltip' => __('Select how the parent field should be handled', 'importwp')
                        ])
                    ]);

                    break;
                default:

                    $show_field = in_array($field['name'], ['_ID', 'cct_status', 'cct_created', 'cct_modified']);

                    $group_fields[] = $template->register_field($field['title'], $field['name'], [
                        'core' => !$show_field ? true : false
                    ]);
                    break;
            }
        }

        $fields[] = $template->register_group($content_type_data->get_arg('name') . ' Fields', 'content-type', $group_fields);

        if (!empty($tmp)) {
            $fields = array_merge($fields, $tmp);
        }

        return $fields;
    }

    /**
     * Process data before record is importer.
     * 
     * Alter data that is passed to the mapper.
     *
     * @param ParsedData $data
     * @return ParsedData
     */
    public function pre_process(ParsedData $data)
    {
        $data = parent::pre_process($data);

        if (is_null($this->content_type)) {
            $content_type = $this->importer->getSetting('content_type');
            $this->content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types($content_type);
        }

        $fields = $data->getData();
        $cct_fields = $this->content_type->get_formatted_fields();

        $values = $this->process_fields($fields, $cct_fields);

        $data->replace($values);
        return $data;
    }

    public function process_fields($fields, $cct_fields)
    {
        $values = [];
        foreach ($cct_fields as $field) {

            $field_id = 'content-type.' . $field['name'];

            // TODO: Need to check only on optional fields
            $show_field = in_array($field['name'], ['_ID', 'cct_status', 'cct_created', 'cct_modified']);
            if ($show_field && !$this->importer->isEnabledField($field_id)) {
                continue;
            }

            switch ($field['type']) {
                case 'repeater':

                    $repeater_data = [];

                    $max = intval(isset($fields[$field['name'] . '._index']) ? $fields[$field['name'] . '._index'] : 0);
                    for ($i = 0; $i < $max; $i++) {

                        $row_key = 'item-' . $i;
                        $repeater_data[$row_key] = [];

                        foreach ($field['repeater-fields'] as $sub_field) {
                            $repeater_data[$row_key][$sub_field['name']] = isset($fields[$field['name'] . '.' . $i . '.' . $sub_field['name']]) ? trim($fields[$field['name'] . '.' . $i . '.' . $sub_field['name']]) : '';
                        }
                    }

                    $values[$field['name']] = $repeater_data;

                    break;
                case 'posts':
                    $is_multple = isset($field['is_multiple']) && $field['is_multiple'] === true;
                    $post_types = (array)$field['search_post_type'];
                    if (empty($post_types)) {
                        break;
                    }

                    $field_values = isset($fields[$field_id . '.parent']) ? array_values(array_map('trim', explode(',', $fields[$field_id . '.parent']))) : [];
                    $field_type = isset($fields[$field_id . '._parent_type']) ? $fields[$field_id . '._parent_type'] : null;

                    $found_ids = [];

                    foreach ($field_values as $field_value) {
                        $post_query_args = [
                            'post_type' => $post_types,
                            'fields' => 'ids',
                            'posts_per_page' => $is_multple ? -1 : 1
                        ];

                        if ($field_type == 'name' || $field_type == 'slug') {
                            $post_query_args['name'] = sanitize_title($field_value);
                        } else {
                            $found_ids[] = $field_value;
                            continue;
                        }

                        $posts_query = new \WP_Query($post_query_args);
                        $found_ids = array_merge($found_ids, $posts_query->posts);
                    }

                    if (count($found_ids) > 0) {

                        $found_ids = array_map('strval', $found_ids);

                        if ($is_multple) {
                            $values[$field['name']] = $found_ids;
                        } else {
                            $values[$field['name']] = $found_ids[0];
                        }
                    } else {
                        $values[$field['name']] = '';
                    }

                    break;
                case 'select':

                    $is_multple = isset($field['is_multiple']) && $field['is_multiple'] === true;
                    $field_values = isset($fields[$field_id]) ? array_values(array_map('trim', explode(',', $fields[$field_id]))) : [];
                    $options = $field['options'];

                    $output = [];
                    foreach ($options as $option) {

                        if (in_array($option['key'], $field_values) || in_array($option['value'], $field_values)) {
                            $output[] = $option['key'];
                        }
                    }

                    if ($is_multple) {
                        $values[$field['name']] = (array)$output;
                    } else {
                        $values[$field['name']] = !empty($output) ? $output[0] : '';
                    }

                    break;
                case 'checkbox':

                    $field_values = isset($fields[$field_id]) ? array_values(array_map('trim', explode(',', $fields[$field_id]))) : [];
                    $options = $field['options'];

                    $output = [];
                    foreach ($options as $option) {

                        $exists = in_array($option['key'], $field_values) || in_array($option['value'], $field_values);

                        $field_values = array_filter($field_values, function ($item) use ($option) {
                            return $item !== $option['key'] && $item !== $option['value'];
                        });

                        if (isset($field['is_array']) && $field['is_array'] === true) {

                            if ($exists) {
                                $output[] = $option['key'];
                            }
                        } else {

                            if ($exists) {
                                $output[$option['key']] = "true";
                            } else {
                                $output[$option['key']] = "false";
                            }
                        }
                    }

                    if (isset($field['allow_custom']) && $field['allow_custom'] === true && !empty($field_values)) {

                        foreach ($field_values as $field_value) {
                            if (isset($field['is_array']) && $field['is_array'] === true) {
                                $output[] = $field_value;
                            } else {
                                $output[$field_value] = "true";
                            }
                        }
                    }

                    $values[$field['name']] = $output;

                    break;
                case 'media':
                case 'gallery':

                    /**
                     * @var Filesystem $filesystem
                     */
                    $filesystem = Container::getInstance()->get('filesystem');

                    /**
                     * @var Ftp $ftp
                     */
                    $ftp = Container::getInstance()->get('ftp');

                    /**
                     * @var Attachment $attachment
                     */
                    $attachment = Container::getInstance()->get('attachment');

                    $attachment_data = [];
                    $attachment_keys = [
                        'location',
                        '_meta._title',
                        '_meta._alt',
                        '_meta._caption',
                        '_meta._description',
                        '_enable_image_hash',
                        '_download',
                        '_featured',
                        '_remote_url',
                        '_ftp_user',
                        '_ftp_host',
                        '_ftp_pass',
                        '_ftp_path',
                        '_local_url',
                        '_meta._enabled'
                    ];

                    foreach ($attachment_keys as $k) {
                        if (isset($fields[$field_id . '.settings.' . $k])) {
                            $attachment_data[$k] = $fields[$field_id . '.settings.' . $k];
                        } elseif (isset($fields[$field_id . '.' . $k])) {
                            $attachment_data[$k] = $fields[$field_id . '.' . $k];
                        }
                    }

                    $ids = $this->process_attachment(0, $attachment_data, '', $filesystem, $ftp, $attachment);
                    $values[$field['name']] = implode(',', $ids);

                    break;
                default:
                    if (isset($fields[$field_id])) {
                        $values[$field['name']] = $fields[$field_id];
                    }
                    break;
            }
        }

        return $values;
    }
}
