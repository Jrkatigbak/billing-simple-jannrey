<h1>Clients</h1>
<div class="panel">
    <form class="inline" method="post">
        <?php renderFormFields('client'); ?>
        <label>
            Borrower name
            <input required name="name" maxlength="120" placeholder="Full name">
        </label>
        <button class="primary">Add client</button>
    </form>
</div>
<section class="sectionhead">
    <h2>Client directory</h2>
</section>
<div class="panel">
    <?php if (!$clients): ?>
        Your clients will appear here.
    <?php endif; ?>
    <?php foreach ($clients as $clientRow): ?>
        <div class="item">
            <div>
                <strong><?= escapeHtml($clientRow['name']) ?></strong>
                <small>
                    <?= $clientRow['loans'] ?>
                    <?= $clientRow['loans'] == 1 ? 'loan' : 'loans' ?>
                </small>
            </div>
            <a class="button" href="?new&amp;client=<?= $clientRow['id'] ?>">New loan</a>
        </div>
    <?php endforeach; ?>
</div>
