# D7 Article Migrate (Drupal 11)

## What it does
This module provides a Drush command to migrate published 'article' nodes from a Drupal 7 database connection defined in `settings.php` under the `'migrate'` key.

It migrates:
- title
- body (imports inline <img> images and replaces src)
- field_image (multiple)
- field_tags (D7 vocabulary `vid = 3` -> destination vocabulary `tags`)
- preserves path aliases for nodes and taxonomy terms
- stores mapping in `d7_article_migrate_map` to avoid duplicate imports

## Install
1. Copy module to `modules/custom/d7_article_migrate`
2. Add D7 DB connection to `settings.php`:
```php
$databases['migrate']['default'] = [
  'driver' => 'mysql',
  'database' => 'drupal7_db',
  'username' => 'dbuser',
  'password' => 'dbpass',
  'host' => '127.0.0.1',
  'port' => '3306',
  'prefix' => '',
];
```
3. Enable module:
```
vendor/bin/drush en d7_article_migrate -y
```
4. Clear caches:
```
composer dump-autoload
rm -rf ~/.drush
vendor/bin/drush cache:clear drush
vendor/bin/drush cr
```

## Usage
Run migration (files-base-url required):
```
vendor/bin/drush d7-migrate:articles --files-base-url="https://old.example/sites/default/files" --limit=50
```
`--limit=0` (default) processes all.

## Notes
- Ensure site has `article` content type with fields `field_tags` (vocab `tags`) and `field_image`.
- Test on staging first.
