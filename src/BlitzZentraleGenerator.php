<?php

namespace Noo\CraftBlitzZentraleGenerator;

use Craft;
use craft\base\Event;
use craft\events\RegisterComponentTypesEvent;
use putyourlightson\blitz\helpers\CacheGeneratorHelper;
use yii\base\Module;

class BlitzZentraleGenerator extends Module
{
    private array $config;

    public static function getInstance(): static
    {
        return Craft::$app->getModule('blitz-zentrale-generator');
    }

    public function init(): void
    {
        Craft::setAlias('@Noo/CraftBlitzZentraleGenerator', __DIR__);

        parent::init();

        $this->config = array_merge(
            require dirname(__DIR__) . '/config.php',
            Craft::$app->config->getConfigFromFile('blitz-zentrale-generator'),
        );

        Event::on(
            CacheGeneratorHelper::class,
            CacheGeneratorHelper::EVENT_REGISTER_GENERATOR_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ZentraleGenerator::class;
            }
        );
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
