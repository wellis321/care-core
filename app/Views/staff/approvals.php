<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead"><div><p class="eyebrow">Staff records</p><h1>Approvals</h1><p class="muted">Changes people have asked to make to their own record. You see the people who report to you; HR and managers see everyone.</p></div></div>
<?php if (!$queue): ?>
<p class="empty">Nothing is waiting for you.</p>
<?php else: $last = null; foreach ($queue as $c): if ($last !== $c['person_uuid']): if ($last !== null): ?></div></section><?php endif; $last = $c['person_uuid']; ?>
<section class="section"><h2><a href="/people/<?= $e($c['person_uuid']) ?>"><?= $e($c['first_name'] . ' ' . $c['last_name']) ?></a></h2><div class="card">
<?php endif; ?>
  <div class="changeset" style="padding:.6rem 0;border-top:1px solid var(--rule-soft,#ebe8df)">
    <div><strong><?= $e($c['label']) ?></strong><br><span class="muted small">asked <?= View::date(substr($c['submitted_at'], 0, 10)) ?></span></div>
    <div>
    <?php if ($c['field_type'] === 'file'): ?>
      <img class="<?= $c['field'] === 'signature_path' ? 'sigimg' : 'avatar' ?>" src="/files/pending/<?= $e($c['uuid']) ?>" alt="New <?= $e(strtolower($c['label'])) ?> for <?= $e($c['first_name']) ?>">
    <?php else: ?>
      <span class="was"><?= $e($c['current_value_shown'] ?: 'empty') ?></span> → <strong><?= $e($c['proposed_value_shown'] ?: 'empty') ?></strong>
    <?php endif; ?>
    </div>
    <form method="post" action="/approvals/<?= $e($c['uuid']) ?>" class="inlineform" style="grid-column:1/-1;margin:.4rem 0 .2rem"><?= Csrf::field() ?>
      <div class="form-group"><label for="r<?= $e($c['uuid']) ?>" class="sr">Reason if declining</label><input id="r<?= $e($c['uuid']) ?>" type="text" name="reason" maxlength="300" placeholder="Reason if declining (optional)"></div>
      <button class="btn btn-primary" type="submit" name="decision" value="approve">Approve</button>
      <button class="btn btn-secondary" type="submit" name="decision" value="reject">Decline</button>
    </form>
  </div>
<?php endforeach; ?></div></section><?php endif; ?>
