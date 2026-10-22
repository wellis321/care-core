<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/admin">Admin</a> › Integrations</p>
<div class="pagehead"><div><h1>Integrations</h1><p class="muted">Care Core announces every joiner, mover and leaver. Other systems listen, or push changes in.</p></div></div>

<section class="section">
  <div class="section__head"><h2>Microsoft Entra: joiners and leavers pushed in (SCIM)</h2></div>
  <div class="card">
    <p>In Entra, add an enterprise application with <strong>automatic provisioning</strong>. Use this tenant URL and a token from below:</p>
    <p class="secret"><?= $e($scimUrl) ?></p>
    <p class="muted small">When Entra disables a user, Care Core runs the whole leaver process: their employment ends, team places and key worker links end, their login stops, and offboarding tasks are created.</p>
    <table class="list" style="margin:1rem 0"><thead><tr><th>Token</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>
      <?php foreach ($tokens as $t): ?><tr><td><?= $e($t['name']) ?> <?= $t['active'] ? '' : '<span class="chip chip--bad">Revoked</span>' ?></td><td class="nowrap"><?= View::date($t['created_at']) ?></td><td class="nowrap"><?= View::date($t['last_used_at'], 'never') ?></td><td class="right"><?php if ($t['active']): ?><form method="post" action="/admin/scim/revoke" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-secondary" type="submit">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$tokens): ?><tr class="empty-row"><td colspan="4">No tokens yet.</td></tr><?php endif; ?>
    </tbody></table>
    <form class="inlineform" method="post" action="/admin/scim"><?= Csrf::field() ?><div class="form-group"><label for="tname">Token name</label><input id="tname" name="name" type="text" required placeholder="Microsoft Entra"></div><button class="btn btn-primary" type="submit">Create token</button></form>
  </div>
</section>

<section class="section">
  <div class="section__head"><h2>Webhooks: events sent out</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Address</th><th>Events</th><th>Delivered</th><th>Waiting or failed</th><th></th></tr></thead>
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
