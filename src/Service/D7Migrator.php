<?php

namespace Drupal\d7_article_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
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
  protected ClientInterface $httpClient;
  protected LoggerInterface $logger;
  protected Connection $database;
  protected string $filesBaseUrl;
  protected ?Connection $sourceDb = NULL;

  public function __construct(
      EntityTypeManagerInterface $etm,
      FileSystemInterface $fs,
      ClientInterface $http_client,
      LoggerInterface $logger,
      Connection $database
  ) {
    $this->entityTypeManager = $etm;
    $this->fileSystem = $fs;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->database = $database;
  }

  public function setFilesBaseUrl(string $url) {
    $this->filesBaseUrl = $url;
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

      // Skip already migrated
      $already = $this->database->select('d7_article_migrate_map','m')
        ->fields('m',['dest_id'])
        ->condition('type','node')
        ->condition('source_id',(string)$nid)
        ->execute()
        ->fetchField();
      if ($already) {
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

      // Create node
      $node = Node::create([
        'type'=>'article',
        'title'=>$row->title,
        'body'=>['value'=>$body,'format'=>'full_html'],
        'status'=>1,
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
    $url = rtrim($this->filesBaseUrl,'/').'/'.$relative;

    try {
      $response = $this->httpClient->get($url,['stream'=>true,'timeout'=>30]);
      if ($response->getStatusCode() !== 200) throw new \Exception('Bad status');

      $data = $response->getBody()->getContents();
      $destination = 'public://d7_migrated/'.$relative;
      $this->fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $uri = file_save_data($data,$destination,FILE_EXISTS_REPLACE);
      if ($uri) {
        $file = File::create(['uri'=>$uri,'filemime'=>$f->filemime]);
        $file->setPermanent();
        $file->save();
        $new_fid = $file->id();

        $this->database->insert('d7_article_migrate_map')->fields(['type'=>'file','source_id'=>(string)$fid,'dest_id'=>(string)$new_fid])->execute();
        $this->logger->info("Migrated file fid {$fid} -> {$new_fid}");
        return $new_fid;
      }
    } catch (\Exception $e) {
      $this->logger->warning("Failed to download file {$url}: ".$e->getMessage());
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
      $url = isset($parsed['host']) ? $src : rtrim($this->filesBaseUrl,'/').'/'.ltrim($src,'/');

      try {
        $response = $this->httpClient->get($url,['stream'=>true,'timeout'=>30]);
        if ($response->getStatusCode() !== 200) continue;
        $data = $response->getBody()->getContents();

        $basename = basename(parse_url($url, PHP_URL_PATH));
        $destination = "public://d7_migrated/body_images/{$nid}/{$basename}";
        $this->fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $uri = file_save_data($data,$destination,FILE_EXISTS_RENAME);
        if ($uri) {
          $file = File::create(['uri'=>$uri]);
          $file->setPermanent();
          $file->save();
          $img->setAttribute('src', file_create_url($file->getFileUri()));
          $changed = TRUE;
        }
      } catch (\Exception $e) {
        $this->logger->warning('Failed body image '.$url.': '.$e->getMessage());
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

  protected function migrateAliasFor(string $entity_type,$old_id,$new_id) {
    $source = $this->getSourceDb();
    $path = $entity_type === 'node' ? "node/{$old_id}" : "taxonomy/term/{$old_id}";
    $alias = $source->select('url_alias','ua')->fields('ua',['alias'])->condition('source',$path)->execute()->fetchField();
    if ($alias) {
      // avoid duplicate aliases
      $existing = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias'=>'/'.$alias]);
      if (!$existing) {
        PathAlias::create([
          'path' => ($entity_type === 'node') ? "node/{$new_id}" : "taxonomy/term/{$new_id}",
          'alias' => '/'.ltrim($alias,'/'),
          'langcode' => 'und',
        ])->save();
        $this->logger->info("Migrated alias {$alias} for {$entity_type} {$new_id}");
      }
    }
  }

}
