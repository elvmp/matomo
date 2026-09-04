<?php

namespace Piwik\Plugins\TrackingAssistant;

use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\TrackingAssistant\Access\Capability\RunTrackingDiagnostics;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public function index()
    {
        $idSite = Common::getRequestVar('idSite', 0, 'int');
        Piwik::checkUserHasCapability($idSite, RunTrackingDiagnostics::ID);

        return $this->renderTemplate('index', [
            'idSite' => $idSite,
            'title' => Piwik::translate('TrackingAssistant_TrackingAssistant'),
        ]);
    }
}
