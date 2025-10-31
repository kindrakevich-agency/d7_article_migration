<?php

namespace Drupal\d7_article_migrate\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for D7 Article migration.
 */
final class MigrateArticlesCommands extends DrushCommands {

  /**
   * Migrate published D7 articles.
   *
   * @command d7-migrate:articles
   * @option files-base-path Base path to D7 public files (e.g. /www/wwwroot/polissya.today/sites/default/files or https://oldsite/sites/default/files)
   * @option limit Number of nodes to process (0 = all)
   * @option update-existing Update existing nodes instead of skipping them
   * @option domains Comma-separated list of domain machine names to assign articles to (e.g. new_polissya_today,polissya_today)
   * @option skip-domain-source Skip setting field_domain_source (canonical domain)
   */
  public function migrate(array $options = ['files-base-path'=>'','limit'=>0,'update-existing'=>FALSE,'domains'=>'','skip-domain-source'=>FALSE]) {
    $migrator = \Drupal::service('d7_article_migrate.migrator');

    $files_base = $options['files-base-path'] ?? '';
    $limit = (int) ($options['limit'] ?? 0);
    $update_existing = (bool) ($options['update-existing'] ?? FALSE);
    $domains = $options['domains'] ?? '';
    $skip_domain_source = (bool) ($options['skip-domain-source'] ?? FALSE);

    if (empty($files_base)) {
      $this->io()->error('You must provide --files-base-path');
      return 1;
    }

    // Use DB connection key 'migrate' from settings.php
    $migrator->setFilesBasePath(rtrim($files_base, '/'));
    $migrator->setUpdateExisting($update_existing);
    $migrator->setSkipDomainSource($skip_domain_source);

    // Set domains if provided
    if (!empty($domains)) {
      $domain_ids = array_map('trim', explode(',', $domains));
      $migrator->setDomainIds($domain_ids);
      $this->io()->note('Articles will be assigned to domains: ' . implode(', ', $domain_ids));

      if ($skip_domain_source) {
        $this->io()->note('field_domain_source will NOT be set (--skip-domain-source enabled)');
      }
    }

    $migrator->setSourceConnectionKey('migrate');

    $migrator->migrateArticles($limit);

    $this->io()->success('D7 Article migration finished.');
    return 0;
  }

  /**
   * Clear all migrated articles, taxonomy terms, and files.
   *
   * @command d7-migrate:clear
   * @usage d7-migrate:clear
   *   Delete all migrated content (nodes, terms, files, aliases)
   */
  public function clear() {
    $migrator = \Drupal::service('d7_article_migrate.migrator');

    if (!$this->io()->confirm('Are you sure you want to delete ALL migrated articles, taxonomy terms, and files? This cannot be undone!')) {
      $this->io()->warning('Clear operation cancelled.');
      return 1;
    }

    $this->io()->note('Clearing all migrated content...');
    $migrator->clearMigratedContent();

    $this->io()->success('All migrated content has been cleared.');
    return 0;
  }

  /**
   * Migrate articles from another Drupal 11 site.
   *
   * @command d11-migrate:articles
   * @option source-db Database connection key from settings.php (default: d11_source)
   * @option files-base-path Base path to source D11 public files (e.g. /www/wwwroot/oldsite/sites/default/files or https://oldsite/sites/default/files)
   * @option limit Number of nodes to process (0 = all)
   * @option update-existing Update existing nodes instead of skipping them
   * @option domains Comma-separated list of domain machine names to assign articles to (e.g. new_polissya_today,polissya_today)
   * @option skip-domain-source Skip setting field_domain_source (canonical domain)
   */
  public function migrateFromD11(array $options = ['source-db'=>'d11_source','files-base-path'=>'','limit'=>0,'update-existing'=>FALSE,'domains'=>'','skip-domain-source'=>FALSE]) {
    $migrator = \Drupal::service('d7_article_migrate.d11_migrator');

    $source_db = $options['source-db'] ?? 'd11_source';
    $files_base = $options['files-base-path'] ?? '';
    $limit = (int) ($options['limit'] ?? 0);
    $update_existing = (bool) ($options['update-existing'] ?? FALSE);
    $domains = $options['domains'] ?? '';
    $skip_domain_source = (bool) ($options['skip-domain-source'] ?? FALSE);

    if (empty($files_base)) {
      $this->io()->error('You must provide --files-base-path');
      return 1;
    }

    // Set source database connection
    $migrator->setSourceConnectionKey($source_db);
    $this->io()->note("Using source database connection: {$source_db}");

    $migrator->setFilesBasePath(rtrim($files_base, '/'));
    $migrator->setUpdateExisting($update_existing);
    $migrator->setSkipDomainSource($skip_domain_source);

    // Set domains if provided
    if (!empty($domains)) {
      $domain_ids = array_map('trim', explode(',', $domains));
      $migrator->setDomainIds($domain_ids);
      $this->io()->note('Articles will be assigned to domains: ' . implode(', ', $domain_ids));

      if ($skip_domain_source) {
        $this->io()->note('field_domain_source will NOT be set (--skip-domain-source enabled)');
      }
    }

    $migrator->migrateArticles($limit);

    $this->io()->success('D11 Article migration finished.');
    return 0;
  }

  /**
   * Clear all articles migrated from D11 source.
   *
   * @command d11-migrate:clear
   * @option source-db Database connection key from settings.php (default: d11_source)
   * @usage d11-migrate:clear
   *   Delete all migrated content from D11 source (nodes, terms, files, aliases)
   */
  public function clearD11(array $options = ['source-db'=>'d11_source']) {
    $migrator = \Drupal::service('d7_article_migrate.d11_migrator');

    $source_db = $options['source-db'] ?? 'd11_source';

    if (!$this->io()->confirm('Are you sure you want to delete ALL articles migrated from the D11 source database? This cannot be undone!')) {
      $this->io()->warning('Clear operation cancelled.');
      return 1;
    }

    // Set source database connection to verify it exists
    try {
      $migrator->setSourceConnectionKey($source_db);
      $this->io()->note("Clearing content migrated from database: {$source_db}");
    } catch (\Exception $e) {
      $this->io()->error("Could not connect to source database '{$source_db}': " . $e->getMessage());
      return 1;
    }

    $this->io()->note('Clearing all migrated content from D11 source...');
    $migrator->clearMigratedContent();

    $this->io()->success('All migrated D11 content has been cleared.');
    return 0;
  }
}
