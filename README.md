# tangible/phan-ddd-plugins

[Phan](https://github.com/phan/phan) plugins that turn architecture rules into
static-analysis issues. They are written for codebases built on
[Tangible DDD](https://github.com/TangibleInc/tangible-ddd), and the layer
plugin works for any PHP codebase organized by namespace.

## Installation

```bash
composer require --dev tangible/phan-ddd-plugins
```

The plugins need Phan 5 (they are tested with 5.5). The package does not
install Phan itself, so a project that runs Phan from a PHAR keeps doing so,
but Composer refuses to install it next to an older `phan/phan`.

## LayerRulesPlugin

Splits a codebase into layers by namespace and reports every reference from a
layer to code it may not use. Every way of naming a class counts: extends and
implements, traits, property, parameter and return types (including PHPDoc
types such as `array<int, Foo>`), `new`, static calls and properties, class
constants, `instanceof`, `catch`, and `Foo::class`, whether the name is
imported with `use` or written out in full.

Each issue is reported as `PhanPluginLayerViolation` and starts with the rule
ID you configure, so the report can be read next to the rules it enforces.

```php
// .phan/config.php
$config['plugins'][] = 'vendor/tangible/phan-ddd-plugins/plugins/LayerRulesPlugin.php';

$config['plugin_config']['layer_rules'] = [
    'layers' => [
        'domain' => [
            'namespaces'    => ['Acme\Shop\Domain'],
            'rule'          => 'DDD-L1',
            'may_use'       => [],
            // Optional: the only code outside every layer this layer may use,
            // besides PHP's own classes.
            'external_only' => ['TangibleDDD\Domain'],
        ],
        'application' => [
            'namespaces' => ['Acme\Shop\Application'],
            'rule'       => 'DDD-L4',
            'may_use'    => ['domain'],
        ],
        'adapters' => [
            'namespaces'       => ['Acme\Shop\Api'],
            'rule'             => 'DDD-L5',
            'may_use'          => ['domain', 'application'],
            // Optional: namespaces forbidden even inside an allowed layer.
            'deny'             => ['Acme\Shop\Domain\Repositories' => 'DDD-L5'],
            // Optional: classes forbidden by what they extend or implement.
            'deny_subtypes_of' => ['TangibleDDD\Domain\Shared\Aggregate' => 'DDD-L5'],
        ],
    ],
];
```

A layer may always use itself. Code outside every layer is not checked, and
without configuration the plugin reports nothing.

## DDDAggregateEventCollectionPlugin

Checks that domain events recorded on an aggregate are collected when it is
saved:

- `PhanPluginDDDMissingEventCollection`: a repository method that accepts an
  aggregate does not call `collect_from()`.
- `PhanPluginDDDMissingEventsUnitOfWork`: a repository that persists
  aggregates does not accept `EventsUnitOfWork` in its constructor.

Repositories extending the framework's `PersistsAggregatesRepository` are
skipped; the base class collects events for them.

## Tests

```bash
bash tests/run-tests.sh
```

Runs Phan over the fixtures in `tests/` and checks both that the planted
violations are reported and that correct code is not.
