<?php use App\Core\Csrf; use App\Core\View; ?>
<section class="login card">
  <h1>One more step</h1>
  <p class="muted">Your password was right. Enter the 6-digit code from your authenticator app. If you have lost your phone, enter one of your recovery codes instead (each works once).</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <form method="post" action="/login/2fa" autocomplete="off">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="code">Code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="14"></div>
    <div class="actions"><button class="btn btn-primary" type="submit">Sign in</button> <a class="btn btn-secondary" href="/login">Start again</a></div>
  </form>
  <p class="muted small">Lost your phone and your codes? Ask an administrator to reset two-step sign-in for you.</p>
</section>
