<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; $o = fn($k) => $e($old[$k] ?? ''); ?>
<p class="crumbs"><a href="/people">People</a> › Add a person we support</p>
<div class="pagehead"><div><p class="eyebrow">Care</p><h1>Add a person we support</h1></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<form class="form form--wide" method="post">
  <?= Csrf::field() ?>
  <div class="formgrid">
    <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" required value="<?= $o('first_name') ?>"></div>
    <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" required value="<?= $o('last_name') ?>"></div>
    <div class="form-group"><label for="preferred_name">Known as</label><input id="preferred_name" name="preferred_name" type="text" value="<?= $o('preferred_name') ?>"></div>
    <div class="form-group"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= $o('date_of_birth') ?>"></div>
    <div class="form-group"><label for="start_date">Support starts on</label><input id="start_date" name="start_date" type="date" required value="<?= $o('start_date') ?>"></div>
    <div class="form-group"><label for="reference">Reference</label><input id="reference" name="reference" type="text" value="<?= $o('reference') ?>"></div>
    <div class="form-group"><label for="nhs_number">NHS number</label><input id="nhs_number" name="nhs_number" type="text" value="<?= $o('nhs_number') ?>"><span class="hint">Ten digits. We check it.</span></div>
    <div class="form-group"><label for="local_authority_ref">Local authority reference</label><input id="local_authority_ref" name="local_authority_ref" type="text" value="<?= $o('local_authority_ref') ?>"></div>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Add</button><a class="btn btn-secondary" href="/people">Cancel</a></div>
</form>
