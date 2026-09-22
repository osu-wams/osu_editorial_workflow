<?php

declare(strict_types=1);

namespace Drupal\osu_editorial_workflow\Hook;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\content_moderation\Entity\ContentModerationStateInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\osu_editorial_workflow\Event\ContentModerationEvents;
use Drupal\osu_editorial_workflow\Event\ContentModerationStateChangedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Content Moderation Hooks.
 */
class OsuEditorialWorkflowContentModerationHooks {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert and hook_ENTITY_TYPE_update.
   */
  public function onModerationStateChange(ContentModerationStateInterface $entity): void {
    if (class_exists('\Drupal\content_moderation\Event\ContentModerationStateChangedEvent')) {
      // When drupal.org/i/2873287 is merged, Core will dispatch these events.
      return;
    }

    if (class_exists('\Drupal\workbench_email\EventSubscriber\ContentModerationStateChangedEvent')) {
      return;
    }
    $entity_type_id = $entity->get('content_entity_type_id')->getString();
    $language_code = $entity->get('langcode')->getString();

    try {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $entity_storage */
      $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $moderation_state_storage */
      $moderation_state_storage = \Drupal::entityTypeManager()
        ->getStorage($entity->getEntityTypeId());
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $exception) {
      \Drupal::logger('osu_editorial_workflow')->error($exception->getMessage());

      return;
    }
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $moderated_entity */
    $moderated_entity = $entity_storage->loadRevision((int) $entity->get('content_entity_revision_id')
      ->getString());

    if (!$moderated_entity instanceof ContentEntityInterface) {
      return;
    }

    $isModerated = $this->moderationInformation->isModeratedEntity($moderated_entity);

    if (!$isModerated) {
      return;
    }

    if ($entity->getLoadedRevisionId() === NULL) {
      $original_state = FALSE;
    }
    else {
      /** @var \Drupal\content_moderation\Entity\ContentModerationState $original_content_moderation_state */
      $original_content_moderation_state = $moderation_state_storage->loadRevision($entity->getLoadedRevisionId());

      if (!$entity->isDefaultTranslation() && $original_content_moderation_state->hasTranslation($language_code)) {
        $original_content_moderation_state = $original_content_moderation_state->getTranslation($language_code);
      }
      $original_state = $original_content_moderation_state->get('moderation_state')
        ->getString();
    }
    $new_state = $entity->get('moderation_state')->getString();

    if ($original_state === $new_state) {
      return;
    }
    $workflow = $entity->get('workflow')->getString();
    $this->eventDispatcher->dispatch(new ContentModerationStateChangedEvent($moderated_entity, $new_state, $original_state, $workflow), ContentModerationEvents::STATE_CHANGED);
  }

}
