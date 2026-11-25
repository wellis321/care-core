<?php
use App\Core\Csrf;
use App\Core\View;
use App\Modules\Staff\Compliance;
use App\Modules\Staff\StaffRecord;
$e = [View::class, 'e'];
$field = static fn(array $a) => print View::partial('partials/field', $a);
$name = $person['first_name'] . ' ' . $person['last_name'];
$base = '/people/' . $person['uuid'];
$pv = static fn(string $f) => array_key_exists($f, $old) ? $old[$f] : ($profile[$f] ?? '');
$op = static fn(string $s): string => $open === $s ? ' open' : '';
$ucw = static fn(string $s) => ucwords(str_replace('_', ' ', $s));
?>
<p class="crumbs"><a href="/people">People</a> › <a href="<?= $e($base) ?>"><?= $e($name) ?></a> › Staff record</p>
<div class="pagehead"><div><p class="eyebrow">Staff record</p><h1><?= $e($name) ?></h1><p class="muted"><?= $e($employment['job_title'] ?? 'No employment recorded') ?><?= $employment && $employment['end_date'] ? ' · left ' . View::date($employment['end_date']) : '' ?></p></div>
<div class="actions"><a class="btn btn-secondary" href="<?= $e($base) ?>">Back to profile</a></div></div>
<?php if ($errors || $formErrors): ?><div class="alert alert-error" role="alert">Some of what you entered needs another look. The details are marked below.</div><?php endif; ?>

<details class="fold" id="profile"<?= ($open === '' || $open === 'profile') ? ' open' : '' ?>><summary>Personal details, address, emergency contact, pay and tax</summary><div class="fold__body">
<form class="form" method="post" action="<?= $e($base) ?>/record/profile"><?= Csrf::field() ?>
  <div class="formgrid">
    <?php foreach (['address_line1' => 'Address line 1', 'address_line2' => 'Address line 2', 'address_city' => 'Town or city', 'address_county' => 'County', 'address_postcode' => 'Postcode', 'address_country' => 'Country', 'emergency_contact_name' => 'Emergency contact name', 'emergency_contact_phone' => 'Emergency contact phone', 'ni_number' => 'National Insurance number', 'bank_account_name' => 'Name on the bank account', 'bank_sort_code' => 'Sort code', 'bank_account_number' => 'Account number'] as $f => $l) {
      $field(['name' => $f, 'label' => $l, 'type' => 'text', 'value' => $pv($f), 'error' => $errors[$f] ?? null]);
    } ?>
  </div>
  <p class="muted small">National Insurance and bank details are stored encrypted and only people who manage staff can see them.</p>
  <div class="actions"><button class="btn btn-primary" type="submit">Save personal details</button></div>
</form>
<div class="grid cols-2" style="margin-top:1rem">
  <div><h2>Photo</h2><?php if ($profile['photo_path']): ?><img class="avatar" src="/files/photo/<?= $e($person['uuid']) ?>" alt="Photo of <?= $e($name) ?>"><?php else: ?><div class="avatar avatar--empty">No photo</div><?php endif; ?>
    <form method="post" action="<?= $e($base) ?>/record/file" enctype="multipart/form-data" class="form" style="margin-top:.6rem"><?= Csrf::field() ?><input type="hidden" name="field" value="photo">
      <div class="form-group"><label for="mphoto" class="sr">Photo file</label><input id="mphoto" type="file" name="file" accept="image/png,image/jpeg,image/webp,image/gif" required></div><button class="btn btn-secondary" type="submit">Set photo</button></form></div>
  <div><h2>Signature</h2><?php if ($profile['signature_path']): ?><img class="sigimg" src="/files/signature/<?= $e($person['uuid']) ?>" alt="Signature of <?= $e($name) ?>"><?php else: ?><p class="muted">None yet. They can add it under My profile.</p><?php endif; ?>
    <form method="post" action="<?= $e($base) ?>/record/file" enctype="multipart/form-data" class="form" style="margin-top:.6rem"><?= Csrf::field() ?><input type="hidden" name="field" value="signature">
      <div class="form-group"><label for="msig" class="sr">Signature file</label><input id="msig" type="file" name="file" accept="image/png,image/jpeg,image/webp,image/gif" required></div><button class="btn btn-secondary" type="submit">Set signature</button></form></div>
</div></div></details>

<?php if ($employment): foreach (StaffRecord::SECTIONS as $sec => [$title, $fields]): ?>
<details class="fold" id="<?= $e($sec) ?>"<?= $op($sec) ?>><summary><?= $e($title) ?><?php if ($sec === 'tupe' && !empty($details['is_tupe'])): ?> <span class="chip chip--warn">TUPE</span><?php endif; ?></summary><div class="fold__body">
<form class="form" method="post" action="<?= $e($base) ?>/record/section"><?= Csrf::field() ?><input type="hidden" name="section" value="<?= $e($sec) ?>">
  <div class="formgrid">
  <?php foreach ($fields as $f => [$type, $label]) {
    $v = array_key_exists($f, $old) && ($open === $sec) ? $old[$f] : ($details[$f] ?? '');
    $field(['name' => $f, 'label' => $label, 'type' => $type === 'bool' ? 'checkbox' : ($type === 'longtext' ? 'textarea' : ($type === 'num' ? 'number' : ($type === 'date' ? 'date' : ($type === 'url' ? 'url' : 'text')))), 'value' => $v, 'error' => $errors[$f] ?? null, 'full' => $type === 'longtext']);
  } ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Save <?= $e(strtolower($title)) ?></button></div>
</form></div></details>
<?php endforeach; else: ?><p class="muted">Employment terms (TUPE, working time, visa, apprenticeship, leave) appear here once there is an employment.</p><?php endif; ?>

<details class="fold" id="documents"<?= $op('documents') ?>><summary>Documents (<?= count($documents) ?>)</summary><div class="fold__body">
<?php if ($documents): ?><div class="tablewrap"><table class="list"><thead><tr><th>Document</th><th>Kind</th><th>Expires</th><th>Shared</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
<?php foreach ($documents as $d): $exp = $d['expires_on']; ?><tr>
  <td><a href="/documents/<?= $e($d['uuid']) ?>/download"><?= $e($d['title']) ?></a><br><span class="muted small"><?= $e($d['original_name']) ?> · <?= $e(number_format($d['size_bytes'] / 1024, 0)) ?> KB · added <?= View::date(substr($d['created_at'], 0, 10)) ?></span></td>
  <td><?= $e(\App\Modules\Documents\DocumentRepo::CATEGORIES[$d['category']]) ?></td>
  <td><?= $exp ? View::date($exp) . ($exp < date('Y-m-d') ? ' <span class="chip chip--bad">Expired</span>' : '') : '—' ?></td>
  <td><form method="post" action="/documents/<?= $e($d['uuid']) ?>/share" class="row-actions"><?= Csrf::field() ?><label class="check"><input type="checkbox" name="shared" value="1"<?= $d['shared_with_person'] ? ' checked' : '' ?>> They can see it</label> <button class="btn btn-secondary" type="submit">Save</button></form></td>
  <td class="right"><form method="post" action="/documents/<?= $e($d['uuid']) ?>/remove" class="row-actions" onsubmit="return confirm('Remove this document for good?');"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit">Remove</button></form></td>
</tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="muted">No documents yet.</p><?php endif; ?>
<h2>Add a document</h2>
<form class="form" method="post" action="<?= $e($base) ?>/documents" enctype="multipart/form-data"><?= Csrf::field() ?>
  <div class="formgrid">
    <div class="form-group"><label for="doc_file">File (PDF, Word, PNG or JPEG, up to 10 MB)</label><input id="doc_file" type="file" name="file" accept=".pdf,.docx,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg" required></div>
    <div class="form-group"><label for="doc_title">Title</label><input id="doc_title" name="title" type="text" maxlength="150"></div>
    <div class="form-group"><label for="doc_cat">Kind</label><select id="doc_cat" name="category"><?php foreach (\App\Modules\Documents\DocumentRepo::CATEGORIES as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="doc_exp">Expires (optional)</label><input id="doc_exp" name="expires_on" type="date"></div>
    <div class="form-group"><label for="doc_notes">Notes (optional)</label><input id="doc_notes" name="notes" type="text" maxlength="500"></div>
    <div class="form-group"><label class="check"><input type="checkbox" name="shared" value="1"> Let them see it under My profile</label></div>
  </div>
  <button class="btn btn-primary" type="submit">Save document</button>
</form>
<p class="muted small">Files are kept privately and downloaded only by people who manage staff. Care Core does not read them. <a href="<?= $e($base) ?>/subject-access">Download everything held about <?= $e($person['first_name']) ?></a> (for a subject access request).</p>
</div></details>

<?php foreach (Compliance::KINDS as $kind => $k): $rows = $lists[$kind]; $fe = $formErrors[$kind] ?? []; ?>
<details class="fold" id="<?= $e($kind) ?>"<?= $op($kind) ?>><summary><?= $e($k['title']) ?> (<?= count($rows) ?>)</summary><div class="fold__body">
<?php if ($rows): ?><div class="tablewrap"><table class="list"><thead><tr>
  <?php foreach ($k['fields'] as $f => [$type, $label]): if ($type === 'longtext') { continue; } ?><th><?= $e($label) ?></th><?php endforeach; ?><th><span class="sr">Actions</span></th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?><tr>
    <?php foreach ($k['fields'] as $f => [$type, $label]): if ($type === 'longtext') { continue; } ?><td>
      <?php if ($type === 'date'): ?><?= View::date($row[$f]) ?><?php elseif ($type === 'bool'): ?><?= $row[$f] ? 'Yes' : 'No' ?><?php elseif ($type === 'person'): ?><?= $e($row['other_name'] ?? '') ?: '—' ?><?php elseif (str_starts_with($type, 'enum:')): ?><?= $e($ucw((string) $row[$f])) ?><?php else: ?><?= $e((string) $row[$f]) ?: '—' ?><?php endif; ?></td><?php endforeach; ?>
    <td class="right"><form method="post" action="<?= $e($base) ?>/compliance/<?= $e($kind) ?>/remove" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $e($row['uuid']) ?>"><button class="btn btn-secondary" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="muted">None recorded.</p><?php endif; ?>
<?php if ($fe): ?><div class="alert alert-error" role="alert"><ul style="margin:0;padding-left:1.1rem"><?php foreach ($fe as $m): ?><li><?= $e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<h2>Add</h2>
<form class="form" method="post" action="<?= $e($base) ?>/compliance/<?= $e($kind) ?>"><?= Csrf::field() ?>
  <div class="formgrid">
  <?php foreach ($k['fields'] as $f => [$type, $label, $required]) {
    $v = $open === $kind && array_key_exists($f, $old) ? $old[$f] : '';
    if (str_starts_with($type, 'enum:')) {
      $opts = []; foreach (explode(',', substr($type, 5)) as $o) { $opts[$o] = $ucw($o); }
      $field(['name' => $f, 'label' => $label, 'type' => 'select', 'options' => $opts, 'value' => $v ?: array_key_first($opts)]);
    } elseif ($type === 'person') {
      $opts = ['' => 'Not recorded']; foreach ($colleagues as $c) { $opts[$c['uuid']] = $c['first_name'] . ' ' . $c['last_name']; }
      $field(['name' => $f, 'label' => $label, 'type' => 'select', 'options' => $opts, 'value' => $v]);
    } else {
      $field(['name' => $f, 'label' => $label, 'type' => $type === 'bool' ? 'checkbox' : ($type === 'longtext' ? 'textarea' : ($type === 'int' ? 'number' : ($type === 'date' ? 'date' : 'text'))), 'value' => $type === 'bool' ? ($open === $kind ? $v : ($f === 'is_required_for_role' ? 1 : 0)) : $v, 'required' => $required, 'full' => $type === 'longtext']);
    }
  } ?>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Add</button></div>
</form></div></details>
<?php endforeach; ?>
