# ZF1 → Laravel Migration Tool

A CLI tool built with Laravel 13 that analyzes Zend Framework 1 projects and generates equivalent Laravel code.

It **reads** from a ZF1 codebase and **writes** the generated Laravel files to a separate target directory — the original ZF1 project is never modified.

## Quick Start

```bash
# 1. Analyze a ZF1 project
php artisan zf1:analyze /path/to/zf1-project

# 2. Run the full migration wizard
php artisan zf1:migrate-all /path/to/zf1-project --target=/path/to/new-laravel-project
```

## Requirements

- PHP 8.2+
- Laravel 13 (already included)
- Composer dependencies installed (`composer install`)

## Usage

### Analyze a ZF1 project

Scans the project structure and prints a summary of detected apps, modules, controllers, models, and views.

```bash
php artisan zf1:analyze /path/to/zf1-project

# Save analysis as JSON
php artisan zf1:analyze /path/to/zf1-project --json --output=analysis.json
```

### Full Migration Wizard

Runs all migration steps sequentially with interactive confirmation for each file.

```bash
php artisan zf1:migrate-all /path/to/zf1-project --target=/path/to/new-laravel-project

# Options:
#   --target=    Destination Laravel project path (required)
#   --app=       Migrate only a specific app (gps, clinosweb, corazon)
#   --module=    Migrate only a specific module
#   --force      Write all files without confirmation prompts
```

### Individual Commands

Each migration step can be run independently:

```bash
# Migrate models (Zend_Db_Table_Abstract → Eloquent)
php artisan zf1:migrate-models /path/to/zf1-project --target=/path/to/new-laravel-project

# Migrate controllers (Zend_Controller_Action → Laravel)
php artisan zf1:migrate-controllers /path/to/zf1-project --target=/path/to/new-laravel-project

# Migrate views (.phtml → Blade)
php artisan zf1:migrate-views /path/to/zf1-project --target=/path/to/new-laravel-project

# Generate routes
php artisan zf1:migrate-routes /path/to/zf1-project --target=/path/to/new-laravel-project
```

## ZF1 Project Structure Expected

The tool expects a ZF1 project with the following layout:

```
application/
  gps/                        ← App
    modules/
      {Module}/
        controllers/          ← Zend_Controller_Action subclasses
        models/               ← Zend_Db_Table_Abstract subclasses
        views/scripts/        ← .phtml templates
  clinosweb/                 ← App
    modules/
      ...
  corazon/                   ← App
    modules/
      ...
```

Multiple apps under `application/` are automatically detected.

## What Gets Migrated

| ZF1 | Laravel Generated |
|-----|------------------|
| `Zend_Controller_Action` | `Controller` with `Request` injection |
| `$this->_getParam('id')` | `$request->input('id')` |
| `$this->_redirect('/url')` | `redirect()->to('/url')` |
| `$this->view->var = 'val'` | `$data['var'] = 'val'` + `view(...)` |
| `<?= $this->escape($var) ?>` | `{{ $var }}` |
| `<?= $this->translate('x') ?>` | `{{ __('x') }}` |
| `<?php foreach(...): ?>` | `@foreach(...)` |
| `Zend_Db_Table_Abstract` | `Eloquent\Model` |
| `$_name`, `$_primary` | `$table`, `$primaryKey` |
| `$_referenceMap` | `belongsTo()` relations |
| `$_dependentTables` | `hasMany()` relations |
| Default module/controller/action routing | `Route::get(...)` groups |

## Generated File Structure

```
/path/to/new-laravel-project/
  app/Models/{App}/{Module}/*.php       ← Eloquent models
  app/Http/Controllers/{App}/{Module}/*.php  ← Laravel controllers
  resources/views/{App}/{Module}/.../*.blade.php  ← Blade templates
  routes/{app}.php                      ← Route definitions
```

## Semi-Automatic Mode

By default (without `--force`), the tool shows each generated file and asks for confirmation before writing it. This gives you full control over what gets migrated.

## Limitations

- Zend_Form is not migrated (marked as TODO in generated code)
- Custom ACL systems require manual adaptation
- Complex view helpers (headScript, headLink) are mapped to `@push()` with review notes
- Class names from ZF1 (e.g., `Gps_Foo_BarController`) are preserved — rename them manually after migration
- Schema migrations are generated as model stubs; actual column types require a database connection to the ZF1 database

## License

MIT
