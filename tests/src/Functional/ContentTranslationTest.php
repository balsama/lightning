<?php

namespace Drupal\lightning\Tests\Functional;

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;

/**
 * Ensures Content Translation module can be enabled.
 *
 * @group lightning
 * @group translation
 */
class ContentTranslationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'lightning';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_translation'];

  public function test() {
    $assert = $this->assertSession();
  }

}
