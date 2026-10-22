<?php use App\Core\Config; use App\Core\Csrf; use App\Core\View; ?>
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
  <form method="post" action="/login">
    <?= Csrf::field() ?>
    <input type="hidden" name="next" value="<?= View::e($next) ?>">
    <div class="form-group"><label for="email">Email address</label><input id="email" name="email" type="email" required autofocus autocomplete="username" value="<?= View::e($email) ?>"></div>
    <div class="form-group"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</section>
