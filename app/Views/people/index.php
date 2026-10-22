<?php use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead">
  <div><p class="eyebrow">Directory</p><h1>People</h1><p class="muted">One record for everyone: staff, people we support and leavers.</p></div>
  <div class="actions">
    <?php if (\App\Core\Auth::can('staff.manage') && \App\Core\Modules::enabled(\App\Core\Auth::orgId(), 'staff')): ?><a class="btn btn-primary" href="/staff/join">New starter</a><?php endif; ?>
    <?php if ($care && \App\Core\Auth::can('care.manage')): ?><a class="btn btn-secondary" href="/care/new">Add a person we support</a><?php endif; ?>
  </div>
</div>
<?php if ($full): ?>
<nav class="tabs" aria-label="Filter">
  <?php foreach (['all' => 'Everyone', 'staff' => 'Staff', 'supported' => 'People we support', 'leavers' => 'Leavers'] as $k => $label): if ($k === 'supported' && !$care) { continue; } ?>
  <a href="/people?kind=<?= $k ?><?= $q !== '' ? '&amp;q=' . rawurlencode($q) : '' ?>"<?= $kind === $k ? ' aria-current="page"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<form class="searchbar" method="get" action="/people" role="search">
  <input type="hidden" name="kind" value="<?= $e($kind) ?>">
  <label class="skip" for="q">Search by name or email</label>
  <input id="q" type="search" name="q" value="<?= $e($q) ?>" placeholder="Search by name or email">
  <button class="btn btn-secondary" type="submit">Search</button>
</form>
<div class="tablewrap"><table class="list">
  <thead><tr><th>Name</th><th>What they are</th><th>Role</th><th>Email</th></tr></thead>
  <tbody>
  <?php foreach ($people as $pp): ?>
    <tr>
      <td><a href="/people/<?= $e($pp['uuid']) ?>"><?= $e($pp['first_name'] . ' ' . $pp['last_name']) ?></a><?= $pp['preferred_name'] ? '<br><span class="muted small">known as ' . $e($pp['preferred_name']) . '</span>' : '' ?></td>
      <td>
        <?php if ($pp['is_staff']): ?><span class="chip chip--staff">Staff</span> <?php endif; ?>
        <?php if ($pp['is_supported'] && $care): ?><span class="chip chip--supported">Supported</span> <?php endif; ?>
        <?php if (!$pp['is_staff'] && $pp['was_staff']): ?><span class="chip chip--warn">Left</span> <?php endif; ?>
        <?php if (!$pp['is_staff'] && !$pp['was_staff'] && !$pp['is_supported']): ?><span class="chip">Person</span><?php endif; ?>
      </td>
      <td><?= $e($pp['current_job_title'] ?? '') ?: '<span class="muted">—</span>' ?></td>
      <td><?= $e($pp['email'] ?? '') ?: '<span class="muted">—</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$people): ?><tr class="empty-row"><td colspan="4">Nobody matches.</td></tr><?php endif; ?>
  </tbody>
</table></div>
