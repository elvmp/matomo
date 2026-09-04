<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Proposal;

class CanonicalJson
{
    public function encode($value): string
    {
        return json_encode($this->normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function hash($value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function normalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->normalise($item);
            }
            return $out;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalise($item);
        }
        return $value;
    }

    private function isList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }
}
