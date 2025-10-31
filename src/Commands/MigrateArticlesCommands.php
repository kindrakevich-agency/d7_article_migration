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
   * @option domains Comma-separated list of domain IDs to assign articles to (e.g. new.polissya.today,polissya.today)
   */
  public function migrate(array $options = ['files-base-path'=>'','limit'=>0,'update-existing'=>FALSE,'domains'=>'']) {
    $migrator = \Drupal::service('d7_article_migrate.migrator');

    $files_base = $options['files-base-path'] ?? '';
    $limit = (int) ($options['limit'] ?? 0);
    $update_existing = (bool) ($options['update-existing'] ?? FALSE);
    $domains = $options['domains'] ?? '';

    if (empty($files_base)) {
      $this->io()->error('You must provide --files-base-path');
      return 1;
    }

    // Use DB connection key 'migrate' from settings.php
    $migrator->setFilesBasePath(rtrim($files_base, '/'));
    $migrator->setUpdateExisting($update_existing);

    // Set domains if provided
    if (!empty($domains)) {
      $domain_ids = array_map('trim', explode(',', $domains));
      $migrator->setDomainIds($domain_ids);
      $this->io()->note('Articles will be assigned to domains: ' . implode(', ', $domain_ids));
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
}
