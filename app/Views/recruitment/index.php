<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d);
$statuses = ['' => 'All', 'open' => 'Open', 'draft' => 'Draft', 'filled' => 'Filled', 'closed' => 'Closed']; ?>
<div class="pagehead"><div><p class="eyebrow">Staff</p><h1>Recruitment</h1><p class="muted">Vacancies, candidates and hiring. Hiring starts someone through the ordinary joiner process, so their record, team and onboarding come from here.</p></div>
  <div class="actions"><a class="btn btn-secondary" href="/recruitment/roles">Job roles</a></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<nav class="tabs" aria-label="Vacancy status">
  <?php foreach ($statuses as $k => $label): ?><a href="/recruitment<?= $k ? '?status=' . $e($k) : '' ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
</nav>
<div class="split">
  <section class="card">
    <?php if ($vacancies): ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>Vacancy</th><th>Team</th><th>Line manager</th><th>Status</th><th class="right">Posts</th><th class="right">Candidates</th></tr></thead>
      <tbody>
      <?php foreach ($vacancies as $v): ?>
        <tr>
          <td><a href="/recruitment/vacancies/<?= $e($v['uuid']) ?>"><?= $e($v['reference']) ?> · <?= $e($v['title']) ?></a></td>
          <td><?= $e($v['team_name'] ?? '') ?: '—' ?></td>
          <td><?= $e($v['manager_name'] ?? '') ?: '—' ?></td>
          <td><span class="chip"><?= $e(ucfirst($v['status'])) ?></span></td>
          <td class="right"><?= (int) $v['hired'] ?> / <?= (int) $v['posts'] ?></td>
          <td class="right"><?= (int) $v['in_progress'] ?> in progress · <?= (int) $v['applications'] ?> total</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="muted">No vacancies<?= $status ? ' with that status' : ' yet' ?>.</p><?php endif; ?>
  </section>
  <aside class="card">
    <h2>New vacancy</h2>
    <?php if (!$roles): ?>
      <p class="muted">A vacancy is a post in a job role. <a href="/recruitment/roles">Create a role</a> first.</p>
    <?php else: ?>
    <form method="post" action="/recruitment/vacancies">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="role_id">Job role</label><select id="role_id" name="role_id" required><option value="">Choose a role</option><?php foreach ($roles as $rl): ?><option value="<?= (int) $rl['id'] ?>"<?= (string) ($old['role_id'] ?? '') === (string) $rl['id'] ? ' selected' : '' ?>><?= $e($rl['title']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="posts">Posts to fill</label><input id="posts" name="posts" type="number" min="1" max="500" value="<?= $o('posts', '1') ?>"></div>
      <div class="form-group"><label for="team_id">Team</label><select id="team_id" name="team_id"><option value="">The role’s usual team</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="line_manager_person_id">Reports to</label><select id="line_manager_person_id" name="line_manager_person_id"><option value="">Not decided</option><?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="closing_date">Closing date</label><input id="closing_date" name="closing_date" type="date" value="<?= $o('closing_date') ?>"></div>
      <button class="btn btn-primary" type="submit">Create vacancy</button>
    </form>
    <?php endif; ?>
  </aside>
</div>
