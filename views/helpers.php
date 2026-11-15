<?php
declare(strict_types=1);

function renderFormFields(string $operation): void
{
    global $csrf;
    ?>
    <input type="hidden" name="csrf" value="<?= escapeHtml($csrf) ?>">
    <input type="hidden" name="op" value="<?= escapeHtml($operation) ?>">
    <?php
}

function renderTotals(int $total, int $received): void
{
    $totals = ['Total amount' => $total, 'Amount paid' => $received, ($received > $total ? 'Credit balance' : 'Balance') => abs($total - $received)];
    ?>
    <div class="stats">
        <?php foreach ($totals as $label => $cents): ?>
            <div>
                <span><?= escapeHtml($label) ?></span>
                <strong><?= money($cents) ?></strong>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
