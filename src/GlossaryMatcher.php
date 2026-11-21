<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Loads published glossary terms and finds them in text.
 */
class GlossaryMatcher {

  /**
   * The glossary vocabulary ID.
   */
  public const VOCABULARY = 'glossary';

  /**
   * Published terms keyed by lowercased name, or NULL if not loaded yet.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface>|null
   */
  protected ?array $terms = NULL;

  /**
   * Constructs a GlossaryMatcher object.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns published glossary terms keyed by lowercased name.
   *
   * @return array<string, \Drupal\taxonomy\TermInterface>
   *   The terms.
   */
  public function getTerms(): array {
    if ($this->terms === NULL) {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', self::VOCABULARY)
        ->condition('status', 1)
        ->execute();
      $this->terms = [];
      /** @var \Drupal\taxonomy\TermInterface $term */
      foreach ($storage->loadMultiple($ids) as $term) {
        $name = trim($term->getName());
        if ($name !== '') {
          $this->terms[mb_strtolower($name)] = $term;
        }
      }
    }
    return $this->terms;
  }

  /**
   * Returns the term for a matched string.
   */
  public function getTerm(string $match): ?TermInterface {
    $key = mb_strtolower(preg_replace('/\s+/u', ' ', $match));
    return $this->getTerms()[$key] ?? NULL;
  }

  /**
   * Builds a regex matching any term as a whole word, longest terms first.
   *
   * @return string|null
   *   The pattern, or NULL if there are no terms.
   */
  public function getPattern(): ?string {
    $names = array_keys($this->getTerms());
    if (!$names) {
      return NULL;
    }
    // Longer terms first, so "wet food" wins over "food".
    usort($names, fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    $alternatives = array_map(
      fn(string $name): string => preg_replace('/\s+/u', '\s+', preg_quote($name, '/')),
      $names,
    );
    // \b is ASCII-only, so use Unicode letter/number lookarounds instead.
    return '/(?<![\p{L}\p{N}_])(' . implode('|', $alternatives) . ')(?![\p{L}\p{N}_])/iu';
  }

}
