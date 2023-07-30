<?php

namespace ImportWPAddon\JetEngine\Importer\Mapper;

use ImportWP\Common\Importer\Exception\MapperException;
use ImportWP\Common\Importer\Mapper\AbstractMapper;
use ImportWP\Common\Importer\MapperInterface;
use ImportWP\Common\Importer\ParsedData;
use ImportWPAddon\JetEngine\Importer\Template\CustomContentTypeTemplate;

/**
 * @property CustomContentTypeTemplate $template 
 */
class CustomContentTypeMapper extends AbstractMapper implements MapperInterface
{

    /**
     * @var \Jet_Engine\Modules\Custom_Content_Types\Factory
     */
    public $content_type;

    public function setup()
    {
        if (is_null($this->content_type)) {
            $content_type = $this->importer->getSetting('content_type');
            $this->content_type = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types($content_type);
        }
    }

    public function teardown()
    {
    }

    public function exists(ParsedData $data)
    {
        // If update permissions are not set then we can import without any unique fields.
        $update_permission = $this->importer->getPermission('update');
        $delete_permission = $this->importer->getPermission('delete');
        if ($update_permission['enabled'] !== true && $delete_permission['enabled'] !== true) {
            return false;
        }

        $unique_field_args = $this->get_unique_field($data);
        if (empty($unique_field_args)) {
            throw new MapperException("No Unique fields present.");
        }

        $results = $this->content_type->get_db()->query($unique_field_args);
        $result_count = count($results);
        if ($result_count > 1) {
            throw new MapperException("Record is not unique.");
        }

        if ($result_count === 1) {
            $this->ID = $results[0]['_ID'];
            return $this->ID;
        }

        return false;
    }

    public function get_unique_field(ParsedData $data)
    {
        $unique_fields = ['_ID'];

        // allow user to set unique field name, get from importer setting
        $unique_field = $this->importer->getSetting('unique_field');
        if ($unique_field !== null) {
            $unique_fields = is_string($unique_field) ? [$unique_field] : $unique_field;
        }

        $unique_fields = $this->getUniqueIdentifiers($unique_fields);
        $unique_fields = apply_filters('iwp/template_unique_fields', $unique_fields, $this->template, $this->importer);

        $query_args = [];

        foreach ($unique_fields as $field) {

            // check all groups for a unique value
            $unique_value = $data->getValue($field, '*');
            if (!empty($unique_value)) {
                $query_args[$field] = $unique_value;
                break;
            }
        }

        if (empty($query_args)) {
            return false;
        }

        return $query_args;
    }

    public function insert(ParsedData $data)
    {
        $values = $data->getData();

        $this->ID = $this->content_type->get_db()->insert($values);
        if (is_wp_error($this->ID)) {
            throw new MapperException($this->ID->get_error_message());
        }

        $this->template->process($this->ID, $data, $this->importer);
        $this->add_session_tag('jet-engine-cct');
        $this->template->post_process($this->ID, $data);

        return $this->ID;
    }

    public function update(ParsedData $data)
    {
        $values = $data->getData();
        $where = ['_ID' => $this->ID];

        $this->content_type->get_db()->update($values, $where);

        $this->template->process($this->ID, $data, $this->importer);
        $this->add_session_tag('jet-engine-cct');
        $this->template->post_process($this->ID, $data);

        return $this->ID;
    }

    public function get_objects_for_removal()
    {
        return $this->get_ids_without_session_tag('jet-engine-cct');
    }

    public function delete($id)
    {
        $this->content_type->get_db()->delete(['_ID' => $id]);

        $this->remove_session_tag($id, 'jet-engine-cct');
    }
}
