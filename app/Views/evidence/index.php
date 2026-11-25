<?php use App\Core\View; $e = [View::class, 'e']; $s = $pack['summary'];
$st = ['in_date' => 'In date', 'soon' => 'Expires soon', 'expired' => 'Expired', 'current' => 'Current', 'not_done' => 'Not completed']; $cls = ['expired' => 'chip chip--bad', 'soon' => 'chip', 'not_done' => 'chip']; ?>
<div class="pagehead"><div><p class="eyebrow">Staff</p><h1>Staff evidence pack</h1>
<p class="muted">What is recorded in Care Core about <?= $pack['team'] ? 'the staff of ' . $e($pack['team']['name']) : 'all current staff' ?>, as it stood on <?= View::date($pack['as_at']) ?>. Use it for an inspection, a commissioner's visit or your own review. Print it from your browser, or download the spreadsheet.</p></div></div>

<section class="card section no-print"><form class="form" method="get" action="/evidence"><div class="formgrid">
  <div class="form-group"><label for="team">Team</label><select id="team" name="team"><option value="">All staff</option><?php foreach ($teams as $t): ?><option value="<?= $e($t['uuid']) ?>"<?= $teamUuid === $t['uuid'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label for="as_at">As at</label><input id="as_at" name="as_at" type="date" value="<?= $e($pack['as_at']) ?>"></div></div>
  <button class="btn btn-primary" type="submit">Show</button>
  <button class="btn btn-secondary" type="submit" name="format" value="csv">Download spreadsheet (CSV)</button>
  <button class="btn btn-secondary" type="submit" name="format" value="print">Print view</button></form></section>

<section class="card section">
  <h2>In summary</h2>
  <dl class="facts"><dt>Staff in post</dt><dd><?= (int) $s['staff'] ?></dd>
  <dt>Registrations in date</dt><dd><?= (int) $s['reg_in_date'] ?></dd><dt>Registrations expired</dt><dd><?= (int) $s['reg_expired'] ?></dd><dt>Staff with no registration recorded</dt><dd><?= (int) $s['reg_none'] ?></dd>
  <dt>Mandatory training current</dt><dd><?= (int) $s['train_current'] ?></dd><dt>Mandatory training expired</dt><dd><?= (int) $s['train_expired'] ?></dd>
  <dt>Supervision recorded as due and not since recorded</dt><dd><?= (int) $s['sup_overdue'] ?></dd><dt>Appraisal recorded as due and not since recorded</dt><dd><?= (int) $s['apr_overdue'] ?></dd></dl>
  <p class="small muted">This is a list of what has been recorded. It does not say anyone is, or is not, compliant: how often supervision should happen, which training is required and what an inspector will expect are for your organisation and your regulator. A person with “none recorded” may simply not have had it entered. “Recorded as due” means a next-due date was entered and has passed with nothing newer recorded.</p>
</section>

<section class="card section">
  <h2>Dates that have passed <span class="muted small">(<?= count($pack['gaps']) ?>)</span></h2>
  <?php if ($pack['gaps']): ?><div class="tablewrap"><table class="list"><thead><tr><th>Person</th><th>What</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($pack['gaps'] as $g): ?><tr><td><a href="/people/<?= $e($g['uuid']) ?>/record"><?= $e($g['person']) ?></a></td><td><?= $e($g['what']) ?></td><td class="nowrap"><?= View::date($g['date']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><p class="muted">Nothing recorded has passed its date.</p><?php endif; ?>
</section>

<section class="card section">
  <h2>Each member of staff</h2>
  <?php if (!$pack['staff']): ?><p class="muted">Nobody was in post on that date<?= $pack['team'] ? ' in that team' : '' ?>.</p><?php endif; ?>
  <?php foreach ($pack['staff'] as $m): ?>
  <div class="section evidence-person">
    <h3><a href="/people/<?= $e($m['uuid']) ?>/record"><?= $e($m['first_name'] . ' ' . $m['last_name']) ?></a> <span class="muted small">· <?= $e($m['job_title']) ?><?= $m['teams'] ? ' · ' . $e(implode(', ', $m['teams'])) : '' ?> · started <?= View::date($m['start_date']) ?></span></h3>
    <div class="tablewrap"><table class="list"><tbody>
      <tr><th scope="row">Registration</th><td><?php if (!$m['regs']): ?><span class="muted">None recorded</span><?php endif; ?>
        <?php foreach ($m['regs'] as $r): ?><div><?= $e($r['registration_type']) ?><?= $r['registration_number'] ? ' ' . $e($r['registration_number']) : '' ?> · expires <?= View::date($r['expiry_date']) ?> <span class="<?= $cls[$r['state']] ?? 'chip' ?>"><?= $e($st[$r['state']]) ?></span></div><?php endforeach; ?></td></tr>
      <tr><th scope="row">Supervision</th><td><?php if ($m['sup']): ?>Last <?= View::date($m['sup']['supervision_date']) ?> · <?= (int) $m['sup_count'] ?> in the 12 months before<?= $m['sup']['next_due_date'] ? ' · next recorded as due ' . View::date($m['sup']['next_due_date']) . ($m['sup_overdue'] ? ' <span class="chip chip--bad">Date passed</span>' : '') : '' ?><?php else: ?><span class="muted">None recorded</span><?php endif; ?></td></tr>
      <tr><th scope="row">Appraisal</th><td><?php if ($m['apr']): ?>Last <?= View::date($m['apr']['appraisal_date']) ?><?= $m['apr']['next_due_date'] ? ' · next recorded as due ' . View::date($m['apr']['next_due_date']) . ($m['apr_overdue'] ? ' <span class="chip chip--bad">Date passed</span>' : '') : '' ?><?php else: ?><span class="muted">None recorded</span><?php endif; ?></td></tr>
      <tr><th scope="row">Mandatory training</th><td><?php if (!$m['train']): ?><span class="muted">None recorded</span><?php endif; ?>
        <?php foreach ($m['train'] as $t): ?><div><?= $e($t['title']) ?> · <?= $t['expiry_date'] ? 'expires ' . View::date($t['expiry_date']) : 'no expiry' ?> <span class="<?= $cls[$t['state']] ?? 'chip' ?>"><?= $e($st[$t['state']]) ?></span></div><?php endforeach; ?></td></tr>
    </tbody></table></div>
  </div>
  <?php endforeach; ?>
</section>
<p class="small muted">Generated <?= View::date($generated) ?> from Care Core. Data as recorded on <?= View::date($pack['as_at']) ?>; records entered later with an earlier date will appear when this is run again.</p>
