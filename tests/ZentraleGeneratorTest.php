<?php

namespace Noo\CraftBlitzZentraleGenerator\Tests;

use Craft;
use craft\config\GeneralConfig;
use craft\console\Application;
use craft\services\Config;
use craft\services\Tokens;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Noo\CraftBlitzZentraleGenerator\ZentraleGenerator;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\storage\DummyStorage;
use putyourlightson\blitz\models\SiteUriModel;
use putyourlightson\blitz\services\CacheRequestService;
use ReflectionClass;
use RuntimeException;
use yii\i18n\I18N;
use yii\i18n\MessageSource;

beforeEach(function (): void {
    Craft::$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
    Craft::$app->set('i18n', new I18N(['translations' => [
        '*' => new MessageSource(['sourceLanguage' => 'en-US']),
        'yii' => new MessageSource(['sourceLanguage' => 'en-US']),
    ]]));
    $this->general = $this->createStub(GeneralConfig::class);
    $this->general->tokenParam = 'warmToken';
    $config = $this->createStub(Config::class);
    $config->method('getGeneral')->willReturn($this->general);
    Craft::$app->set('config', $config);

    $this->tokens = $this->createMock(Tokens::class);
    Craft::$app->set('tokens', $this->tokens);
    Blitz::$plugin = (new ReflectionClass(Blitz::class))->newInstanceWithoutConstructor();
    $cache = $this->createStub(CacheRequestService::class);
    $cache->method('getIsCacheableSiteUri')->willReturn(true);
    Blitz::$plugin->set('cacheRequest', $cache);

    $this->generator = new TestZentraleGenerator();
    $this->history = [];
    $this->handler = new MockHandler();
    $stack = HandlerStack::create($this->handler);
    $stack->push(Middleware::history($this->history));
    $this->generator->client = new Client(['handler' => $stack]);
    $this->generator->apiUrl = 'https://zentrale.example.com/api/cache/warm';
    $this->generator->apiKey = 'fixture-key';
});

afterEach(function (): void {
    Craft::$app = null;
});

function siteUri(string $path = ''): SiteUriModel
{
    return new class(['siteId' => 1, 'uri' => $path]) extends SiteUriModel {
        public function getUrl(array $params = []): string
        {
            return 'https://example.com/' . $this->uri . ($params ? '?' . http_build_query($params) : '');
        }
    };
}

it('warms only the selected targets with separate URL identities', function (string $mode, array $expected): void {
    $this->generator->warmingMode = $mode;
    $start = time();
    $this->tokens->expects($mode === 'edge' ? $this->never() : $this->once())->method('createToken')
        ->with(ZentraleGenerator::GENERATE_ACTION_ROUTE, 1, $this->callback(
            static fn(DateTime $expires): bool => $expires->getTimestamp() >= $start + 3600 && $expires->getTimestamp() <= time() + 3600
        ))->willReturn('fixture-token');
    foreach ($expected as $_) {
        $this->handler->append(new Response(202));
    }

    $this->generator->generateUrisWithProgress([siteUri('page?filter=a&filter=b&warmToken=old')]);

    expect($this->history)->toHaveCount(count($expected))
        ->and($this->generator->generated)->toBe(1);
    foreach ($expected as $i => $target) {
        $request = $this->history[$i]['request'];
        $url = 'https://example.com/page?filter=a&filter=b' . ($target === 'origin' ? '&warmToken=fixture-token' : '');
        expect($request->getMethod())->toBe('POST')
            ->and((string)$request->getUri())->toBe($this->generator->apiUrl)
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer fixture-key')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and(json_decode((string)$request->getBody(), true))->toBe(['urls' => [$url], 'mode' => $target])
            ->and($this->history[$i]['options']['timeout'])->toEqual(30);
    }
})->with([
    ['origin', ['origin']],
    ['edge', ['edge']],
    ['both', ['origin', 'edge']],
]);

it('encodes unsafe path bytes once and evicts unsendable site URIs', function (): void {
    $storage = $this->createMock(DummyStorage::class);
    $storage->expects($this->once())->method('deleteUris')->with($this->callback(
        static fn(array $skipped): bool => array_map(static fn(SiteUriModel $model) => $model->uri, $skipped) === ["\n", 'about us']
    ));
    Blitz::$plugin->set('cacheStorage', $storage);
    $this->handler->append(new Response(202));
    $this->generator->warmingMode = 'edge';
    $this->generator->generateUrisWithProgress([
        siteUri(), ['siteId' => 1, 'uri' => "\n"], siteUri('about us'), siteUri('50%/a|b/%C3%A9/"'),
    ]);

    expect(json_decode((string)$this->history[0]['request']->getBody(), true)['urls'])->toBe([
        'https://example.com/', 'https://example.com/50%25/a%7Cb/%C3%A9/%22',
    ]);
});

it('reports progress after each accepted batch', function (): void {
    $this->handler->append(new Response(202), new Response(202));
    $this->generator->warmingMode = 'edge';
    $updates = [];
    $this->generator->generateUrisWithProgress(
        array_map(static fn(int $i) => siteUri("page-$i"), range(1, 501)),
        static function (int $count, int $total, string $label) use (&$updates): void {
            $updates[] = [$count, $total, $label];
        }
    );

    expect(array_map(static fn(array $tx) => count(json_decode((string)$tx['request']->getBody(), true)['urls']), $this->history))->toBe([500, 1])
        ->and($updates)->toBe(array_map(static fn(int $n) => [$n, 501, 'Warming 501 pages via Zentrale'], [0, 500, 501]))
        ->and($this->generator->generated)->toBe(501);
});

it('propagates failures without counting unaccepted work', function ($result, string $exception): void {
    $this->generator->warmingMode = 'both';
    $this->tokens->method('createToken')->willReturn('fixture-token');
    $this->handler->append(new Response(202), $result);
    expect(fn() => $this->generator->generateUrisWithProgress([siteUri()]))->toThrow($exception)
        ->and($this->generator->generated)->toBe(0);
})->with([
    [new Response(200), RuntimeException::class],
    [new Response(403), RequestException::class],
    [new ConnectException('Connection failed', new Request('POST', 'https://example.com')), ConnectException::class],
]);

it('fails closed before submission when token generation fails', function (): void {
    $this->tokens->method('createToken')->willReturn(false);
    expect(fn() => $this->generator->generateUrisWithProgress([siteUri()]))->toThrow(RuntimeException::class, 'Unable to create')
        ->and($this->history)->toBeEmpty();
});

it('rejects invalid settings before generating or sending anything', function (string $attribute, ?string $value): void {
    $this->generator->$attribute = $value;
    expect($this->generator->validate())->toBeFalse()
        ->and($this->generator->hasErrors($attribute))->toBeTrue()
        ->and(fn() => $this->generator->generateUrisWithProgress([siteUri()]))->toThrow(RuntimeException::class)
        ->and($this->history)->toBeEmpty();
})->with([['warmingMode', 'Edge'], ['warmingMode', ''], ['apiKey', null], ['apiUrl', '$ZENTRALE_TEST_UNSET_URL']]);

it('preserves environment references in settings and resolves them for the request', function (): void {
    $generator = new TestZentraleGenerator();
    $generator->client = $this->generator->client;
    $generator->warmingMode = 'edge';
    $this->handler->append(new Response(202));
    $original = $_SERVER;
    try {
        $_SERVER['ZENTRALE_API_URL'] = 'https://zentrale.example.com/env';
        $_SERVER['ZENTRALE_API_KEY'] = 'test-env-value';
        $generator->generateUrisWithProgress([siteUri()]);
        expect($generator->apiUrl)->toBe('$ZENTRALE_API_URL')
            ->and($generator->apiKey)->toBe('$ZENTRALE_API_KEY')
            ->and((string)$this->history[0]['request']->getUri())->toBe('https://zentrale.example.com/env')
            ->and($this->history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-env-value');
    } finally {
        $_SERVER = $original;
    }
});

class TestZentraleGenerator extends ZentraleGenerator
{
    public ClientInterface $client;

    protected function createHttpClient(): ClientInterface
    {
        return $this->client;
    }

    protected function logAcceptedRequest(int $urlCount): void {}
}

