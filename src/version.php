<?php
/**
 * Release identity, shown in the footer on every page.
 *
 * Bump BOTH values in the same commit that creates the git tag, so the footer always names a
 * real tag and the moment it shipped. RELEASED_AT is the tag timestamp in UTC (ISO 8601).
 */
declare(strict_types=1);

const APP_VERSION     = 'v0.1.0';
const APP_RELEASED_AT = '2026-08-17T22:30:00+00:00';

/** Footer string, e.g. "v0.1.0 · released Aug 17, 2026 10:30 PM UTC". */
function app_version_line(): string
{
    $ts = new DateTimeImmutable(APP_RELEASED_AT);
    return APP_VERSION . ' · released ' . $ts->format('M j, Y g:i A') . ' UTC';
}
