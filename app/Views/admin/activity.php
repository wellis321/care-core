<?php use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead"><div><p class="eyebrow">Audit</p><h1>Activity</h1><p class="muted">Every change is recorded here, with who made it. This list only ever grows.</p></div></div>
<div class="tablewrap"><table class="list">
  <thead><tr><th>When</th><th>What</th><th>Who</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $a): ?>
    <tr><td class="nowrap"><?= $e(date('j M Y H:i', strtotime($a['created_at']))) ?></td><td><?= $a['entity_type'] === 'person' && $a['entity_uuid'] ? '<a href="/people/' . $e($a['entity_uuid']) . '">' . $e($a['summary']) . '</a>' : $e($a['summary']) ?></td><td class="muted small"><?= $e($a['actor_label']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr class="empty-row"><td colspan="3">Nothing yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="actions" style="margin-top:1rem"><?php if ($page > 1): ?><a class="btn btn-secondary" href="/activity?page=<?= $page - 1 ?>">Newer</a><?php endif; ?><?php if ($more): ?><a class="btn btn-secondary" href="/activity?page=<?= $page + 1 ?>">Older</a><?php endif; ?></p>
