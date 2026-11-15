<?php
declare(strict_types=1);

function reserveLoginAttempt(string $clientKey): void
{
    $now = time();
    $windowSeconds = 900;
    $maxAttempts = 10;
    query('DELETE FROM login_attempts WHERE expires_at <= ?', [$now]);
    $attempt = query(
        'INSERT INTO login_attempts(client_key, expires_at, attempts)
         VALUES (?, ?, 1)
         ON CONFLICT(client_key) DO UPDATE SET attempts = attempts + 1
         WHERE attempts < CAST(? AS INTEGER)',
        [$clientKey, $now + $windowSeconds, $maxAttempts]
    );
    if ($attempt->rowCount() === 0) {
        http_response_code(429);
        throw new DomainException(
            'Too many sign-in attempts. Try again in 15 minutes.'
        );
    }
}
