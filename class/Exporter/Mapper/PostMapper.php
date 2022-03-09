<?php

namespace ImportWPAddon\JetEngine\Exporter\Mapper;

class PostMapper extends Mapper
{
    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler)
    {
        parent::__construct($event_handler, 'post_type');
        $this->jet_engine_type = 'post';
    }
}
