<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
   * Text inside these elements is never replaced.
   */
  protected const SKIP_ELEMENTS = ['a', 'button', 'code', 'pre', 'script', 'style', 'textarea'];

  /**
   * The glossary matcher.
   */
  protected GlossaryMatcher $matcher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->matcher = $container->get(GlossaryMatcher::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);
    // Any term change (add, edit, unpublish, delete) must refresh the output.
    $result->addCacheTags(['taxonomy_term_list']);

    $pattern = $this->matcher->getPattern();
    if ($pattern === NULL || !preg_match($pattern, strip_tags($text))) {
      return $result;
    }

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $excluded = implode(' or ', array_map(fn(string $tag): string => "ancestor::$tag", self::SKIP_ELEMENTS));
    // Collect nodes first: modifying the DOM while iterating breaks the list.
    $nodes = iterator_to_array($xpath->query("//body//text()[not($excluded)]"));

    foreach ($nodes as $node) {
      $parts = preg_split($pattern, $node->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE);
      if (count($parts) < 2) {
        continue;
      }
      $fragment = $dom->createDocumentFragment();
      foreach ($parts as $i => $part) {
        // Odd indexes are the captured matches.
        $term = $i % 2 ? $this->matcher->getTerm($part) : NULL;
        if ($term) {
          $fragment->appendChild($this->buildTooltip($dom, $part, $term));
        }
        elseif ($part !== '') {
          $fragment->appendChild($dom->createTextNode($part));
        }
      }
      $node->parentNode->replaceChild($fragment, $node);
    }

    $result->setProcessedText(Html::serialize($dom));
    return $result;
  }

  /**
   * Builds the tooltip markup for a matched term.
   */
  protected function buildTooltip(\DOMDocument $dom, string $text, TermInterface $term): \DOMElement {
    $id = Html::getUniqueId('glossary-tooltip-' . $term->id());

    $wrapper = $dom->createElement('span');
    $wrapper->setAttribute('class', 'glossary-tooltip');

    $trigger = $dom->createElement('button');
    $trigger->setAttribute('type', 'button');
    $trigger->setAttribute('class', 'glossary-tooltip__term');
    $trigger->setAttribute('aria-describedby', $id);
    $trigger->appendChild($dom->createTextNode($text));
    $wrapper->appendChild($trigger);

    $description = trim(Html::decodeEntities(strip_tags($term->getDescription() ?? '')));
    $content = $dom->createElement('span');
    $content->setAttribute('id', $id);
    $content->setAttribute('role', 'tooltip');
    $content->setAttribute('class', 'glossary-tooltip__content');
    $content->appendChild($dom->createTextNode($description));
    $wrapper->appendChild($content);

    return $wrapper;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Glossary terms are highlighted and show their description on hover, focus or tap.');
  }

}
