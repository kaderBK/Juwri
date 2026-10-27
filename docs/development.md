# Development setup

Requirements: PHP 8.4 (ext-sodium, ext-intl, ext-gd, ext-pdo_*), Composer 2, Node 20+,
MySQL 8.4 or PostgreSQL 18 (SQLite works for quick local tests).

```bash
cd core
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
```

- Tests: `php artisan test --parallel` (the suite must also pass on MySQL and PostgreSQL).
- Static analysis: `vendor/bin/phpstan analyse --memory-limit=1G`. Format changed files with Pint.
- Enterprise features (the private `juwri/enterprise` Composer package) need a licence; this repository is the
  Community edition and runs without them.
