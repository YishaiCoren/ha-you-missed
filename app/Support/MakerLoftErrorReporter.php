<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a single exception report to the MakerLoft error-ingest webhook.
 *
 * Wired from bootstrap/app.php's withExceptions() closure. No-ops
 * silently when MAKERLOFT_ERROR_INGEST_URL or MAKERLOFT_ERROR_INGEST_SECRET
 * is unset, which is the case for any deploy outside the MakerLoft
 * orchestration path. Exceptions inside the reporter itself are
 * swallowed - we never want our error reporter to mask the original
 * exception.
 */
final class MakerLoftErrorReporter
{
    /**
     * Hard timeout for the outbound POST. The deployed app is a single
     * web component (no worker queue), so the call runs synchronously
     * inside the request lifecycle. 2 seconds is the budget we're
     * willing to add to a 5xx response on a slow network.
     */
    private const REQUEST_TIMEOUT_SECONDS = 2;

    /**
     * Truncate stack traces before sending to keep payloads compact and
     * inside the ingest controller's 1 MB cap.
     */
    private const STACK_TRACE_BYTES = 16384;

    public static function report(Throwable $e): void
    {
        $url = (string) env('MAKERLOFT_ERROR_INGEST_URL', '');
        $secret = (string) env('MAKERLOFT_ERROR_INGEST_SECRET', '');

        if ($url === '' || $secret === '') {
            return;
        }

        try {
            $payload = self::buildPayload($e);
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

            Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Makerloft-Signature' => $signature,
                    'X-Makerloft-Timestamp' => $timestamp,
                    // Delivery id drives the controller-side replay
                    // dedup so a network retry inside the deployed
                    // app's runtime doesn't write two rows.
                    'X-Makerloft-Delivery' => bin2hex(random_bytes(8)),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable $reporterError) {
            // Reporter must never displace the original exception.
            // Log locally and move on - the deployed app's exception
            // handler still surfaces the original to the user.
            try {
                Log::warning('makerloft.error_reporter.failed', [
                    'reason' => $reporterError->getMessage(),
                ]);
            } catch (Throwable) {
                // Logger itself broken (no driver, full disk). Silent
                // is the only safe choice.
            }
        }
    }

    /**
     * @return array{
     *     error_class: string,
     *     message: string,
     *     file: ?string,
     *     line: ?int,
     *     stack_trace: string,
     *     url: ?string,
     *     reported_at: string
     * }
     */
    private static function buildPayload(Throwable $e): array
    {
        $stack = (string) $e->getTraceAsString();
        if (strlen($stack) > self::STACK_TRACE_BYTES) {
            $stack = substr($stack, 0, self::STACK_TRACE_BYTES);
        }

        $url = null;
        if (function_exists('request')) {
            try {
                $url = request()?->fullUrl();
            } catch (Throwable) {
                // Bound to console / queue context where no request
                // exists. URL stays null.
            }
        }

        return [
            'error_class' => get_class($e),
            'message' => (string) $e->getMessage(),
            'file' => (string) $e->getFile(),
            'line' => (int) $e->getLine(),
            'stack_trace' => $stack,
            'url' => is_string($url) ? $url : null,
            'reported_at' => gmdate('c'),
        ];
    }
}
