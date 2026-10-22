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
    $known = ['/login', '/about', '/features', '/security', '/docs'];
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
