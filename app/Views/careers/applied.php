<?php use App\Core\View; $e = [View::class, 'e']; ?>
<section class="card section">
  <p class="eyebrow">Thank you</p>
  <h1>Your application has been received</h1>
  <p>Thank you for applying for <strong><?= $e($v['title']) ?></strong> at <?= $e($org['name']) ?>. We will read it and reply to the email address you gave us.</p>
  <p><a href="/careers/<?= $e($org['slug']) ?>">See other jobs</a></p>
</section>
