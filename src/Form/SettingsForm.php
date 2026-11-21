<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\glossary_tooltip\GlossaryMatcher;

/**
 * Configures how glossary terms are highlighted.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'glossary_tooltip_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['glossary_tooltip.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['highlight'] = [
      '#type' => 'radios',
      '#title' => $this->t('Highlight'),
      '#options' => [
        'all' => $this->t('Every occurrence of a term'),
        'first' => $this->t('Only the first occurrence of a term'),
      ],
      '#description' => $this->t('"First occurrence" applies to each text field separately, e.g. the body of a page. Glossary terms are managed in the <a href=":url">Glossary vocabulary</a>.', [
        ':url' => Url::fromRoute('entity.taxonomy_vocabulary.overview_form', [
          'taxonomy_vocabulary' => GlossaryMatcher::VOCABULARY,
        ])->toString(),
      ]),
      '#config_target' => 'glossary_tooltip.settings:highlight',
    ];
    return parent::buildForm($form, $form_state);
  }

}
