# D7 Article Migrate (Drupal 11)

## What it does
This module provides a Drush command to migrate published 'article' nodes from a Drupal 7 database connection defined in `settings.php` under the `'migrate'` key.

It migrates:
- title
- body (imports inline <img> images with relative URLs, cleans HTML)
- field_image (multiple)
- field_tags (D7 vocabulary `vid = 3` -> destination vocabulary `tags`, excludes "Новини")
- creation and modification dates (created, changed)
- assigns random authors to migrated articles
- preserves path aliases for nodes and taxonomy terms
- stores mapping in `d7_article_migrate_map` to avoid duplicate imports
- supports updating existing migrated nodes with `--update-existing` option
- supports assigning articles to multiple domains with `--domains` option

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
Run migration (files-base-path required):
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files"
```

### Available Options

| Option | Description | Default | Required |
|--------|-------------|---------|----------|
| `--files-base-path` | Base path to D7 public files (local path or HTTP URL) | - | Yes |
| `--limit` | Number of nodes to process (0 = all) | `0` | No |
| `--update-existing` | Update existing nodes instead of skipping | `FALSE` | No |
| `--domains` | Comma-separated list of domain machine names for Domain module (e.g., `new_polissya_today,polissya_today`) | - | No |
| `--skip-domain-source` | Skip setting field_domain_source (canonical domain) even if the field exists | `FALSE` | No |

### Examples

**Migrate from local filesystem path:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files"
```

**Migrate from HTTP URL:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="https://old.example.com/sites/default/files"
```

**Migrate with limit (for testing):**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files" \
  --limit=50
```

**Update existing migrated nodes:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files" \
  --update-existing
```

**Assign articles to multiple domains:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files" \
  --domains="new_polissya_today,polissya_today"
```

**Assign domains but skip setting canonical domain (field_domain_source):**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files" \
  --domains="new_polissya_today,polissya_today" \
  --skip-domain-source
```

**Full example with all options:**
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files" \
  --limit=100 \
  --update-existing \
  --domains="new_polissya_today,polissya_today"
```

### Clear Migrated Content

**WARNING: This command will delete ALL migrated content and cannot be undone!**

Clear all migrated articles, taxonomy terms, files, and path aliases:
```bash
vendor/bin/drush d7-migrate:clear
```

This command will:
- Delete all migrated article nodes
- Delete all files attached to field_image
- Delete all body images (public://body_images/)
- Delete all migrated taxonomy terms
- Delete all migrated path aliases
- Clear the migration map table

The command requires confirmation before proceeding.

## Features

### Date Preservation
The module preserves the original creation and modification dates from Drupal 7:
- `created` - Original article creation timestamp
- `changed` - Last modification timestamp

This ensures your migrated articles maintain their original publication dates.

### Flexible File Source
The `--files-base-path` option accepts both local filesystem paths and HTTP URLs:
- **Local filesystem path**: `/www/wwwroot/polissya.today/sites/default/files`
- **HTTP URL**: `https://old.example.com/sites/default/files`

Files are automatically copied from the source (local or remote) to the current Drupal 11 installation's `public://` files directory, maintaining the original directory structure.

### URL Alias Preservation
The module automatically migrates URL aliases from Drupal 7 for both articles and taxonomy terms:
- Preserves original D7 path aliases
- Uses the language code from D7 (or site default if not set)
- Prevents duplicate aliases
- Works for both new migrations and updates with `--update-existing`
- Deletes aliases when using `d7-migrate:clear` command

### Update Existing Nodes
Use `--update-existing` to re-migrate articles that were already imported:
- Updates all node fields (title, body, tags, images, dates)
- Updates or creates path aliases
- Useful for fixing corrupted imports or updating content
- Does not create duplicate mapping entries
- Logs updates separately from new migrations

### HTML Cleanup
The module automatically cleans up body HTML during migration:
- Removes unwanted attributes: `class`, `style`, `dir`
- Removes Google Docs internal IDs (`docs-internal-guid-*`)
- Removes empty paragraphs and divs (containing only whitespace or &nbsp;)
- Preserves elements containing child elements like images and links
- Converts all non-breaking spaces (`&nbsp;`) to regular spaces
- Uses relative URLs for body images (without domain)

### Random Author Assignment
Articles are automatically assigned random authors during migration:
- Selects from all active users (excluding admin uid 1 and anonymous uid 0)
- Each article gets a randomly selected author
- Falls back to admin if no valid users are found
- Helps distribute content ownership across the editorial team

### Tag Exclusions
The module excludes specific tags during migration:
- "Новини" tag is automatically excluded
- Other tags from D7 vocabulary `vid = 3` are migrated to destination vocabulary `tags`

### Domain Module Integration
Use `--domains` option to assign articles to multiple domains:
- Requires the Domain module to be installed and configured
- Domain IDs must be **machine names** (use underscores, not dots: `new_polissya_today` not `new.polissya.today`)
- Articles will be assigned to the `field_domain_access` field (all domains) - **required**
- The first domain in the list is set as `field_domain_source` (canonical/primary domain) - **optional**
- If `field_domain_source` doesn't exist, it will be skipped automatically
- Use `--skip-domain-source` to prevent setting `field_domain_source` even if the field exists
- Supports multiple domains (comma-separated list)
- Works with both new migrations and `--update-existing`
- Example: `--domains="new_polissya_today,polissya_today"`
  - `field_domain_access`: both domains (required)
  - `field_domain_source`: new_polissya_today (optional - first domain)
- Example with `--skip-domain-source`:
  - `field_domain_access`: both domains
  - `field_domain_source`: not set (skipped)

## Notes
- Ensure site has `article` content type with fields `field_tags` (vocab `tags`) and `field_image`.
- For domain assignment, ensure the Domain module is installed and `field_domain_access` field exists (required). The `field_domain_source` field is optional.
- Test on staging first.
- The `--update-existing` option will overwrite existing content, use with caution.
