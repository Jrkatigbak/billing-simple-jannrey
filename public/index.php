<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require dirname(__DIR__) . '/core.php';
require dirname(__DIR__) . '/app/security.php';
require dirname(__DIR__) . '/app/loans.php';
require dirname(__DIR__) . '/app/mail.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header(
    "Content-Security-Policy: default-src 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'",
);
$public = array_key_exists('s', $_GET);
$loan = null;
$admin = false;
$error = '';
$notice = '';
$csrf = '';
function redirectTo(string $q = ''): never
{
    header('Location: ./' . $q, true, 303);
    exit();
}
function field(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}
if ($public) {
    $loan = ledger('WHERE l.token=?', [is_string($_GET['s']) ? $_GET['s'] : ''])[0] ?? null;
    if (!$loan) {
        http_response_code(404);
    }
    if ($loan && isset($_GET['verify']) && is_string($_GET['verify'])) {
        $verification = query('SELECT * FROM email_verifications WHERE client=?', [$loan['client']])->fetch();
        if ($verification && $verification['expires_at'] >= time() && hash_equals($verification['token_hash'], hash('sha256', $_GET['verify']))) {
            db()->beginTransaction();
            query('UPDATE clients SET email=? WHERE id=?', [$verification['email'], $loan['client']]);
            query('UPDATE mail_jobs SET state="cancelled" WHERE loan IN (SELECT id FROM loans WHERE client=?) AND state="pending" AND recipient<>?', [$loan['client'], $verification['email']]);
            query('DELETE FROM email_verifications WHERE client=?', [$loan['client']]);
            db()->commit();
            $notice = 'Your notification email is confirmed.';
        } else {
            $error = 'This confirmation link has expired or was already used.';
        }
    }
    if ($loan && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $settings = mailSettings();
            if (!$settings || !$settings['enabled']) {
                throw new DomainException('Email notifications are unavailable.');
            }
            $email = customerEmail(field('email'));
            if ($email === null) {
                throw new DomainException('Enter your email address.');
            }
            $token = bin2hex(random_bytes(32));
            query('INSERT INTO email_verifications(client,email,token_hash,expires_at) VALUES(?,?,?,?) ON CONFLICT(client) DO UPDATE SET email=excluded.email,token_hash=excluded.token_hash,expires_at=excluded.expires_at', [
                $loan['client'], $email, hash('sha256', $token), time() + 86400,
            ]);
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/iD', $host)) {
                throw new DomainException('Unable to create a confirmation link.');
            }
            $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            $link = $scheme . '://' . $host . $path . '?s=' . rawurlencode($loan['token']) . '&verify=' . $token;
            smtpSend($settings, $email, 'Confirm billing notification email', "Open this link to receive billing notifications:\n\n" . $link . "\n\nThis link expires in 24 hours.");
            $notice = 'Check your email to confirm the address.';
        } catch (Throwable $exception) {
            error_log('Billing email confirmation failed: ' . $exception->getMessage());
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'Unable to send confirmation email. Please try again.';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit();
    }
} else {
    session_name('billing_' . substr(hash('sha256', __DIR__), 0, 12));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    $hash = query('SELECT password FROM admin WHERE id=1')->fetchColumn();
    $admin = $hash && hash_equals(hash('sha256', $hash), $_SESSION['auth'] ?? '');
    $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!hash_equals($csrf, field('csrf'))) {
                http_response_code(403);
                throw new DomainException('Refresh the page and try again.');
            }
            $operation = field('op');
            if ($operation === 'login') {
                $clientKey = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
                reserveLoginAttempt($clientKey);
                if (!$hash || !password_verify(field('password'), $hash)) {
                    throw new DomainException('Unable to sign in. Check your admin password.');
                }
                query('DELETE FROM login_attempts WHERE client_key=?', [$clientKey]);
                session_regenerate_id(true);
                $_SESSION = ['auth' => hash('sha256', $hash)];
                redirectTo();
            }
            if (!$admin) {
                http_response_code(403);
                exit();
            }
            if ($operation === 'logout') {
                session_destroy();
                redirectTo();
            }
            if ($operation === 'client') {
                query('INSERT INTO clients(name,email) VALUES(?,?)', [labelText(field('name')), customerEmail(field('email'))]);
                redirectTo('?clients');
            }
            if ($operation === 'client_email') {
                $email = customerEmail(field('email'));
                query('UPDATE clients SET email=? WHERE id=?', [$email, (int) field('id')]);
                query('UPDATE mail_jobs SET state="cancelled" WHERE loan IN (SELECT id FROM loans WHERE client=?) AND state="pending" AND (recipient<>? OR ? IS NULL)', [(int) field('id'), $email, $email]);
                redirectTo('?clients');
            }
            if ($operation === 'mail_settings') {
                saveMailSettings($_POST);
                redirectTo('?settings&saved=1');
            }
            if ($operation === 'details') {
                $id = (int) field('id');
                savePaymentDetails(
                    $id,
                    field('month'),
                    field('remarks'),
                    $_FILES['proof'] ?? null,
                    field('remove') === '1',
                );
                redirectTo('?loan=' . $id . '#p-' . rawurlencode(field('month')));
            }
            if ($operation === 'payment') {
                $newPaid = (int) (field('paid') === '1');
                $changed = query('UPDATE payments SET paid=? WHERE loan=? AND month=? AND paid<>?', [
                    $newPaid,
                    (int) field('id'),
                    field('month'),
                    $newPaid,
                ]);
                if ($changed->rowCount() && $newPaid) {
                    query('INSERT OR IGNORE INTO mail_jobs(loan,month,kind,due_date,recipient) SELECT p.loan,p.month,?,p.month,c.email FROM payments p JOIN loans l ON l.id=p.loan JOIN clients c ON c.id=l.client JOIN mail_settings s ON s.id=1 AND s.enabled=1 WHERE p.loan=? AND p.month=? AND c.email IS NOT NULL', ['paid', (int) field('id'), field('month')]);
                    $mailJobId = query('SELECT id FROM mail_jobs WHERE loan=? AND month=? AND kind="paid" AND state="pending"', [(int) field('id'), field('month')])->fetchColumn();
                    if ($mailJobId) {
                        deliverMailJobs((int) $mailJobId);
                    }
                }
                if ($changed->rowCount() && !$newPaid) {
                    query('DELETE FROM mail_jobs WHERE loan=? AND month=? AND kind="paid"', [(int) field('id'), field('month')]);
                }
                redirectTo('?loan=' . (int) field('id'));
            }
            if ($operation === 'save') {
                $id = saveLoanFromForm();
                redirectTo('?loan=' . $id);
            }
        } catch (DomainException $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = $ex->getMessage();
        } catch (PDOException $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = 'Unable to save. Please try again.';
        }
    }
    if ($admin && isset($_GET['loan'])) {
        $loan = ledger('WHERE l.id=?', [(int) $_GET['loan']])[0] ?? null;
        if (!$loan) {
            http_response_code(404);
            $error = 'Loan unavailable.';
        }
    }
}
if (isset($_GET['proof'])) {
    $month = is_string($_GET['proof']) ? $_GET['proof'] : '';
    $file = $loan
        ? query('SELECT proof,mime FROM payment_details WHERE loan=? AND month=? AND mime IS NOT NULL', [
            $loan['id'],
            $month,
        ])->fetch()
        : null;
    if (!$file) {
        http_response_code(404);
        exit();
    }
    $ext = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'application/pdf' => 'pdf',
    ][$file['mime']];
    header('Content-Type: ' . $file['mime']);
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    $disposition = isset($_GET['preview']) ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="payment-' . $month . '.' . $ext . '"');
    echo $file['proof'];
    exit();
}
require dirname(__DIR__) . '/views/helpers.php';
if ($public && !$loan):
    $view = 'unavailable.php';
elseif (!$public && !$admin):
    $view = 'login.php';
elseif ($admin && (isset($_GET['new']) || ($loan && isset($_GET['edit'])))):
    $clients = query('SELECT * FROM clients ORDER BY name,id')->fetchAll();
    $defaultAmount = '';
    if ($loan) {
        $defaultAmount = number_format(
            (int) query('SELECT cents FROM payments WHERE loan=? AND scheduled=1 ORDER BY paid,month LIMIT 1', [
                $loan['id'],
            ])->fetchColumn() / 100,
            2,
            '.',
            '',
        );
    }
    $formValue = fn($key, $default) => escapeHtml($error && isset($_POST[$key]) ? field($key) : $default);
    $selectedClient = $error ? field('client') : ($loan['client'] ?? $_GET['client'] ?? 0);
    $firstDate = date('Y-m-d');
    if ($loan) {
        $firstDate = $loan['payment_day'] ? dueDate($loan['first'], $loan['payment_day']) : '';
    }
    $view = 'loan-form.php';
elseif ($loan):
    $payments = query(
        'SELECT p.*, d.mime, d.remarks FROM payments p LEFT JOIN payment_details d USING(loan,month) WHERE p.loan=? AND p.scheduled=1 ORDER BY p.month',
        [$loan['id']],
    )->fetchAll();
    $view = 'statement.php';
elseif (isset($_GET['clients'])):
    $clients = query(
        'SELECT c.*,count(l.id) loans FROM clients c LEFT JOIN loans l ON l.client=c.id GROUP BY c.id ORDER BY c.name,c.id',
    )->fetchAll();
    $view = 'clients.php';
elseif ($admin && isset($_GET['settings'])):
    $mailSettings = mailSettings();
    $view = 'settings.php';
else:
    $loans = ledger();
    $view = 'ledger.php';
endif;
require dirname(__DIR__) . '/views/layout.php';
