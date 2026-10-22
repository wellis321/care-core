<?php use App\Core\Config; use App\Core\Csrf; use App\Core\View; use App\Modules\Identity\Oidc; $e = [View::class, 'e']; $sso = Oidc::configured(); ?>
<p class="crumbs"><a href="/admin">Admin</a> › Integrations</p>
<div class="pagehead"><div><h1>Integrations</h1><p class="muted">Care Core announces every joiner, mover and leaver. Other systems listen, or push changes in.</p></div></div>

<section class="section">
  <div class="section__head"><h2>Microsoft Entra: sign in with Microsoft</h2></div>
  <div class="card">
    <p><?= $sso ? '<span class="chip chip--staff">On</span> Staff can sign in with their Microsoft work account.' : '<span class="chip chip--warn">Not set up</span> Staff sign in with a password.' ?></p>
    <p>In Entra, register an application (<strong>Accounts in this organisational directory only</strong>), add a <strong>Web</strong> redirect address of:</p>
    <p class="secret"><?= $e(Oidc::redirectUri() ?: 'Set APP_URL in the server settings first') ?></p>
    <p class="muted small">Then add <code>ENTRA_TENANT_ID</code>, <code>ENTRA_CLIENT_ID</code> and <code>ENTRA_CLIENT_SECRET</code> to the server settings file (.env), with <code>APP_URL</code> set to this site’s address. Nobody is created by signing in: people are created by joining them here or by SCIM below, and Microsoft sign-in only works for them. Set <code>PASSWORD_LOGIN=false</code> to switch passwords off for everyone except administrators, who keep one in case Microsoft is unavailable.</p>
    <p class="muted small">Passwords are <?= Config::bool('PASSWORD_LOGIN', true) || !$sso ? 'currently allowed for everyone' : 'currently off for everyone except administrators' ?>.</p>
  </div>
</section>

<section class="section" id="api">
  <div class="section__head"><h2>API keys</h2></div>
  <div class="card">
    <p>Other systems use the <a href="/docs/api">Care Core API</a> with a key. Each key can only do what you tick, can be limited to the addresses it will be used from, and can expire. Base address: <span class="secret"><?= $e(\App\Core\Config::appUrl() . '/api/v1') ?></span></p>
    <table class="list" style="margin:1rem 0"><thead><tr><th>Key</th><th>Can</th><th>Last used</th><th>Expires</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
      <?php foreach ($apiKeys as $k): ?><tr>
        <td><?= $e($k['name']) ?><br><span class="muted small"><code><?= $e($k['key_prefix']) ?>…</code> · <?= (int) $k['rate_limit_per_minute'] ?>/min<?= $k['allowed_ips'] ? ' · ' . $e($k['allowed_ips']) : '' ?></span> <?= $k['active'] ? '' : '<span class="chip chip--bad">Revoked</span>' ?></td>
        <td class="small"><?= $e(str_replace(',', ', ', $k['scopes'])) ?></td>
        <td class="nowrap"><?= View::date($k['last_used_at'] ? substr($k['last_used_at'], 0, 10) : null, 'never') ?></td>
        <td class="nowrap"><?= View::date($k['expires_at'] ? substr($k['expires_at'], 0, 10) : null, 'never') ?></td>
        <td class="right"><?php if ($k['active']): ?><form method="post" action="/admin/api-keys/revoke" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-secondary" type="submit">Revoke</button></form><?php endif; ?></td>
      </tr><?php endforeach; ?>
      <?php if (!$apiKeys): ?><tr class="empty-row"><td colspan="5">No API keys yet.</td></tr><?php endif; ?>
    </tbody></table>
    <form method="post" action="/admin/api-keys" class="form form--wide">
      <?= Csrf::field() ?>
      <h3>Create a key</h3>
      <div class="formgrid">
        <div class="form-group"><label for="kname">Name</label><input id="kname" name="name" type="text" required maxlength="100" placeholder="For example Payroll sync"><span class="hint">The system that will use it. It appears in the activity log.</span></div>
        <div class="form-group"><label for="kips">Allowed addresses (optional)</label><input id="kips" name="allowed_ips" type="text" placeholder="203.0.113.5, 198.51.100.0/24"><span class="hint">Separated by commas. Empty means any address.</span></div>
        <div class="form-group"><label for="kexp">Expires on (optional)</label><input id="kexp" name="expires_on" type="date"></div>
        <div class="form-group"><label for="krate">Requests per minute</label><input id="krate" name="rate_limit" type="number" min="10" max="6000" value="120"></div>
      </div>
      <fieldset style="border:0;padding:0;margin:0 0 1rem"><legend><strong>What this key can do</strong></legend>
        <p class="muted small">Give each key the least it needs. <strong>staff.sensitive</strong> is National Insurance and bank details.</p>
        <div class="formgrid">
        <?php foreach (\App\Api\Scopes::ALL as $scope => $desc): ?>
          <div class="form-group"><label class="check" for="s_<?= $e($scope) ?>"><input id="s_<?= $e($scope) ?>" type="checkbox" name="scopes[<?= $e($scope) ?>]" value="1"> <span><strong><?= $e($scope) ?></strong><br><span class="muted small"><?= $e($desc) ?></span></span></label></div>
        <?php endforeach; ?>
        </div>
      </fieldset>
      <div class="actions"><button class="btn btn-primary" type="submit">Create API key</button></div>
    </form>
  </div>
</section>

<section class="section">
  <div class="section__head"><h2>Microsoft Entra: joiners and leavers pushed in (SCIM)</h2></div>
  <div class="card">
    <p>In Entra, add an enterprise application with <strong>automatic provisioning</strong>. Use this tenant URL and a token from below:</p>
    <p class="secret"><?= $e($scimUrl) ?></p>
    <p class="muted small">When Entra disables a user, Care Core runs the whole leaver process: their employment ends, team places and key worker links end, their login stops, and offboarding tasks are created.</p>
    <table class="list" style="margin:1rem 0"><thead><tr><th>Token</th><th>Created</th><th>Last used</th><th><span class="sr">Actions</span></th></tr></thead><tbody>
      <?php foreach ($tokens as $t): ?><tr><td><?= $e($t['name']) ?> <?= $t['active'] ? '' : '<span class="chip chip--bad">Revoked</span>' ?></td><td class="nowrap"><?= View::date($t['created_at']) ?></td><td class="nowrap"><?= View::date($t['last_used_at'], 'never') ?></td><td class="right"><?php if ($t['active']): ?><form method="post" action="/admin/scim/revoke" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-secondary" type="submit">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$tokens): ?><tr class="empty-row"><td colspan="4">No tokens yet.</td></tr><?php endif; ?>
    </tbody></table>
    <form class="inlineform" method="post" action="/admin/scim"><?= Csrf::field() ?><div class="form-group"><label for="tname">Token name</label><input id="tname" name="name" type="text" required placeholder="Microsoft Entra"></div><button class="btn btn-primary" type="submit">Create token</button></form>
  </div>
</section>

<section class="section">
  <div class="section__head"><h2>Webhooks: events sent out</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Address</th><th>Events</th><th>Delivered</th><th>Waiting or failed</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($webhooks as $w): ?>
      <tr><td class="small" style="word-break:break-all"><?= $e($w['url']) ?> <?= $w['active'] ? '' : '<span class="chip chip--warn">Paused</span>' ?></td><td><?= $e($w['event_types']) ?></td><td><?= (int) $w['delivered'] ?></td><td><?= (int) $w['undelivered'] ?></td><td class="right"><form method="post" action="/admin/webhooks/toggle" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button class="btn btn-secondary" type="submit"><?= $w['active'] ? 'Pause' : 'Resume' ?></button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$webhooks): ?><tr class="empty-row"><td colspan="5">No webhooks yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <form class="inlineform" method="post" action="/admin/webhooks" style="margin-top:0.75rem"><?= Csrf::field() ?>
    <div class="form-group"><label for="url">Address (https)</label><input id="url" name="url" type="url" required placeholder="https://example.com/hooks/carecore"></div>
    <div class="form-group"><label for="event_types">Events</label><input id="event_types" name="event_types" type="text" value="*" placeholder="employment.*, keyworker.*"></div>
    <button class="btn btn-primary" type="submit">Add webhook</button>
  </form>
  <p class="muted small">Each event is a small signed message with identifiers and dates only (no names, no health details). Failed deliveries are retried.</p>
</section>

<section class="section">
  <div class="section__head"><h2>Recent events</h2></div>
  <div class="tablewrap"><table class="list"><thead><tr><th>Event</th><th>When</th><th>Queued for delivery</th></tr></thead><tbody>
    <?php foreach ($events as $ev): ?><tr><td><code><?= $e($ev['type']) ?></code></td><td class="nowrap"><?= $e(date('j M Y H:i', strtotime($ev['occurred_at']))) ?></td><td><?= $ev['fanned_out_at'] ? 'Yes' : 'Not yet' ?></td></tr><?php endforeach; ?>
    <?php if (!$events): ?><tr class="empty-row"><td colspan="3">No events yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
