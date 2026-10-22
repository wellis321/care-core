<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Logins</p>
<div class="pagehead"><div><h1>Logins</h1><p class="muted">When someone leaves, their login stops working after their last day without anyone having to remember.</p></div></div>
<div class="tablewrap"><table class="list">
  <thead><tr><th>Login</th><th>Person</th><th>Role</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= $e($u['email']) ?></td>
      <td><?= $u['person_uuid'] ? '<a href="/people/' . $e($u['person_uuid']) . '">' . $e($u['first_name'] . ' ' . $u['last_name']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td><?= $e(ucfirst($u['role'])) ?></td>
      <td><?php if ($u['status'] === 'disabled'): ?><span class="chip chip--bad">Disabled</span><?php elseif ($u['access_ends_on'] && $u['access_ends_on'] < date('Y-m-d')): ?><span class="chip chip--bad">Access ended <?= View::date($u['access_ends_on']) ?></span><?php elseif ($u['access_ends_on']): ?><span class="chip chip--warn">Ends <?= View::date($u['access_ends_on']) ?></span><?php elseif ($u['status'] === 'invited'): ?><span class="chip chip--warn">Invited</span><?php else: ?><span class="chip chip--staff">Active</span><?php endif; ?></td>
      <td class="right"><?php if ($u['status'] !== 'disabled'): ?><form method="post" action="/admin/users/reinvite" class="row-actions" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-secondary" type="submit">New invitation</button></form> <form method="post" action="/admin/users/disable" class="row-actions" onsubmit="return confirm('Disable this login?')"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-secondary" type="submit">Disable</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<section class="section card" style="margin-top:2rem">
  <h2>Invite someone</h2>
  <form class="inlineform" method="post" action="/admin/users">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
    <div class="form-group"><label for="role">Role</label><select id="role" name="role"><option value="staff">Member of staff</option><option value="manager">Manager</option><option value="admin">Administrator</option></select></div>
    <div class="form-group"><label for="person_id">Linked person</label><select id="person_id" name="person_id"><option value="">None</option><?php foreach ($people as $pp): ?><option value="<?= (int) $pp['id'] ?>"><?= $e($pp['first_name'] . ' ' . $pp['last_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit">Create invitation</button>
  </form>
</section>
