<?php
/** One labelled form field. $name, $label, $type (text|date|url|number|textarea|select|checkbox), optional $value, $error, $options, $hint, $full, $autocomplete, $required */
use App\Core\View;
$e = [View::class, 'e'];
$value = $value ?? '';
$error = $error ?? null;
$id = 'f_' . $name;
$desc = ($error ? $id . '_err ' : '') . (!empty($hint) ? $id . '_hint' : '');
?>
<div class="form-group<?= !empty($full) ? ' full' : '' ?><?= $error ? ' has-error' : '' ?>">
<?php if ($type === 'checkbox'): ?>
  <label class="check" for="<?= $e($id) ?>"><input id="<?= $e($id) ?>" name="<?= $e($name) ?>" type="checkbox" value="1"<?= $value ? ' checked' : '' ?>> <?= $e($label) ?></label>
<?php else: ?>
  <label for="<?= $e($id) ?>"><?= $e($label) ?><?= !empty($required) ? ' <span class="muted small">(required)</span>' : '' ?></label>
  <?php if ($type === 'textarea'): ?>
    <textarea id="<?= $e($id) ?>" name="<?= $e($name) ?>"<?= $desc ? ' aria-describedby="' . $e(trim($desc)) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?>><?= $e($value) ?></textarea>
  <?php elseif ($type === 'select'): ?>
    <select id="<?= $e($id) ?>" name="<?= $e($name) ?>"<?= $desc ? ' aria-describedby="' . $e(trim($desc)) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?>>
      <?php foreach ($options as $k => $o): ?><option value="<?= $e($k) ?>"<?= (string) $k === (string) $value ? ' selected' : '' ?>><?= $e($o) ?></option><?php endforeach; ?>
    </select>
  <?php else: ?>
    <input id="<?= $e($id) ?>" name="<?= $e($name) ?>" type="<?= $e($type) ?>"<?= $type === 'number' ? ' step="0.01" min="0"' : '' ?> value="<?= $e($value) ?>"<?= !empty($autocomplete) ? ' autocomplete="' . $e($autocomplete) . '"' : '' ?><?= !empty($required) ? ' required' : '' ?><?= $desc ? ' aria-describedby="' . $e(trim($desc)) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?>>
  <?php endif; ?>
<?php endif; ?>
  <?php if (!empty($hint)): ?><span class="hint" id="<?= $e($id) ?>_hint"><?= $e($hint) ?></span><?php endif; ?>
  <?php if ($error): ?><span class="field-error" id="<?= $e($id) ?>_err"><?= $e($error) ?></span><?php endif; ?>
</div>
