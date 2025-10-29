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
   * @option files-base-url Base HTTP URL to D7 public files (e.g. https://oldsite/sites/default/files)
   * @option limit Number of nodes to process (0 = all)
   */
  public function migrate(array $options = ['files-base-url'=>'','limit'=>0]) {
    $migrator = \Drupal::service('d7_article_migrate.migrator');

    $files_base = $options['files-base-url'] ?? '';
    $limit = (int) ($options['limit'] ?? 0);

    if (empty($files_base)) {
      $this->io()->error('You must provide --files-base-url');
      return 1;
    }

    // Use DB connection key 'migrate' from settings.php
    $migrator->setFilesBaseUrl(rtrim($files_base, '/'));
    $migrator->setSourceConnectionKey('migrate');

    $migrator->migrateArticles($limit);

    $this->io()->success('D7 Article migration finished.');
    return 0;
  }
}
