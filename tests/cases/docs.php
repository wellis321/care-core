<?php
declare(strict_types=1);

use App\Modules\Docs\Docs;

test('docs: every catalogued page exists, nothing is uncatalogued, and each has real content', function () {
    $flat = Docs::flat();
    ok(count($flat) >= 30, 'the catalogue should be comprehensive');
    $files = array_map(static fn($f) => basename($f, '.php'), glob(ROOT_PATH . '/app/Views/docs/pages/*.php') ?: []);
    eq(array_values(array_diff(array_keys($flat), $files)), [], 'catalogued pages without a file');
    eq(array_values(array_diff($files, array_keys($flat))), [], 'files that are not in the catalogue');
    foreach (array_keys($flat) as $slug) {
        $text = strip_tags(Docs::body($slug));
        ok(mb_strlen(trim($text)) > 400, "$slug is too thin");
        ok(!str_contains($text, '<?') && !str_contains($text, 'TODO'), "$slug has leftovers");
    }
});

test('docs: every internal link on every docs and info page points at a page that exists', function () {
    $known = ['/login', '/about', '/features', '/security', '/docs', '/api/v1/openapi.json'];
    $pages = [];
    foreach (array_keys(Docs::flat()) as $slug) {
        $pages[$slug] = Docs::body($slug);
    }
    foreach (['about', 'features', 'security'] as $info) {
        $pages["info/$info"] = App\Core\View::partial("info/$info");
    }
    $bad = [];
    foreach ($pages as $name => $html) {
        preg_match_all('~href="(/[^"#?]*)~', $html, $m);
        foreach ($m[1] as $href) {
            if (preg_match('~^/docs/([a-z-]+)$~', $href, $mm)) {
                if (!Docs::exists($mm[1])) {
                    $bad[] = "$name -> $href";
                }
            } elseif (!in_array($href, $known, true)) {
                $bad[] = "$name -> $href";
            }
        }
    }
    eq($bad, []);
});

test('docs: search finds pages by any word, ranks title matches first, and needs every word', function () {
    $hits = Docs::search('leaver');
    ok(count($hits) >= 3);
    eq($hits[0]['slug'], 'leavers', 'the page with it in the title comes first');
    ok(in_array('scim', array_column(Docs::search('Entra provisioning'), 'slug'), true));
    ok(in_array('webhooks', array_column(Docs::search('hmac signature'), 'slug'), true));
    eq(Docs::search('zzzqqqxxx'), []);
    eq(Docs::search(''), []);
    eq(Docs::search('a'), [], 'single letters are ignored');
});

test('docs: the facts that matter are stated as the code behaves', function () {
    $all = '';
    foreach (array_keys(Docs::flat()) as $slug) {
        $all .= ' ' . strip_tags(Docs::body($slug));
    }
    foreach (['X-CareCore-Signature', 'AES-256-GCM', 'last day', 'APP_KEY', 'PASSWORD_LOGIN', '7 days', '15 minutes', 'New invitation', 'Waiting for approval', 'employment.ended', 'keyworker.ended', '/scim/v2'] as $needle) {
        ok(stripos($all, $needle) !== false, "the documentation never mentions: $needle");
    }
    // Every event type the code can emit is in the catalogue.
    $catalogue = Docs::body('event-catalogue');
    $types = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app')) as $f) {
        if (str_ends_with((string) $f, '.php') && preg_match_all("~Events::emit\([^,]+,\s*'([a-z_.]+)'~", (string) file_get_contents((string) $f), $m)) {
            $types = array_merge($types, $m[1]);
        }
    }
    $missing = array_filter(array_unique($types), static fn($t) => !str_contains($catalogue, "<code>$t</code>"));
    eq(array_values($missing), [], 'event types in the code but not in the catalogue');
});

test('docs: the API reference lists every endpoint the API serves, and the scope table lists every scope', function () {
    $ref = strip_tags(Docs::body('api-reference'));
    $missing = [];
    foreach (App\Api\Api::routes() as $r) {
        if (!str_contains($ref, $r['method'] . ' ' . $r['path'])) {
            $missing[] = $r['method'] . ' ' . $r['path'];
        }
    }
    eq($missing, []);
    $auth = strip_tags(Docs::body('api-auth'));
    foreach (array_keys(App\Api\Scopes::ALL) as $scope) {
        ok(str_contains($auth, $scope), "scope $scope is not documented");
    }
    $errors = Docs::body('api-errors');
    foreach (['bad_request', 'unauthorized', 'forbidden', 'https_required', 'not_found', 'method_not_allowed', 'request_in_progress', 'unsupported_media_type', 'validation_failed', 'idempotency_mismatch', 'rate_limited', 'internal_error'] as $code) {
        ok(str_contains($errors, 'id="' . $code . '"'), "error code $code has no anchor in the errors page");
    }
});

test('docs: every error code the API can emit is documented', function () {
    $errors = Docs::body('api-errors');
    $codes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/app/Api')) as $f) {
        if (str_ends_with((string) $f, '.php') && preg_match_all("~(?:new ApiException\(\d+,\s*|ApiException\([^)]*?)'([a-z_]+)'~", (string) file_get_contents((string) $f), $m)) {
            $codes = array_merge($codes, $m[1]);
        }
    }
    $codes = array_merge($codes, ['not_found', 'validation_failed', 'bad_request', 'forbidden']);   // the ApiException helper constructors
    $undocumented = array_filter(array_unique($codes), static fn($c) => !str_contains($errors, 'id="' . $c . '"'));
    eq(array_values($undocumented), []);
});

test('docs: the complete example in the import format page really imports, without errors or warnings', function () {
    $page = Docs::body('import-format');
    ok(preg_match('~A complete small example</h2>\s*<pre[^>]*><code>(.*?)</code></pre>~s', $page, $m) === 1, 'example not found');
    $bundle = App\Modules\Import\Bundle::parse(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    $org = Fx::org();
    $dry = (new App\Modules\Import\Importer($org, true))->run($bundle, 'docs example');
    eq([$dry['errors'], $dry['warnings']], [[], []], json_encode([$dry['errors'], $dry['warnings']]));
    $r = (new App\Modules\Import\Importer($org, false))->run($bundle, 'docs example');
    eq($r['counts']['people']['created'], 3);
    eq([$r['counts']['team_places']['created'], $r['counts']['key_workers']['created'], $r['counts']['employments']['created']], [1, 1, 2]);
});

test('docs: every script and command the import pages mention exists', function () {
    $text = Docs::body('import') . Docs::body('import-migrating') . Docs::body('import-format') . Docs::body('install');
    preg_match_all('~scripts/([a-z-]+\.php)~', $text, $m);
    foreach (array_unique($m[1]) as $script) {
        ok(is_file(ROOT_PATH . '/scripts/' . $script), "scripts/$script is mentioned but does not exist");
    }
});
