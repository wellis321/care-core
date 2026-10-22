<?php
use App\Api\Api;
use App\Api\Scopes;

$routes = Api::routes();
$byTag = [];
foreach ($routes as $r) { $byTag[$r['tag']][] = $r; }
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<p>Every endpoint, generated from the same table the API serves from (<?= count($routes) ?> endpoints). Paths are relative to <code>/api/v1</code>. The same information is available as an <a href="/api/v1/openapi.json">OpenAPI 3.1 document</a>.</p>
<p>All <code>POST</code> endpoints accept an <code>Idempotency-Key</code> header. List endpoints accept <code>limit</code> and <code>cursor</code>. A <code>{id}</code> is the UUID of the resource named in the path.</p>
<div class="tablewrap"><table>
<thead><tr><th>Area</th><?php foreach (array_keys($byTag) as $t): ?><?php endforeach; ?><th>Endpoints</th></tr></thead>
<tbody><?php foreach ($byTag as $tag => $rs): ?><tr><td><a href="#<?= $e(preg_replace('/[^a-z]+/', '-', strtolower($tag))) ?>"><?= $e($tag) ?></a></td><td><?= count($rs) ?></td></tr><?php endforeach; ?></tbody></table></div>

<?php foreach ($byTag as $tag => $rs): ?>
<h2 id="<?= $e(preg_replace('/[^a-z]+/', '-', strtolower($tag))) ?>"><?= $e($tag) ?></h2>
<?php foreach ($rs as $r): ?>
<h3><code><?= $e($r['method']) ?> <?= $e($r['path']) ?></code></h3>
<p><strong><?= $e($r['summary']) ?></strong>
<?= $r['scope'] ? ' · needs <code>' . $e($r['scope']) . '</code>' : ' · any valid key' ?>
· returns <?= $r['status'] === 204 ? '204 No Content' : ($r['status'] . ($r['response'] ? ' <code>' . $e(str_starts_with($r['response'], 'list:') ? 'list of ' . substr($r['response'], 5) : $r['response']) . '</code>' : '')) ?></p>
<?php if ($r['description'] !== ''): ?><p><?= $e($r['description']) ?></p><?php endif; ?>
<?php if ($r['query']): ?>
<div class="tablewrap"><table><thead><tr><th>Query parameter</th><th>Type</th><th>Description</th></tr></thead><tbody>
<?php foreach ($r['query'] as $name => [$type, $desc]): ?><tr><td><code><?= $e($name) ?></code></td><td><?= $e($type) ?></td><td><?= $e($desc) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if ($r['body']): ?>
<div class="tablewrap"><table><thead><tr><th>Body field</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>
<?php foreach ($r['body'] as $name => $f): $type = $f['type'] ?? 'string'; if ($type === 'enum') { $type = implode(' | ', $f['enum']); } if (!empty($f['nullable'])) { $type .= ' or null'; } ?>
<tr><td><code><?= $e($name) ?></code></td><td><?= $e($type) ?></td><td><?= !empty($f['required']) ? 'Yes' : 'No' ?></td><td><?= $e($f['desc'] ?? '') ?><?= isset($f['max']) && ($f['type'] ?? 'string') === 'string' ? ' Up to ' . (int) $f['max'] . ' characters.' : '' ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php endforeach; ?>
<?php endforeach; ?>
