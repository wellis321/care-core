<?php
use App\Core\Csrf;
use App\Core\View;
use App\Modules\Staff\StaffRecord;
$e = [View::class, 'e'];
if (!$person): ?>
<div class="pagehead"><h1>My profile</h1></div>
<div class="alert alert-error" role="status">Your sign-in is not linked to a staff record yet, so there is nothing to show here. Ask your manager or HR to link it.</div>
<?php return; endif;
$val = static fn(string $f) => array_key_exists($f, $old) ? $old[$f] : ($profile[$f] ?? $person[$f] ?? '');
$err = static fn(string $f) => $errors[$f] ?? null;
$field = static fn(array $a) => print View::partial('partials/field', $a);
$pendingBy = [];
foreach ($pending as $c) { $pendingBy[$c['field']] = $c; }
$secretNote = static fn(string $f) => ($profile[$f] ?? '') !== '' ? 'Currently ' . StaffRecord::mask($profile[$f]) . '. Leave empty to keep it.' : 'Nothing recorded yet.';
?>
<div class="pagehead"><div><p class="eyebrow">My profile</p><h1><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></h1>
<p class="muted"><?= $e($current['job_title'] ?? '') ?><?= $manager ? ' · reports to ' . $e($manager['first_name'] . ' ' . $manager['last_name']) : '' ?></p></div></div>

<?php if ($pending): ?>
<section class="section card" aria-labelledby="waiting"><h2 id="waiting">Waiting for approval</h2>
  <p class="muted small">Your line manager has these. Until they approve them, your record keeps the old details.</p>
  <?php foreach ($pending as $c): ?>
  <form method="post" action="/me/changes/withdraw" class="inlineform" style="margin:0 0 .6rem"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $e($c['uuid']) ?>">
    <span><strong><?= $e($c['label']) ?></strong>: <?= $c['field_type'] === 'file' ? 'a new picture' : '<span class="was">' . $e($c['current_value_shown'] ?: 'empty') . '</span> → ' . $e($c['proposed_value_shown'] ?: 'empty') ?></span>
    <button class="btn btn-secondary" type="submit">Withdraw</button></form>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php foreach ($decided as $c): if ($c['seen_at'] !== null) { continue; } ?>
  <div class="alert alert-<?= $c['status'] === 'approved' ? 'success' : 'error' ?>" role="status"><?= $e($c['label']) ?> was <?= $c['status'] === 'approved' ? 'approved' : 'declined' ?><?= $c['rejection_reason'] ? ': ' . $e($c['rejection_reason']) : '' ?>.</div>
<?php endforeach; ?>

<div class="split"><div>
<form class="form card section" method="post" action="/me">
  <?= Csrf::field() ?>
  <h2>My details</h2>
  <p class="muted small">Phone and emergency contact save straight away. Everything else goes to your line manager first.</p>
  <h3>Name and date of birth</h3>
  <div class="formgrid">
    <?php $field(['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'value' => $val('first_name'), 'error' => $err('first_name'), 'required' => true, 'autocomplete' => 'given-name']); ?>
    <?php $field(['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'value' => $val('last_name'), 'error' => $err('last_name'), 'required' => true, 'autocomplete' => 'family-name']); ?>
    <?php $field(['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'value' => $val('date_of_birth'), 'error' => $err('date_of_birth'), 'autocomplete' => 'bday']); ?>
    <?php $field(['name' => 'phone', 'label' => 'Phone', 'type' => 'text', 'value' => $val('phone'), 'error' => $err('phone'), 'autocomplete' => 'tel', 'hint' => 'Saved straight away.']); ?>
  </div>
  <h3>Home address</h3>
  <div class="formgrid">
    <?php $field(['name' => 'address_line1', 'label' => 'Address line 1', 'type' => 'text', 'value' => $val('address_line1'), 'error' => $err('address_line1'), 'autocomplete' => 'address-line1']); ?>
    <?php $field(['name' => 'address_line2', 'label' => 'Address line 2', 'type' => 'text', 'value' => $val('address_line2'), 'error' => $err('address_line2'), 'autocomplete' => 'address-line2']); ?>
    <?php $field(['name' => 'address_city', 'label' => 'Town or city', 'type' => 'text', 'value' => $val('address_city'), 'error' => $err('address_city'), 'autocomplete' => 'address-level2']); ?>
    <?php $field(['name' => 'address_county', 'label' => 'County', 'type' => 'text', 'value' => $val('address_county'), 'error' => $err('address_county')]); ?>
    <?php $field(['name' => 'address_postcode', 'label' => 'Postcode', 'type' => 'text', 'value' => $val('address_postcode'), 'error' => $err('address_postcode'), 'autocomplete' => 'postal-code']); ?>
    <?php $field(['name' => 'address_country', 'label' => 'Country', 'type' => 'text', 'value' => $val('address_country'), 'error' => $err('address_country'), 'autocomplete' => 'country-name']); ?>
  </div>
  <h3>Emergency contact</h3>
  <div class="formgrid">
    <?php $field(['name' => 'emergency_contact_name', 'label' => 'Name', 'type' => 'text', 'value' => $val('emergency_contact_name'), 'error' => $err('emergency_contact_name')]); ?>
    <?php $field(['name' => 'emergency_contact_phone', 'label' => 'Phone', 'type' => 'text', 'value' => $val('emergency_contact_phone'), 'error' => $err('emergency_contact_phone'), 'hint' => 'Saved straight away.']); ?>
  </div>
  <h3>Pay and tax</h3>
  <div class="formgrid">
    <?php $field(['name' => 'ni_number', 'label' => 'National Insurance number', 'type' => 'text', 'value' => $old['ni_number'] ?? '', 'error' => $err('ni_number'), 'hint' => $secretNote('ni_number')]); ?>
    <?php $field(['name' => 'bank_account_name', 'label' => 'Name on the bank account', 'type' => 'text', 'value' => $old['bank_account_name'] ?? '', 'error' => $err('bank_account_name'), 'hint' => $secretNote('bank_account_name')]); ?>
    <?php $field(['name' => 'bank_sort_code', 'label' => 'Sort code', 'type' => 'text', 'value' => $old['bank_sort_code'] ?? '', 'error' => $err('bank_sort_code'), 'hint' => $secretNote('bank_sort_code')]); ?>
    <?php $field(['name' => 'bank_account_number', 'label' => 'Account number', 'type' => 'text', 'value' => $old['bank_account_number'] ?? '', 'error' => $err('bank_account_number'), 'hint' => $secretNote('bank_account_number')]); ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Save my details</button></div>
</form>

<section class="section card" id="photo" aria-labelledby="ph">
  <h2 id="ph">Photo</h2>
  <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start">
    <?php if ($profile['photo_path']): ?><img class="avatar" src="/files/photo/<?= $e($person['uuid']) ?>" alt="Your current photo"><?php else: ?><div class="avatar avatar--empty">No photo</div><?php endif; ?>
    <?php if (isset($pendingBy['photo_path'])): ?><div><img class="avatar" src="/files/pending/<?= $e($pendingBy['photo_path']['uuid']) ?>" alt="Your new photo, waiting for approval"><span class="hint">Waiting for approval</span></div><?php endif; ?>
    <form method="post" action="/me/photo" enctype="multipart/form-data" class="form" style="flex:1;min-width:14rem"><?= Csrf::field() ?>
      <div class="form-group"><label for="photo_file">Choose a new photo</label><input id="photo_file" type="file" name="photo" accept="image/png,image/jpeg,image/webp,image/gif" required><span class="hint">PNG, JPEG, WebP or GIF, up to 3 MB. Your line manager approves it.</span></div>
      <button class="btn btn-secondary" type="submit">Send photo</button></form>
  </div>
</section>

<section class="section card" id="signature" aria-labelledby="sg">
  <h2 id="sg">Signature</h2>
  <?php if ($profile['signature_path']): ?><img class="sigimg" src="/files/signature/<?= $e($person['uuid']) ?>" alt="Your current signature"><p class="muted small">Added <?= View::date(substr((string) $profile['signature_at'], 0, 10)) ?>.</p><?php else: ?><p class="muted">You have not added a signature yet.</p><?php endif; ?>
  <?php if (isset($pendingBy['signature_path'])): ?><p><img class="sigimg" src="/files/pending/<?= $e($pendingBy['signature_path']['uuid']) ?>" alt="Your new signature, waiting for approval"><span class="hint">Waiting for approval</span></p><?php endif; ?>
  <form method="post" action="/me/signature" enctype="multipart/form-data" class="form"><?= Csrf::field() ?>
    <input type="hidden" name="signature_data" value="">
    <p><label for="sigpad"><strong>Draw it</strong> with your mouse, finger or a pen</label></p>
    <canvas id="sigpad" class="sigpad" role="img" aria-label="Signature drawing area"></canvas>
    <p><button class="btn btn-secondary" type="button" id="sigclear">Clear</button></p>
    <div class="form-group"><label for="signature_file">Or upload a picture of it</label><input id="signature_file" type="file" name="signature_file" accept="image/png,image/jpeg,image/webp,image/gif"><span class="hint">Up to 1 MB. Your line manager approves it.</span></div>
    <p id="sigmsg" class="field-error" role="alert" hidden></p>
    <button class="btn btn-primary" type="submit">Send signature</button>
  </form>
</section>
</div>

<aside>
  <section class="section card"><h2>My job</h2>
    <dl class="facts"><dt>Role</dt><dd><?= $e($current['job_title'] ?? '—') ?></dd>
    <dt>Since</dt><dd><?= View::date($current['start_date'] ?? null) ?></dd>
    <dt>Reports to</dt><dd><?= $manager ? $e($manager['first_name'] . ' ' . $manager['last_name']) : '—' ?></dd>
    <dt>Team</dt><dd><?php foreach ($teams as $t): ?><?= $e($t['team_name']) ?><br><?php endforeach; ?><?= $teams ? '' : '—' ?></dd></dl>
    <p class="muted small">Your manager or HR keeps these up to date.</p></section>
  <?php foreach (['registration' => ['Registrations', 'registration_type', 'expiry_date', 'Expires'], 'supervision' => ['Supervisions', 'supervision_date', 'next_due_date', 'Next due'], 'appraisal' => ['Appraisals', 'appraisal_date', 'next_due_date', 'Next due'], 'learning' => ['Learning', 'title', 'expiry_date', 'Expires']] as $k => [$h, $main, $due, $dueLabel]): if (!$lists[$k]) { continue; } ?>
  <section class="section card"><h2><?= $e($h) ?></h2><ul class="plain" style="list-style:none;margin:0;padding:0">
    <?php foreach (array_slice($lists[$k], 0, 5) as $row): ?><li style="padding:.35rem 0;border-top:1px solid var(--rule-soft,#ebe8df)"><?= $k === 'supervision' || $k === 'appraisal' ? View::date($row[$main]) : $e($row[$main]) ?><?php if (!empty($row[$due])): ?><br><span class="muted small"><?= $e($dueLabel) ?> <?= View::date($row[$due]) ?></span><?php endif; ?></li><?php endforeach; ?></ul></section>
  <?php endforeach; ?>
</aside></div>
<script src="/assets/signature-pad.js" defer></script>
