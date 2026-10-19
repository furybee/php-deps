<div align="center">

# PHP Deps

**Static analysis tool for visualizing PHP class dependencies and call graphs.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1+-8892BF.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-Compatible-FF2D20.svg)](https://laravel.com)
[![Sigma.js](https://img.shields.io/badge/Sigma.js-v2-E8590C.svg)](https://www.sigmajs.org)


*Analyze your codebase in seconds. Explore thousands of classes interactively.*

</div>

---

## Features

- **Full call graph extraction** — classes, methods, calls, instantiations, DI resolution
- **Type-aware analysis** — resolves constructor injection, method injection, property types, `app()` container calls
- **Interactive WebGL viewer** — powered by Sigma.js v2 + Graphology, handles 10k+ nodes smoothly
- **Layer detection** — auto-categorizes classes (Controller, Service, Model, Job, Action, etc.)
- **Force-directed layout** — ForceAtlas2 algorithm for natural clustering
- **Search & filter** — find classes instantly, filter by layer or edge type
- **No build step viewer** — static HTML, libraries loaded from a CDN, works from `file://` with a file picker
- **Architecture rules** — forbid dependencies between layers, fail CI on new violations, baseline for existing ones
- **Laravel optimized** — understands Laravel patterns (service injection, `app()` helper, Form Requests)

## Requirements

- **PHP 8.1+**
- A modern browser (Chrome, Firefox, Safari, Edge)

## Quick Start

### 1. Clone and install

```bash
git clone git@github.com:furybee/php-deps.git
cd php-deps
composer install
```

> **Already in a PHP project with nikic/php-parser?** (e.g. via PHPStan, Larastan)
> Skip `composer install` — the tool will use your project's `vendor/` automatically.

### 2. Run the analyzer

From the root of the project to analyze:

```bash
php path/to/php-deps/analyze.php --dir=app
```

By default it scans the current directory (`.`) and writes `output/graph.json` inside php-deps.
`vendor/`, `node_modules/`, `.git/`, `storage/` are excluded automatically, at any depth.

### 3. Open the viewer

```bash
open viewer/index.html
```

Load `output/graph.json` via the file picker (drag & drop supported).

> If served via HTTP (`python3 -m http.server 8765 -d viewer`), it auto-loads `graph.json` from the same directory.

## CLI Options

```bash
php analyze.php [--root=.] [--dir=app] [--dir=app-modules] [--exclude=vendor,tests/Fixtures] [--output=graph.json] [--pretty] [--quiet]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--root` | current directory | Project root; `--dir` and path excludes are relative to it |
| `--dir` | `.` | Directory to scan, repeatable |
| `--exclude` | `vendor,node_modules,.git,.claude,storage,bootstrap/cache` | Comma-separated. A bare name (`vendor`) matches a directory at any depth, a path (`bootstrap/cache`) is relative to the root |
| `--output` | `<php-deps>/output/graph.json` | JSON output, relative to the current directory |
| `--pretty` | off | Pretty-print the JSON (about twice as big) |
| `--config` | `<root>/php-deps.php` if present | Architecture rules file, see below |
| `--check` | off | Check the rules, exit 1 on violations not in the baseline |
| `--format` | `text` | Check report: `text`, `github` (Actions annotations) or `gitlab` (Code Quality report) |
| `--generate-baseline` | off | Accept every current violation into the baseline |
| `--quiet` | off | Only print errors and the check report |

### Examples

```bash
# Laravel app + modules
php analyze.php --dir=app --dir=app-modules

# Analyze another project without leaving php-deps
php analyze.php --root=../my-project --dir=src --output=output/my-project.json
```

## Architecture Rules

Describe your layers and their allowed dependencies in `php-deps.php` at the project root
(see [`examples/clean-architecture.php`](examples/clean-architecture.php)):

```php
return [
    'paths' => ['src'],
    'layers' => [
        'Domain' => ['**\Domain\**'],
        'Application' => ['**\Application\**'],
        'Infrastructure' => ['**\Infrastructure\**'],
        'Model' => ['App\*'],
    ],
    'rules' => [
        'Domain' => [],
        'Application' => ['Domain', 'Model'],
        'Infrastructure' => ['Domain', 'Application', 'Model'],
    ],
];
```

- Layer patterns are FQCN globs: `*` stays within one namespace segment, `**` crosses segments.
  A class belongs to the first layer it matches.
- A layer may always depend on itself. A layer missing from `rules` is unrestricted.
  Classes in no layer, vendor and PHP classes are not checked.
- Every edge counts as a dependency, including types only used in signatures or PHPDoc
  and `Foo::BAR` / `instanceof` / `catch` references.

```bash
php analyze.php --check                       # text report, exit 1 on violations
php analyze.php --generate-baseline           # accept the current violations
php analyze.php --check --format=gitlab > gl-code-quality-report.json
```

The baseline lists violating class pairs, not lines, so moving code does not resurface them.
The text report tells when baseline entries no longer occur.

GitLab CI:

```yaml
architecture:
  script: php vendor/bin/analyze.php --check --format=gitlab > gl-code-quality-report.json
  artifacts:
    when: always
    reports:
      codequality: gl-code-quality-report.json
```

## What It Detects

### Node Types
| Type | Description |
|------|-------------|
| Class | Regular PHP classes |
| Interface | PHP interfaces |
| Trait | PHP traits |
| Enum | PHP 8.1 enums |

### Edge Types
| Type | Example | Description |
|------|---------|-------------|
| `calls` | `$this->service->handle()` | Method call on a typed variable |
| `depends_on` | `__construct(UserService $svc)` | Constructor injection |
| `instantiates` | `new UserService()` | Direct instantiation |
| `resolves` | `app(UserService::class)` | Laravel container resolution |
| `extends` | `class Foo extends Bar` | Inheritance |
| `implements` | `class Foo implements Bar` | Interface implementation |
| `uses_trait` | `use SoftDeletes` | Trait usage |
| `uses_type` | `function find(): User` | Type only used in a signature or PHPDoc |
| `references` | `Status::Active`, `catch (FooException)` | Class used without being called |

Every edge carries the `line` where it occurs. `uses_type` and `references` are only kept
between two classes that no other edge links already.

Only classes found in the scanned directories become nodes: edges towards vendor or PHP
built-in classes are dropped. A call to a method that is inherited (parent class, trait,
interface) points to the class that declares it; a call to a method that is declared nowhere
in the scanned code (Eloquent `User::query()`, `__call` magic, vendor parent) points to the
class node itself.

### Type inference

The receiver of a call is resolved from: native types, PHPDoc (`@var`, `@param`, `@return`,
inline `/** @var Foo $foo */`), constructor promotion, inherited properties, method return types
(including fluent `static`), `new`, `clone`, `??`, ternaries, `instanceof` checks, typed closure
parameters, nullsafe calls and container calls (`app(Foo::class)`, `resolve()`, `app()->make()`,
`App::make()`).

### Layer Auto-Detection

Classes are categorized by namespace, in this order: a known layer segment, deepest first
(`Modules\Billing\Application\Actions\Pay` → Action), a Clean Architecture layer
(Domain, Application, Infrastructure, Presentation), the class name suffix
(`PdfRenderService` → Service), then the first meaningful namespace segment.

| Layer | Namespace Pattern |
|-------|------------------|
| Controller | `\Http\Controllers\` |
| Service | `\Services\` |
| Model | `\Models\` |
| Action | `\Actions\` |
| Job | `\Jobs\` |
| Event | `\Events\` |
| Listener | `\Listeners\` |
| Repository | `\Repositories\` |
| Command | `\Console\` |
| DTO | `\Dtos\` |
| ... | and 12 more |

## Viewer Features

| Feature | Description |
|---------|-------------|
| **Namespaces mode** | Browse by folder/namespace, click to drill-down, breadcrumb navigation |
| **Classes mode** | All classes as nodes with aggregated edges |
| **Full Graph mode** | Classes + methods with detailed call edges |
| **Search** | Type `/` to search, autocomplete class names |
| **Layer filter** | Toggle visibility by layer (Controller, Service, Model...) |
| **Type filter** | Toggle by PHP type (Class, Abstract, Interface, Trait, Enum) |
| **Edge filter** | Toggle edge types (calls, extends, implements...) |
| **Click** | Select a node to see all its connections in the info panel |
| **Hover** | Highlight direct neighbors, fade everything else |
| **Force Layout** | Toggle ForceAtlas2 to reorganize the graph |
| **Info panel** | Shows dependencies, dependents, layer breakdown |

## Architecture

```
php-deps/
├── analyze.php              # CLI entry point
├── composer.json             # Dependencies (nikic/php-parser)
├── src/
│   ├── Analyzer.php         # Orchestrator: scans files, runs 2-pass analysis
│   ├── ClassVisitor.php     # Pass 1: extracts classes, methods, properties, DI
│   ├── CallVisitor.php      # Pass 2: extracts method calls, instantiations
│   ├── TypeResolver.php     # Resolves type hints and PHPDoc types to FQCNs
│   ├── ClassHierarchy.php   # Member lookups through parents, traits, interfaces
│   ├── LayerDetector.php    # Infers the architectural layer of a class
│   ├── GraphBuilder.php     # Builds the final JSON graph (nodes + edges)
│   ├── Architecture/        # Layer rules, baseline, text/GitHub/GitLab reports
│   └── Console/             # CLI command
├── tests/                   # PHPUnit tests and fixture projects
├── viewer/
│   ├── index.html           # Standalone viewer (works with file://)
│   ├── app.js               # Sigma.js v2 + Graphology + ForceAtlas2
│   └── style.css            # Dark theme UI
└── output/
    └── graph.json           # Generated graph (gitignored)
```

## How It Works

1. **Pass 1 — Class extraction**: Parses all PHP files with [nikic/php-parser](https://github.com/nikic/PHP-Parser), extracts classes, methods, properties, `use` statements, constructor injection, traits, and inheritance.

2. **Pass 2 — Call graph**: Traverses method bodies to detect `$this->prop->method()` calls, `ClassName::static()` calls, `new ClassName()`, and `app(ClassName::class)` container resolution. Types are resolved using the context from Pass 1.

3. **JSON export**: Builds a graph with nodes (classes + methods) and edges (calls, dependencies, inheritance), then exports as JSON.

4. **Viewer**: Loads the JSON into a Graphology graph and renders it with Sigma.js v2 (WebGL). ForceAtlas2 computes the layout in real-time.

## Development

```bash
composer install
composer test
```

## Performance

Tested on a real Laravel project:

| Metric | Value |
|--------|-------|
| Files scanned | 3,258 |
| Classes found | 3,245 |
| Methods found | 11,977 |
| Edges detected | 21,371 |
| Analysis time | **3.3s** |
| JSON size | 10.3 MB |
| Viewer rendering | Smooth at 60fps |

## License

[MIT](LICENSE)
