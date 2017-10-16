<?php

namespace Drupal\Tests\lightning_workflow\Functional;

use Drupal\Tests\content_translation\Functional\ContentTranslationTestBase;

/**
 * Tests that publishing forward revisions of one language does not affect
 * published revisions of other languages that also have forward revisions.
 *
 * @group lightning_workflow
 */
class TranslatedForwardRevisionsTest extends ContentTranslationTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'lightning';

  /**
   * {@inheritdoc}
   */
  public static $modules = ['language', 'content_translation', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $entityTypeId = 'node';

  /**
   * {@inheritdoc}
   */
  protected $bundle = 'page';

  /**
   * Tests that publishing a forward revision of one language does not affect
   * published revisions of other languages that also have forward revisions.
   */
  public function testForwardRevisionsPublish() {
    $this->drupalLogin($this->translator);
    $entity_manager = \Drupal::entityManager();

    // Create a new test entity with original values in the default language.
    $default_langcode = $this->langcodes[0];
    $entity_id = $this->createEntity(['title' => 'Translate me'], $default_langcode);
    $storage = $entity_manager->getStorage($this->entityTypeId);
    $storage->resetCache();
    $entity = $storage->load($entity_id);

    // Add a content translation.
    $langcode = 'it';
    $values = $entity->toArray();
    $entity->addTranslation($langcode, $values);

    // @todo
  }

}