<?php

declare(strict_types=1);

namespace App\Middleware;

class CorsMiddleware
{
    public static function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = trim($_ENV['ALLOWED_ORIGINS'] ?? '*');

        // Allow all origins
        if ($allowedOrigins === '*') {
            header('Access-Control-Allow-Origin: *');
        } else {
            $origins = array_map('trim', explode(',', $allowedOrigins));

            if ($origin !== '' && in_array($origin, $origins, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');

        // Browser CORS preflight
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}