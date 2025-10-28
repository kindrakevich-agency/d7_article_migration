# D7 Article Migration Module

This module provides a custom batch migration from a Drupal 7 database to Drupal 11.

## 1. CRITICAL: Database Setup

This module **REQUIRES** you to configure a database connection to your Drupal 7 database.

In your Drupal 11 site's `settings.php` file (e.g., `web/sites/default/settings.php`), add a new database connection with the key `migrate`:

```php
$databases['migrate']['default'] = [
  'database' => 'your_d7_database_name',
  'username' => 'your_d7_db_user',
  'password' => 'your_d7_db_password',
  'prefix' => '',
  'host' => 'your_d7_db_host', // e.g., '127.0.0.1'
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
];
```

**You must do this before installing the module.**

## 2. Drupal 11 Site Setup (Prerequisites)

Before you run the migration, ensure your Drupal 11 site is ready:

1.  **Content Type:** You must have a content type with the machine name `article`.
2.  **Fields:** The `article` content type must have the following fields:
    * `field_tags`: An **Entity Reference** field pointing to a Taxonomy Vocabulary.
    * `field_image`: An **Image** field, configured to allow **multiple** values.
    * `body`: (This is included by default).
3.  **Taxonomy:** You must have a Vocabulary. The `d7_taxonomy_tags` migration is set to migrate to a vocabulary named `tags`. If your D11 vocabulary has a different machine name, you **must** edit `config/install/migrate_plus.migration.d7_taxonomy_tags.yml` and change the `bundle` value.
4.  **Text Formats:** Ensure you have a text format with the machine name `full_html` (this is standard).
5.  **Modules:** Enable the `path` module to handle URL aliases.

## 3. How to Run the Migration

1.  Install this module (`d7_article_migration`) like any other Drupal module.
2.  Go to **Configuration** > **Content Authoring** > **D7 Article Migration** (at `/admin/config/content/d7-article-migration`).
3.  Click the "Start Article Migration" button.
4.  This will start a batch process that runs all the migrations in the correct order.
5.  The process is resumable. If it times out or stops, you can run it again, and it will pick up where it left off.

## 4. What This Module Does

* **Creates a Batch:** Uses the Batch API to run migrations, preventing server timeouts.
* **Filters Published:** Only migrates published nodes (`status = 1`).
* **Skips Custom Table:** Checks a table named `parser_map` in your D7 database and skips any nodes whose `nid` is present in the `entity_id` column.
* **Migrates Fields:**
    * `d7_taxonomy_tags`: Migrates your `tags` taxonomy terms.
    * `d7_all_image_files`: Migrates all image files from your D7 site.
    * `d7_article_nodes`: Migrates the `article` nodes, mapping `title`, `field_tags`, `field_image` (multiple), and `body`.
    * `d7_url_aliases`: Migrates node and term aliases.
* **Migrates Body Images:** Finds `<img>` tags in the `body` field, migrates the referenced image, and rewrites the `src` attribute to point to the new D11 file path.
