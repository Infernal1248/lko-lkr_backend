<?php

namespace App\Services;

class PushEndpoint
{
    public static function allowed(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        // Never send to arbitrary user-supplied URLs, IPs or internal hosts.
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || (bool) preg_match('/^[a-z0-9-]+\.notify\.windows\.com$/D', $host);
    }

    public static function validKey(string $key, int $bytes): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $key)) {
            return false;
        }
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === $bytes && ($bytes !== 65 || $decoded[0] === "\x04");
    }
}
