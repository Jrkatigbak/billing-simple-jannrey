<h1>Email notifications</h1>
<?php if (isset($_GET['saved'])): ?>
    <p role="status">Email settings saved.</p>
<?php endif; ?>
<section class="panel editor">
    <form method="post">
        <?php renderFormFields('mail_settings'); ?>
        <label>
            SMTP host
            <input required name="host" value="<?= escapeHtml($mailSettings['host'] ?? '') ?>" placeholder="smtp.example.com">
        </label>
        <div class="pair">
            <label>
                SMTP port
                <input required type="number" min="1" max="65535" name="port" value="<?= escapeHtml($mailSettings['port'] ?? '587') ?>">
            </label>
            <label>
                SMTP username
                <input name="username" value="<?= escapeHtml($mailSettings['username'] ?? '') ?>" autocomplete="username">
            </label>
        </div>
        <label>
            SMTP password
            <input type="password" name="password" autocomplete="new-password" placeholder="<?= $mailSettings && $mailSettings['password'] !== '' ? 'Leave blank to keep saved password' : 'SMTP password' ?>">
        </label>
        <label>
            Sender email
            <input required type="email" name="sender" value="<?= escapeHtml($mailSettings['sender'] ?? '') ?>">
        </label>
        <label>
            Send reminder this many days before payment
            <input required type="number" min="0" max="30" name="reminder_days" value="<?= escapeHtml($mailSettings['reminder_days'] ?? '3') ?>">
        </label>
        <label>
            <input type="checkbox" name="enabled" value="1" <?= !empty($mailSettings['enabled']) ? 'checked' : '' ?>> Enable email notifications
        </label>
        <button class="primary">Save settings</button>
    </form>
</section>
