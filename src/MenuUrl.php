<?php

namespace Encore\Admin;

class MenuUrl
{
    /**
     * Resolve a stored menu URI and its existing sidebar target convention.
     *
     * @param string|null $uri
     *
     * @return array{href: string|null, target: string|null}
     */
    public static function resolve($uri = ''): array
    {
        $uri = $uri ?? '';

        // Match the browser's scheme parsing without rewriting the stored text.
        $candidate = str_replace(["\t", "\n", "\r"], '', trim($uri, "\x00..\x20"));

        if (preg_match('/\A([a-z][a-z0-9+.-]*):/i', $candidate, $matches)) {
            if (!in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel', 'sms'], true)) {
                return ['href' => null, 'target' => null];
            }

            return ['href' => $uri, 'target' => '_blank'];
        }

        if (str_starts_with($candidate, '#') || str_starts_with($candidate, '//')) {
            return ['href' => $uri, 'target' => '_blank'];
        }

        return ['href' => admin_url($uri), 'target' => null];
    }
}
