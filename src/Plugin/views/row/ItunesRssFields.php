<?php

namespace Drupal\itunes_rss\Plugin\views\row;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\media\Entity\Media;
use Drupal\views\Plugin\views\row\RssFields;
use function strtolower;

/**
 * Renders an iTunes RSS item based on fields.
 *
 * @ViewsRow(
 *   id = "itunes_rss_fields",
 *   title = @Translation("iTunes Fields"),
 *   help = @Translation("Display fields as iTunes RSS items."),
 *   theme = "views_view_row_rss",
 *   display_types = {"feed"}
 * )
 */
class ItunesRssFields extends RssFields {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['enclosure_field'] = ['default' => ''];

    foreach ($this->getItunesItemFields() as $field) {
      $options['itunes']['contains'][$this->getItunesFieldMachineName($field)] = ['default' => ''];
    }

    return $options;
  }

  /**
   * Get a list of all itunes:* fields that apply to the <item> element.
   *
   * @return array
   *   A flat array of field names.
   *
   * @see https://help.apple.com/itc/podcasts_connect/#/itcb54353390
   */
  public function getItunesItemFields() {
    $fields = [
      'subtitle',
      'summary',
      'title',
      'episodeType',
      'episode',
      'season',
      'author',
      'explicit',
      'block',
      'duration',
      'image',
      'isClosedCaptioned',
      'order',
      'language'
    ];

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getItunesFieldMachineName($field) {
    return $field . "_field";
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $initial_labels = ['' => $this->t('- None -')];
    $view_fields_labels = $this->displayHandler->getFieldLabels();
    $view_fields_labels = array_merge($initial_labels, $view_fields_labels);

    $form['enclosure_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Enclosure field'),
      '#description' => $this->t('Describes a media object that is attached to the item. This must be a file field or a media entity reference.'),
      '#options' => $view_fields_labels,
      '#default_value' => $this->options['enclosure_field'],
    ];
    $form['itunes'] = [
      '#type' => 'details',
      '#title' => $this->t('iTunes fields'),
      '#open' => TRUE,
    ];
    $form['itunes']['help']['#markup'] = $this->t(
      'See @link for detailed information on available iTunes tags.',
      ['@link' => 'https://help.apple.com/itc/podcasts_connect/#/itcb54353390']
    );
    foreach ($this->getItunesItemFields() as $field) {
      $form['itunes'][$this->getItunesFieldMachineName($field)] = [
        '#type' => 'select',
        '#title' => $this->t('iTunes @field_name field', ['@field_name' => $field]),
        '#description' => $this->t("The itunes:@field_name field. If set to none, field will not be rendered.", ['@field_name' => $field]),
        '#options' => $view_fields_labels,
        '#default_value' => $this->options['itunes'][$this->getItunesFieldMachineName($field)],
        '#required' => FALSE,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render($row) {
    $build = parent::render($row);

    // Views relation is not mandatory, skip row processing if audio is not present
    if (empty($row->_relationship_entities)) {
      return $build;
    }

    $related_media = array_filter($row->_relationship_entities, function ($related_entity) {
      return $related_entity instanceof \Drupal\media\Entity\Media;
    });

    // Skip row processing if media relation is not present
    if (empty($related_media)) {
      return $build;
    }

    static $row_index;
    if (!isset($row_index)) {
      $row_index = 0;
    }
    $item = $build['#row'];

    if ($this->options['enclosure_field']) {
      $field_name = $this->options['enclosure_field'];
      $entity = $row->_entity;

      if ($entity->get($field_name) instanceof EntityReferenceFieldItemList) {
        /** @var \Drupal\media\Entity\Media $media */
        $media = $row->_relationship_entities[$field_name];
        $file = File::load($media->getSource()->getSourceFieldValue($media));
      }

      if ($entity->get($field_name) instanceof FileFieldItemList) {
        $value = $entity->$field_name->getValue();
        $file = File::load($value[0]['target_id']);
      }

      if (isset($file)) {
        $item->elements[] = [
          'key' => 'enclosure',
          'attributes' => [
            // In RSS feeds, it is necessary to use absolute URLs. The
            // 'url.site' cache context is already associated with RSS feed
            // responses, so it does not need to be specified here.
            'url' => file_create_url($file->getFileUri()),
            'length' => $file->getSize(),
            'type' => $file->getMimeType(),
          ],
        ];
      }
    }

    $fields = $this->getItunesItemFields();

    // Render remaining fields.
    foreach ($fields as $field) {
      if ($this->getField($row_index, $this->options['itunes'][$this->getItunesFieldMachineName($field)]) !== '') {
        $value = $this->getField($row_index, $this->options['itunes'][$this->getItunesFieldMachineName($field)]);

        if ($field === 'image') {
          /** @var \Drupal\media\Entity\Media $media_image */
          $media_image = Media::load((string) $value);
          $fid = $media_image->getSource()->getSourceFieldValue($media_image);
          $file = File::load($fid);

          $item->elements[] = [
            'key' => 'itunes:' . $field,
            'attributes' => ['href' => $file->url()],
          ];
        }
        else {
          $item->elements[] = [
            'key' => 'itunes:' . $field,
            'value' => $value,
          ];
        }
      }
    }

    $row_index++;
    return $build;
  }

}
