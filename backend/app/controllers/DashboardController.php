<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/DashboardController.php — Home screen aggregate endpoint
 *
 * Routes handled:
 *   GET /dashboard
 *
 * Requirements covered: 9.1, 9.3, 9.4, 9.5
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\DashboardService;

class DashboardController
{
    /**
     * Summary cards, current BS period/cycle and the 10 most recent audit
     * entries — assembled by a single aggregate query (no N+1).
     *
     * The BS month name is localised using ?locale=, falling back to the
     * session preference and then English.
     */
    public static function index(array $params, array $body): never
    {
        $locale = (string) ($params['locale'] ?? $_SESSION['lang'] ?? 'en');

        if (!in_array($locale, ['en', 'ne'], true)) {
            $locale = 'en';
        }

        Response::success(DashboardService::summary($locale), 'Dashboard loaded.');
    }
}
