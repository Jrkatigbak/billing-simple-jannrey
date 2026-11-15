<?php
declare(strict_types=1);

function db(): PDO
{
    static $db;
    if ($db) {
        return $db;
    }
    $dir =
        getenv('BILLING_DATA_DIR') ?:
        (getenv('HOME') ?: sys_get_temp_dir()) .
            '/.local/share/billing-simple-' .
            substr(hash('sha256', __DIR__), 0, 12);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot open billing storage.');
    }
    $db = new PDO('sqlite:' . $dir . '/billing.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $db->exec("PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;
      CREATE TABLE IF NOT EXISTS login_attempts(
        client_key TEXT PRIMARY KEY, expires_at INTEGER NOT NULL, attempts INTEGER NOT NULL
      );
      CREATE TABLE IF NOT EXISTS admin(id INTEGER PRIMARY KEY CHECK(id=1), password TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS clients(id INTEGER PRIMARY KEY, name TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS loans(id INTEGER PRIMARY KEY, client INTEGER NOT NULL REFERENCES clients(id), title TEXT NOT NULL, token TEXT NOT NULL UNIQUE);
      CREATE TABLE IF NOT EXISTS payments(loan INTEGER NOT NULL REFERENCES loans(id), month TEXT NOT NULL, cents INTEGER NOT NULL, paid INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(loan,month));
      CREATE TABLE IF NOT EXISTS payment_details(
        loan INTEGER NOT NULL, month TEXT NOT NULL, remarks TEXT NOT NULL DEFAULT '', mime TEXT, proof BLOB,
        PRIMARY KEY(loan,month), FOREIGN KEY(loan,month) REFERENCES payments(loan,month) ON DELETE CASCADE);");
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 0) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 0) {
                $db->exec(
                    'ALTER TABLE loans ADD COLUMN payment_day INTEGER CHECK(payment_day BETWEEN 1 AND 31)'
                );
                $db->exec('PRAGMA user_version=1');
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }
    return $db;
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function ledger(string $where = '', array $params = []): array
{
    return query(
        "SELECT l.*, c.name, min(p.month) first, max(p.month) last, count(*) terms,
        sum(p.cents) total, sum(p.cents*p.paid) received
        FROM loans l JOIN clients c ON l.client=c.id JOIN payments p ON p.loan=l.id
        $where GROUP BY l.id ORDER BY l.id DESC",
        $params
    )->fetchAll();
}

function paymentMonths(string $firstDate, string $term): array
{
    if (
        !preg_match('/^[0-9]{1,3}$/D', $term) ||
        (int) $term < 1 ||
        (int) $term > 600
    ) {
        throw new DomainException(
            'Enter a payment term of 1 to 600 whole months.'
        );
    }
    $month = new DateTimeImmutable(substr($firstDate, 0, 7) . '-01');
    $months = [];
    for ($i = 0; $i < (int) $term; $i++) {
        if ((int) $month->format('Y') > 9999) {
            throw new DomainException(
                'The last payment must fall within year 9999.'
            );
        }
        $months[] = $month->format('Y-m');
        $month = $month->modify('+1 month');
    }
    return $months;
}

function amount(string $input): int
{
    if (!preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', $input, $parts)) {
        throw new DomainException(
            'Enter a monthly amount with up to two decimal places.'
        );
    }
    $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    if (!$cents) {
        throw new DomainException('Enter a positive monthly amount.');
    }
    return $cents;
}

function labelText(string $name): string
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 240) {
        throw new DomainException('A name of up to 240 bytes is required.');
    }
    return $name;
}

function escapeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $cents): string
{
    return '₱' . number_format($cents / 100, 2);
}

function monthName(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->format('F Y');
}

function firstPaymentDay(string $date): int
{
    if (
        !preg_match(
            '/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D',
            $date,
            $parts
        ) ||
        !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
    ) {
        throw new DomainException('Choose a valid first payment date.');
    }
    return (int) $parts[3];
}

function dueDate(string $month, int $day): string
{
    $monthStart = new DateTimeImmutable($month . '-01');
    return sprintf(
        '%s-%02d',
        $month,
        min($day, (int) $monthStart->format('t'))
    );
}

function paymentDateLabel(string $month, ?int $day): string
{
    if ($day === null) {
        return monthName($month);
    }
    return (new DateTimeImmutable(dueDate($month, $day)))->format('F j, Y');
}

function savePaymentDetails(
    int $loan,
    string $month,
    string $remarks,
    ?array $file,
    bool $remove
): void {
    $remarks = trim($remarks);
    if (strlen($remarks) > 8000) {
        throw new DomainException('Please shorten the payment remarks.');
    }
    $mime = null;
    $bytes = null;
    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        if (
            $file['error'] !== UPLOAD_ERR_OK ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new DomainException(
                'Upload failed. Choose the file again and retry.'
            );
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            throw new DomainException('Choose a proof file of 2 MB or less.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (
            !in_array(
                $mime,
                ['application/pdf', 'image/png', 'image/jpeg'],
                true
            )
        ) {
            throw new DomainException('Choose a PDF, PNG or JPEG file.');
        }
        $bytes = file_get_contents($file['tmp_name']);
        if ($bytes === false) {
            throw new DomainException('Unable to read the proof file.');
        }
    }
    $changeFile = $remove || $bytes !== null;
    $result = query(
        'INSERT INTO payment_details(loan,month,remarks,mime,proof)
           SELECT loan,month,?,?,CAST(? AS BLOB) FROM payments WHERE loan=? AND month=?
           ON CONFLICT(loan,month) DO UPDATE SET remarks=excluded.remarks,
           mime=CASE WHEN CAST(? AS INTEGER)=1 THEN excluded.mime ELSE payment_details.mime END,
           proof=CASE WHEN CAST(? AS INTEGER)=1 THEN excluded.proof ELSE payment_details.proof END',
        [
            $remarks,
            $mime,
            $bytes,
            $loan,
            $month,
            (int) $changeFile,
            (int) $changeFile
        ]
    );
    if (!$result->rowCount()) {
        throw new DomainException(
            'This installment is no longer on the schedule.'
        );
    }
}
