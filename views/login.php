<section class="login panel">
    <div class="eyebrow">
        Billing management
    </div>
    <h1>Admin sign in</h1>
    <form method="post">
        <?php renderFormFields('login'); ?>
        <label>
            Admin password
            <input required type="password" name="password" autocomplete="current-password">
        </label>
        <button class="primary">Sign in</button>
    </form>
</section>
