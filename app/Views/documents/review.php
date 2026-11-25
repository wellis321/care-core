<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead"><div><p class="eyebrow">Staff</p><h1>Documents to review</h1><p class="muted">Documents that have expired or are about to, and people whose records are past your retention period.</p></div></div>

<section class="card section" id="expiring">
  <h2>Expired or expiring within 60 days <span class="muted small">(<?= count($expiring) ?>)</span></h2>
  <?php if ($expiring): ?>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Person</th><th>Document</th><th>Kind</th><th>Expires</th></tr></thead>
    <tbody>
    <?php foreach ($expiring as $d): ?>
      <tr>
        <td><a href="/people/<?= $e($d['person_uuid']) ?>/record#documents"><?= $e($d['first_name'] . ' ' . $d['last_name']) ?></a></td>
        <td><a href="/documents/<?= $e($d['uuid']) ?>/download"><?= $e($d['title']) ?></a></td>
        <td><?= $e($categories[$d['category']]) ?></td>
        <td class="nowrap"><?= View::date($d['expires_on']) ?><?= $d['expires_on'] < $today ? ' <span class="chip chip--bad">Expired</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="muted">Nothing has expired and nothing expires in the next 60 days.</p><?php endif; ?>
</section>

<section class="card section" id="retention">
  <h2>Retention</h2>
  <p class="muted">How long you keep a leaver’s records is for your organisation to decide (your data protection policy and your legal advice). Set it here and this page lists people who left longer ago than that, so you can review them. Care Core never deletes anyone on its own.</p>
  <form class="form" method="post" action="/documents/retention"><?= Csrf::field() ?>
    <div class="form-group"><label for="years">Keep a leaver’s records for (years)</label><input id="years" name="years" type="number" min="1" max="50" value="<?= $years === null ? '' : (int) $years ?>"><span class="hint">Leave empty to turn this list off.</span></div>
    <button class="btn btn-secondary" type="submit">Save</button>
  </form>
  <?php if ($years !== null): ?>
    <h3>Left more than <?= (int) $years ?> year<?= $years === 1 ? '' : 's' ?> ago <span class="muted small">(<?= count($past) ?>)</span></h3>
    <?php if ($past): ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>Person</th><th>Last day</th><th class="right">Documents</th></tr></thead>
      <tbody><?php foreach ($past as $pe): ?>
        <tr><td><a href="/people/<?= $e($pe['uuid']) ?>/record#documents"><?= $e($pe['first_name'] . ' ' . $pe['last_name']) ?></a></td><td><?= View::date($pe['last_day']) ?></td><td class="right"><?= (int) $pe['documents'] ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <p class="small muted">Open a person to remove their documents. Their record in People stays: Care Core keeps one permanent identity for each person.</p>
    <?php else: ?><p class="muted">Nobody.</p><?php endif; ?>
  <?php endif; ?>
</section>
