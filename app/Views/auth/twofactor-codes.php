<?php use App\Core\View; ?>
<section class="section card" style="max-width:36rem">
  <h1>Save your recovery codes</h1>
  <p>Two-step sign-in is on. These eight codes are for the day you lose your phone. <strong>Each works once, and they will not be shown again.</strong> Keep them somewhere safe and apart from your password.</p>
  <ul class="plain" style="font-family:monospace;font-size:1.1rem;columns:2;list-style:none;padding:0"><?php foreach ($codes as $c): ?><li><?= View::e($c) ?></li><?php endforeach; ?></ul>
  <p><a class="btn btn-primary" href="/account#two-step">I have saved them</a></p>
</section>
