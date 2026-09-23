<?php

declare(strict_types=1);

namespace Drupal\osu_editorial_workflow\Hook;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hooks for Entity Bundles.
 */
class OsuEditorialWorkflowEntityBundleHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Implements hook_entity_bundle_create.
   */
  #[Hook('entity_bundle_create')]
  public function entityBundleCreate(string $entity_type_id, string $bundle): void {
    // Check for node type.
    if ($entity_type_id !== 'node') {
      return;
    }

    // For all Nodes we need to enroll them in the Editorial Workflow.
    $workflowStorage = $this->entityTypeManager->getStorage('workflow');
    $nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    /** @var \Drupal\workflows\Entity\Workflow $workflow */
    $workflow = $workflowStorage->load('editorial');
    $nodeType = $nodeTypeStorage->load($bundle);

    $nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    $nodeTypeStorage->load($bundle);

    if ($workflow !== NULL) {
      /** @var \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface $typePlugin */
      $typePlugin = $workflow->getTypePlugin();

      // If the node type is already enrolled, don't try to re-enroll it.
      if (!$this->moderationInformation->shouldModerateEntitiesOfBundle($nodeType->getEntityType(), $bundle)) {
        $typePlugin->addEntityTypeAndBundle($entity_type_id, $bundle);
        $workflow->save();
      }
    }
  }

}
