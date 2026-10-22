<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e'];
$render = function (array $nodes) use (&$render, $e): void {
    echo '<ul>';
    foreach ($nodes as $n) {
        echo '<li><a href="/teams/' . $e($n['uuid']) . '">' . $e($n['name']) . '</a> <span class="muted small">' . $e($n['kind']) . '</span>' . ($n['staff_only'] ? ' <span class="chip">Staff only</span>' : '');
        if (!empty($n['children'])) { $render($n['children']); }
        echo '</li>';
    }
    echo '</ul>';
};
?>
<div class="pagehead"><div><p class="eyebrow">Structure</p><h1>Teams</h1><p class="muted">Services, teams and sub-teams. Who is in each one, and when, is kept for good.</p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<div class="split">
  <section class="card">
    <?php if ($tree): ?><div class="tree"><?php $render($tree); ?></div><?php else: ?><p class="muted">No teams yet.</p><?php endif; ?>
  </section>
  <?php if ($canManage): ?>
  <aside class="card">
    <h2>New team</h2>
    <form method="post" action="/teams">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="name">Name</label><input id="name" name="name" type="text" required value="<?= $e($old['name'] ?? '') ?>"></div>
      <div class="form-group"><label for="parent_team_id">Sits under</label><select id="parent_team_id" name="parent_team_id"><option value="">Top level</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="kind">Kind</label><input id="kind" name="kind" type="text" placeholder="care, support, HR…" value="<?= $e($old['kind'] ?? '') ?>"></div>
      <div class="form-group"><label class="check"><input type="checkbox" name="staff_only" value="1"> Staff only (people we support cannot be added)</label></div>
      <button class="btn btn-primary" type="submit">Create team</button>
    </form>
  </aside>
  <?php endif; ?>
</div>
