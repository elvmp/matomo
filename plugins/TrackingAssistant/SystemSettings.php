<?php

namespace Piwik\Plugins\TrackingAssistant;

use Piwik\Settings\FieldConfig;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    public $enabled;
    public $runnerMode;
    public $runnerEndpoint;
    public $runnerSecret;
    public $maxConcurrentRuns;
    public $runTimeout;
    public $allowPrivateNetworkTargets;
    public $allowedDiagnosticDomains;
    public $allowScreenshots;
    public $allowTraces;
    public $artifactRetentionDays;
    public $enableAiRecommendations;
    public $allowJavaScriptRecommendations;
    public $allowMtmDraftModifications;
    public $allowPublishing;

    protected function init()
    {
        $this->title = 'Tracking Assistant';

        $this->enabled = $this->boolSetting('enabled', true, 'Enable Tracking Assistant', 'Allow new browser diagnostic jobs to be created.');
        $this->runnerMode = $this->stringSetting('runnerMode', 'local', 'Runner mode', 'Use "local" for the bundled Node + Playwright worker. Remote runner support is reserved by the protocol but not enabled in this build.');
        $this->runnerEndpoint = $this->stringSetting('runnerEndpoint', '', 'Runner endpoint', 'HTTPS endpoint for a future remote runner. Ignored in local mode.');

        $this->runnerSecret = $this->stringSetting('runnerSecret', '', 'Runner authentication secret', 'Reserved for signed remote-runner jobs. Keep this empty for local mode.');
        $this->runnerSecret->setIsWritableByCurrentUser(false);

        $this->maxConcurrentRuns = $this->intSetting('maxConcurrentRuns', 2, 'Maximum concurrent runs', 'Upper bound for worker concurrency. The initial CLI worker processes one job at a time.');
        $this->runTimeout = $this->intSetting('runTimeout', 60, 'Run timeout (seconds)', 'Maximum wall-clock time for one browser diagnostic.');
        $this->allowPrivateNetworkTargets = $this->boolSetting('allowPrivateNetworkTargets', false, 'Allow private-network targets', 'Disabled by default to reduce SSRF risk. Enable only for intentional intranet diagnostics.');
        $this->allowedDiagnosticDomains = $this->stringSetting('allowedDiagnosticDomains', '', 'Additional diagnostic domains', 'Comma-separated hostnames that may be used in addition to the selected Matomo site hostname. Wildcards may use the form *.example.test.');
        $this->allowScreenshots = $this->boolSetting('allowScreenshots', false, 'Allow screenshots', 'Screenshots may contain page content and remain disabled by default.');
        $this->allowTraces = $this->boolSetting('allowTraces', false, 'Allow Playwright traces', 'Browser traces may contain page content and remain disabled by default.');
        $this->artifactRetentionDays = $this->intSetting('artifactRetentionDays', 7, 'Artifact retention (days)', 'Default retention for opt-in raw browser artifacts.');
        $this->enableAiRecommendations = $this->boolSetting('enableAiRecommendations', false, 'Enable AI recommendations', 'AI reasoning remains optional and is not allowed to execute browser code or Tag Manager mutations directly.');
        $this->allowJavaScriptRecommendations = $this->boolSetting('allowJavaScriptRecommendations', true, 'Allow JavaScript recommendations', 'Allow copyable recommendations when application code is the appropriate implementation layer.');
        $this->allowMtmDraftModifications = $this->boolSetting('allowMtmDraftModifications', false, 'Allow Tag Manager draft modifications', 'Allow explicitly approved low-risk proposals to update the current MTM draft. Preview replay and automatic rollback remain mandatory.');
        $this->allowPublishing = $this->boolSetting('allowPublishing', false, 'Allow publishing through Tracking Assistant', 'Live publishing is not implemented in the MVP. This setting is reserved for a future separately confirmed publishing workflow.');
    }

    private function boolSetting(string $name, bool $default, string $title, string $description)
    {
        return $this->makeSetting($name, $default, FieldConfig::TYPE_BOOL, function (FieldConfig $field) use ($title, $description) {
            $field->title = $title;
            $field->description = $description;
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
        });
    }

    private function stringSetting(string $name, string $default, string $title, string $description)
    {
        return $this->makeSetting($name, $default, FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($title, $description) {
            $field->title = $title;
            $field->description = $description;
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
        });
    }

    private function intSetting(string $name, int $default, string $title, string $description)
    {
        return $this->makeSetting($name, $default, FieldConfig::TYPE_INT, function (FieldConfig $field) use ($title, $description) {
            $field->title = $title;
            $field->description = $description;
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
        });
    }
}
