<?php

use ImportWP\EventHandler;
use ImportWPAddon\JetEngine\Exporter\Mapper\PostMapper;
use ImportWPAddon\JetEngine\Exporter\Mapper\TaxMapper;
use ImportWPAddon\JetEngine\Exporter\Mapper\UserMapper;
use ImportWPAddon\JetEngine\Importer\Template\JetEngine;

function iwp_jet_engine_register_events(EventHandler $event_handler)
{
    $jet_engine = new JetEngine($event_handler);

    new PostMapper($event_handler);
    new TaxMapper($event_handler);
    new UserMapper($event_handler);
}

add_action('iwp/register_events', 'iwp_jet_engine_register_events');
