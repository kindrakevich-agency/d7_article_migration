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
   * @option destination-path Destination path for downloaded files (default: public://d7_migrated/)
   * @option limit Number of nodes to process (0 = all)
   * @option update-existing Update existing nodes instead of skipping them
   */
  public function migrate(array $options = ['files-base-url'=>'','destination-path'=>'public://d7_migrated/','limit'=>0,'update-existing'=>FALSE]) {
    $migrator = \Drupal::service('d7_article_migrate.migrator');

    $files_base = $options['files-base-url'] ?? '';
    $destination_path = $options['destination-path'] ?? 'public://d7_migrated/';
    $limit = (int) ($options['limit'] ?? 0);
    $update_existing = (bool) ($options['update-existing'] ?? FALSE);

    if (empty($files_base)) {
      $this->io()->error('You must provide --files-base-url');
      return 1;
    }

    // Use DB connection key 'migrate' from settings.php
    $migrator->setFilesBaseUrl(rtrim($files_base, '/'));
    $migrator->setDestinationPath(rtrim($destination_path, '/'));
    $migrator->setUpdateExisting($update_existing);
    $migrator->setSourceConnectionKey('migrate');

    $migrator->migrateArticles($limit);

    $this->io()->success('D7 Article migration finished.');
    return 0;
  }
}
