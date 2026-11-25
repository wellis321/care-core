<?php use App\Core\View; $e = [View::class, 'e']; $total = 0; foreach ($rows as $r0) { $total += count($r0['items']); } ?>
<div class="pagehead"><div><p class="eyebrow">Today</p><h1>My work</h1><p class="muted">What is waiting for you, from every product your organisation has connected. Nothing is copied here: each product is asked when you open this page.</p></div>
  <div><a class="btn btn--small" href="/my-work?refresh=1">Check again</a></div></div>

<?php if (!$connected && !$tasks): ?>
  <div class="card"><p>No products are connected yet. <?php if (\App\Core\Auth::can('*')): ?>An administrator can connect them in <a href="/admin/my-work">Admin &rsaquo; My work</a>.<?php else: ?>Ask your administrator to connect them.<?php endif; ?></p></div>
<?php endif; ?>

<?php foreach ($rows as $s): ?>
<section class="section">
  <div class="section__head"><h2><?= $e($s['name']) ?></h2></div>
  <div class="card">
    <?php if ($s['error']): ?>
      <p class="muted"><?= $e($s['error']) ?> <a href="<?= $e($s['url']) ?>">Open <?= $e($s['name']) ?></a> to look yourself.</p>
    <?php elseif (!$s['items']): ?>
      <p class="muted">Nothing waiting for you here.</p>
    <?php else: ?>
      <ul class="plain">
      <?php foreach ($s['items'] as $it): $late = $it['due_on'] && $it['due_on'] < $today; ?>
        <li><?= $it['url'] ? '<a href="' . $e($it['url']) . '">' . $e($it['title']) . '</a>' : $e($it['title']) ?>
          <?php if ($it['kind']): ?> <span class="chip"><?= $e($it['kind']) ?></span><?php endif; ?>
          <?php if ($it['due_on']): ?> <span class="muted small">due <?= View::date($it['due_on']) ?></span><?php endif; ?>
          <?php if ($late): ?> <span class="chip chip--bad">Overdue</span><?php endif; ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php if ($tasks): ?>
<section class="section">
  <div class="section__head"><h2>Care Core tasks</h2></div>
  <div class="card"><ul class="plain">
    <?php foreach ($tasks as $t): ?>
      <li><a href="/tasks"><?= $e($t['title']) ?></a> <span class="muted small">about <?= $e($t['first_name'] . ' ' . $t['last_name']) ?></span>
        <?php if ($t['due_date']): ?> <span class="muted small">due <?= View::date($t['due_date']) ?></span><?php endif; ?>
        <?php if ($t['due_date'] && $t['due_date'] < $today): ?> <span class="chip chip--bad">Overdue</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul><p class="small"><a href="/tasks">All tasks</a></p></div>
</section>
<?php endif; ?>
