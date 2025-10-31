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

class D11Migrator {

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
  protected array $domainIds = [];
  protected bool $skipDomainSource = FALSE;

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

  public function setDomainIds(array $domainIds) {
    $this->domainIds = $domainIds;
  }

  public function setSkipDomainSource(bool $skip) {
    $this->skipDomainSource = $skip;
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

    // Get published article nodes from source D11
    $query = $source->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title', 'created', 'changed', 'uid'])
      ->condition('n.type', 'article')
      ->condition('n.status', 1)
      ->orderBy('n.nid', 'ASC');

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $nodes = $query->execute()->fetchAll();
    $count = count($nodes);
    $this->logger->info("Found {$count} published articles in source D11 site");

    foreach ($nodes as $row) {
      $nid = $row->nid;

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

      // Get body field
      $body = '';
      $body_format = 'full_html';
      $body_row = $source->select('node__body','b')
        ->fields('b',['body_value', 'body_format'])
        ->condition('entity_id',$nid)
        ->range(0,1)
        ->execute()
        ->fetchObject();
      if ($body_row) {
        $body = $body_row->body_value;
        $body_format = $body_row->body_format ?? 'full_html';
      }

      // Get tags (field_tags)
      $tag_tids = [];
      $tag_rows = $source->select('node__field_tags','t')
        ->fields('t',['field_tags_target_id'])
        ->condition('entity_id',$nid)
        ->execute();
      foreach ($tag_rows as $t) {
        $tag_tids[] = $t->field_tags_target_id;
      }

      // Get images (field_image)
      $image_fids = [];
      $image_rows = $source->select('node__field_image','i')
        ->fields('i',['field_image_target_id'])
        ->condition('entity_id',$nid)
        ->execute();
      foreach ($image_rows as $i) {
        $image_fids[] = $i->field_image_target_id;
      }

      // Get field_video (Video Embed field)
      $video_embed_url = NULL;
      $video_row = $source->select('node__field_video','v')
        ->fields('v',['field_video_value'])
        ->condition('entity_id',$nid)
        ->range(0,1)
        ->execute()
        ->fetchObject();
      if ($video_row && !empty($video_row->field_video_value)) {
        $video_embed_url = $video_row->field_video_value;
      }

      // Migrate terms
      $new_tag_ids = [];
      foreach ($tag_tids as $tid) {
        $new_tid = $this->migrateTerm($tid);
        if ($new_tid) $new_tag_ids[] = $new_tid;
      }

      // Migrate files
      $new_image_fids = [];
      foreach ($image_fids as $fid) {
        $new_fid = $this->migrateFile($fid);
        if ($new_fid) $new_image_fids[] = $new_fid;
      }

      // Add video iframe to body if video exists
      if ($video_embed_url) {
        $iframe = $this->convertVideoToIframe($video_embed_url);
        if ($iframe) {
          $body = $body . "\n\n" . $iframe;
          $this->logger->info("Added video iframe to body for node {$nid}");
        }
      }

      // Create or update node
      if ($already) {
        // Update existing node
        $node = Node::load($already);
        if ($node) {
          $this->logger->info("Updating existing node {$already} for D11 source nid {$nid}");

          $node->set('title', $row->title);
          $node->set('body', ['value'=>$body,'format'=>$body_format]);
          $node->set('status', 1);
          $node->set('uid', $row->uid);
          $node->set('created', $row->created);
          $node->set('changed', $row->changed);
          if ($new_tag_ids) $node->set('field_tags', array_map(fn($tid)=>['target_id'=>$tid],$new_tag_ids));
          if ($new_image_fids) $node->set('field_image', array_map(fn($fid)=>['target_id'=>$fid],$new_image_fids));

          // Assign to domains if specified
          if (!empty($this->domainIds)) {
            if ($node->hasField('field_domain_access')) {
              $node->set('field_domain_access', $this->domainIds);
              $this->logger->info("Assigned domains to node {$already}: " . implode(', ', $this->domainIds));

              // Set the first domain as the source domain (canonical domain) unless skipped
              if (!$this->skipDomainSource && $node->hasField('field_domain_source')) {
                $node->set('field_domain_source', $this->domainIds[0]);
                $this->logger->info("Set source domain for node {$already}: " . $this->domainIds[0]);
              }
            } else {
              $this->logger->warning("Node {$already} does not have field_domain_access field. Domain assignment skipped.");
            }
          }

          $node->save();
          $new_nid = $node->id();

          // Update alias for existing node
          $this->migrateAliasFor('node',$nid,$new_nid);

          $domain_info = !empty($this->domainIds) ? ' (domains: ' . implode(', ', $this->domainIds) . ')' : '';
          $this->logger->info("Updated D11 source nid {$nid} -> D11 dest nid {$new_nid}{$domain_info}");
        } else {
          $this->logger->warning("Could not load node {$already} for update");
          continue;
        }
      } else {
        // Create new node
        $node = Node::create([
          'type'=>'article',
          'title'=>$row->title,
          'body'=>['value'=>$body,'format'=>$body_format],
          'status'=>1,
          'uid'=>$row->uid,
          'created'=>$row->created,
          'changed'=>$row->changed,
        ]);
        if ($new_tag_ids) $node->set('field_tags', array_map(fn($tid)=>['target_id'=>$tid],$new_tag_ids));
        if ($new_image_fids) $node->set('field_image', array_map(fn($fid)=>['target_id'=>$fid],$new_image_fids));

        // Assign to domains if specified
        if (!empty($this->domainIds)) {
          if ($node->hasField('field_domain_access')) {
            $node->set('field_domain_access', $this->domainIds);
            $this->logger->info("Assigned domains to new node for D11 source nid {$nid}: " . implode(', ', $this->domainIds));

            // Set the first domain as the source domain (canonical domain) unless skipped
            if (!$this->skipDomainSource && $node->hasField('field_domain_source')) {
              $node->set('field_domain_source', $this->domainIds[0]);
              $this->logger->info("Set source domain for new node D11 source nid {$nid}: " . $this->domainIds[0]);
            }
          } else {
            $this->logger->warning("New node for D11 source nid {$nid} does not have field_domain_access field. Domain assignment skipped.");
          }
        }

        $node->save();
        $new_nid = $node->id();

        $this->migrateAliasFor('node',$nid,$new_nid);

        $this->database->insert('d7_article_migrate_map')->fields(['type'=>'node','source_id'=>(string)$nid,'dest_id'=>(string)$new_nid])->execute();

        $domain_info = !empty($this->domainIds) ? ' (domains: ' . implode(', ', $this->domainIds) . ')' : '';
        $this->logger->info("Migrated D11 source nid {$nid} -> D11 dest nid {$new_nid}{$domain_info}");
      }
    }
  }

  protected function migrateTerm($source_tid) {
    $source = $this->getSourceDb();

    // Check if already migrated
    $already = $this->database->select('d7_article_migrate_map','m')
      ->fields('m',['dest_id'])
      ->condition('type','term')
      ->condition('source_id',(string)$source_tid)
      ->execute()
      ->fetchField();

    if ($already) {
      return (int)$already;
    }

    // Get term data from source D11
    $term_row = $source->select('taxonomy_term_field_data','t')
      ->fields('t',['tid','name','description__value','weight','langcode'])
      ->condition('tid',$source_tid)
      ->execute()
      ->fetchObject();

    if (!$term_row) {
      $this->logger->warning("Term {$source_tid} not found in source D11");
      return NULL;
    }

    // Get vocabulary - we assume it's "tags"
    $vocab_row = $source->select('taxonomy_term__parent','p')
      ->fields('p',['bundle'])
      ->condition('entity_id',$source_tid)
      ->range(0,1)
      ->execute()
      ->fetchObject();

    $vid = $vocab_row ? $vocab_row->bundle : 'tags';

    // Check if term with same name already exists in destination
    $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['name' => $term_row->name, 'vid' => $vid]);

    if ($existing) {
      $existing_term = reset($existing);
      $new_tid = $existing_term->id();
      $this->logger->notice("Term '{$term_row->name}' already exists in D11 dest as tid {$new_tid}, reusing");

      // Store mapping
      $this->database->insert('d7_article_migrate_map')
        ->fields(['type'=>'term','source_id'=>(string)$source_tid,'dest_id'=>(string)$new_tid])
        ->execute();

      return $new_tid;
    }

    // Create new term
    $term = Term::create([
      'vid' => $vid,
      'name' => $term_row->name,
      'description' => ['value' => $term_row->description__value ?? '', 'format' => 'basic_html'],
      'weight' => $term_row->weight ?? 0,
      'langcode' => $term_row->langcode ?? 'uk',
    ]);
    $term->save();
    $new_tid = $term->id();

    // Store mapping
    $this->database->insert('d7_article_migrate_map')
      ->fields(['type'=>'term','source_id'=>(string)$source_tid,'dest_id'=>(string)$new_tid])
      ->execute();

    // Migrate alias
    $this->migrateAliasFor('taxonomy_term',$source_tid,$new_tid);

    $this->logger->info("Migrated D11 source term {$source_tid} -> D11 dest term {$new_tid} ('{$term_row->name}')");

    return $new_tid;
  }

  protected function migrateFile($source_fid) {
    $source = $this->getSourceDb();

    // Check if already migrated
    $already = $this->database->select('d7_article_migrate_map','m')
      ->fields('m',['dest_id'])
      ->condition('type','file')
      ->condition('source_id',(string)$source_fid)
      ->execute()
      ->fetchField();

    if ($already) {
      return (int)$already;
    }

    // Get file data from source D11
    $file_row = $source->select('file_managed','f')
      ->fields('f',['fid','uid','filename','uri','filemime','filesize','status','created'])
      ->condition('fid',$source_fid)
      ->execute()
      ->fetchObject();

    if (!$file_row) {
      $this->logger->warning("File {$source_fid} not found in source D11");
      return NULL;
    }

    // Get relative path from URI
    $uri = $file_row->uri;
    $relative = preg_replace('#^public://#', '', $uri);

    $isSourceUrl = preg_match('#^https?://#', $this->filesBasePath);

    try {
      // Construct full source path
      $fullPath = rtrim($this->filesBasePath,'/').'/'.ltrim($relative,'/');

      // Get file data
      if ($isSourceUrl) {
        // Download from HTTP URL
        $response = $this->httpClient->get($fullPath,['stream'=>true,'timeout'=>30]);
        if ($response->getStatusCode() !== 200) {
          throw new \Exception("HTTP {$response->getStatusCode()}");
        }
        $data = $response->getBody()->getContents();
      } else {
        // Copy from local filesystem
        if (!file_exists($fullPath)) {
          throw new \Exception("File not found: {$fullPath}");
        }
        $data = file_get_contents($fullPath);
        if ($data === false) {
          throw new \Exception("Failed to read file");
        }
      }

      // Save to destination Drupal 11
      $destination = 'public://' . $relative;
      $destination_dir = dirname($destination);
      $this->fileSystem->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file = $this->fileRepository->writeData($data, $destination, FileSystemInterface::EXISTS_REPLACE);

      if ($file) {
        $file->setPermanent();
        $file->save();
        $new_fid = $file->id();

        // Store mapping
        $this->database->insert('d7_article_migrate_map')
          ->fields(['type'=>'file','source_id'=>(string)$source_fid,'dest_id'=>(string)$new_fid])
          ->execute();

        $this->logger->info("Migrated file fid {$source_fid} -> {$new_fid} ({$relative})");
        return $new_fid;
      }
    } catch (\Exception $e) {
      $this->logger->warning("Failed to migrate file {$source_fid} ({$relative}): ".$e->getMessage());
    }

    return NULL;
  }

  protected function migrateAliasFor(string $entity_type, $source_id, $dest_id) {
    $source = $this->getSourceDb();

    // Determine source path based on entity type
    if ($entity_type === 'node') {
      $source_path = "/node/{$source_id}";
      $dest_path = "/node/{$dest_id}";
    } elseif ($entity_type === 'taxonomy_term') {
      $source_path = "/taxonomy/term/{$source_id}";
      $dest_path = "/taxonomy/term/{$dest_id}";
    } else {
      return;
    }

    // Get alias from source D11
    $alias_row = $source->select('path_alias','pa')
      ->fields('pa',['alias','langcode'])
      ->condition('path',$source_path)
      ->execute()
      ->fetchObject();

    if (!$alias_row) {
      return; // No alias in source
    }

    $alias = $alias_row->alias;
    $langcode = $alias_row->langcode ?? \Drupal::languageManager()->getDefaultLanguage()->getId();

    $this->logger->info("Found D11 source alias: {$alias} for {$entity_type} {$source_id}, migrating to {$dest_id}");

    // Check if alias already exists in destination
    $existing = \Drupal::entityTypeManager()->getStorage('path_alias')
      ->loadByProperties(['alias' => $alias, 'langcode' => $langcode]);

    if ($existing) {
      $this->logger->notice("Alias {$alias} already exists in D11 dest, skipping {$entity_type} {$dest_id}");
      return;
    }

    // Create path alias in destination
    PathAlias::create([
      'path' => $dest_path,
      'alias' => '/' . ltrim($alias, '/'),
      'langcode' => $langcode,
    ])->save();

    $this->logger->info("Migrated alias: {$alias} -> {$dest_path}");
  }

  protected function convertVideoToIframe(string $video_url): ?string {
    // Extract video ID from various formats (YouTube, Vimeo, etc.)

    // YouTube
    if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]+)#', $video_url, $matches)) {
      $video_id = $matches[1];
      return '<p><iframe width="560" height="315" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></p>';
    }

    // Vimeo
    if (preg_match('#vimeo\.com/(\d+)#', $video_url, $matches)) {
      $video_id = $matches[1];
      return '<p><iframe src="https://player.vimeo.com/video/' . $video_id . '" width="560" height="315" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></p>';
    }

    $this->logger->warning("Could not convert video URL to iframe: {$video_url}");
    return NULL;
  }
}
