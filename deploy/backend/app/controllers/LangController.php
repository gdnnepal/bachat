<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/LangController.php — Language file delivery and preference
 *
 * Routes handled:
 *   GET  /lang/{locale}     (public — the login screen needs it before auth)
 *   POST /lang/preference   (authenticated)
 *
 * Requirements covered: 14.3, 14.4, 14.5
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;

class LangController
{
    /** Locales the cooperative ships translations for. */
    private const SUPPORTED = ['en', 'ne'];

    /** Locale used when an unknown one is requested (Req 14.5). */
    private const FALLBACK = 'en';

    // =========================================================================
    // GET /lang/{locale}
    // =========================================================================

    /**
     * Return the full translation map for a locale. Unknown locales silently
     * fall back to English rather than erroring, so a stale client preference
     * can never lock a user out of the UI.
     *
     * Non-English locales are merged over the English map so any key missing
     * from the translation resolves to its English value instead of a blank.
     */
    public static function getLocale(array $params, array $body): never
    {
        $requested = strtolower(trim((string) ($params['locale'] ?? '')));
        $locale    = in_array($requested, self::SUPPORTED, true) ? $requested : self::FALLBACK;

        $english = self::loadFile(self::FALLBACK);

        if ($english === null) {
            Response::error('INTERNAL_ERROR', 'The English language file is missing or invalid.', [], 500);
        }

        $translations = $english;

        if ($locale !== self::FALLBACK) {
            $localised = self::loadFile($locale);
            if ($localised !== null) {
                // English keys stay as the fallback for anything untranslated.
                $translations = array_merge($english, array_filter(
                    $localised,
                    static fn ($v): bool => is_string($v) && trim($v) !== ''
                ));
            }
        }

        Response::success(
            [
                'locale'       => $locale,
                'fallback'     => self::FALLBACK,
                'requested'    => $requested,
                'translations' => $translations,
            ],
            'Language file loaded.'
        );
    }

    // =========================================================================
    // POST /lang/preference
    // =========================================================================

    /**
     * Persist the admin's chosen locale on the session (Req 14.4).
     * An unknown or absent locale resets the preference to English.
     */
    public static function setPreference(array $params, array $body): never
    {
        $requested = strtolower(trim((string) ($body['locale'] ?? '')));

        if ($requested !== '' && !in_array($requested, self::SUPPORTED, true)) {
            Response::error(
                'VALIDATION_ERROR',
                'Unsupported locale.',
                ['locale' => 'Supported locales are: ' . implode(', ', self::SUPPORTED) . '.'],
                422
            );
        }

        $locale = $requested === '' ? self::FALLBACK : $requested;
        $_SESSION['lang'] = $locale;

        Response::success(['locale' => $locale], 'Language preference saved.');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Read and decode one lang file. Returns null when the file is absent or
     * is not a flat key → string map.
     *
     * @return array<string, string>|null
     */
    private static function loadFile(string $locale): ?array
    {
        $dir  = defined('LANG_PATH') ? LANG_PATH : dirname(__DIR__, 2) . '/lang';
        $path = $dir . '/' . $locale . '.json';

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
