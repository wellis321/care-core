<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d); ?>
<p class="crumbs"><a href="/recruitment">Recruitment</a> › Job roles</p>
<div class="pagehead"><div><p class="eyebrow">Reference</p><h1>Job roles</h1><p class="muted">What each job is, who it reports to, where it normally sits, and what has to be checked before someone can start. Vacancies and hires use these.</p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<div class="split">
  <section class="card">
    <?php if ($roles): ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>Role</th><th>Reports to</th><th>Usual team</th><th class="right">Live vacancies</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r): ?>
        <tr<?= $r['active'] ? '' : ' class="muted"' ?>>
          <td><a href="/recruitment/roles/<?= $e($r['uuid']) ?>"><?= $e($r['title']) ?></a><?= $r['code'] ? ' <span class="muted small">' . $e($r['code']) . '</span>' : '' ?><?= $r['active'] ? '' : ' <span class="chip">Retired</span>' ?></td>
          <td><?= $e($r['reports_to_title'] ?? '') ?: '—' ?></td>
          <td><?= $e($r['team_name'] ?? '') ?: '—' ?></td>
          <td class="right"><?= (int) $r['live_vacancies'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="muted">No roles yet. Add the first one.</p><?php endif; ?>
  </section>
  <aside class="card">
    <h2>New role</h2>
    <form method="post" action="/recruitment/roles">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="title">Title</label><input id="title" name="title" type="text" required value="<?= $o('title') ?>"></div>
      <div class="form-group"><label for="code">Code</label><input id="code" name="code" type="text" value="<?= $o('code') ?>"><span class="hint">Optional, for example SW.</span></div>
      <div class="form-group"><label for="reports_to_role_id">Reports to (role)</label><select id="reports_to_role_id" name="reports_to_role_id"><option value="">Nobody</option><?php foreach ($allRoles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= $e($r['title']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="default_team_id">Usual team</label><select id="default_team_id" name="default_team_id"><option value="">None</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="contract_type">Contract</label><select id="contract_type" name="contract_type"><?php foreach ($contracts as $c): ?><option value="<?= $e($c) ?>"><?= $e(ucwords(str_replace('_', ' ', $c))) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="hours_per_week">Hours per week</label><input id="hours_per_week" name="hours_per_week" type="number" step="0.5" min="0.5" max="168" value="<?= $o('hours_per_week') ?>"></div>
      <button class="btn btn-primary" type="submit">Create role</button>
    </form>
  </aside>
</div>
