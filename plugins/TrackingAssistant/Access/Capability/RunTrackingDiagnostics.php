<?php

namespace Piwik\Plugins\TrackingAssistant\Access\Capability;

use Piwik\Access\Capability;
use Piwik\Access\Role\Admin;
use Piwik\Access\Role\Write;

class RunTrackingDiagnostics extends Capability
{
    public const ID = 'tracking_assistant_run';

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return 'Run Tracking Assistant diagnostics';
    }

    public function getCategory(): string
    {
        return 'Tracking Assistant';
    }

    public function getDescription(): string
    {
        return 'Reproduce and diagnose browser-based Matomo tracking problems for a website.';
    }

    public function getIncludedInRoles(): array
    {
        return [Write::ID, Admin::ID];
    }
}
