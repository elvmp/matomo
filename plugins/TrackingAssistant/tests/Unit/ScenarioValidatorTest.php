<?php

namespace Piwik\Plugins\TrackingAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\TrackingAssistant\Service\Scenario\ScenarioValidator;

class ScenarioValidatorTest extends TestCase
{
    public function testStripsArbitraryScriptFields(): void
    {
        $scenario = (new ScenarioValidator())->validate([
            'steps' => [[
                'action' => 'click',
                'locator' => ['type' => 'css', 'value' => '#donate'],
                'javascript' => 'alert(1)',
                'evaluate' => 'window.secret',
                'script' => 'anything',
            ]],
            'assertions' => [[
                'type' => 'matomo.event.count',
                'category' => 'Donation',
                'action' => 'Click',
                'count' => 1,
            ]],
        ]);

        self::assertArrayNotHasKey('javascript', $scenario['steps'][0]);
        self::assertArrayNotHasKey('evaluate', $scenario['steps'][0]);
        self::assertArrayNotHasKey('script', $scenario['steps'][0]);
    }

    public function testRejectsUnsupportedAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ScenarioValidator())->validate([
            'steps' => [['action' => 'evaluate', 'javascript' => 'document.cookie']],
        ]);
    }
}
