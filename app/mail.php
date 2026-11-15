<?php
declare(strict_types=1);

function customerEmail(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $value)) {
        throw new DomainException('Enter a valid customer email address.');
    }
    return $value;
}

function mailSettings(): ?array
{
    return query('SELECT * FROM mail_settings WHERE id=1')->fetch() ?: null;
}

function saveMailSettings(array $input): void
{
    $host = trim((string) ($input['host'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $sender = customerEmail((string) ($input['sender'] ?? ''));
    $port = filter_var($input['port'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $days = filter_var($input['reminder_days'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
    if (!preg_match('/^[a-z0-9.-]{1,253}$/iD', $host) || !$port || !$sender || $days === false || strlen($username) > 254) {
        throw new DomainException('Enter a valid SMTP host, port, sender and reminder days.');
    }
    if ($password === '') {
        $password = mailSettings()['password'] ?? '';
    }
    if ($username !== '' && $password === '') {
        throw new DomainException('Enter the SMTP password.');
    }
    query('INSERT INTO mail_settings(id,host,port,username,password,sender,reminder_days,enabled) VALUES(1,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET host=excluded.host,port=excluded.port,username=excluded.username,password=excluded.password,sender=excluded.sender,reminder_days=excluded.reminder_days,enabled=excluded.enabled', [
        $host, $port, $username, $password, $sender, $days, !empty($input['enabled']) ? 1 : 0,
    ]);
}

function queueDueReminders(): void
{
    $settings = mailSettings();
    if (!$settings || !$settings['enabled']) {
        return;
    }
    $today = new DateTimeImmutable('today');
    $target = $today->modify('+' . $settings['reminder_days'] . ' days')->format('Y-m-d');
    foreach (query('SELECT p.loan,p.month,l.payment_day,c.email FROM payments p JOIN loans l ON l.id=p.loan JOIN clients c ON c.id=l.client WHERE p.scheduled=1 AND p.paid=0 AND c.email IS NOT NULL')->fetchAll() as $row) {
        if ($row['payment_day'] === null || dueDate($row['month'], (int) $row['payment_day']) !== $target) {
            continue;
        }
        query('INSERT OR IGNORE INTO mail_jobs(loan,month,kind,due_date,recipient) VALUES(?,?,?,?,?)', [
            $row['loan'], $row['month'], 'reminder', $target, $row['email'],
        ]);
    }
}

function smtpSend(array $settings, string $recipient, string $subject, string $body): void
{
    $host = $settings['host'];
    $port = (int) $settings['port'];
    $address = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($address, $errorCode, $errorText, 10, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]));
    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errorText);
    }
    stream_set_timeout($socket, 10);
    $read = static function (array $allowed) use ($socket): void {
        do {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new RuntimeException('SMTP connection closed.');
            }
        } while (isset($line[3]) && $line[3] === '-');
        if (!in_array((int) substr($line, 0, 3), $allowed, true)) {
            throw new RuntimeException('SMTP server rejected the message.');
        }
    };
    $send = static function (string $command, array $allowed) use ($socket, $read): void {
        fwrite($socket, $command . "\r\n");
        $read($allowed);
    };
    try {
        $read([220]);
        $send('EHLO localhost', [250]);
        if ($port !== 465) {
            $send('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed.');
            }
            $send('EHLO localhost', [250]);
        }
        if ($settings['username'] !== '') {
            $send('AUTH LOGIN', [334]);
            $send(base64_encode($settings['username']), [334]);
            $send(base64_encode($settings['password']), [235]);
        }
        $send('MAIL FROM:<' . $settings['sender'] . '>', [250]);
        $send('RCPT TO:<' . $recipient . '>', [250, 251]);
        $send('DATA', [354]);
        $message = 'From: <' . $settings['sender'] . ">\r\nTo: <" . $recipient . ">\r\nSubject: " . $subject . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $body;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $message));
        $send(implode("\r\n", array_map(static fn(string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, $lines)) . "\r\n.", [250]);
        $send('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function deliverMailJobs(?int $jobId = null): int
{
    $settings = mailSettings();
    if (!$settings || !$settings['enabled']) {
        return 0;
    }
    $sent = 0;
    foreach (query('SELECT j.*,l.title,c.name,p.cents,p.paid,p.scheduled,l.payment_day FROM mail_jobs j JOIN loans l ON l.id=j.loan JOIN clients c ON c.id=l.client JOIN payments p ON p.loan=j.loan AND p.month=j.month WHERE j.state="pending" AND j.attempts<5 AND (? IS NULL OR j.id=?) ORDER BY j.id LIMIT 50', [$jobId, $jobId])->fetchAll() as $job) {
        if ($job['kind'] === 'reminder' && ($job['paid'] || !$job['scheduled'] || dueDate($job['month'], (int) $job['payment_day']) !== $job['due_date'])) {
            query('UPDATE mail_jobs SET state="cancelled" WHERE id=?', [$job['id']]);
            continue;
        }
        $due = paymentDateLabel($job['month'], $job['payment_day']);
        $subject = $job['kind'] === 'paid' ? 'Payment received' : 'Upcoming payment reminder';
        $body = $job['kind'] === 'paid'
            ? "Hello {$job['name']},\n\nYour payment of " . money((int) $job['cents']) . " for {$job['title']} due {$due} has been marked paid.\n\nJohn Rey Simple Billing System"
            : "Hello {$job['name']},\n\nYour payment of " . money((int) $job['cents']) . " for {$job['title']} is due {$due}.\n\nJohn Rey Simple Billing System";
        try {
            smtpSend($settings, $job['recipient'], $subject, $body);
            query('UPDATE mail_jobs SET state="sent" WHERE id=?', [$job['id']]);
            $sent++;
        } catch (Throwable $error) {
            error_log('Billing email delivery failed: ' . $error->getMessage());
            query('UPDATE mail_jobs SET attempts=attempts+1 WHERE id=?', [$job['id']]);
        }
    }
    return $sent;
}
