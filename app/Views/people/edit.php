<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/people">People</a> › <a href="/people/<?= $e($person['uuid']) ?>"><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></a> › Edit</p>
<div class="pagehead"><h1>Edit details</h1></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<form class="form" method="post">
  <?= Csrf::field() ?>
  <div class="formgrid">
    <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" required value="<?= $e($old['first_name'] ?? '') ?>"></div>
    <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" required value="<?= $e($old['last_name'] ?? '') ?>"></div>
    <div class="form-group"><label for="preferred_name">Known as</label><input id="preferred_name" name="preferred_name" type="text" value="<?= $e($old['preferred_name'] ?? '') ?>"></div>
    <div class="form-group"><label for="date_of_birth">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="<?= $e($old['date_of_birth'] ?? '') ?>"></div>
    <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= $e($old['email'] ?? '') ?>"></div>
    <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" type="text" value="<?= $e($old['phone'] ?? '') ?>"></div>
    <div class="form-group full"><label for="notes">Notes</label><textarea id="notes" name="notes"><?= $e($old['notes'] ?? '') ?></textarea></div>
  </div>
  <div class="actions"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-secondary" href="/people/<?= $e($person['uuid']) ?>">Cancel</a></div>
</form>
