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

function smtpSend(array $settings, string $recipient, string $subject, string $body, ?string $html = null): void
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
        $message = 'From: <' . $settings['sender'] . ">\r\nTo: <" . $recipient . ">\r\nSubject: " . $subject . "\r\nMIME-Version: 1.0\r\n";
        if ($html === null) {
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $body;
        } else {
            $boundary = 'billing-' . bin2hex(random_bytes(12));
            $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
            $message .= "--{$boundary}--";
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $message));
        $send(implode("\r\n", array_map(static fn(string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, $lines)) . "\r\n.", [250]);
        $send('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function paymentEmailContent(array $payment): array
{
    $paid = (bool) $payment['paid'];
    $name = trim((string) $payment['name']);
    $title = trim((string) $payment['title']);
    $dueDate = paymentDateLabel($payment['month'], $payment['payment_day']);
    $amount = money((int) $payment['cents']);
    $subject = $paid ? 'Payment received' : 'Payment schedule reminder';
    $heading = $paid ? 'Payment received' : 'Your payment is coming up';
    $message = $paid
        ? 'Your payment has been marked paid. Thank you.'
        : 'Here are the details of your upcoming payment.';
    $plain = "Hello {$name},\n\n{$message}\n\nLoan: {$title}\nAmount: {$amount}\nPayment date: {$dueDate}\nStatus: " . ($paid ? 'Paid' : 'Unpaid') . "\n\nJohn Rey Simple Billing System";
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $nameHtml = $escape($name);
    $titleHtml = $escape($title);
    $dueHtml = $escape($dueDate);
    $amountHtml = $escape($amount);
    $statusHtml = $paid ? 'PAID' : 'UPCOMING';
    $html = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f5f4fa;color:#24223a;font-family:Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f4fa;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e4e0ee;border-radius:16px;overflow:hidden">'
        . '<tr><td style="background:#3d326d;padding:28px 32px;color:#ffffff">'
        . '<span style="display:inline-block;background:#6a55b4;border-radius:10px;padding:8px 11px;font-size:24px;vertical-align:middle">▤</span>'
        . '<span style="font-size:18px;font-weight:700;vertical-align:middle;padding-left:10px">John Rey Simple Billing System</span>'
        . '</td></tr><tr><td style="padding:36px 32px">'
        . '<p style="margin:0 0 10px;color:#705cae;font-size:12px;font-weight:700;letter-spacing:2px">PAYMENT UPDATE</p>'
        . '<h1 style="margin:0 0 18px;font-size:30px;line-height:1.2;color:#24223a">' . $escape($heading) . '</h1>'
        . '<p style="margin:0 0 10px;font-size:16px;line-height:1.6">Hello ' . $nameHtml . ',</p>'
        . '<p style="margin:0 0 26px;color:#56536b;font-size:16px;line-height:1.6">' . $escape($message) . '</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7f5fc;border:1px solid #e5e0f1;border-radius:12px">'
        . '<tr><td style="padding:22px 24px"><p style="margin:0 0 8px;color:#68627e;font-size:13px">' . $titleHtml . '</p>'
        . '<p style="margin:0 0 18px;color:#3d326d;font-size:30px;font-weight:700">' . $amountHtml . '</p>'
        . '<p style="margin:0;color:#56536b;font-size:14px">Payment date <strong style="color:#24223a">' . $dueHtml . '</strong></p>'
        . '<p style="margin:10px 0 0;color:#56536b;font-size:14px">Status <strong style="color:#3d326d">' . $statusHtml . '</strong></p>'
        . '</td></tr></table></td></tr><tr><td style="padding:20px 32px;background:#f0edf8;color:#625c76;font-size:12px;line-height:1.5">'
        . 'John Rey Simple Billing System<br>This message was sent about your payment schedule.'
        . '</td></tr></table></td></tr></table></body></html>';
    return [$subject, $plain, $html];
}

function sendManualPaymentNotice(int $loanId, string $month): void
{
    $settings = mailSettings();
    if (!$settings || !$settings['enabled']) {
        throw new DomainException('Enable email notifications in Email settings first.');
    }
    $payment = query('SELECT p.*,l.title,l.payment_day,c.name,c.email FROM payments p JOIN loans l ON l.id=p.loan JOIN clients c ON c.id=l.client WHERE p.loan=? AND p.month=? AND p.scheduled=1', [$loanId, $month])->fetch();
    if (!$payment) {
        throw new DomainException('This payment is no longer on the schedule.');
    }
    if (!$payment['email']) {
        throw new DomainException('Add an email address for this client first.');
    }
    [$subject, $plain, $html] = paymentEmailContent($payment);
    try {
        smtpSend($settings, $payment['email'], $subject, $plain, $html);
    } catch (RuntimeException $error) {
        error_log('Manual billing email failed: ' . $error->getMessage());
        throw new DomainException('The email could not be sent. Check the SMTP settings and try again.');
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
        $job['paid'] = $job['kind'] === 'paid';
        [$subject, $body, $html] = paymentEmailContent($job);
        try {
            smtpSend($settings, $job['recipient'], $subject, $body, $html);
            query('UPDATE mail_jobs SET state="sent" WHERE id=?', [$job['id']]);
            $sent++;
        } catch (Throwable $error) {
            error_log('Billing email delivery failed: ' . $error->getMessage());
            query('UPDATE mail_jobs SET attempts=attempts+1 WHERE id=?', [$job['id']]);
        }
    }
    return $sent;
}
