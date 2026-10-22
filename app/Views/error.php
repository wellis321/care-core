<?php use App\Core\View; ?>
<section class="empty">
  <p class="eyebrow"><?= (int) $status ?></p>
  <h1><?= View::e($title) ?></h1>
  <p><?= View::e($message) ?></p>
  <p><a class="btn btn-primary" href="/">Back to the dashboard</a></p>
</section>
