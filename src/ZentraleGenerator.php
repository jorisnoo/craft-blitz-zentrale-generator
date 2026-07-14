<?php

namespace Noo\CraftBlitzZentraleGenerator;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\Cp;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\generators\BaseCacheGenerator;

class ZentraleGenerator extends BaseCacheGenerator
{
    public const URL_BATCH_SIZE = 500;

    public ?string $apiUrl = null;

    public ?string $apiKey = null;

    public string $warmingMode = 'origin';

    public function init(): void
    {
        parent::init();

        $this->apiUrl ??= App::env('ZENTRALE_API_URL');
        $this->apiKey ??= App::env('ZENTRALE_API_KEY');
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

    public function generateUrisWithProgress(array $siteUris, ?callable $setProgressHandler = null): void
    {
        $urls = $this->getUrlsToGenerate($siteUris);

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
                'instructions' => Craft::t('blitz', 'The full Zentrale cache warm endpoint URL.'),
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
    protected function sendWarmRequest(array $urls): void
    {
        $apiKey = App::parseEnv($this->apiKey);
        $apiUrl = App::parseEnv($this->apiUrl);

        if (empty($apiKey) || empty($apiUrl)) {
            throw new RuntimeException('Zentrale API URL or key not configured.');
        }

        $client = $this->createHttpClient();

        $response = $client->post($apiUrl, [
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

        if ($response->getStatusCode() !== 202) {
            throw new RuntimeException(sprintf(
                'Zentrale cache warm request returned HTTP %d instead of 202.',
                $response->getStatusCode(),
            ));
        }

        $this->logAcceptedRequest(count($urls));
    }

    protected function createHttpClient(): ClientInterface
    {
        return Craft::createGuzzleClient();
    }

    protected function logAcceptedRequest(int $urlCount): void
    {
        Blitz::$plugin->log("Zentrale cache warm request accepted for $urlCount URL(s).");
    }
}
