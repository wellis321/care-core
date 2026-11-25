<?php use App\Core\Config; use App\Core\Csrf; use App\Core\View; use App\Modules\Identity\Oidc; $sso = Oidc::configured(); $pwdAll = Config::bool('PASSWORD_LOGIN', true) || !$sso; ?>
<section class="login card">
  <h1>Sign in</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <?php if (Config::demo()): ?>
  <form method="post" action="/demo/enter" class="demo-enter" aria-labelledby="demo-enter-h">
    <?= Csrf::field() ?><?= \App\Modules\Identity\DemoEntry::fields() ?><?php if (!empty($next)): ?><input type="hidden" name="next" value="<?= View::e($next) ?>"><?php endif; ?>
    <h2 id="demo-enter-h">Explore the demonstration in one click</h2>
    <p class="muted small">No sign-up and nothing to type. It is full of invented people, and it resets every night.</p>
    <div class="demo-enter__btns">
      <button class="btn btn-primary" type="submit" name="role" value="manager">Explore as a manager<span>Record joiners and leavers, see tasks and teams</span></button>
      <button class="btn btn-secondary" type="submit" name="role" value="staff">Explore as a member of staff<span>See your own profile and your team</span></button>
    </div>
  </form>
  <div class="demo-login-help alert alert-info">
    <strong>Or sign in with a demo account</strong>
    <p class="small">Sign in with any account. The password for both is <strong>DemoPass2026!</strong></p>
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
  <p class="muted small" style="margin-top:1rem">New here? <a href="/about">What is Care Core?</a> · <a href="/docs">Documentation</a></p>
</section>
