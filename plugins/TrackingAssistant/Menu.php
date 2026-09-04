<?php

namespace Piwik\Plugins\TrackingAssistant;

use Piwik\Common;
use Piwik\Menu\MenuAdmin;
use Piwik\Piwik;
use Piwik\Plugins\TrackingAssistant\Access\Capability\RunTrackingDiagnostics;
use Piwik\Plugins\UsersManager\UserPreferences;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureAdminMenu(MenuAdmin $menu)
    {
        $preferences = new UserPreferences();
        $idSite = Common::getRequestVar('idSite', $preferences->getDefaultWebsiteId(), 'int');

        if ($idSite && Piwik::isUserHasCapability($idSite, RunTrackingDiagnostics::ID)) {
            $menu->addMeasurableItem('TrackingAssistant_TrackingAssistant', $this->urlForAction('index'), 42);
        }
    }
}
