<?php

namespace Drupal\d7_article_migration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate_plus\Entity\MigrationGroup;
use Drupal\migrate_tools\Batch\MigrateImportBatch;

/**
 * Provides a form to trigger the D7 article migration batch.
 */
class MigrationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'd7_article_migration_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>This will run the full D7 to D11 article migration process.</p>
                    <p>It will migrate:
                    <ol>
                      <li>Taxonomy Tags</li>
                      <li>Image Files</li>
                      <li>Article Nodes (and rewrite body images)</li>
                      <li>URL Aliases</li>
                    </ol>
                    </p>
                    <p>This process uses the Batch API and is resumable. If it stops, you can safely run it again to continue.</p>
                    <p><strong>IMPORTANT:</strong> Please review the README.md file for critical setup instructions before running this.</p>',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Article Migration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Define the migrations to run, in the correct dependency order.
    $migrations = [
      'd7_taxonomy_tags',
      'd7_all_image_files',
      'd7_article_nodes',
      'd7_url_aliases',
    ];

    $batch = [
      'title' => $this->t('Migrating D7 Articles...'),
      'operations' => [],
      'finished' => [MigrateImportBatch::class, 'finished'],
    ];

    foreach ($migrations as $migration_id) {
      $migration = $this->entityTypeManager()
        ->getStorage('migration')
        ->load($migration_id);
      
      if ($migration) {
        $batch['operations'][] = [
          [MigrateImportBatch::class, 'run'],
          [$migration_id, ['update' => FALSE]],
        ];
      }
      else {
        $this->messenger()->addWarning($this->t('Migration @id not found.', ['@id' => $migration_id]));
      }
    }

    batch_set($batch);
    $this->messenger()->addStatus($this->t('Batch migration started.'));
  }

}
