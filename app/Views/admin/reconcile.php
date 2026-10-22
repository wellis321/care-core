<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Consistency checks</p>
<div class="pagehead">
  <div><h1>Consistency checks</h1><p class="muted">Joiners, movers and leavers keep everything in step. This check proves it, and finds anything that was changed some other way (an import, a direct edit).</p></div>
  <?php if ($canFix && array_filter($issues, fn($i) => $i['fixable'])): ?><form method="post" action="/admin/reconcile/fix"><?= Csrf::field() ?><button class="btn btn-primary" type="submit">Put right what can be put right</button></form><?php endif; ?>
</div>
<?php if (!$issues): ?><div class="alert alert-success">Everything is in step.</div><?php endif; ?>
<?php if ($issues): ?>
<div class="tablewrap"><table class="list">
  <thead><tr><th>Person</th><th>Finding</th><th>Severity</th></tr></thead>
  <tbody>
  <?php foreach ($issues as $i): ?>
    <tr><td><a href="/people/<?= $e($i['person_uuid']) ?>"><?= $e($i['person']) ?></a></td><td><?= $e($i['detail']) ?></td><td><span class="chip <?= $i['severity'] === 'high' ? 'chip--bad' : ($i['severity'] === 'medium' ? 'chip--warn' : '') ?>"><?= $e(ucfirst($i['severity'])) ?></span><?= $i['fixable'] ? '' : ' <span class="muted small">needs a person</span>' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
