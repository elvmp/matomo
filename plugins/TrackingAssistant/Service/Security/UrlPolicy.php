<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Security;

use Piwik\Site;
use Piwik\Plugins\TrackingAssistant\SystemSettings;

class UrlPolicy
{
    private $settings;

    public function __construct(?SystemSettings $settings = null)
    {
        $this->settings = $settings ?: new SystemSettings();
    }

    public function assertAllowedForSite(int $idSite, string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Diagnostic URL must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http:// and https:// diagnostic URLs are allowed.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new \InvalidArgumentException('Credentials must not be embedded in a diagnostic URL.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (!$this->hostMatchesAny($host, $this->getAllowedNavigationHosts($idSite))) {
            throw new \InvalidArgumentException('Diagnostic host must match the selected Matomo site or an administrator-approved diagnostic domain.');
        }

        if (!$this->isPrivateNetworkAllowed()) {
            $this->assertHostDoesNotResolvePrivate($host);
        }
    }

    public function getAllowedNavigationHosts(int $idSite): array
    {
        $mainUrl = Site::getMainUrlFor($idSite);
        $mainHost = strtolower((string) parse_url($mainUrl, PHP_URL_HOST));
        $hosts = $mainHost !== '' ? [$mainHost] : [];

        $configured = (string) $this->settings->allowedDiagnosticDomains->getValue();
        foreach (preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public function isPrivateNetworkAllowed(): bool
    {
        return (bool) $this->settings->allowPrivateNetworkTargets->getValue();
    }

    private function hostMatchesAny(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(rtrim((string) $allowed, '.'));
            if ($host === $allowed) {
                return true;
            }

            if (strpos($allowed, '*.') === 0 && $this->endsWith($host, substr($allowed, 1))) {
                $suffix = substr($allowed, 1);
                if ($host !== ltrim($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function endsWith(string $value, string $suffix): bool
    {
        if ($suffix === '') {
            return true;
        }

        return substr($value, -strlen($suffix)) === $suffix;
    }

    private function assertHostDoesNotResolvePrivate(string $host): void
    {
        if ($host === 'localhost' || $this->endsWith($host, '.localhost')) {
            throw new \InvalidArgumentException('Loopback diagnostic targets are blocked by default.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);
            return;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new \InvalidArgumentException('Diagnostic hostname could not be resolved.');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null) {
                $this->assertPublicIp($ip);
            }
        }
    }

    private function assertPublicIp(string $ip): void
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($valid === false) {
            throw new \InvalidArgumentException('Private, loopback, link-local and reserved diagnostic addresses are blocked by default.');
        }
    }
}
