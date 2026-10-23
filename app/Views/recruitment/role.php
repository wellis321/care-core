<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/recruitment">Recruitment</a> › <a href="/recruitment/roles">Job roles</a> › <?= $e($role['title']) ?></p>
<div class="pagehead"><div><p class="eyebrow">Job role</p><h1><?= $e($role['title']) ?><?= $role['active'] ? '' : ' <span class="chip">Retired</span>' ?></h1></div>
  <form method="post" action="/recruitment/roles/<?= $e($role['uuid']) ?>/active"><?= Csrf::field() ?><?php if ($role['active']): ?><button class="btn btn-secondary" type="submit">Retire this role</button><?php else: ?><input type="hidden" name="on" value="1"><button class="btn btn-secondary" type="submit">Bring it back</button><?php endif; ?></form></div>
<div class="split">
<div>
  <section class="card section">
    <h2>The role</h2>
    <form class="form" method="post" action="/recruitment/roles/<?= $e($role['uuid']) ?>">
      <?= Csrf::field() ?>
      <div class="formgrid">
        <div class="form-group"><label for="title">Title</label><input id="title" name="title" type="text" required value="<?= $e($role['title']) ?>"></div>
        <div class="form-group"><label for="code">Code</label><input id="code" name="code" type="text" value="<?= $e($role['code'] ?? '') ?>"></div>
        <div class="form-group"><label for="grade">Grade or band</label><input id="grade" name="grade" type="text" value="<?= $e($role['grade'] ?? '') ?>"></div>
        <div class="form-group"><label for="reports_to_role_id">Reports to (role)</label><select id="reports_to_role_id" name="reports_to_role_id"><option value="">Nobody</option><?php foreach ($allRoles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $role['reports_to_role_id'] === (int) $r['id'] ? ' selected' : '' ?>><?= $e($r['title']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="default_team_id">Usual team</label><select id="default_team_id" name="default_team_id"><option value="">None</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $role['default_team_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="contract_type">Contract</label><select id="contract_type" name="contract_type"><?php foreach ($contracts as $c): ?><option value="<?= $e($c) ?>"<?= $role['contract_type'] === $c ? ' selected' : '' ?>><?= $e(ucwords(str_replace('_', ' ', $c))) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="hours_per_week">Hours per week</label><input id="hours_per_week" name="hours_per_week" type="number" step="0.5" min="0.5" max="168" value="<?= $e($role['hours_per_week'] ?? '') ?>"></div>
        <div class="form-group full"><label for="description">Job description</label><textarea id="description" name="description" rows="6"><?= $e($role['description'] ?? '') ?></textarea></div>
        <div class="form-group full"><label for="person_specification">Person specification</label><textarea id="person_specification" name="person_specification" rows="5"><?= $e($role['person_specification'] ?? '') ?></textarea></div>
      </div>
      <div class="actions"><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </section>
  <section class="card section">
    <h2>Checks before someone can start</h2>
    <p class="muted">References, right to work, PVG membership, registration. They are copied to each candidate, and someone cannot be hired until every <em>required</em> one is done.</p>
    <?php if ($requirements): ?><ul class="plain">
      <?php foreach ($requirements as $q): ?>
        <li><strong><?= $e($q['title']) ?></strong> <?= $q['is_required'] ? '<span class="chip">Required</span>' : '<span class="chip">Optional</span>' ?><?= $q['description'] ? '<br><span class="muted small">' . $e($q['description']) . '</span>' : '' ?>
          <form class="inline" method="post" action="/recruitment/roles/<?= $e($role['uuid']) ?>/checks"><?= Csrf::field() ?><input type="hidden" name="remove" value="<?= (int) $q['id'] ?>"><button class="linkbtn" type="submit">Remove</button></form></li>
      <?php endforeach; ?></ul>
    <?php else: ?><p class="muted">None yet.</p><?php endif; ?>
    <form class="form" method="post" action="/recruitment/roles/<?= $e($role['uuid']) ?>/checks">
      <?= Csrf::field() ?>
      <div class="formgrid">
        <div class="form-group"><label for="title2">Add a check</label><input id="title2" name="title" type="text" required placeholder="Two references"></div>
        <div class="form-group"><label for="description2">Note</label><input id="description2" name="description" type="text"></div>
        <div class="form-group"><label class="check"><input type="checkbox" name="is_required" value="1" checked> Required before they can start</label></div>
      </div>
      <button class="btn btn-secondary" type="submit">Add check</button>
    </form>
  </section>
</div>
<aside>
  <section class="card section"><h2>Reporting line</h2>
    <p>Reports to: <?php if ($role['reports_to_role_id']): $up = array_values(array_filter($allRoles, fn($x) => (int) $x['id'] === (int) $role['reports_to_role_id']))[0] ?? null; ?><?= $up ? '<a href="/recruitment/roles/' . $e($up['uuid']) . '">' . $e($up['title']) . '</a>' : '—' ?><?php else: ?>nobody<?php endif; ?></p>
    <?php if ($reports): ?><p>Roles that report to this one:</p><ul class="plain"><?php foreach ($reports as $x): ?><li><a href="/recruitment/roles/<?= $e($x['uuid']) ?>"><?= $e($x['title']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="card section"><h2>Vacancies</h2>
    <?php if ($vacancies): ?><ul class="plain"><?php foreach ($vacancies as $v): ?><li><a href="/recruitment/vacancies/<?= $e($v['uuid']) ?>"><?= $e($v['reference']) ?></a> <span class="chip"><?= $e(ucfirst($v['status'])) ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">None yet. Start one from <a href="/recruitment">Recruitment</a>.</p><?php endif; ?>
  </section>
</aside>
</div>
