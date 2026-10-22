<?php
declare(strict_types=1);

namespace App\Modules\Docs;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** Public documentation and information pages. No sign-in needed: they describe the product, never your data. */
final class DocsController extends Controller
{
    public function index(Request $r, array $p = []): Response
    {
        $q = trim($r->input('q'));
        return $this->view('docs/index', ['title' => $q !== '' ? 'Search the documentation' : 'Documentation', 'q' => $q, 'hits' => $q !== '' ? Docs::search($q) : [], 'groups' => Docs::groups()]);
    }

    public function page(Request $r, array $p): Response
    {
        $slug = $p['slug'];
        if (!Docs::exists($slug)) {
            return Router::error(404, 'Page not found', 'There is no documentation page with that name.');
        }
        $flat = Docs::flat();
        $slugs = array_keys($flat);
        $i = array_search($slug, $slugs, true);
        return $this->view('docs/page', [
            'title' => $flat[$slug][0], 'slug' => $slug, 'summary' => $flat[$slug][1], 'group' => $flat[$slug][2], 'groups' => Docs::groups(),
            'body' => Docs::body($slug),
            'prev' => $i > 0 ? [$slugs[$i - 1], $flat[$slugs[$i - 1]][0]] : null,
            'next' => $i < count($slugs) - 1 ? [$slugs[$i + 1], $flat[$slugs[$i + 1]][0]] : null,
        ]);
    }

    public function about(Request $r, array $p = []): Response
    {
        return $this->view('info/about', ['title' => 'About']);
    }

    public function features(Request $r, array $p = []): Response
    {
        return $this->view('info/features', ['title' => 'Features']);
    }

    public function trust(Request $r, array $p = []): Response
    {
        return $this->view('info/security', ['title' => 'Security and privacy']);
    }
}
