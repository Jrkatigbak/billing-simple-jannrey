<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>
            <?= escapeHtml($loan['title'] ?? 'Loan ledger') ?>
            · John Rey Simple Billing System
        </title>
        <link rel="stylesheet" href="assets/css/billing.css">
        <script defer src="assets/js/billing.js">
        </script>
    </head>
    <body>
        <header>
            <div class="bar">
                <b class="brand">
                    <i aria-hidden="true">▤</i>
                    John Rey Simple Billing System
                </b>
                <?php if ($admin): ?>
                    <nav>
                        <a href="./">Loans</a>
                        <a href="?clients">Clients</a>
                        <a href="?settings">Email settings</a>
                    </nav>
                    <form class="right" method="post">
                        <?php renderFormFields('logout'); ?>
                        <button>Sign out</button>
                    </form>
                <?php else: ?>
                    <span class="right quiet"><?= $public ? 'Borrower statement' : 'Billing management' ?></span>
                <?php endif; ?>
            </div>
        </header>
        <main>
            <?php if ($error): ?>
                <p role="alert">
                    <?= escapeHtml($error) ?>
                </p>
            <?php endif; ?>
            <?php if ($notice): ?>
                <p role="status"><?= escapeHtml($notice) ?></p>
            <?php endif; ?>
            <?php require __DIR__ . '/' . $view; ?>
            <?php if ($loan): ?>
                <dialog id="proof-preview" aria-labelledby="proof-title">
                    <div class="preview-heading">
                        <h2 id="proof-title">Proof of payment</h2>
                        <form method="dialog">
                            <button autofocus>Close</button>
                        </form>
                    </div>
                    <img alt="Proof of payment">
                </dialog>
            <?php endif; ?>
        </main>
    </body>
</html>
