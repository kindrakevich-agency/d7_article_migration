# D7 Article Migrate (Drupal 11)

## What it does
This module provides Drush commands to migrate published 'article' nodes:
- **d7-migrate:articles** - Migrate from Drupal 7 to Drupal 11
- **d11-migrate:articles** - Migrate from Drupal 11 to Drupal 11

Database connections are defined in `settings.php`.

### D7 to D11 Migration Features:
- title, body (imports inline <img> images with relative URLs, cleans HTML)
- field_image (multiple), field_tags (D7 vocabulary `vid = 3` -> `tags`, excludes "Новини")
- creation and modification dates (created, changed)
- assigns random authors to migrated articles
- preserves path aliases for nodes and taxonomy terms
- stores mapping in `d7_article_migrate_map` to avoid duplicate imports
- supports updating existing migrated nodes with `--update-existing` option
- supports assigning articles to multiple domains with `--domains` option

### D11 to D11 Migration Features:
- title, body (imports inline <img> images with relative URLs)
- field_image (multiple), field_tags, field_video (converts to iframe in body)
- creation and modification dates (created, changed), uid (preserves user ID)
- preserves path aliases for nodes and taxonomy terms
- stores mapping in source-specific tables (`d11_migrate_map_{source_db_key}`) to support multiple D11 sources
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

## Drupal 7 to Drupal 11 Migration

### Basic Migration (D7 to D11)
Run migration (files-base-path required):
```bash
vendor/bin/drush d7-migrate:articles \
  --files-base-path="/www/wwwroot/polissya.today/sites/default/files"
```

### Available Options (D7 Migration)

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

---

## Drupal 11 to Drupal 11 Migration

### Setup for D11 to D11 Migration

Add source D11 database connection to `settings.php`:
```php
$databases['d11_source']['default'] = [
  'driver' => 'mysql',
  'database' => 'source_drupal11_db',
  'username' => 'dbuser',
  'password' => 'dbpass',
  'host' => '127.0.0.1',
  'port' => '3306',
  'prefix' => '',
];
```

### Basic Migration (D11 to D11)
```bash
vendor/bin/drush d11-migrate:articles \
  --files-base-path="/www/wwwroot/source-site/sites/default/files"
```

### Available Options (D11 Migration)

| Option | Description | Default | Required |
|--------|-------------|---------|----------|
| `--source-db` | Database connection key from settings.php | `d11_source` | No |
| `--files-base-path` | Base path to source D11 public files (local path or HTTP URL) | - | Yes |
| `--limit` | Number of nodes to process (0 = all) | `0` | No |
| `--update-existing` | Update existing nodes instead of skipping | `FALSE` | No |
| `--domains` | Comma-separated list of domain machine names (e.g., `new_polissya_today,polissya_today`) | - | No |
| `--skip-domain-source` | Skip setting field_domain_source (canonical domain) | `FALSE` | No |

### Examples (D11 Migration)

**Migrate from another D11 site with default database key:**
```bash
vendor/bin/drush d11-migrate:articles \
  --files-base-path="/www/wwwroot/source-site/sites/default/files"
```

**Migrate with custom database connection key:**
```bash
vendor/bin/drush d11-migrate:articles \
  --source-db="old_d11_site" \
  --files-base-path="https://old-site.example.com/sites/default/files"
```

**Migrate with domains and limit:**
```bash
vendor/bin/drush d11-migrate:articles \
  --source-db="d11_source" \
  --files-base-path="/www/wwwroot/source-site/sites/default/files" \
  --domains="new_polissya_today,polissya_today" \
  --limit=100
```

**Full example with all options:**
```bash
vendor/bin/drush d11-migrate:articles \
  --source-db="d11_source" \
  --files-base-path="/www/wwwroot/source-site/sites/default/files" \
  --limit=100 \
  --update-existing \
  --domains="new_polissya_today,polissya_today"
```

### What Gets Migrated (D11 to D11)

From source Drupal 11 site:
- **Nodes**: Article content type
- **Fields**:
  - `title`
  - `body` (with text format preserved, inline images migrated)
  - `field_image` (multiple images)
  - `field_tags` (taxonomy terms from tags vocabulary)
  - `field_video` (Video Embed field - converted to iframe in body)
- **Metadata**: `created`, `changed`, `uid` (user ID preserved)
- **Taxonomy**: Terms from the tags vocabulary
- **Path aliases**: URL aliases for nodes and taxonomy terms
- **Domains**: Optional assignment to domain(s) via field_domain_access
- **Body Images**: All inline `<img>` tags in body HTML are migrated to `public://body_images/{nid}/`

### Body Image Migration (D11 to D11)

The module automatically migrates inline images found in article body HTML:
- Finds all `<img>` tags in the body field
- Downloads/copies images from source D11 site (supports both local paths and HTTP URLs)
- Saves to destination at `public://body_images/{nid}/{filename}`
- Updates `src` attributes to new relative URLs
- Handles both absolute URLs (e.g., `http://example.com/image.jpg`) and relative paths (e.g., `/sites/default/files/inline/image.jpg`)
- Failed images are automatically removed from body content with warnings logged
- Uses proper UTF-8 encoding to preserve special characters

### Video Embed Field Conversion

If the source article has a `field_video` (Video Embed field), it will be converted to an iframe and appended to the article body. Supported video platforms:
- **YouTube**: Converts to YouTube embed iframe
- **Vimeo**: Converts to Vimeo player iframe

Example: A YouTube URL like `https://www.youtube.com/watch?v=VIDEO_ID` becomes:
```html
<p><iframe width="560" height="315" src="https://www.youtube.com/embed/VIDEO_ID" ...></iframe></p>
```

### Migration Map Tables (D11 to D11)

Unlike D7 migration which uses a single `d7_article_migrate_map` table, D11 to D11 migrations use **source-specific migration map tables**:
- Table name format: `d11_migrate_map_{source_db_key}`
- Example: `--source-db=d11_source` creates table `d11_migrate_map_d11_source`
- Example: `--source-db=old_site` creates table `d11_migrate_map_old_site`
- **Why**: Prevents NID conflicts when migrating from multiple D11 sources
- Tables are automatically created on first migration from each source
- Each table stores mappings: `type` (node/term/file), `source_id`, `dest_id`

This allows you to migrate articles from multiple D11 sources (e.g., multiple old sites) into one new D11 site without ID conflicts.

### Clear D11 Migrated Content

**WARNING: This command will delete migrated content from a specific source and cannot be undone!**

Clear all migrated articles from a specific D11 source database:
```bash
vendor/bin/drush d11-migrate:clear --source-db="d11_source"
```

This command will:
- Delete all migrated article nodes from the specified source
- Delete all files attached to field_image
- Delete all body images (public://body_images/{nid}/)
- Delete all migrated taxonomy terms from the specified source
- Delete all migrated path aliases
- Clear the source-specific migration map table (`d11_migrate_map_{source_db_key}`)

The command requires confirmation before proceeding.

**Note**: Only content migrated from the specified `--source-db` will be deleted. Content from other D11 sources remains untouched.

---

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
- Removes unwanted attributes: `class`, `style`, `id`
- Preserves the `align` attribute
- Converts `<div>` elements with content to `<p>` tags
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
