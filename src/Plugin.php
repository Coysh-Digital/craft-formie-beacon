<?php

namespace coyshdigital\formiebeacon;

use craft\base\Event;
use craft\base\Plugin as BasePlugin;
use coyshdigital\formiebeacon\integrations\crm\Beacon;
use verbb\formie\events\RegisterIntegrationsEvent;
use verbb\formie\services\Integrations;

/**
 * Formie Beacon CRM Integration plugin
 *
 * @method static Plugin getInstance()
 * @author Coysh Digital <support@coysh.digital>
 * @license MIT
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Integrations::class,
            Integrations::EVENT_REGISTER_INTEGRATIONS,
            function(RegisterIntegrationsEvent $event) {
                $event->crm[] = Beacon::class;
            }
        );
    }
}
