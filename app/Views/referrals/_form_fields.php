<?php use App\Core\View; use App\Modules\Referrals\ReferralRepo; $e = [View::class, 'e']; $o = fn($k, $d = '') => $e($old[$k] ?? $d);
$types = ReferralRepo::planTypes($old['plan_types'] ?? []); ?>
<input type="hidden" name="plan_types_present" value="1">
<div class="formgrid">
  <div class="form-group"><label for="person_name">Person referred</label><input id="person_name" name="person_name" type="text" maxlength="150" required value="<?= $o('person_name') ?>"></div>
  <div class="form-group"><label for="source">Who referred them</label><select id="source" name="source" required><option value="">Choose</option><?php foreach (ReferralRepo::SOURCES as $k => $label): ?><option value="<?= $e($k) ?>"<?= ($old['source'] ?? '') === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= $o('date_of_birth') ?>"></div>
  <div class="form-group"><label for="nhs_number">CHI or NHS number</label><input id="nhs_number" name="nhs_number" type="text" inputmode="numeric" maxlength="12" value="<?= $o('nhs_number') ?>"></div>
  <div class="form-group"><label for="referrer">Referrer’s name or team</label><input id="referrer" name="referrer" type="text" maxlength="200" value="<?= $o('referrer') ?>"></div>
  <div class="form-group"><label for="received_on">Date received</label><input id="received_on" name="received_on" type="date" value="<?= $o('received_on') ?>"></div>
  <div class="form-group"><label for="wanted_from">Support wanted from</label><input id="wanted_from" name="wanted_from" type="date" value="<?= $o('wanted_from') ?>"></div>
</div>
<div class="form-group"><label for="reason">Why the referral was made</label><textarea id="reason" name="reason" rows="2" maxlength="600" required><?= $o('reason') ?></textarea></div>
<div class="form-group"><label for="needs_summary">What they need</label><textarea id="needs_summary" name="needs_summary" rows="3" maxlength="1000"><?= $o('needs_summary') ?></textarea></div>
<div class="form-group"><label for="what_matters">What matters to them</label><textarea id="what_matters" name="what_matters" rows="2" maxlength="600"><?= $o('what_matters') ?></textarea></div>
<div class="form-group"><label for="risks">Known risks</label><textarea id="risks" name="risks" rows="2" maxlength="600"><?= $o('risks') ?></textarea></div>
<fieldset class="form-group"><legend>Kinds of plan this calls for</legend>
  <?php foreach (ReferralRepo::PLAN_TYPES as $k => $label): ?><label class="check"><input type="checkbox" name="plan_types[]" value="<?= $e($k) ?>"<?= in_array($k, $types, true) ? ' checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?>
</fieldset>
<div class="form-group"><label for="skills">Specialist skills the support will need</label><textarea id="skills" name="skills" rows="3" placeholder="One per line, for example: PEG feeding, Makaton"><?= $o('skills') ?></textarea></div>
