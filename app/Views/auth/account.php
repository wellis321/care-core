<?php use App\Core\Auth; use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $u = Auth::user(); ?>
<div class="pagehead"><div><p class="eyebrow">Your account</p><h1><?= $e($u['email']) ?></h1><p class="muted"><?= $e(ucfirst($u['role'])) ?> at <?= $e($u['org_name']) ?></p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<section class="section card" style="max-width:36rem">
  <h2>Password</h2>
  <?php if (!$hasPassword): ?>
    <p class="muted">You sign in with Microsoft, so there is no password to change here.</p>
  <?php else: ?>
  <form class="form" method="post" action="/account/password">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
    <div class="form-group"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" required minlength="10" autocomplete="new-password"><span class="hint">At least 10 characters. A few words together is stronger than a short complicated one.</span></div>
    <div class="form-group"><label for="new_password2">New password again</label><input id="new_password2" name="new_password2" type="password" required minlength="10" autocomplete="new-password"></div>
    <div class="actions"><button class="btn btn-primary" type="submit">Change password</button></div>
  </form>
  <p class="muted small">Forgotten your password? Ask an administrator for a new invitation.</p>
  <?php endif; ?>
</section>
