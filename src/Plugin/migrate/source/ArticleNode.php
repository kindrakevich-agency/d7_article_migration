<?php

namespace Drupal\d7_article_migration\Plugin\migrate\source;

use Drupal\node\Plugin\migrate\source\d7\Node;
use Drupal\Core\Database\Query\Condition;

/**
 * Custom D7 node source plugin for articles.
 *
 * This plugin filters to only published nodes and skips nodes
 * found in the custom 'parser_map' table.
 *
 * @MigrateSource(
 * id = "d7_article_node_custom",
 * source_module = "node"
 * )
 */
class ArticleNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Get the standard node query from the parent.
    $query = parent::query();

    // 1. Migrate only published nodes (Req #2).
    $query->condition('n.status', 1);

    // 2. Check parser_map table (Req #3).
    // We assume 'parser_map' is in the D7 database (the source database).
    // We create a subquery to find all NIDs that are in the parser_map table.
    try {
      $subquery = $this->select('parser_map', 'pm')
        ->fields('pm', ['entity_id']);
      
      // Add a condition to the main query to exclude nodes in the subquery.
      $query->condition('n.nid', $subquery, 'NOT IN');
    }
    catch (\Exception $e) {
      // Log a warning if the parser_map table doesn't exist.
      // This prevents the migration from failing if the table is missing.
      \Drupal::logger('d7_article_migration')->warning('Could not find source table "parser_map". Skipping this filter. Error: @message', ['@message' => $e->getMessage()]);
    }

    // Add field tables to the query.
    // This ensures field_tags and field_image data is available.
    $this->addJoin($query, 'field_data_field_tags', 'ft', 'n.nid', 'ft.entity_id', 'ft.entity_type', 'node');
    $this->addJoin($query, 'field_data_field_image', 'fi', 'n.nid', 'fi.entity_id', 'fi.entity_type', 'node');

    return $query;
  }

  /**
   * Helper function to add joins correctly.
   */
  protected function addJoin($query, $table, $alias, $left_field, $right_field, $entity_type_field, $entity_type) {
    $condition = new Condition('AND');
    $condition->condition("$alias.$right_field", "n.$left_field", '=');
    $condition->condition("$alias.$entity_type_field", $entity_type);
    
    $query->leftJoin($table, $alias, $condition);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields() + [
      'field_tags' => $this->t('Tags (D7)'),
      'field_image' => $this->t('Image (D7)'),
    ];
    return $fields;
  }

}
