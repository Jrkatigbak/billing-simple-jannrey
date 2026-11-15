<?php
declare(strict_types=1);

function saveLoanFromForm(): int
{
    $id = (int) field('id');
    $client = (int) field('client');
    $title = labelText(field('title'));
    $first = field('first');
    $day = firstPaymentDay($first);
    $schedule = paymentMonths($first, field('terms'));
    $cents = amount(field('amount'));
    if (!query('SELECT id FROM clients WHERE id=?', [$client])->fetchColumn()) {
        throw new DomainException('Add or select a client first.');
    }
    db()->beginTransaction();
    if ($id) {
        if (!query('SELECT id FROM loans WHERE id=?', [$id])->fetchColumn()) {
            throw new DomainException('Loan unavailable.');
        }
        query(
            'UPDATE payments SET scheduled=0, previous_day=(SELECT payment_day FROM loans WHERE id=?)
             WHERE loan=? AND scheduled=1 AND (month<? OR month>?)
             AND (paid=1 OR EXISTS (
                 SELECT 1 FROM payment_details d WHERE d.loan=payments.loan AND d.month=payments.month
                 AND (d.mime IS NOT NULL OR length(d.remarks)>0)
             ))',
            [$id, $id, $schedule[0], end($schedule)],
        );
        query('UPDATE loans SET client=?,title=?,payment_day=? WHERE id=?', [
            $client,
            $title,
            $day,
            $id,
        ]);
        query('DELETE FROM payments WHERE loan=? AND scheduled=1 AND (month<? OR month>?)', [
            $id,
            $schedule[0],
            end($schedule),
        ]);
    } else {
        query('INSERT INTO loans(client,title,token,payment_day) VALUES(?,?,?,?)', [
            $client,
            $title,
            bin2hex(random_bytes(32)),
            $day,
        ]);
        $id = (int) db()->lastInsertId();
    }
    foreach ($schedule as $m) {
        query(
            'INSERT INTO payments(loan,month,cents) VALUES(?,?,?) ON CONFLICT(loan,month) DO UPDATE SET scheduled=1, previous_day=NULL,
             cents=CASE WHEN payments.paid=1 THEN payments.cents ELSE excluded.cents END',
            [$id, $m, $cents],
        );
    }
    db()->commit();
    return $id;
}
