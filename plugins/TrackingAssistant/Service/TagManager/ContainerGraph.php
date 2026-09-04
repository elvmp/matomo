<?php

namespace Piwik\Plugins\TrackingAssistant\Service\TagManager;

class ContainerGraph
{
    private $container;
    private $draftId;
    private $tags;
    private $triggers;
    private $variables;
    private $triggerById = [];

    public function __construct(array $container, int $draftId, array $tags, array $triggers, array $variables)
    {
        $this->container = $container;
        $this->draftId = $draftId;
        $this->tags = $tags;
        $this->triggers = $triggers;
        $this->variables = $variables;

        foreach ($triggers as $trigger) {
            if (isset($trigger['idtrigger'])) {
                $this->triggerById[(string) $trigger['idtrigger']] = $trigger;
            }
        }
    }

    public function getContainer(): array { return $this->container; }
    public function getDraftId(): int { return $this->draftId; }
    public function getTags(): array { return $this->tags; }
    public function getTriggers(): array { return $this->triggers; }
    public function getVariables(): array { return $this->variables; }

    public function getTrigger($idTrigger): ?array
    {
        $key = (string) $idTrigger;
        return isset($this->triggerById[$key]) ? $this->triggerById[$key] : null;
    }

    public function getClickCssSelectorInspections(): array
    {
        $inspections = [];
        foreach ($this->triggers as $trigger) {
            $conditions = isset($trigger['conditions']) && is_array($trigger['conditions']) ? $trigger['conditions'] : [];
            foreach ($conditions as $index => $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                if (($condition['comparison'] ?? '') !== 'match_css_selector') {
                    continue;
                }
                if (!$this->isClickElement($condition['actual'] ?? null)) {
                    continue;
                }
                $selector = $condition['expected'] ?? '';
                if (!is_string($selector) || $selector === '' || strlen($selector) > 2000) {
                    continue;
                }
                $inspections[] = [
                    'key' => self::inspectionKey($trigger['idtrigger'], $index),
                    'triggerId' => (int) $trigger['idtrigger'],
                    'conditionIndex' => (int) $index,
                    'selector' => $selector,
                ];
                if (count($inspections) >= 100) {
                    return $inspections;
                }
            }
        }
        return $inspections;
    }

    public static function inspectionKey($idTrigger, int $conditionIndex): string
    {
        return 'trigger:' . (string) $idTrigger . ':condition:' . $conditionIndex;
    }

    public static function triggerMutationView(array $trigger): array
    {
        return [
            'idtrigger' => (int) ($trigger['idtrigger'] ?? 0),
            'type' => (string) ($trigger['type'] ?? ''),
            'name' => (string) ($trigger['name'] ?? ''),
            'description' => (string) ($trigger['description'] ?? ''),
            'parameters' => isset($trigger['parameters']) && is_array($trigger['parameters']) ? $trigger['parameters'] : [],
            'conditions' => isset($trigger['conditions']) && is_array($trigger['conditions']) ? $trigger['conditions'] : [],
        ];
    }

    public static function isClickElement($actual): bool
    {
        if (is_string($actual)) {
            $normalised = strtolower(preg_replace('/\s+/', '', $actual));
            return $normalised === '{{clickelement}}' || $normalised === 'clickelement';
        }
        if (is_array($actual)) {
            $type = strtolower((string) ($actual['type'] ?? ''));
            $name = strtolower((string) ($actual['name'] ?? ''));
            return $type === 'clickelement' || $name === 'clickelement';
        }
        return false;
    }
}
