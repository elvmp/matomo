<?php

namespace Piwik\Plugins\TrackingAssistant\Service\TagManager;

use Piwik\Plugins\TagManager\API as TagManagerApi;

class TagManagerAdapter implements TagManagerAdapterInterface
{
    private function api()
    {
        return TagManagerApi::getInstance();
    }

    public function getContainer(int $idSite, string $idContainer): array
    {
        $container = $this->api()->getContainer($idSite, $idContainer);
        if (!is_array($container)) {
            throw new \RuntimeException('Tag Manager container could not be loaded.');
        }
        return $container;
    }

    public function getDraftId(int $idSite, string $idContainer): int
    {
        $container = $this->getContainer($idSite, $idContainer);
        $id = (int) ($container['draft']['idcontainerversion'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Tag Manager draft version is unavailable.');
        }
        return $id;
    }

    public function getGraph(int $idSite, string $idContainer): ContainerGraph
    {
        $container = $this->getContainer($idSite, $idContainer);
        $draftId = (int) ($container['draft']['idcontainerversion'] ?? 0);
        if ($draftId <= 0) {
            throw new \RuntimeException('Tag Manager draft version is unavailable.');
        }

        return new ContainerGraph(
            $container,
            $draftId,
            (array) $this->api()->getContainerTags($idSite, $idContainer, $draftId),
            (array) $this->api()->getContainerTriggers($idSite, $idContainer, $draftId),
            (array) $this->api()->getContainerVariables($idSite, $idContainer, $draftId)
        );
    }

    public function getTrigger(int $idSite, string $idContainer, int $idContainerVersion, int $idTrigger): array
    {
        $trigger = $this->api()->getContainerTrigger($idSite, $idContainer, $idContainerVersion, $idTrigger);
        if (!is_array($trigger) || empty($trigger)) {
            throw new \RuntimeException('The referenced Tag Manager trigger no longer exists.');
        }
        return $trigger;
    }

    public function updateTrigger(int $idSite, string $idContainer, int $idContainerVersion, array $trigger): void
    {
        $this->api()->updateContainerTrigger(
            $idSite,
            $idContainer,
            $idContainerVersion,
            (int) $trigger['idtrigger'],
            (string) $trigger['name'],
            isset($trigger['parameters']) && is_array($trigger['parameters']) ? $trigger['parameters'] : [],
            isset($trigger['conditions']) && is_array($trigger['conditions']) ? $trigger['conditions'] : [],
            (string) ($trigger['description'] ?? '')
        );
    }

    public function exportDraft(int $idSite, string $idContainer): string
    {
        $export = $this->api()->exportContainerVersion($idSite, $idContainer);
        $json = json_encode($export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Unable to create Tag Manager draft safety snapshot.');
        }
        return $json;
    }

    public function importDraft(int $idSite, string $idContainer, string $snapshot): void
    {
        $this->api()->importContainerVersion($snapshot, $idSite, $idContainer, '');
    }

    public function getPreviewVersionId(int $idSite, string $idContainer): ?int
    {
        $container = $this->getContainer($idSite, $idContainer);
        foreach ((array) ($container['releases'] ?? []) as $release) {
            if (($release['environment'] ?? '') === 'preview') {
                $id = (int) ($release['idcontainerversion'] ?? 0);
                return $id > 0 ? $id : null;
            }
        }
        return null;
    }

    public function enablePreview(int $idSite, string $idContainer, int $idContainerVersion): void
    {
        $this->api()->enablePreviewMode($idSite, $idContainer, $idContainerVersion);
    }

    public function disablePreview(int $idSite, string $idContainer): void
    {
        $this->api()->disablePreviewMode($idSite, $idContainer);
    }

    public function restorePreview(int $idSite, string $idContainer, ?int $previousVersion): void
    {
        if ($previousVersion !== null && $previousVersion > 0) {
            $this->enablePreview($idSite, $idContainer, $previousVersion);
        } else {
            $this->disablePreview($idSite, $idContainer);
        }
    }
}
