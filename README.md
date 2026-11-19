# Blitz Zentrale Generator

A [Blitz](https://github.com/putyourlightson/craft-blitz) cache generator for [Craft CMS](https://craftcms.com) that delegates cache warming to the Zentrale API.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Blitz 5.0+

## Installation

Install via Composer:

```bash
composer require jorisnoo/craft-blitz-zentrale-generator
```

Register and select the generator in `config/blitz.php`:

```php
'cacheGeneratorTypes' => [\Noo\CraftBlitzZentraleGenerator\ZentraleGenerator::class],
'cacheGeneratorType' => \Noo\CraftBlitzZentraleGenerator\ZentraleGenerator::class,
```

Set `ZENTRALE_API_URL` and `ZENTRALE_API_KEY` in the Craft environment. Control-panel settings store references such as `$ZENTRALE_API_KEY`, not the resolved key. If a previous save committed a literal key to project config, replace it with a reference and rotate the exposed key.

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| `apiUrl` | Full URL of the Zentrale cache warm endpoint | `ZENTRALE_API_URL` env var |
| `apiKey` | API key with `cache:warm` ability | `ZENTRALE_API_KEY` env var |
| `warmingMode` | `origin` (direct), `edge` (via Bunny pull-zone), or `both` | `origin` |

To override defaults, use `cacheGeneratorSettings` in `config/blitz.php`:

```php
'cacheGeneratorType' => \Noo\CraftBlitzZentraleGenerator\ZentraleGenerator::class,
'cacheGeneratorSettings' => [
    'apiUrl' => 'https://zentrale.example.com/api/cache/warm',
    'warmingMode' => 'origin',
],
```

## How it works

Queued generation submits up to `min(500, driverJobBatchSize)` URLs per request (Blitz defaults to 100 per job). Inline generation can submit up to 500 at a time. The console count means **accepted for warming**, not finished. Only `202 Accepted` counts; transport errors, invalid settings, and other status codes fail the job.

- **Origin:** requests go directly to the origin with a Blitz generator token so failed pages can be invalidated. Each batch’s token expires after one hour and allows at most one use per submitted URL in total. Keep Zentrale’s queue latency below one hour and configure Zentrale and origin logging to redact Craft’s token query parameter. These URLs are credentials, not ordinary access-log data.
- **Edge:** Zentrale requests public URLs through the pull zone, without generator tokens. This warms the CDN; it does not force Blitz to regenerate an already cached origin page.
- **Both:** origin and edge are submitted separately. Origin acceptance precedes edge submission, but Zentrale runs them asynchronously; completion order is not guaranteed.

Use Blitz’s **clear and generate** refresh mode. With expire-and-generate, Blitz can purge the CDN as soon as Zentrale accepts the work, before origin generation finishes; an edge miss may then cache stale content. Use a worker with explicit completion ordering if that sequence is required.

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
