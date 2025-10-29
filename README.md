# D7 Article Migrate (Drupal 11)

## What it does
This module provides a Drush command to migrate published 'article' nodes from a Drupal 7 database connection defined in `settings.php` under the `'migrate'` key.

It migrates:
- title
- body (imports inline <img> images and replaces src)
- field_image (multiple)
- field_tags (D7 vocabulary `vid = 3` -> destination vocabulary `tags`)
- creation and modification dates (created, changed)
- preserves path aliases for nodes and taxonomy terms
- stores mapping in `d7_article_migrate_map` to avoid duplicate imports
- supports updating existing migrated nodes with `--update-existing` option

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

### Basic Migration
Run migration (files-base-url required):
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-url="https://old.example/sites/default/files"
```

### Available Options

| Option | Description | Default | Required |
|--------|-------------|---------|----------|
| `--files-base-url` | Base HTTP URL to D7 public files | - | Yes |
| `--destination-path` | Destination path for downloaded files | `public://d7_migrated/` | No |
| `--limit` | Number of nodes to process (0 = all) | `0` | No |
| `--update-existing` | Update existing nodes instead of skipping | `FALSE` | No |

### Examples

**Migrate with custom destination path:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-url="https://old.example.com/sites/default/files" \
  --destination-path="/www/wwwroot/polissya.today/sites/default/files"
```

**Migrate with limit (for testing):**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-url="https://old.example.com/sites/default/files" \
  --limit=50
```

**Update existing migrated nodes:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-url="https://old.example.com/sites/default/files" \
  --destination-path="/www/wwwroot/polissya.today/sites/default/files" \
  --update-existing
```

**Full example with all options:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-url="https://old.example.com/sites/default/files" \
  --destination-path="/www/wwwroot/polissya.today/sites/default/files" \
  --limit=100 \
  --update-existing
```

## Features

### Date Preservation
The module now preserves the original creation and modification dates from Drupal 7:
- `created` - Original article creation timestamp
- `changed` - Last modification timestamp

This ensures your migrated articles maintain their original publication dates.

### Custom Destination Path
By default, files are downloaded to `public://d7_migrated/`, but you can specify any custom path:
- Use Drupal stream wrappers: `public://custom/path/`
- Use absolute filesystem paths: `/www/wwwroot/polissya.today/sites/default/files`

Files maintain their original directory structure relative to the destination path.

### Update Existing Nodes
Use `--update-existing` to re-migrate articles that were already imported:
- Updates all node fields (title, body, tags, images, dates)
- Useful for fixing corrupted imports or updating content
- Does not create duplicate path aliases or mapping entries
- Logs updates separately from new migrations

## Notes
- Ensure site has `article` content type with fields `field_tags` (vocab `tags`) and `field_image`.
- Test on staging first.
- The `--update-existing` option will overwrite existing content, use with caution.
