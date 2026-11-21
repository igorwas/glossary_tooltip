<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Functional;

use Drupal\filter\Entity\FilterFormat;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests glossary tooltips on real pages, with page caching enabled.
 */
#[Group('glossary_tooltip')]
#[RunTestsInSeparateProcesses]
class GlossaryTooltipTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'page_cache', 'dynamic_page_cache'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The node used in the tests.
   */
  protected NodeInterface $node;

  /**
   * The glossary term used in the tests.
   */
  protected TermInterface $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The format must exist before the module is installed, so that
    // hook_install() enables the filter in it.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
    ])->save();
    $this->container->get('module_installer')->install(['glossary_tooltip']);

    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), ['access content']);
    $this->drupalCreateContentType(['type' => 'page']);
    $this->node = $this->drupalCreateNode([
      'type' => 'page',
      'body' => [
        'value' => '<p>My cat eats wet food. Food is <a href="/food">food</a>.</p>',
        'format' => 'basic_html',
      ],
    ]);
    $this->term = Term::create([
      'vid' => 'glossary',
      'name' => 'Food',
      'description' => ['value' => 'Something to eat.', 'format' => 'plain_text'],
    ]);
    $this->term->save();
  }

  /**
   * Tests that install enables the filter in existing formats.
   */
  public function testInstall(): void {
    $filter = FilterFormat::load('basic_html')->filters('glossary_tooltip');
    $this->assertTrue((bool) $filter->status);
    $this->assertSame(20, (int) $filter->weight);
  }

  /**
   * Tests that term changes appear for anonymous users without a cache clear.
   */
  public function testTermChangesWithoutCacheClear(): void {
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertSession()->elementsCount('css', '.glossary-tooltip__term', 2);
    $this->assertSession()->elementTextEquals('css', '.glossary-tooltip__description', 'Something to eat.');
    // The linked "food" is not highlighted.
    $this->assertSession()->elementNotExists('css', 'a .glossary-tooltip');

    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    // Edit the description.
    $this->term->setDescription('Anything people or animals eat.')->save();
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
    $this->assertSession()->pageTextContains('Anything people or animals eat.');
    $this->assertSession()->pageTextNotContains('Something to eat.');

    // Add a longer term that contains the existing one.
    Term::create(['vid' => 'glossary', 'name' => 'Wet food', 'description' => 'Canned food.'])->save();
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementTextEquals('css', '.glossary-tooltip__term', 'wet food');
    $this->assertSession()->elementsCount('css', '.glossary-tooltip__term', 2);

    // Unpublish.
    $this->term->setUnpublished()->save();
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementsCount('css', '.glossary-tooltip__term', 1);
    $this->assertSession()->pageTextNotContains('Anything people or animals eat.');
  }

  /**
   * Tests the settings form and its effect on cached pages.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('admin/config/content/glossary-tooltip');
    $this->assertSession()->statusCodeEquals(403);

    // Warm the anonymous page cache.
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementsCount('css', '.glossary-tooltip__term', 2);

    $this->drupalLogin($this->drupalCreateUser(['administer glossary tooltip']));
    $this->drupalGet('admin/config/content/glossary-tooltip');
    $this->assertSession()->checkboxChecked('edit-highlight-all');
    $this->submitForm(['highlight' => 'first'], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSession()->checkboxChecked('edit-highlight-first');
    $this->drupalLogout();

    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->elementsCount('css', '.glossary-tooltip__term', 1);
  }

  /**
   * Tests the "Read more" link for long descriptions.
   */
  public function testReadMore(): void {
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->linkNotExists('Read more');

    $this->term->setDescription(str_repeat('Long description. ', 10))->save();
    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->linkByHrefExists($this->term->toUrl()->toString());
    $this->clickLink('Read more');
    $this->assertSession()->addressEquals($this->term->toUrl());
    $this->assertSession()->statusCodeEquals(200);
  }

}
