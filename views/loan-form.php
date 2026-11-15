<a class="back" href="<?= $loan ? '?loan=' . $loan['id'] : './' ?>">← Back</a>
<section class="panel editor">
    <h1><?= $loan ? 'Edit loan' : 'New loan' ?></h1>
    <?php if (!$clients): ?>
        <p>
            Add a borrower before creating their loan.
        </p>
        <a class="primary" href="?clients">Add client</a>
    <?php else: ?>
        <form method="post">
            <?php renderFormFields('save'); ?>
            <input type="hidden" name="id" value="<?= escapeHtml($loan['id'] ?? 0) ?>">
            <label>
                Loan owner
                <select name="client" required>
                    <?php foreach ($clients as $clientRow): ?>
                        <option value="<?= $clientRow['id'] ?>" <?= $clientRow['id'] == $selectedClient ? 'selected' : '' ?>><?= escapeHtml($clientRow['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Loan name
                <input required maxlength="120" name="title" value="<?= $formValue('title', $loan['title'] ?? '') ?>">
            </label>
            <label>
                Monthly payment (PHP)
                <input required type="number" min="0.01" max="999999999.99" step=".01" name="amount" value="<?= $formValue('amount', $defaultAmount) ?>">
            </label>
            <div class="pair">
                <label>
                    First payment date
                    <input required type="date" name="first" value="<?= $formValue('first', $firstDate) ?>">
                </label>
                <label>
                    Payment term (months)
                    <input required type="number" min="1" max="600" step="1" name="terms" value="<?= $formValue('terms', $loan['terms'] ?? '') ?>">
                </label>
            </div>
            <p class="quiet">
                Payments repeat on the same day each month. Shorter months use their last day.
            </p>
            <p id="term" class="quiet" aria-live="polite">
            </p>
            <?php if ($loan): ?>
                <p class="quiet">
                    Amount changes apply to unpaid months. Paid installments keep their recorded amount.
                </p>
            <?php endif; ?>
            <button class="primary">Save loan</button>
        </form>
    <?php endif; ?>
</section>
