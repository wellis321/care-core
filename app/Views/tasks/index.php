<?php use App\Core\Clock; use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $today = Clock::today(); ?>
<div class="pagehead"><div><p class="eyebrow">Work to do</p><h1>Tasks</h1><p class="muted">Onboarding, role changes and offboarding create these automatically.</p></div></div>
<div class="tablewrap"><table class="list">
  <thead><tr><th>Task</th><th>About</th><th>Due</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($tasks as $t): ?>
    <tr>
      <td><?= $e($t['title']) ?><?= $t['description'] ? '<br><span class="muted small">' . $e($t['description']) . '</span>' : '' ?> <span class="chip"><?= $e($t['kind']) ?></span></td>
      <td><a href="/people/<?= $e($t['person_uuid']) ?>"><?= $e($t['first_name'] . ' ' . $t['last_name']) ?></a></td>
      <td class="nowrap"><?= $t['due_date'] ? View::date($t['due_date']) . ($t['due_date'] < $today ? ' <span class="chip chip--bad">Overdue</span>' : '') : '—' ?></td>
      <td class="right"><form method="post" action="/tasks/<?= $e($t['uuid']) ?>" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="back" value="/tasks"><button class="btn btn-primary" name="status" value="done" type="submit">Done</button><button class="btn btn-secondary" name="status" value="skipped" type="submit">Skip</button></form></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tasks): ?><tr class="empty-row"><td colspan="4">Nothing to do. Nice.</td></tr><?php endif; ?>
  </tbody>
</table></div>
