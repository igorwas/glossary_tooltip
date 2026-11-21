<?php

declare(strict_types=1);

namespace Drupal\Tests\glossary_tooltip\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests term matching and markup of the glossary tooltip filter.
 */
#[Group('glossary_tooltip')]
#[RunTestsInSeparateProcesses]
class GlossaryTooltipFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'filter',
    'glossary_tooltip',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'glossary_tooltip']);
  }

  /**
   * Tests that the vocabulary and settings are created on install.
   */
  public function testInstallConfig(): void {
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')->load('glossary'));
    $this->assertSame('all', $this->config('glossary_tooltip.settings')->get('highlight'));
  }

  /**
   * Tests whole-word, case-insensitive matching with the longest term first.
   */
  public function testMatching(): void {
    $this->createTerm('Food');
    $this->createTerm('Wet food');

    $this->assertHighlighted(
      ['wet food', 'FOOD'],
      '<p>My cat eats wet food. FOOD is good. Seafood and foods are not.</p>',
    );
    // Terms sharing a beginning: the longer one wins, although it was
    // created later.
    $this->createTerm('Food bowl');
    $this->assertHighlighted(['food bowl', 'food'], '<p>A food bowl for food.</p>');
    // Whitespace inside a multi-word term may differ from the term name.
    $this->assertHighlighted(["Wet\u{00A0}food"], '<p>Wet&nbsp;food</p>');
  }

  /**
   * Tests terms in non-Latin scripts.
   */
  public function testNonLatinTerms(): void {
    $this->createTerm('Кіт');
    $this->createTerm('猫粮');
    $this->createTerm('हिंदी');

    $this->assertHighlighted(['кіт'], '<p>Мій кіт, але не кітель.</p>');
    // Chinese has no spaces between words.
    $this->assertHighlighted(['猫粮'], '<p>我的猫粮很好</p>');
    // A following combining mark is part of the word.
    $this->assertHighlighted(['हिंदी'], '<p>हिंदीा हिंदी</p>');
  }

  /**
   * Tests that links and code are left unchanged.
   */
  public function testSkippedElements(): void {
    $this->createTerm('Food');

    $html = '<p><a href="/food">Food</a> <a href="/x"><em>food</em></a> <code>food</code></p>';
    $this->assertHighlighted([], $html);
    $this->assertSame($html, (string) $this->process($html));
  }

  /**
   * Tests that unpublished terms are not highlighted.
   */
  public function testUnpublishedTerm(): void {
    $term = $this->createTerm('Food');
    $term->setUnpublished()->save();

    $this->assertHighlighted([], '<p>Food</p>');
  }

  /**
   * Tests the description limit and the "Read more" link.
   */
  public function testDescription(): void {
    $short = $this->createTerm('Short', '<p>A <strong>short</strong> &amp; simple description.</p>');
    $long = $this->createTerm('Long', str_repeat('Lorem ipsum dolor sit amet. ', 10));

    $xpath = $this->processToXpath('<p>Short and Long</p>');
    $descriptions = $xpath->query('//span[@class="glossary-tooltip__description"]');
    $links = $xpath->query('//a[@class="glossary-tooltip__link"]');

    // Short: plain text, no link.
    $this->assertSame('A short & simple description.', $descriptions->item(0)->textContent);
    // Long: at most 100 characters, ending with an ellipsis, plus a link.
    $text = $descriptions->item(1)->textContent;
    $this->assertLessThanOrEqual(100, mb_strlen($text));
    $this->assertStringEndsWith('…', $text);
    $this->assertCount(1, $links);
    $this->assertSame($long->toUrl()->toString(), $links->item(0)->getAttribute('href'));
    $this->assertNotSame($short->toUrl()->toString(), $links->item(0)->getAttribute('href'));
  }

  /**
   * Tests the accessibility attributes of the tooltip markup.
   */
  public function testMarkup(): void {
    $this->createTerm('Food');

    $xpath = $this->processToXpath('<p>Food and food</p>');
    $buttons = $xpath->query('//button[@type="button"][@class="glossary-tooltip__term"]');
    $this->assertCount(2, $buttons);
    $ids = [];
    foreach ($buttons as $button) {
      $popup_id = $button->getAttribute('aria-controls');
      $this->assertSame('false', $button->getAttribute('aria-expanded'));
      $this->assertCount(1, $xpath->query("//*[@id='$popup_id']"));
      $this->assertCount(1, $xpath->query("//*[@id='{$button->getAttribute('aria-describedby')}']"));
      $ids[] = $popup_id;
    }
    $this->assertCount(2, array_unique($ids), 'Every tooltip has a unique ID.');
  }

  /**
   * Tests the "first occurrence only" setting.
   */
  public function testFirstOccurrenceSetting(): void {
    $this->createTerm('Food');
    $this->createTerm('Cat');
    $html = '<p>Food, cat, food.</p><p>Food and cat.</p>';

    $this->assertHighlighted(['Food', 'cat', 'food', 'Food', 'cat'], $html);

    $this->config('glossary_tooltip.settings')->set('highlight', 'first')->save();
    $this->assertHighlighted(['Food', 'cat'], $html);
  }

  /**
   * Tests the cacheability metadata and attachments of the result.
   */
  public function testCacheability(): void {
    $result = $this->process('<p>Nothing to see.</p>');
    // Even without matches: a new term may match this text later.
    $this->assertContains('taxonomy_term_list:glossary', $result->getCacheTags());
    $this->assertContains('config:glossary_tooltip.settings', $result->getCacheTags());
    $this->assertEmpty($result->getAttachments());

    $this->createTerm('Food');
    $result = $this->process('<p>Food</p>');
    $this->assertContains('taxonomy_term_list:glossary', $result->getCacheTags());
    $this->assertSame(['glossary_tooltip/tooltip'], $result->getAttachments()['library']);
  }

  /**
   * Creates a published glossary term.
   */
  protected function createTerm(string $name, string $description = 'Description.'): TermInterface {
    $term = Term::create([
      'vid' => 'glossary',
      'name' => $name,
      'description' => ['value' => $description, 'format' => 'plain_text'],
    ]);
    $term->save();
    return $term;
  }

  /**
   * Runs the filter on HTML.
   */
  protected function process(string $html): FilterProcessResult {
    // Forget terms loaded by earlier calls in the same test.
    $this->container->get('glossary_tooltip.matcher')->reset();
    return $this->container->get('plugin.manager.filter')
      ->createInstance('glossary_tooltip')
      ->process($html, 'en');
  }

  /**
   * Runs the filter and returns an XPath object for the result.
   */
  protected function processToXpath(string $html): \DOMXPath {
    return new \DOMXPath(Html::load((string) $this->process($html)));
  }

  /**
   * Asserts which texts are highlighted, in order.
   *
   * @param string[] $expected
   *   The expected term texts.
   * @param string $html
   *   The HTML to filter.
   */
  protected function assertHighlighted(array $expected, string $html): void {
    $actual = [];
    foreach ($this->processToXpath($html)->query('//button[@class="glossary-tooltip__term"]') as $button) {
      $actual[] = $button->textContent;
    }
    $this->assertSame($expected, $actual);
  }

}
