<?php
/**
 * Local redirect safety (Execution 63).
 *
 * Deep links must survive the sign-in and email-verification round trips, but a redirect
 * target taken from the request is an open-redirect risk if it can name another host. Every
 * place that honours a `next` parameter funnels through here: only a single-slash local path
 * is accepted, so `//evil.example`, `https://evil.example` and a scheme-relative value are
 * all refused and the caller falls back to its own default.
 */

if (!function_exists('safe_local_path')) {
    function safe_local_path($value): string {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($value === '' || $value[0] !== '/') return '';
        if (strpos($value, '//') === 0) return '';
        if (strpos($value, '\\') !== false) return '';
        // Allow only the characters a real local path/query uses; reject anything with a
        // control character, a space, or a scheme-like prefix.
        if (!preg_match('~^/[A-Za-z0-9._/?=&%+:#-]*$~', $value)) return '';
        return $value;
    }
}
