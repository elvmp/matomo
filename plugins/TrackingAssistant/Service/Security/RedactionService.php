<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Security;

class RedactionService
{
    private const SENSITIVE_QUERY_NAMES = [
        'token', 'auth', 'password', 'passwd', 'secret', 'key', 'apikey', 'api_key',
        'jwt', 'session', 'sessionid', 'email', 'code', 'access_token', 'refresh_token',
    ];

    private const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key',
    ];

    public function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '[invalid-url]';
        }

        if (!isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach ($query as $name => &$value) {
            if ($this->isSensitiveQueryName((string) $name)) {
                $value = '[REDACTED]';
            }
        }
        unset($value);

        $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $this->buildUrl($parts);
    }

    public function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)
                ? '[REDACTED]'
                : $value;
        }
        return $redacted;
    }

    public function isSensitiveQueryName(string $name): bool
    {
        return in_array(strtolower($name), self::SENSITIVE_QUERY_NAMES, true);
    }

    private function buildUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
