<?php

namespace ImportWPAddon\JetEngine\Exporter\Mapper;

class UserMapper extends Mapper
{
    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler)
    {
        parent::__construct($event_handler, 'user');
        $this->jet_engine_type = 'user';
    }
}
