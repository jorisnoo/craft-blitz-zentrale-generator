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

Set the generator type in `config/blitz.php`:

```php
'cacheGeneratorType' => \Noo\CraftBlitzZentraleGenerator\ZentraleGenerator::class,
```

Add `ZENTRALE_API_URL` and `ZENTRALE_API_KEY` to your `.env` and you're done.

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
    'warmingMode' => 'both',
],
```

## How it works

When Blitz triggers cache generation, this generator batches the URLs (500 per request) and sends them to the Zentrale warm endpoint. Zentrale handles the actual warming asynchronously.

## License

[MIT](LICENSE.md)
