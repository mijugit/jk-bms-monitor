<h1>Logowanie</h1>

<?php if (!empty($error)): ?>
<p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post" action="/login" class="login-form">
    <label for="password">Hasło</label>
    <input type="password" id="password" name="password" autofocus required>
    <button type="submit">Zaloguj</button>
</form>
