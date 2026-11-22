<?php use App\Core\Csrf; use App\Core\View; use App\Modules\Referrals\ReferralRepo; $e = [View::class, 'e'];
$tabs = ['' => 'All'] + ReferralRepo::STATUS; ?>
<div class="pagehead"><div><p class="eyebrow">People we support</p><h1>Referrals</h1><p class="muted">Where a person’s support starts. Record a referral, assess it, decide it, and link it to the person when they arrive. Care Plans and other products read it from here.</p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<nav class="tabs" aria-label="Referral status">
  <?php foreach ($tabs as $k => $label): ?><a href="/referrals<?= $k ? '?status=' . $e($k) : '' ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
</nav>
<div class="split">
  <section class="card">
    <?php if ($referrals): ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>Referral</th><th>Source</th><th>Received</th><th>Status</th><th class="right">Skills</th></tr></thead>
      <tbody>
      <?php foreach ($referrals as $r): ?>
        <tr>
          <td><a href="/referrals/<?= $e($r['uuid']) ?>"><?= $e($r['reference']) ?> · <?= $e($r['person_name']) ?></a></td>
          <td><?= $e(ReferralRepo::SOURCES[$r['source']] ?? $r['source']) ?></td>
          <td><?= View::date($r['received_on']) ?></td>
          <td><span class="chip"><?= $e(ReferralRepo::STATUS[$r['status']]) ?></span></td>
          <td class="right"><?= (int) $r['n_skills'] ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="muted">No referrals<?= $status ? ' with that status' : ' yet' ?>.</p><?php endif; ?>
  </section>
  <aside class="card">
    <h2>Record a referral</h2>
    <form class="form" method="post" action="/referrals">
      <?= Csrf::field() ?>
      <?php require __DIR__ . '/_form_fields.php'; ?>
      <button class="btn btn-primary" type="submit">Record referral</button>
    </form>
  </aside>
</div>
