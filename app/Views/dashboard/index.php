<?php use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead">
  <div><p class="eyebrow">Overview</p><h1>Dashboard</h1></div>
  <?php if ($isManager): ?>
  <div class="actions">
    <?php if (\App\Core\Auth::can('staff.manage') && \App\Core\Modules::enabled(\App\Core\Auth::orgId(), 'staff')): ?><a class="btn btn-primary" href="/staff/join">New starter</a><?php endif; ?>
    <?php if (\App\Core\Auth::can('care.manage') && \App\Core\Modules::enabled(\App\Core\Auth::orgId(), 'care')): ?><a class="btn btn-secondary" href="/care/new">Add a person we support</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($isManager): ?>
<dl class="stats">
  <div><dt>Staff</dt><dd><a href="/people?kind=staff" aria-label="Staff: <?= (int) $stats['staff'] ?>, view"><?= (int) $stats['staff'] ?></a></dd></div>
  <div><dt>People we support</dt><dd><a href="/people?kind=supported" aria-label="People we support: <?= (int) $stats['supported'] ?>, view"><?= (int) $stats['supported'] ?></a></dd></div>
  <div><dt>Open tasks</dt><dd><a href="/tasks" aria-label="Open tasks: <?= (int) $stats['tasks'] ?>, view"><?= (int) $stats['tasks'] ?></a></dd></div>
  <div><dt>Overdue</dt><dd><a href="/tasks" aria-label="Overdue: <?= (int) $stats['overdue'] ?>, view"><?= (int) $stats['overdue'] ?></a></dd></div>
</dl>

<?php if ($issues): ?>
<section class="section">
  <div class="section__head"><h2>Needs attention</h2><a href="/admin/reconcile">See all checks</a></div>
  <div class="alert alert-error" role="alert">
    <strong><?= count($issues) ?> thing<?= count($issues) === 1 ? '' : 's' ?> out of step.</strong> These should have ended when someone left.
    <ul class="small"><?php foreach (array_slice($issues, 0, 4) as $i): ?><li><a href="/people/<?= $e($i['person_uuid']) ?>"><?= $e($i['person']) ?></a>: <?= $e($i['detail']) ?></li><?php endforeach; ?></ul>
  </div>
</section>
<?php endif; ?>

<div class="grid cols-2 section">
  <section>
    <div class="section__head"><h2>Starting soon</h2></div>
    <div class="tablewrap"><table class="list"><tbody>
      <?php foreach ($joiners as $j): ?><tr><td><a href="/people/<?= $e($j['uuid']) ?>"><?= $e($j['first_name'] . ' ' . $j['last_name']) ?></a><br><span class="muted small"><?= $e($j['job_title']) ?></span></td><td class="right nowrap"><?= View::date($j['start_date']) ?></td></tr><?php endforeach; ?>
      <?php if (!$joiners): ?><tr class="empty-row"><td>Nobody is due to start in the next 30 days.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section>
    <div class="section__head"><h2>Leaving soon</h2></div>
    <div class="tablewrap"><table class="list"><tbody>
      <?php foreach ($leavers as $l): ?><tr><td><a href="/people/<?= $e($l['uuid']) ?>"><?= $e($l['first_name'] . ' ' . $l['last_name']) ?></a><br><span class="muted small"><?= $e($l['job_title']) ?></span></td><td class="right nowrap">Last day <?= View::date($l['end_date']) ?></td></tr><?php endforeach; ?>
      <?php if (!$leavers): ?><tr class="empty-row"><td>Nobody is due to leave in the next 30 days.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

<div class="grid cols-2 section">
  <section>
    <div class="section__head"><h2>Tasks due first</h2><a href="/tasks">All tasks</a></div>
    <div class="tablewrap"><table class="list"><tbody>
      <?php foreach ($tasks as $t): ?><tr><td><?= $e($t['title']) ?><br><span class="muted small"><a href="/people/<?= $e($t['person_uuid']) ?>"><?= $e($t['first_name'] . ' ' . $t['last_name']) ?></a></span></td><td class="right nowrap"><?= $t['due_date'] ? View::date($t['due_date']) : '—' ?></td></tr><?php endforeach; ?>
      <?php if (!$tasks): ?><tr class="empty-row"><td>No open tasks.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <?php if ($recent): ?>
  <section>
    <div class="section__head"><h2>Recent activity</h2><a href="/activity">All activity</a></div>
    <div class="card"><ul class="timeline">
      <?php foreach ($recent as $a): ?><li><time><?= View::date($a['created_at']) ?></time><span><?= $e($a['summary']) ?><br><span class="muted small"><?= $e($a['actor_label']) ?></span></span></li><?php endforeach; ?>
    </ul></div>
  </section>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="grid cols-2">
  <section class="card">
    <h2>Your details</h2>
    <?php if ($person): ?>
    <dl class="facts"><dt>Name</dt><dd><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></dd><dt>Job title</dt><dd><?= $e($employment['job_title'] ?? '—') ?></dd><dt>Started</dt><dd><?= View::date($employment['start_date'] ?? null) ?></dd></dl>
    <?php else: ?><p class="muted">Your login is not linked to a person record yet.</p><?php endif; ?>
  </section>
  <section class="card">
    <h2>Your teams</h2>
    <ul><?php foreach ($teams as $t): if ($t['valid_to'] === null): ?><li><a href="/teams/<?= $e($t['team_uuid']) ?>"><?= $e($t['team_name']) ?></a></li><?php endif; endforeach; ?></ul>
  </section>
</div>
<?php endif; ?>
