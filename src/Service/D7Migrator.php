<?php

namespace Drupal\d7_article_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\path_alias\Entity\PathAlias;

class D7Migrator {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileSystemInterface $fileSystem;
  protected FileRepositoryInterface $fileRepository;
  protected FileUrlGeneratorInterface $fileUrlGenerator;
  protected ClientInterface $httpClient;
  protected LoggerInterface $logger;
  protected Connection $database;
  protected string $filesBasePath;
  protected bool $updateExisting = FALSE;
  protected ?Connection $sourceDb = NULL;

  public function __construct(
      EntityTypeManagerInterface $etm,
      FileSystemInterface $fs,
      FileRepositoryInterface $file_repository,
      FileUrlGeneratorInterface $file_url_generator,
      ClientInterface $http_client,
      LoggerInterface $logger,
      Connection $database
  ) {
    $this->entityTypeManager = $etm;
    $this->fileSystem = $fs;
    $this->fileRepository = $file_repository;
    $this->fileUrlGenerator = $file_url_generator;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->database = $database;
  }

  public function setFilesBasePath(string $path) {
    $this->filesBasePath = $path;
  }

  public function setUpdateExisting(bool $update) {
    $this->updateExisting = $update;
  }

  public function setSourceConnectionKey(string $key) {
    $this->sourceDb = Database::getConnection('default', $key);
  }

  protected function getSourceDb(): Connection {
    if (!$this->sourceDb) throw new \RuntimeException('Source DB not set. Call setSourceConnectionKey() first.');
    return $this->sourceDb;
  }

  public function migrateArticles(int $limit = 0) {
    $source = $this->getSourceDb();

    $query = $source->select('node','n')
      ->fields('n',['nid','title','created','changed'])
      ->condition('n.type','article')
      ->condition('n.status',1)
      ->orderBy('n.nid','ASC');
    if ($limit > 0) $query->range(0,$limit);

    $result = $query->execute();

    foreach ($result as $row) {
      $nid = $row->nid;

      // Skip parser_map
      $exists = $source->select('parser_map','pm')->condition('pm.entity_id',$nid)->countQuery()->execute()->fetchField();
      if ($exists) {
        $this->logger->notice("Skipping nid {$nid} — present in parser_map.");
        continue;
      }

      // Check if already migrated
      $already = $this->database->select('d7_article_migrate_map','m')
        ->fields('m',['dest_id'])
        ->condition('type','node')
        ->condition('source_id',(string)$nid)
        ->execute()
        ->fetchField();
      if ($already && !$this->updateExisting) {
        $this->logger->notice("Skipping nid {$nid} — already migrated to {$already}.");
        continue;
      }

      // Body
      $body = '';
      $b = $source->select('field_data_body','bd')->fields('bd',['body_value'])->condition('entity_id',$nid)->range(0,1)->execute()->fetchObject();
      if (!$b) $b = $source->select('field_revision_body','br')->fields('br',['body_value'])->condition('entity_id',$nid)->range(0,1)->execute()->fetchObject();
      if ($b) $body = $b->body_value;

      // Tags (only D7 tids are collected; vocabulary check happens in migrateTerm)
      $tids = [];
      $trows = $source->select('field_data_field_tags','ft')->fields('ft',['field_tags_tid'])->condition('entity_id',$nid)->execute();
      foreach ($trows as $t) $tids[] = $t->field_tags_tid;

      // Images
      $fids = [];
      $frows = $source->select('field_data_field_image','fi')->fields('fi',['field_image_fid'])->condition('entity_id',$nid)->execute();
      foreach ($frows as $f) $fids[] = $f->field_image_fid;

      // Migrate terms
      $term_ids = [];
      foreach ($tids as $tid) {
        $new_tid = $this->migrateTerm($tid);
        if ($new_tid) $term_ids[] = $new_tid;
      }

      // Migrate files
      $image_fids = [];
      foreach ($fids as $fid) {
        $file_id = $this->migrateFile($fid);
        if ($file_id) $image_fids[] = $file_id;
      }

      // Process body images
      $body = $this->processBodyImages($body,$nid);

      // Create or update node
      if ($already) {
        // Update existing node
        $node = Node::load($already);
        if ($node) {
          $node->set('title', $row->title);
          $node->set('body', ['value'=>$body,'format'=>'full_html']);
          $node->set('status', 1);
          $node->set('created', $row->created);
          $node->set('changed', $row->changed);
          if ($term_ids) $node->set('field_tags', array_map(fn($tid)=>['target_id'=>$tid],$term_ids));
          if ($image_fids) $node->set('field_image', array_map(fn($fid)=>['target_id'=>$fid],$image_fids));
          $node->save();
          $new_nid = $node->id();

          // Update alias for existing node
          $this->migrateAliasFor('node',$nid,$new_nid);

          $this->logger->info("Updated D7 nid {$nid} -> D11 nid {$new_nid}");
        } else {
          $this->logger->warning("Could not load node {$already} for update");
          continue;
        }
      } else {
        // Create new node
        $node = Node::create([
          'type'=>'article',
          'title'=>$row->title,
          'body'=>['value'=>$body,'format'=>'full_html'],
          'status'=>1,
          'created'=>$row->created,
          'changed'=>$row->changed,
        ]);
        if ($term_ids) $node->set('field_tags', array_map(fn($tid)=>['target_id'=>$tid],$term_ids));
        if ($image_fids) $node->set('field_image', array_map(fn($fid)=>['target_id'=>$fid],$image_fids));
        $node->save();
        $new_nid = $node->id();

        $this->migrateAliasFor('node',$nid,$new_nid);

        $this->database->insert('d7_article_migrate_map')->fields(['type'=>'node','source_id'=>(string)$nid,'dest_id'=>(string)$new_nid])->execute();

        $this->logger->info("Migrated D7 nid {$nid} -> D11 nid {$new_nid}");
      }
    }
  }

  protected function migrateTerm($tid) {
    $existing = $this->database->select('d7_article_migrate_map','m')->fields('m',['dest_id'])->condition('type','term')->condition('source_id',(string)$tid)->execute()->fetchField();
    if ($existing) return (int)$existing;

    $source = $this->getSourceDb();
    $tr = $source->select('taxonomy_term_data','t')->fields('t',['tid','name','vid'])->condition('tid',$tid)->execute()->fetchObject();
    if (!$tr) {
      $this->logger->warning("Term {$tid} not found in source");
      return NULL;
    }

    // Only migrate terms from D7 vid = 3
    if ((int)$tr->vid !== 3) {
      $this->logger->notice("Skipping term {$tid} — vid {$tr->vid} != 3");
      return NULL;
    }

    // Destination vocabulary is 'tags'
    $vocab = 'tags';
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name'=>$tr->name,'vid'=>$vocab]);
    if ($terms) {
      $term = reset($terms);
    } else {
      $term = Term::create(['name'=>$tr->name,'vid'=>$vocab]);
      $term->save();
    }
    $new_tid = $term->id();

    // Migrate alias for term
    $this->migrateAliasFor('taxonomy/term',$tid,$new_tid);

    $this->database->insert('d7_article_migrate_map')->fields(['type'=>'term','source_id'=>(string)$tid,'dest_id'=>(string)$new_tid])->execute();
    $this->logger->info("Migrated term {$tid} -> {$new_tid}");
    return $new_tid;
  }

  protected function migrateFile($fid) {
    $existing = $this->database->select('d7_article_migrate_map','m')->fields('m',['dest_id'])->condition('type','file')->condition('source_id',(string)$fid)->execute()->fetchField();
    if ($existing) return (int)$existing;

    $source = $this->getSourceDb();
    $f = $source->select('file_managed','f')->fields('f',['fid','filename','uri','filemime'])->condition('fid',$fid)->execute()->fetchObject();
    if (!$f) {
      $this->logger->warning("File fid {$fid} not found in source");
      return NULL;
    }

    $relative = ltrim(preg_replace('#^(public://|private://|sites/default/files/)#','',$f->uri),'/');
    $destination = 'public://'.$relative;

    try {
      // Check if filesBasePath is HTTP URL or local path
      $isUrl = preg_match('#^https?://#', $this->filesBasePath);

      if ($isUrl) {
        // Download from HTTP URL
        $url = rtrim($this->filesBasePath,'/').'/'.$relative;
        $response = $this->httpClient->get($url,['stream'=>true,'timeout'=>30]);
        if ($response->getStatusCode() !== 200) throw new \Exception('Bad status');
        $data = $response->getBody()->getContents();
      } else {
        // Copy from local filesystem
        $sourcePath = rtrim($this->filesBasePath,'/').'/'.$relative;
        if (!file_exists($sourcePath)) {
          throw new \Exception("File not found: {$sourcePath}");
        }
        $data = file_get_contents($sourcePath);
      }

      $this->fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $file = $this->fileRepository->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
      if ($file) {
        $file->setPermanent();
        $file->save();
        $new_fid = $file->id();

        $this->database->insert('d7_article_migrate_map')->fields(['type'=>'file','source_id'=>(string)$fid,'dest_id'=>(string)$new_fid])->execute();
        $this->logger->info("Migrated file fid {$fid} -> {$new_fid}");
        return $new_fid;
      }
    } catch (\Exception $e) {
      $this->logger->warning("Failed to migrate file {$relative}: ".$e->getMessage());
    }

    return NULL;
  }

  protected function processBodyImages(string $body,$nid): string {
    if (!$body) return $body;
    libxml_use_internal_errors(true);
    $dom = new \DOMDocument();
    $dom->loadHTML(mb_convert_encoding($body,'HTML-ENTITIES','UTF-8'));
    $imgs = $dom->getElementsByTagName('img');
    $changed = FALSE;
    foreach ($imgs as $img) {
      $src = $img->getAttribute('src');
      if (!$src) continue;

      $parsed = parse_url($src);
      $isUrl = preg_match('#^https?://#', $this->filesBasePath);

      try {
        // Determine the file path/URL
        if (isset($parsed['host'])) {
          // Image src already has full URL
          $fullPath = $src;
        } else {
          // Relative path
          $fullPath = rtrim($this->filesBasePath,'/').'/'.ltrim($src,'/');
        }

        // Get file data
        if ($isUrl || isset($parsed['host'])) {
          // Download from HTTP URL
          $response = $this->httpClient->get($fullPath,['stream'=>true,'timeout'=>30]);
          if ($response->getStatusCode() !== 200) continue;
          $data = $response->getBody()->getContents();
        } else {
          // Copy from local filesystem
          if (!file_exists($fullPath)) continue;
          $data = file_get_contents($fullPath);
        }

        $basename = basename(parse_url($fullPath, PHP_URL_PATH));
        $destination = "public://body_images/{$nid}/{$basename}";
        $this->fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $file = $this->fileRepository->writeData($data, $destination, FileSystemInterface::EXISTS_RENAME);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $img->setAttribute('src', $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()));
          $changed = TRUE;
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed body image '.$src.': '.$e->getMessage());
      }
    }

    if ($changed) {
      $bodyNode = $dom->getElementsByTagName('body')->item(0);
      $inner = '';
      foreach ($bodyNode->childNodes as $child) $inner .= $dom->saveHTML($child);
      return $inner;
    }
    return $body;
  }

  public function clearMigratedContent() {
    $this->logger->info("Starting to clear all migrated content...");

    // Get all migrated nodes
    $node_map = $this->database->select('d7_article_migrate_map','m')
      ->fields('m',['dest_id'])
      ->condition('type','node')
      ->execute()
      ->fetchCol();

    // Delete nodes and their files
    foreach ($node_map as $nid) {
      $node = Node::load($nid);
      if ($node) {
        // Delete files attached to field_image
        if ($node->hasField('field_image') && !$node->get('field_image')->isEmpty()) {
          foreach ($node->get('field_image') as $item) {
            if ($item->entity) {
              $file = $item->entity;
              $this->logger->info("Deleting image file: " . $file->getFilename());
              $file->delete();
            }
          }
        }

        // Delete body images (files in public://body_images/{nid}/)
        $body_images_dir = "public://body_images/{$nid}";
        if ($this->fileSystem->prepareDirectory($body_images_dir)) {
          $files = $this->fileSystem->scanDirectory($body_images_dir, '/.*/');
          foreach ($files as $file) {
            $file_entity = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file->uri]);
            if ($file_entity) {
              $file_entity = reset($file_entity);
              $this->logger->info("Deleting body image file: " . $file_entity->getFilename());
              $file_entity->delete();
            }
          }
          // Remove directory
          $this->fileSystem->deleteRecursive($body_images_dir);
        }

        // Delete path aliases
        $path_alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
        $aliases = $path_alias_storage->loadByProperties(['path' => "/node/{$nid}"]);
        foreach ($aliases as $alias) {
          $alias->delete();
          $this->logger->info("Deleted alias for node {$nid}");
        }

        $this->logger->info("Deleting node {$nid}: " . $node->label());
        $node->delete();
      }
    }

    // Get all migrated terms
    $term_map = $this->database->select('d7_article_migrate_map','m')
      ->fields('m',['dest_id'])
      ->condition('type','term')
      ->execute()
      ->fetchCol();

    // Delete terms
    foreach ($term_map as $tid) {
      $term = Term::load($tid);
      if ($term) {
        // Delete path aliases
        $path_alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
        $aliases = $path_alias_storage->loadByProperties(['path' => "/taxonomy/term/{$tid}"]);
        foreach ($aliases as $alias) {
          $alias->delete();
          $this->logger->info("Deleted alias for term {$tid}");
        }

        $this->logger->info("Deleting term {$tid}: " . $term->label());
        $term->delete();
      }
    }

    // Get all migrated files (field images)
    $file_map = $this->database->select('d7_article_migrate_map','m')
      ->fields('m',['dest_id'])
      ->condition('type','file')
      ->execute()
      ->fetchCol();

    // Delete files
    foreach ($file_map as $fid) {
      $file = File::load($fid);
      if ($file) {
        $this->logger->info("Deleting file {$fid}: " . $file->getFilename());
        $file->delete();
      }
    }

    // Clear the migration map
    $this->database->truncate('d7_article_migrate_map')->execute();

    $this->logger->info("All migrated content has been cleared.");
    $this->logger->info("Deleted: " . count($node_map) . " nodes, " . count($term_map) . " terms, " . count($file_map) . " files");
  }

  protected function migrateAliasFor(string $entity_type,$old_id,$new_id) {
    $source = $this->getSourceDb();
    $path = $entity_type === 'node' ? "node/{$old_id}" : "taxonomy/term/{$old_id}";
    $alias_row = $source->select('url_alias','ua')
      ->fields('ua',['alias','language'])
      ->condition('source',$path)
      ->execute()
      ->fetchObject();

    if ($alias_row) {
      $alias = $alias_row->alias;
      $langcode = $alias_row->language ?? \Drupal::languageManager()->getDefaultLanguage()->getId();

      // avoid duplicate aliases
      $existing = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias'=>'/'.$alias]);
      if (!$existing) {
        PathAlias::create([
          'path' => ($entity_type === 'node') ? "/node/{$new_id}" : "/taxonomy/term/{$new_id}",
          'alias' => '/'.ltrim($alias,'/'),
          'langcode' => $langcode,
        ])->save();
        $this->logger->info("Migrated alias {$alias} for {$entity_type} {$new_id}");
      } else {
        $this->logger->notice("Alias {$alias} already exists, skipping");
      }
    }
  }

}
