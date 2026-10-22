<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/teams">Teams</a><?php foreach ($path as $c): ?> › <?= (int) $c['id'] === (int) $team['id'] ? $e($c['name']) : '<a href="/teams/' . $e($c['uuid']) . '">' . $e($c['name']) . '</a>' ?><?php endforeach; ?></p>
<div class="pagehead">
  <div><p class="eyebrow"><?= $e($team['kind']) ?><?= $team['staff_only'] ? ' · staff only' : '' ?></p><h1><?= $e($team['name']) ?></h1>
  <?php if ($children): ?><p class="muted">Sub-teams: <?php foreach ($children as $i => $c): ?><?= $i ? ', ' : '' ?><a href="/teams/<?= $e($c['uuid']) ?>"><?= $e($c['name']) ?></a><?php endforeach; ?></p><?php endif; ?></div>
</div>

<section class="section">
  <div class="section__head"><h2>Members (<?= count($members) ?>)</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Name</th><th>Role</th><th>Joined</th><?php if ($canManage): ?><th><span class="sr">Actions</span></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><a href="/people/<?= $e($m['person_uuid']) ?>"><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></a> <span class="chip <?= $m['is_staff'] ? 'chip--staff' : 'chip--supported' ?>"><?= $m['is_staff'] ? 'Staff' : 'Supported' ?></span><?= $m['is_primary'] ? ' <span class="chip">Primary</span>' : '' ?></td>
        <td><?= $e($m['role'] ?? '') ?: '<span class="muted">—</span>' ?></td>
        <td class="nowrap"><?= View::date($m['valid_from']) ?></td>
        <?php if ($canManage): ?><td class="right"><form method="post" action="/teams/<?= $e($team['uuid']) ?>/members/end" class="row-actions" onsubmit="return confirm('Remove from this team?')"><?= Csrf::field() ?><input type="hidden" name="person_id" value="<?= (int) $m['person_id'] ?>"><button class="btn btn-secondary" type="submit">Remove</button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$members): ?><tr class="empty-row"><td colspan="4">Nobody is in this team.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($canManage && $candidates): ?>
  <form class="inlineform" method="post" action="/teams/<?= $e($team['uuid']) ?>/members" style="margin-top:0.75rem">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="person_id">Add someone</label><select id="person_id" name="person_id" required><option value="">Choose…</option><?php foreach ($candidates as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $e($c['first_name'] . ' ' . $c['last_name']) ?><?= $c['is_staff'] ? '' : ' (supported)' ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="role">Role</label><input id="role" name="role" type="text"></div>
    <div class="form-group"><label for="valid_from">From</label><input id="valid_from" name="valid_from" type="date" value="<?= $e($today) ?>"></div>
    <label class="check"><input type="checkbox" name="is_primary" value="1"> Their main team</label>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($past): ?>
<section class="section">
  <div class="section__head"><h2>Past members (<?= count($past) ?>)</h2></div>
  <p class="muted small">Everyone who has left this team, with the dates they were in it. Someone who rejoins gets a new line, so earlier dates are never overwritten.</p>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Name</th><th>Role</th><th>Joined</th><th>Left</th></tr></thead>
    <tbody><?php foreach ($past as $m): ?><tr><td><a href="/people/<?= $e($m['person_uuid']) ?>"><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></a></td><td><?= $e($m['role'] ?? '') ?: '<span class="muted">—</span>' ?></td><td class="nowrap"><?= View::date($m['valid_from']) ?></td><td class="nowrap"><?= View::date($m['valid_to']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="section card">
  <h2>Edit team</h2>
  <form class="form" method="post" action="/teams/<?= $e($team['uuid']) ?>/edit">
    <?= Csrf::field() ?>
    <div class="formgrid">
      <div class="form-group"><label for="name">Name</label><input id="name" name="name" type="text" required value="<?= $e($team['name']) ?>"></div>
      <div class="form-group"><label for="parent_team_id">Sits under</label><select id="parent_team_id" name="parent_team_id"><option value="">Top level</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $team['parent_team_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="kind">Kind</label><input id="kind" name="kind" type="text" value="<?= $e($team['kind']) ?>"></div>
      <div class="form-group"><label class="check" style="margin-top:1.8rem"><input type="checkbox" name="staff_only" value="1"<?= $team['staff_only'] ? ' checked' : '' ?>> Staff only</label></div>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
  <form method="post" action="/teams/<?= $e($team['uuid']) ?>/close" style="margin-top:1rem" onsubmit="return confirm('Close this team? Everyone\'s place in it ends today.')"><?= Csrf::field() ?><button class="btn btn-danger" type="submit">Close this team</button></form>
</section>
<?php endif; ?>
