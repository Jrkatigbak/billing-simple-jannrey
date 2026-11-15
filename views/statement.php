<?php if ($admin): ?>
    <a class="back" href="./">← Loan ledger</a>
<?php endif; ?>
<section class="hero">
    <div class="eyebrow">
        Billing details
    </div>
    <h1><?= escapeHtml($loan['name']) ?></h1>
    <p><?= escapeHtml($loan['title']) ?></p>
    <?php renderTotals($loan['total'], $loan['received']); ?>
</section>
<section class="sectionhead">
    <div>
        <h2>Payment schedule</h2>
        <p class="quiet">
            <?= $loan['terms'] ?>
            monthly
            <?= $loan['terms'] == 1 ? 'payment' : 'payments' ?>
            ·
            <?= paymentDateLabel($loan['first'], $loan['payment_day']) ?>
            –
            <?= paymentDateLabel($loan['last'], $loan['payment_day']) ?>
        </p>
    </div>
    <?php if ($admin): ?>
        <div class="actions">
            <a class="button" href="?loan=<?= $loan['id'] ?>&amp;edit">Edit loan</a>
            <button class="primary" id="share" data-link="?s=<?= escapeHtml($loan['token']) ?>">Share link</button>
        </div>
    <?php endif; ?>
</section>
<?php if ($admin && $loan['payment_day'] === null): ?>
    <p class="quiet">
        Edit this loan to set its monthly payment day.
    </p>
<?php endif; ?>
<div id="sharebox" hidden>
    <label>
        Borrower link
        <input readonly>
    </label>
    <a target="_blank" rel="noreferrer">Open statement ↗</a>
    <span aria-live="polite">
    </span>
</div>
<div class="panel tablebox">
    <table>
        <thead>
            <tr>
                <th>Payment</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Proof of payment</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payments as $index => $payment): ?>
                <tr id="p-<?= escapeHtml($payment['month']) ?>">
                    <td>
                        <strong>
                            Payment <?= $index + 1 ?>
                        </strong>
                        <small><?= paymentDateLabel($payment['month'], $loan['payment_day']) ?></small>
                    </td>
                    <td><?= money($payment['cents']) ?></td>
                    <td>
                        <?php $status = $payment['paid'] ? 'Paid' : 'Unpaid'; ?>
                        <?php if ($admin): ?>
                            <form method="post">
                                <?php renderFormFields('payment'); ?>
                                <input type="hidden" name="id" value="<?= $loan['id'] ?>">
                                <input type="hidden" name="month" value="<?= escapeHtml($payment['month']) ?>">
                                <input type="hidden" name="paid" value="<?= $payment['paid'] ? 0 : 1 ?>">
                                <button class="pill p<?= $payment['paid'] ?>" aria-label="Mark Payment <?= $index + 1 ?> as <?= $payment['paid'] ? 'unpaid' : 'paid' ?>" title="Mark as <?= $payment['paid'] ? 'unpaid' : 'paid' ?>">
                                    <?= $status ?>
                                    ⇄
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="pill p<?= $payment['paid'] ?>"><?= $status ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="proof-cell">
                        <?php if ($payment['mime']):
                            $proofUrl =
                                '?' .
                                http_build_query(
                                    ($public ? ['s' => $loan['token']] : ['loan' => $loan['id']]) + [
                                        'proof' => $payment['month']
                                    ]
                                ); ?>
                            <div class="proof-actions">
                                <a class="proof-button" href="<?= escapeHtml($proofUrl . '&preview=1') ?>" target="_blank" rel="noopener" <?= $payment['mime'] === 'application/pdf' ? '' : 'data-image-proof' ?> data-payment="Payment <?= $index + 1 ?>">View proof</a>
                            </div>
                        <?php
                        endif; ?>
                        <?php if (strlen($payment['remarks'] ?? '')): ?>
                            <small class="remarks"><?= escapeHtml($payment['remarks']) ?></small>
                        <?php elseif (!$payment['mime'] && !$admin): ?>
                            —
                        <?php endif; ?>
                        <?php if ($admin):
                            $retry = $error && field('op') === 'details' && field('month') === $payment['month']; ?>
                            <details <?= $retry ? 'open' : '' ?>>
                                <summary>
                                    <?= $payment['mime'] || strlen($payment['remarks'] ?? '') ? 'Edit' : 'Add' ?>
                                    proof or remarks
                                </summary>
                                <form method="post" enctype="multipart/form-data">
                                    <?php renderFormFields('details'); ?>
                                    <input type="hidden" name="id" value="<?= $loan['id'] ?>">
                                    <input type="hidden" name="month" value="<?= escapeHtml($payment['month']) ?>">
                                    <label>
                                        Proof file (optional)
                                        <input type="file" name="proof" accept="image/jpeg,image/png,application/pdf">
                                    </label>
                                    <small>JPEG, PNG or PDF · Up to 2 MB</small>
                                    <?php if ($payment['mime']): ?>
                                        <label class="remove-proof">
                                            <input type="checkbox" name="remove" value="1">
                                            Remove current proof
                                        </label>
                                    <?php endif; ?>
                                    <label>
                                        Payment remarks (optional)
                                        <textarea name="remarks" rows="3" maxlength="2000"><?= escapeHtml($retry ? field('remarks') : $payment['remarks'] ?? '') ?></textarea>
                                    </label>
                                    <button>Save details</button>
                                </form>
                            </details>
                        <?php
                        endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
