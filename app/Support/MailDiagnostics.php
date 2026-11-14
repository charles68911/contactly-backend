<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class MailDiagnostics
{
    /**
     * Determine whether the configured mailer only writes to a log or array.
     */
    public static function isNonDeliveringMailer(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Log a mail failure without leaking credentials, tokens, or links.
     *
     * SMTP exceptions can include host/port details, but we still defensively
     * redact the configured username and password in case a transport echoes
     * them back. Never pass plaintext authorization tokens in $context.
     */
    public static function logFailure(Throwable $exception, array $context = []): void
    {
        $message = $exception->getMessage();

        $secrets = array_filter(
            [
                (string) config('mail.mailers.smtp.password'),
                (string) config('mail.mailers.smtp.username'),
            ],
            fn (string $value): bool => $value !== '' && $value !== 'null'
        );

        if ($secrets !== []) {
            $message = str_replace($secrets, '[redacted]', $message);
        }

        Log::error('Mail delivery failed.', array_merge([
            'exception' => $exception::class,
            'message' => $message,
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
        ], $context));
    }
}
