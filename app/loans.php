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
        foreach (
            query(
                'SELECT p.month FROM payments p LEFT JOIN payment_details d USING(loan,month) WHERE p.loan=? AND (p.paid=1 OR d.mime IS NOT NULL OR length(d.remarks)>0)',
                [$id],
            )
            as $row
        ) {
            if (!in_array($row['month'], $schedule, true)) {
                throw new DomainException(
                    'The term must include months with a paid installment, proof or remarks.',
                );
            }
        }
        query('UPDATE loans SET client=?,title=?,payment_day=? WHERE id=?', [
            $client,
            $title,
            $day,
            $id,
        ]);
        query('DELETE FROM payments WHERE loan=? AND (month<? OR month>?)', [
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
            'INSERT INTO payments(loan,month,cents) VALUES(?,?,?) ON CONFLICT(loan,month) DO UPDATE SET cents=excluded.cents WHERE payments.paid=0',
            [$id, $m, $cents],
        );
    }
    db()->commit();
    return $id;
}
