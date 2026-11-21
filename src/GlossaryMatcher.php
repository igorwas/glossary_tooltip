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
   * Characters that continue a word: letters, combining marks, digits.
   */
  protected const WORD_CHAR = '[\p{L}\p{M}\p{N}_]';

  /**
   * Scripts written without spaces, where word boundaries cannot be detected.
   */
  protected const NO_SPACE_SCRIPTS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

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
        $key = $this->normalize($term->getName());
        if ($key !== '') {
          $this->terms[$key] = $term;
        }
      }
    }
    return $this->terms;
  }

  /**
   * Forgets the loaded terms, so the next call loads them again.
   */
  public function reset(): void {
    $this->terms = NULL;
  }

  /**
   * Returns cache tags invalidated when any glossary term changes.
   *
   * @return string[]
   *   The cache tags.
   */
  public function getCacheTags(): array {
    return $this->entityTypeManager->getDefinition('taxonomy_term')->getBundleListCacheTags(self::VOCABULARY);
  }

  /**
   * Returns the term for a matched string.
   */
  public function getTerm(string $match): ?TermInterface {
    return $this->getTerms()[$this->normalize($match)] ?? NULL;
  }

  /**
   * Normalizes a term name or match for lookup: lowercase, single spaces.
   */
  protected function normalize(string $text): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
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
    $alternatives = array_map($this->buildAlternative(...), $names);
    return '/(' . implode('|', $alternatives) . ')/iu';
  }

  /**
   * Builds the regex for one term, requiring word boundaries where possible.
   */
  protected function buildAlternative(string $name): string {
    $no_space = '/[' . self::NO_SPACE_SCRIPTS . ']/u';
    // \b is ASCII-only, so use Unicode lookarounds instead. They are skipped
    // on the side where the term begins/ends in a script without spaces
    // (e.g. Chinese), since a neighbouring letter is normal there.
    $before = preg_match($no_space, mb_substr($name, 0, 1)) ? '' : '(?<!' . self::WORD_CHAR . ')';
    $after = preg_match($no_space, mb_substr($name, -1)) ? '' : '(?!' . self::WORD_CHAR . ')';
    return $before . preg_replace('/\s+/u', '\s+', preg_quote($name, '/')) . $after;
  }

}
