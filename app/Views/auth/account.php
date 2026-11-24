<?php use App\Core\Auth; use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $u = Auth::user(); ?>
<div class="pagehead"><div><p class="eyebrow">Your account</p><h1><?= $e($u['email']) ?></h1><p class="muted"><?= $e(ucfirst($u['role'])) ?> at <?= $e($u['org_name']) ?></p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<section class="section card" style="max-width:36rem">
  <h2>Password</h2>
  <?php if (!empty($demoLocked)): ?>
    <p class="muted">This is a shared demo account. Everyone trying the demo signs in with it, so its password cannot be changed here. In your own installation you can change your password on this page.</p>
  <?php elseif (!$hasPassword): ?>
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
<?php $tf = $tf ?? null; if ($tf): ?>
<?php if (\App\Modules\Identity\SignInPolicy::mustSetUp(Auth::user())): ?><div class="alert alert-error" role="alert"><strong>Your organisation requires two-step sign-in.</strong> Set it up in the box below before you carry on. Care Core will not open anything else until you have.</div><?php endif; ?>
<section class="section card" id="two-step" style="max-width:36rem">
  <h2>Two-step sign-in</h2>
  <?php if (!$tf['available']): ?>
    <p class="muted">Two-step sign-in applies to accounts that sign in with a password. If you sign in with Microsoft, your Microsoft settings decide this. A shared demonstration account cannot use it.</p>
  <?php elseif ($tf['on']): ?>
    <p><strong>It is on.</strong> After your password, Care Core asks for a code from your authenticator app. You have <?= (int) $tf['left'] ?> recovery code<?= $tf['left'] === 1 ? '' : 's' ?> left.</p>
    <form class="form" method="post" action="/account/2fa/codes"><?= Csrf::field() ?>
      <div class="form-group"><label for="tf-pw1">Make new recovery codes (your password)</label><input id="tf-pw1" name="password" type="password" required autocomplete="current-password"></div>
      <div class="actions"><button class="btn btn-secondary" type="submit">New recovery codes</button></div></form>
    <form class="form" method="post" action="/account/2fa/disable"><?= Csrf::field() ?>
      <div class="form-group"><label for="tf-pw2">Switch it off (your password)</label><input id="tf-pw2" name="password" type="password" required autocomplete="current-password"></div>
      <div class="actions"><button class="btn btn-secondary" type="submit">Switch off two-step sign-in</button></div></form>
  <?php elseif ($tf['secret']): ?>
    <p>1. Open an authenticator app on your phone (such as Microsoft Authenticator or Google Authenticator), choose to add an account, and scan this code:</p>
    <div data-qr="<?= $e($tf['uri']) ?>" hidden></div>
    <details style="margin:.75rem 0"><summary>Cannot scan it? Type the key in instead</summary>
      <p style="font-family:monospace;font-size:1.15rem;letter-spacing:.08em"><strong><?= $e(trim(chunk_split($tf['secret'], 4, ' '))) ?></strong></p>
      <p class="muted small">On the phone that has the app, <a href="<?= $e($tf['uri']) ?>">this link</a> opens it directly.</p></details>
    <script src="/assets/qr/qrcode.js"></script><script src="/assets/qr/qr-init.js"></script>
    <p>2. Enter the 6-digit code it shows, to prove it works:</p>
    <form class="form" method="post" action="/account/2fa/enable"><?= Csrf::field() ?>
      <div class="form-group"><label for="tf-code">Code</label><input id="tf-code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7"></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Switch it on</button></div></form>
  <?php else: ?>
    <p>Add a second step to signing in: a 6-digit code from an authenticator app on your phone, as well as your password. It is not on yet.</p>
    <form class="form" method="post" action="/account/2fa/start"><?= Csrf::field() ?><div class="actions"><button class="btn btn-primary" type="submit">Set it up</button></div></form>
  <?php endif; ?>
</section>
<?php endif; ?>
