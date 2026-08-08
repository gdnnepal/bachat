<?php

/**
 * VCMS — Village Cooperative Management System
 * app/helpers/Response.php — JSON response builder
 *
 * All public methods are declared `never` because they set headers, emit JSON,
 * and terminate the process via exit().  They must not return.
 */

declare(strict_types=1);

namespace App\Helpers;

class Response
{
    // JSON encoding flags — preserve Nepali Unicode text and forward-slashes
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Emit a successful JSON response and exit.
     *
     * Envelope:
     * {
     *   "success": true,
     *   "data":    <mixed>,
     *   "message": "<string>"
     * }
     *
     * @param mixed  $data     Payload to include under the "data" key.
     * @param string $message  Human-readable success message.
     * @param int    $httpCode HTTP status code (default 200).
     */
    public static function success(
        mixed $data = null,
        string $message = '',
        int $httpCode = 200
    ): never {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => true,
                'data'    => $data,
                'message' => $message,
            ],
            self::JSON_FLAGS
        );

        exit;
    }

    /**
     * Emit an error JSON response and exit.
     *
     * Envelope:
     * {
     *   "success": false,
     *   "error": {
     *     "code":    "<string>",
     *     "message": "<string>",
     *     "fields":  { "<field>": "<reason>", ... }
     *   }
     * }
     *
     * @param string   $code     Machine-readable error code (e.g. "VALIDATION_ERROR").
     * @param string   $message  Human-readable error description.
     * @param array    $fields   Field-level validation messages keyed by field name.
     * @param int      $httpCode HTTP status code (default 400).
     */
    public static function error(
        string $code,
        string $message,
        array $fields = [],
        int $httpCode = 400
    ): never {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => false,
                'error'   => [
                    'code'    => $code,
                    'message' => $message,
                    'fields'  => $fields,
                ],
            ],
            self::JSON_FLAGS
        );

        exit;
    }

    /**
     * Emit a paginated JSON response and exit.
     *
     * Envelope:
     * {
     *   "success":    true,
     *   "data":       <mixed>,
     *   "message":    "<string>",
     *   "pagination": {
     *     "total":       <int>,
     *     "page":        <int>,
     *     "per_page":    <int>,
     *     "total_pages": <int>
     *   }
     * }
     *
     * @param mixed  $data     The current page of items.
     * @param int    $total    Total number of records across all pages.
     * @param int    $page     Current page number (1-based).
     * @param int    $perPage  Number of records per page.
     * @param string $message  Optional human-readable message.
     */
    public static function paginated(
        mixed $data,
        int $total,
        int $page,
        int $perPage,
        string $message = ''
    ): never {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

        echo json_encode(
            [
                'success'    => true,
                'data'       => $data,
                'message'    => $message,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total_pages' => $totalPages,
                ],
            ],
            self::JSON_FLAGS
        );

        exit;
    }
}
