<?php use App\Core\Config; use App\Core\Csrf; use App\Core\View; use App\Modules\Identity\Oidc; $sso = Oidc::configured(); $pwdAll = Config::bool('PASSWORD_LOGIN', true) || !$sso; ?>
<section class="login card">
  <h1>Sign in</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <?php if (Config::demo()): ?>
  <div class="demo-login-help alert alert-info">
    <strong>Try the demo</strong>
    <p class="small">Sign in with either account. The password for both is <strong>DemoPass2026!</strong></p>
    <ul class="small"><li>Administrator: <strong>admin@demo.lochview.test</strong></li><li>Manager: <strong>manager@demo.lochview.test</strong></li><li>Member of staff: <strong>staff@demo.lochview.test</strong></li></ul>
  </div>
  <?php endif; ?>
  <?php if ($sso): ?>
  <p><a class="btn btn-primary" href="/login/entra<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Sign in with Microsoft</a></p>
  <?php if ($pwdAll): ?><p class="muted small">Or use your email address and password.</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!$pwdAll): ?><details><summary class="muted small">Administrator password sign-in</summary><?php endif; ?>
  <form method="post" action="/login" id="pwform">
    <?= Csrf::field() ?>
    <input type="hidden" name="next" value="<?= View::e($next) ?>">
    <div class="form-group"><label for="email">Email address</label><input id="email" name="email" type="email" required autofocus autocomplete="username" value="<?= View::e($email) ?>"></div>
    <div class="form-group"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
  <?php if (!$pwdAll): ?></details><?php endif; ?>
</section>
