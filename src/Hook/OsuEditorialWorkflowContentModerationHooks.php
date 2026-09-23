<?php

declare(strict_types=1);

namespace Drupal\osu_editorial_workflow\Hook;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\content_moderation\Entity\ContentModerationStateInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\osu_editorial_workflow\Event\ContentModerationEvents;
use Drupal\osu_editorial_workflow\Event\ContentModerationStateChangedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Content Moderation Hooks.
 */
class OsuEditorialWorkflowContentModerationHooks {

  use LoggerChannelTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert and hook_ENTITY_TYPE_update.
   *
   * Handles content moderation state changes for editorial workflows.
   *
   * This helper method processes changes in moderation states for entities
   * and dispatches the appropriate events when relevant. It ensures support
   * for Drupal's content moderation and custom plugins during state changes.
   * When https://www.drupal.org/i/2873287 is merged, this method will be
   * deprecated and replaced by the core event system.
   *
   * @param \Drupal\content_moderation\Entity\ContentModerationState $entity
   *   The content moderation state entity containing the changes. It includes
   *   moderation state information, workflow details, and associated entity
   *   data to determine whether moderation-related actions should occur.
   */
  #[Hook('content_moderation_state_insert')]
  #[Hook('content_moderation_state_update')]
  public function onModerationStateChange(ContentModerationStateInterface $entity): void {
    if (class_exists('\Drupal\content_moderation\Event\ContentModerationStateChangedEvent')) {
      // When drupal.org/i/2873287 is merged, Core will dispatch these events.
      return;
    }

    if (class_exists('\Drupal\workbench_email\EventSubscriber\ContentModerationStateChangedEvent')) {
      return;
    }
    $language_code = $entity->get('langcode')->getString();

    try {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $entityStorage */
      $entityStorage = $this->entityTypeManager->getStorage($entity->get('content_entity_type_id')->getString());
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $moderationStateStorage */
      $moderationStateStorage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $exception) {
      $this->getLogger('osu_editorial_workflow')->error($exception->getMessage());

      return;
    }
    $revisionId = (int) $entity->get('content_entity_revision_id')->getString();
    $moderated_entity = $entityStorage->loadRevision($revisionId);

    if (!$moderated_entity instanceof ContentEntityInterface) {
      return;
    }

    $isModerated = $this->moderationInformation->isModeratedEntity($moderated_entity);

    if (!$isModerated) {
      return;
    }

    if ($entity->getLoadedRevisionId() === NULL) {
      $originalState = FALSE;
    }
    else {
      /** @var \Drupal\content_moderation\Entity\ContentModerationState $originalContentModerationState */
      $originalContentModerationState = $moderationStateStorage->loadRevision($entity->getLoadedRevisionId());

      if (!$originalContentModerationState instanceof ContentModerationStateInterface) {
        return;
      }

      if (!$entity->isDefaultTranslation() && $originalContentModerationState->hasTranslation($language_code)) {
        $originalContentModerationState = $originalContentModerationState->getTranslation($language_code);
      }
      $originalState = $originalContentModerationState->get('moderation_state')
        ->getString();
    }
    $newState = $entity->get('moderation_state')->getString();

    if ($originalState === $newState) {
      return;
    }
    $workflow = $entity->get('workflow')->getString();
    $this->eventDispatcher->dispatch(new ContentModerationStateChangedEvent($moderated_entity, $newState, $originalState, $workflow), ContentModerationEvents::STATE_CHANGED);
  }

}
