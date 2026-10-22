<?php use App\Core\Csrf; use App\Core\View; ?>
<section class="login card">
  <h1>Welcome to Care Core</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <?php if ($email): ?>
  <p>Choose a password for <strong><?= View::e($email) ?></strong>. Use at least 10 characters.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="password">New password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"></div>
    <div class="form-group"><label for="password2">Repeat the password</label><input id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password"></div>
    <button class="btn btn-primary" type="submit">Set password</button>
  </form>
  <?php else: ?><p><a href="/login">Go to sign in</a></p><?php endif; ?>
</section>
