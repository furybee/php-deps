<div align="center">

# PHP Deps

**Static analysis tool for visualizing PHP class dependencies and call graphs.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1+-8892BF.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-Compatible-FF2D20.svg)](https://laravel.com)
[![Sigma.js](https://img.shields.io/badge/Sigma.js-v2-E8590C.svg)](https://www.sigmajs.org)

<br>

<img src="https://raw.githubusercontent.com/furybee/php-deps/main/.github/preview.png" alt="PHP Deps Preview" width="720">

<br>

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
- **Zero dependencies viewer** — single HTML file, loads via CDN, works offline with file picker
- **Laravel optimized** — understands Laravel patterns (service injection, `app()` helper, Form Requests)

## Requirements

- **PHP 8.1+**
- **nikic/php-parser** (typically available via PHPStan, Larastan, or Laravel IDE Helper)
- A modern browser (Chrome, Firefox, Safari, Edge)

## Quick Start

### 1. Clone or copy into your project

```bash
# As a standalone tool
git clone git@github.com:furybee/php-deps.git
cd php-deps

# Or copy into an existing Laravel project
cp -r php-deps/ your-project/scripts/deps-graph/
```

### 2. Run the analyzer

```bash
php analyze.php
```

By default, it scans `app/` and outputs to `output/graph.json`.

### 3. Open the viewer

```bash
open viewer/index.html
```

Load `output/graph.json` via the file picker (drag & drop supported).

> If served via HTTP (`python3 -m http.server 8765 -d viewer`), it auto-loads `graph.json` from the same directory.

## CLI Options

```bash
php analyze.php [--dir=app] [--output=output/graph.json]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--dir` | `app` | Directory to scan (relative to project root) |
| `--output` | `output/graph.json` | Output path for the JSON graph |

### Examples

```bash
# Scan a specific directory
php analyze.php --dir=src

# Custom output path
php analyze.php --output=/tmp/graph.json

# Scan modules
php analyze.php --dir=app-modules
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

### Layer Auto-Detection

Classes are categorized by namespace:

| Layer | Namespace Pattern |
|-------|------------------|
| Controller | `\Http\Controllers\` |
| Service | `\Services\` |
| Model | `\Models\` |
| Action | `\Actions\` |
| Job | `\Jobs\` |
| Event | `\Events\` |
| Listener | `\Listeners\` |
| Repository | `\Repository\` |
| Command | `\Console\` |
| DTO | `\Dtos\` |
| ... | and 12 more |

## Viewer Features

| Feature | Description |
|---------|-------------|
| **Search** | Type `/` to search, autocomplete class names |
| **Layer filter** | Toggle visibility by layer (Controller, Service, Model...) |
| **Edge filter** | Toggle edge types (calls, extends, implements...) |
| **Click** | Select a node to see all its connections in the info panel |
| **Hover** | Highlight direct neighbors, fade everything else |
| **Double-click** | Zoom into a node |
| **Force Layout** | Toggle ForceAtlas2 to reorganize the graph |
| **View modes** | "Classes" (aggregated) or "Full Graph" (with methods) |
| **Info panel** | Shows dependencies and dependents, click to navigate |

## Architecture

```
php-deps/
├── analyze.php              # CLI entry point
├── src/
│   ├── Analyzer.php         # Orchestrator: scans files, runs 2-pass analysis
│   ├── ClassVisitor.php     # Pass 1: extracts classes, methods, properties, DI
│   ├── CallVisitor.php      # Pass 2: extracts method calls, instantiations
│   ├── TypeResolver.php     # Resolves types from use statements, type hints
│   └── GraphBuilder.php     # Builds the final JSON graph (nodes + edges)
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
