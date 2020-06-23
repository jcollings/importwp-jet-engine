<?php

use ImportWP\EventHandler;
use ImportWPAddon\JetEngine\Importer\Template\JetEngine;

function iwp_jet_engine_register_events(EventHandler $event_handler)
{
    $jet_engine = new JetEngine($event_handler);
}

add_action('iwp/register_events', 'iwp_jet_engine_register_events');
