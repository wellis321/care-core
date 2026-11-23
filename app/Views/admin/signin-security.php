<?php use App\Core\Csrf; use App\Core\View; use App\Modules\Identity\SignInPolicy; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Sign-in security</p>
<div class="pagehead"><div><h1>Sign-in security</h1><p class="muted">Two-step sign-in adds a code from an authenticator app to a password. Each person can switch it on for themselves; here you can <strong>require</strong> it.</p></div></div>
<section class="section card" style="max-width:40rem">
  <p><strong><?= (int) $on ?> of <?= (int) $all ?></strong> logins that use a password have it on.</p>
  <form class="form" method="post" action="/admin/signin-security">
    <?= Csrf::field() ?>
    <fieldset>
      <legend class="sr-only">Who must use two-step sign-in</legend>
      <?php foreach (SignInPolicy::LEVELS as $k => $label): ?>
      <div class="form-group"><label class="check"><input type="radio" name="level" value="<?= $e($k) ?>"<?= $level === $k ? ' checked' : '' ?>> <?= $e($label) ?></label></div>
      <?php endforeach; ?>
    </fieldset>
    <div class="actions"><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
  <h2>What happens</h2>
  <ul>
    <li>Someone it applies to is taken to set it up the next time they use Care Core, and cannot do anything else until they have. Their recovery codes are shown once.</li>
    <li>It applies to people who sign in with a <strong>password</strong>. People who sign in with Microsoft follow your Microsoft settings. The shared demonstration accounts are exempt.</li>
    <li>This applies to you too, straight away. Have your phone to hand before you choose.</li>
    <li>Someone who loses their phone and recovery codes can be reset from <a href="/admin/users">Logins</a>, and then set it up again.</li>
  </ul>
</section>
