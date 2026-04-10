<?php

namespace Noo\CraftBlitzZentraleGenerator;

use craft\base\Event;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use putyourlightson\blitz\helpers\CacheGeneratorHelper;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            CacheGeneratorHelper::class,
            CacheGeneratorHelper::EVENT_REGISTER_GENERATOR_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ZentraleGenerator::class;
            }
        );
    }
}
