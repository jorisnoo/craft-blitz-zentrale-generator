<?php

namespace Noo\CraftBlitzZentraleGenerator;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\Cp;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\generators\BaseCacheGenerator;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\log\Logger;

class ZentraleGenerator extends BaseCacheGenerator
{
    public const URL_BATCH_SIZE = 500;

    public ?string $apiUrl = null;

    public ?string $apiKey = null;

    public string $warmingMode = 'origin';

    public function init(): void
    {
        parent::init();

        $defaults = require dirname(__DIR__) . '/config.php';
        $config = array_merge($defaults, Craft::$app->config->getConfigFromFile('blitz-zentrale-generator'));

        $this->apiUrl ??= $config['apiUrl'];
        $this->apiKey ??= $config['apiKey'];
        $this->warmingMode = $config['warmingMode'] ?? $this->warmingMode;
    }

    public static function displayName(): string
    {
        return Craft::t('blitz', 'Zentrale Cache Warmer');
    }

    public function attributeLabels(): array
    {
        return [
            'apiUrl' => Craft::t('blitz', 'Zentrale API URL'),
            'apiKey' => Craft::t('blitz', 'Zentrale API Key'),
            'warmingMode' => Craft::t('blitz', 'Warming Mode'),
        ];
    }

    protected function generateUrisWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $urls = SiteUriHelper::getUrlsFromSiteUris($siteUris);

        $count = 0;
        $total = count($urls);
        $label = 'Warming {total} pages via Zentrale';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        $batches = array_chunk($urls, self::URL_BATCH_SIZE);

        foreach ($batches as $batch) {
            $this->sendWarmRequest($batch);

            $count += count($batch);

            if (is_callable($setProgressHandler)) {
                call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
            }
        }
    }

    public function test(): bool
    {
        $apiKey = App::parseEnv($this->apiKey);
        $apiUrl = App::parseEnv($this->apiUrl);

        if (empty($apiKey)) {
            $this->addError('apiKey', Craft::t('blitz', 'An API key is required.'));

            return false;
        }

        if (empty($apiUrl)) {
            $this->addError('apiUrl', Craft::t('blitz', 'An API URL is required.'));

            return false;
        }

        return true;
    }

    public function getSettingsHtml(): ?string
    {
        return
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'Zentrale API URL'),
                'instructions' => Craft::t('blitz', 'The base URL of the Zentrale instance (e.g. `https://zentrale.example.com`).'),
                'id' => 'apiUrl',
                'name' => 'apiUrl',
                'value' => $this->apiUrl,
                'required' => true,
                'suggestEnvVars' => true,
            ]) .
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'Zentrale API Key'),
                'instructions' => Craft::t('blitz', 'The `ZENTRALE_API_KEY` token with `cache:warm` ability.'),
                'id' => 'apiKey',
                'name' => 'apiKey',
                'value' => $this->apiKey,
                'required' => true,
                'suggestEnvVars' => true,
            ]) .
            Cp::selectFieldHtml([
                'label' => Craft::t('blitz', 'Warming Mode'),
                'instructions' => Craft::t('blitz', 'How Zentrale should warm the cache.'),
                'id' => 'warmingMode',
                'name' => 'warmingMode',
                'value' => $this->warmingMode,
                'options' => [
                    ['value' => 'origin', 'label' => 'Origin (direct, bypass CDN)'],
                    ['value' => 'edge', 'label' => 'Edge (via Bunny pull-zone)'],
                    ['value' => 'both', 'label' => 'Both'],
                ],
            ]);
    }

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiUrl', 'apiKey'],
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['apiUrl', 'apiKey'], 'required'],
        ];
    }

    /**
     * @param string[] $urls
     */
    private function sendWarmRequest(array $urls): bool
    {
        $apiKey = App::parseEnv($this->apiKey);
        $apiUrl = App::parseEnv($this->apiUrl);

        if (empty($apiKey) || empty($apiUrl)) {
            Blitz::$plugin->log('Zentrale API URL or key not configured.', [], Logger::LEVEL_WARNING);

            return false;
        }

        $endpoint = rtrim($apiUrl, '/') . '/api/cache/warm';

        $client = Craft::createGuzzleClient();

        try {
            $response = $client->post($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'urls' => $urls,
                    'mode' => $this->warmingMode,
                ],
                'timeout' => 30,
            ]);

            Blitz::$plugin->log('Zentrale cache warm request accepted for ' . count($urls) . ' URL(s).');

            return $response->getStatusCode() === 202;
        } catch (\Throwable $e) {
            Blitz::$plugin->log(
                'Zentrale cache warm request failed: ' . $e->getMessage(),
                [],
                Logger::LEVEL_ERROR
            );

            return false;
        }
    }
}
