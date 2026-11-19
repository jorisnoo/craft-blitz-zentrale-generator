<?php

namespace Noo\CraftBlitzZentraleGenerator;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use DateTime;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use RuntimeException;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\generators\BaseCacheGenerator;
use putyourlightson\blitz\models\SiteUriModel;

class ZentraleGenerator extends BaseCacheGenerator
{
    public const URL_BATCH_SIZE = 500;
    private const WARMING_MODES = ['origin', 'edge', 'both'];

    public ?string $apiUrl = null;

    public ?string $apiKey = null;

    public string $warmingMode = 'origin';

    public function init(): void
    {
        parent::init();

        $this->apiUrl ??= '$ZENTRALE_API_URL';
        $this->apiKey ??= '$ZENTRALE_API_KEY';
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
        if (!$this->test()) {
            throw new RuntimeException(implode(' ', $this->getFirstErrors()));
        }

        $warmable = $skipped = [];
        foreach ($siteUris as $siteUri) {
            if ($this->isWarmableSiteUri($siteUri)) {
                $warmable[] = $siteUri;
            } else {
                $skipped[] = is_array($siteUri) ? new SiteUriModel($siteUri) : $siteUri;
            }
        }
        if ($skipped !== []) {
            Blitz::$plugin->cacheStorage->deleteUris($skipped);
        }

        // Let Blitz check cacheability and invalidate stale records just once, even in both mode.
        $urls = $this->getUrlsToGenerate($warmable, withToken: false);

        $count = 0;
        $total = count($urls);
        $label = 'Warming {total} pages via Zentrale';

        if (is_callable($setProgressHandler)) {
            call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
        }

        $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
        foreach (array_chunk($urls, self::URL_BATCH_SIZE) as $batch) {
            // PSR-7 escapes invalid path/query bytes without double-encoding existing escapes.
            $plain = array_map(static fn(string $url): Uri => Uri::withoutQueryValue(new Uri($url), $tokenParam), $batch);
            if ($this->warmingMode !== 'edge') {
                $token = Craft::$app->getTokens()->createToken(self::GENERATE_ACTION_ROUTE, count($plain), new DateTime('+1 hour'));
                if ($token === false) {
                    throw new RuntimeException('Unable to create a Blitz generator token.');
                }
                $origin = array_map(static fn(Uri $uri): string => (string)Uri::withQueryValue($uri, $tokenParam, $token), $plain);
                $this->sendWarmRequest($origin, 'origin');
            }
            if ($this->warmingMode !== 'origin') {
                $this->sendWarmRequest(array_map('strval', $plain), 'edge');
            }

            $count += count($batch);
            $this->generated += count($batch);

            if (is_callable($setProgressHandler)) {
                call_user_func($setProgressHandler, $count, $total, Craft::t('blitz', $label, ['total' => $total]));
            }
        }
    }

    public function test(): bool
    {
        return $this->validate(['apiUrl', 'apiKey', 'warmingMode']);
    }

    public function getSettingsHtml(): ?string
    {
        $disabled = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return
            Cp::autosuggestFieldHtml([
                'label' => Craft::t('blitz', 'Zentrale API URL'),
                'instructions' => Craft::t('blitz', 'The full Zentrale cache warm endpoint URL.'),
                'id' => 'apiUrl',
                'name' => 'apiUrl',
                'value' => $this->apiUrl,
                'required' => true,
                'suggestEnvVars' => true,
                'disabled' => $disabled,
                'errors' => $this->getErrors('apiUrl'),
            ]) .
            Cp::textFieldHtml([
                'label' => Craft::t('blitz', 'Zentrale API Key'),
                'instructions' => Craft::t('blitz', 'Enter an environment variable reference such as `$ZENTRALE_API_KEY`, not the key itself.'),
                'id' => 'apiKey',
                'name' => 'apiKey',
                'value' => preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $this->apiKey ?? '') ? $this->apiKey : '$ZENTRALE_API_KEY',
                'required' => true,
                'inputAttributes' => ['pattern' => '\$[A-Za-z_][A-Za-z0-9_]*'],
                'disabled' => $disabled,
                'errors' => $this->getErrors('apiKey'),
            ]) .
            Cp::selectFieldHtml([
                'label' => Craft::t('blitz', 'Warming Mode'),
                'instructions' => Craft::t('blitz', 'How Zentrale should warm the cache.'),
                'id' => 'warmingMode',
                'name' => 'warmingMode',
                'value' => $this->warmingMode,
                'disabled' => $disabled,
                'errors' => $this->getErrors('warmingMode'),
                'options' => [
                    ['value' => 'origin', 'label' => 'Origin (direct, bypass CDN)'],
                    ['value' => 'edge', 'label' => 'Edge (via Bunny pull-zone)'],
                    ['value' => 'both', 'label' => 'Both'],
                ],
            ]);
    }

    protected function defineRules(): array
    {
        return [
            [['apiUrl', 'apiKey'], 'required', 'isEmpty' => static fn(?string $value): bool => !App::parseEnv($value)],
            [['warmingMode'], 'in', 'range' => self::WARMING_MODES, 'strict' => true, 'skipOnEmpty' => false],
        ];
    }

    /**
     * @param string[] $urls
     */
    protected function sendWarmRequest(array $urls, string $mode): void
    {
        $apiKey = App::parseEnv($this->apiKey);
        $apiUrl = App::parseEnv($this->apiUrl);

        $client = $this->createHttpClient();

        $response = $client->post($apiUrl, [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'urls' => $urls,
                'mode' => $mode,
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

    /**
     * Blitz can track URIs from malformed requests (e.g. `/%0A`). Their URLs
     * are invalid, and Zentrale rejects them.
     *
     * @param array{siteId: int, uri: string}|SiteUriModel $siteUri
     */
    protected function isWarmableSiteUri(array|SiteUriModel $siteUri): bool
    {
        $uri = $siteUri instanceof SiteUriModel ? $siteUri->uri : $siteUri['uri'];

        return preg_match('/[\s\x00-\x1F\x7F]/', $uri) !== 1;
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
