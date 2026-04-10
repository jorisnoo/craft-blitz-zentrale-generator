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

Add `ZENTRALE_API_KEY` to your `.env` and you're done.

## Configuration

The API key is read from the `ZENTRALE_API_KEY` env var and the API URL defaults to `https://zentrale.noo.work/api/cache/warm`. To override these or other settings, create `config/blitz-zentrale-generator.php`:

```php
<?php

use craft\helpers\App;

return [
    'apiUrl' => App::env('ZENTRALE_API_URL'),
    'apiKey' => App::env('ZENTRALE_API_KEY'),
    'warmingMode' => 'both',
];
```

| Setting | Description | Default |
|---------|-------------|---------|
| `apiUrl` | The Zentrale cache warm endpoint | `https://zentrale.noo.work/api/cache/warm` |
| `apiKey` | API key with `cache:warm` ability | `ZENTRALE_API_KEY` env var |
| `warmingMode` | `origin` (direct), `edge` (via Bunny pull-zone), or `both` | `origin` |

Values set in the Blitz CP settings take precedence over the config file.

## How it works

When Blitz triggers cache generation, this generator batches the URLs (500 per request) and sends them to the Zentrale warm endpoint. Zentrale handles the actual warming asynchronously.

## License

[MIT](LICENSE.md)
