<?php

namespace Noo\CraftBlitzZentraleGenerator\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Noo\CraftBlitzZentraleGenerator\ZentraleGenerator;
use RuntimeException;

it('warms Blitz generation URLs', function (): void {
    $generator = new GenerationUrlZentraleGenerator();
    $siteUris = [['siteId' => 1, 'uri' => 'old-uri']];

    $generator->generateUrisWithProgress($siteUris);

    expect($generator->siteUris)->toBe($siteUris)
        ->and($generator->warmedUrls)->toBe(['https://example.com/?token=signed']);
});

it('accepts a 202 response', function (): void {
    $generator = generatorWith(new Response(202));

    $generator->send(['https://example.com/']);

    expect(true)->toBeTrue();
});

it('throws when Zentrale returns a non-202 success response', function (): void {
    generatorWith(new Response(200))->send(['https://example.com/']);
})->throws(RuntimeException::class, 'HTTP 200 instead of 202');

it('propagates rejected API responses', function (): void {
    generatorWith(new Response(403))->send(['https://example.com/']);
})->throws(RequestException::class);

it('propagates transport errors', function (): void {
    $error = new ConnectException('Connection failed', new Request('POST', 'https://zentrale.example.com'));

    generatorWith($error)->send(['https://example.com/']);
})->throws(ConnectException::class, 'Connection failed');

it('throws when credentials are missing', function (): void {
    $generator = generatorWith(new Response(202));
    $generator->apiKey = null;

    $generator->send(['https://example.com/']);
})->throws(RuntimeException::class, 'URL or key not configured');

function generatorWith(Response|\Throwable $result): TestZentraleGenerator
{
    $handler = new MockHandler([$result]);
    $generator = new TestZentraleGenerator();
    $generator->client = new Client(['handler' => HandlerStack::create($handler)]);
    $generator->apiUrl = 'https://zentrale.example.com/api/cache/warm';
    $generator->apiKey = 'secret';

    return $generator;
}

class TestZentraleGenerator extends ZentraleGenerator
{
    public ClientInterface $client;

    public function init(): void {}

    /** @param string[] $urls */
    public function send(array $urls): void
    {
        $this->sendWarmRequest($urls);
    }

    protected function createHttpClient(): ClientInterface
    {
        return $this->client;
    }

    protected function logAcceptedRequest(int $urlCount): void {}
}

class GenerationUrlZentraleGenerator extends ZentraleGenerator
{
    public array $siteUris = [];

    public array $warmedUrls = [];

    public function init(): void {}

    protected function getUrlsToGenerate(array $siteUris, bool $withToken = true): array
    {
        $this->siteUris = $siteUris;

        return ['https://example.com/?token=signed'];
    }

    protected function sendWarmRequest(array $urls): void
    {
        $this->warmedUrls = $urls;
    }
}
