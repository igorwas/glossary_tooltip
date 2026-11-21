<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\glossary_tooltip\GlossaryMatcher;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wraps glossary terms found in text with a tooltip.
 */
#[Filter(
  id: 'glossary_tooltip',
  title: new TranslatableMarkup('Glossary tooltips'),
  description: new TranslatableMarkup('Highlights glossary terms and shows their description in a tooltip.'),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: 20,
)]
class GlossaryTooltipFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum length of the description shown in the tooltip.
   */
  public const DESCRIPTION_LENGTH = 100;

  /**
   * Text inside these elements is never replaced.
   */
  protected const SKIP_ELEMENTS = ['a', 'button', 'code', 'pre', 'script', 'style', 'textarea'];

  /**
   * The glossary matcher.
   */
  protected GlossaryMatcher $matcher;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->matcher = $container->get(GlossaryMatcher::class);
    $instance->renderer = $container->get('renderer');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    // Any glossary term change (add, edit, unpublish, delete) must refresh
    // the output, without a cache clear.
    $result->addCacheTags($this->matcher->getCacheTags());
    // Changing the settings must refresh the output too.
    $config = $this->configFactory->get('glossary_tooltip.settings');
    $result->addCacheableDependency($config);
    $first_only = $config->get('highlight') === 'first';

    $pattern = $this->matcher->getPattern();
    if ($pattern === NULL || !preg_match($pattern, strip_tags($text))) {
      return $result;
    }

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $excluded = implode(' or ', array_map(fn(string $tag): string => "ancestor::$tag", self::SKIP_ELEMENTS));
    // Never wrap text that is already inside a tooltip.
    $excluded .= " or ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' glossary-tooltip ')]";
    // Collect nodes first: modifying the DOM while iterating breaks the list.
    $nodes = iterator_to_array($xpath->query("//body//text()[not($excluded)]"));

    $found = FALSE;
    // IDs of terms already highlighted, for "first occurrence only" mode.
    $seen = [];
    foreach ($nodes as $node) {
      $parts = preg_split($pattern, $node->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE);
      if (count($parts) < 2) {
        continue;
      }
      $fragment = $dom->createDocumentFragment();
      foreach ($parts as $i => $part) {
        // Odd indexes are the captured matches.
        $term = $i % 2 ? $this->matcher->getTerm($part) : NULL;
        if ($term && $first_only && isset($seen[$term->id()])) {
          $term = NULL;
        }
        if ($term) {
          $this->appendHtml($fragment, $this->renderTooltip($part, $term));
          $seen[$term->id()] = TRUE;
          $found = TRUE;
        }
        elseif ($part !== '') {
          $fragment->appendChild($dom->createTextNode($part));
        }
      }
      $node->parentNode->replaceChild($fragment, $node);
    }

    if ($found) {
      $result->setProcessedText(Html::serialize($dom));
      $result->addAttachments(['library' => ['glossary_tooltip/tooltip']]);
    }
    return $result;
  }

  /**
   * Renders the tooltip markup for a matched term.
   */
  protected function renderTooltip(string $text, TermInterface $term): string {
    $description = $this->getPlainDescription($term);
    $truncated = Unicode::truncate($description, self::DESCRIPTION_LENGTH, TRUE, TRUE);
    $build = [
      '#theme' => 'glossary_tooltip',
      '#text' => $text,
      // Random, so IDs stay unique when cached texts are combined on a page.
      '#tooltip_id' => 'glossary-tooltip-' . bin2hex(random_bytes(4)),
      '#description' => $truncated,
      '#url' => $truncated !== $description ? $term->toUrl()->toString() : NULL,
    ];
    $html = (string) $this->renderer->renderInIsolation($build);
    // Template indentation would otherwise show up as spaces around the term.
    return preg_replace('/>\s+</', '><', trim($html));
  }

  /**
   * Returns the term description as plain text on a single line.
   */
  protected function getPlainDescription(TermInterface $term): string {
    $text = Html::decodeEntities(strip_tags($term->getDescription() ?? ''));
    return trim(preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Parses an HTML snippet and appends its nodes to a fragment.
   */
  protected function appendHtml(\DOMDocumentFragment $fragment, string $html): void {
    $source = Html::load(trim($html));
    foreach ($source->getElementsByTagName('body')->item(0)->childNodes as $child) {
      $fragment->appendChild($fragment->ownerDocument->importNode($child, TRUE));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Glossary terms are highlighted and show their description on hover, focus or tap.');
  }

}
