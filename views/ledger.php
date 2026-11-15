<section class="hero">
    <div class="eyebrow">
        Billing overview
    </div>
    <h1>Loan ledger</h1>
    <?php renderTotals(array_sum(array_column($loans, 'total')), array_sum(array_column($loans, 'received'))); ?>
</section>
<section class="sectionhead">
    <h2>
        Loans
        <span class="count"><?= count($loans) ?></span>
    </h2>
    <div class="actions">
        <a class="button" href="?clients">Manage clients</a>
        <a class="primary" href="?new">+ New loan</a>
    </div>
</section>
<div class="panel">
    <?php if (!$loans): ?>
        <div class="empty">
            <h3>No loans yet</h3>
            <p>
                Add a client to start their first payment schedule.
            </p>
            <a href="?clients">Add a client →</a>
        </div>
    <?php endif; ?>
    <?php foreach ($loans as $loanRow): ?>
        <a class="item loan" href="?loan=<?= $loanRow['id'] ?>">
            <div>
                <strong><?= escapeHtml($loanRow['title']) ?></strong>
                <small><?= escapeHtml($loanRow['name']) ?></small>
            </div>
            <div class="end">
                <strong><?= money($loanRow['total'] - $loanRow['received']) ?></strong>
                <small>
                    <?= $loanRow['total'] == $loanRow['received'] ? 'Paid in full' : 'Outstanding balance' ?>
                    ›
                </small>
            </div>
        </a>
    <?php endforeach; ?>
</div>
