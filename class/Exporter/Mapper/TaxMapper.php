<?php

namespace ImportWPAddon\JetEngine\Exporter\Mapper;

class TaxMapper extends Mapper
{
    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler)
    {
        parent::__construct($event_handler, 'taxonomy');
        $this->jet_engine_type = 'term';
    }
}
