<?php

namespace Piwik\Plugins\TrackingAssistant\Service\TagManager;

interface TagManagerAdapterInterface
{
    public function getContainer(int $idSite, string $idContainer): array;
    public function getDraftId(int $idSite, string $idContainer): int;
    public function getGraph(int $idSite, string $idContainer): ContainerGraph;
    public function getTrigger(int $idSite, string $idContainer, int $idContainerVersion, int $idTrigger): array;
    public function updateTrigger(int $idSite, string $idContainer, int $idContainerVersion, array $trigger): void;
    public function exportDraft(int $idSite, string $idContainer): string;
    public function importDraft(int $idSite, string $idContainer, string $snapshot): void;
    public function getPreviewVersionId(int $idSite, string $idContainer): ?int;
    public function enablePreview(int $idSite, string $idContainer, int $idContainerVersion): void;
    public function disablePreview(int $idSite, string $idContainer): void;
    public function restorePreview(int $idSite, string $idContainer, ?int $previousVersion): void;
}
