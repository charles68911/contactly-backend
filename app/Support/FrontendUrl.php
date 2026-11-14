<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Append query parameters to a configured frontend URL.
     */
    public static function build(string $baseUrl, array $query): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
