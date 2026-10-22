<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $labels = ['joiner' => 'When someone joins', 'mover' => 'When someone changes role or team', 'leaver' => 'When someone leaves']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Checklists</p>
<div class="pagehead"><div><h1>Checklists</h1><p class="muted">These become tasks automatically. "Due" is in days from the start date, change date or last day (negative means before).</p></div></div>
<?php foreach ($templates as $t): ?>
<section class="section">
  <div class="section__head"><h2><?= $e($labels[$t['kind']] ?? $t['kind']) ?></h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Task</th><th>Who</th><th>Due</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($t['items'] as $i): ?>
      <tr><td><?= $e($i['title']) ?></td><td><?= $e(ucfirst($i['owner_role'])) ?></td><td><?= (int) $i['due_offset_days'] === 0 ? 'On the day' : ((int) $i['due_offset_days'] > 0 ? (int) $i['due_offset_days'] . ' days after' : abs((int) $i['due_offset_days']) . ' days before') ?></td><td class="right"><form method="post" action="/admin/checklists/items/remove" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="btn btn-secondary" type="submit">Remove</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <form class="inlineform" method="post" action="/admin/checklists/items" style="margin-top:0.75rem"><?= Csrf::field() ?><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
    <div class="form-group"><label>Task<input name="title" type="text" required></label></div>
    <div class="form-group"><label>Who<select name="owner_role"><option value="manager">Manager</option><option value="admin">Administrator</option><option value="staff">The person</option></select></label></div>
    <div class="form-group"><label>Due (days)<input name="due_offset_days" type="number" value="0" style="width:6rem"></label></div>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
</section>
<?php endforeach; ?>
