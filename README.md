# Blitz Zentrale Generator

A [Craft CMS](https://craftcms.com) module that adds a [Blitz](https://github.com/putyourlightson/craft-blitz) cache generator which delegates cache warming to the Zentrale API.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Blitz 5.0+

## Installation

Install via Composer:

```bash
composer require jorisnoo/craft-blitz-zentrale-generator
```

Register the module in `config/app.php`:

```php
return [
    'modules' => [
        'blitz-zentrale-generator' => \Noo\CraftBlitzZentraleGenerator\BlitzZentraleGenerator::class,
    ],
    'bootstrap' => ['blitz-zentrale-generator'],
];
```

Then select "Zentrale Cache Warmer" as the cache generator in the Blitz settings.

## Configuration

Create `config/blitz-zentrale-generator.php`:

```php
<?php

use craft\helpers\App;

return [
    'apiUrl' => App::env('ZENTRALE_API_URL'),
    'apiKey' => App::env('ZENTRALE_API_KEY'),
];
```

| Setting | Description | Default |
|---------|-------------|---------|
| `apiUrl` | Base URL of the Zentrale instance (e.g. `https://zentrale.example.com`) | — |
| `apiKey` | API key with `cache:warm` ability | — |
| `warmingMode` | `origin` (direct), `edge` (via Bunny pull-zone), or `both` | `origin` |

Settings from the config file are used as defaults. Values set in the Blitz CP settings take precedence.

## How it works

When Blitz triggers cache generation, this generator batches the URLs (500 per request) and sends them to the Zentrale `/api/cache/warm` endpoint. Zentrale handles the actual warming asynchronously.

## License

[MIT](LICENSE.md)
