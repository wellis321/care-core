<?php
declare(strict_types=1);

namespace App\Modules\Work;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\UrlGuard;

/**
 * "My work": ask each connected product, one person at a time, what is waiting for them. Read only, nothing is copied here.
 * Each product answers GET {base}/api/v1/my-work?person=<Care Core person id>&email=<address> with its own "my_work" key (Authorization: Bearer ...).
 * A product that is slow or down never stops the page: it shows as "did not answer".
 */
final class WorkSources
{
    public const TIMEOUT_SECONDS = 4;
    public const MAX_ITEMS = 25;
    private const CACHE_SECONDS = 60;

    /** @return list<array<string,mixed>> */
    public static function all(int $orgId): array
    {
        return DB::all('SELECT * FROM work_sources WHERE organisation_id = ? ORDER BY name', [$orgId]);
    }

    public static function add(int $orgId, string $name, string $url, string $key, Actor $actor): void
    {
        $name = trim($name);
        $url = rtrim(trim($url), '/');
        $key = trim($key);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Give the product a name.');
        }
        if ($p = UrlGuard::problem($url)) {
            throw new DomainException($p);
        }
        $parts = parse_url($url);
        if (!empty($parts['query']) || !empty($parts['fragment'])) {
            throw new DomainException('Enter just the address of the product, for example https://incidents.example.org');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{16,120}$/', $key)) {
            throw new DomainException('That does not look like a key. Make one in the product (Integrations) with the "My work" permission and paste it here.');
        }
        DB::insert('work_sources', ['organisation_id' => $orgId, 'name' => $name, 'base_url' => $url, 'key_sealed' => (string) Crypto::encrypt($key)]);
        Audit::record($orgId, $actor, 'work.source_added', 'work_source', null, 'Connected ' . $name . ' to My work');
    }

    public static function remove(int $orgId, int $id, Actor $actor): void
    {
        $s = DB::one('SELECT name FROM work_sources WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
        if (!$s) {
            throw new DomainException('That product is not connected.');
        }
        DB::run('DELETE FROM work_sources WHERE id = ? AND organisation_id = ?', [$id, $orgId]);
        Audit::record($orgId, $actor, 'work.source_removed', 'work_source', null, 'Disconnected ' . $s['name'] . ' from My work');
    }

    public static function setActive(int $orgId, int $id, bool $on): void
    {
        DB::run('UPDATE work_sources SET active = ? WHERE id = ? AND organisation_id = ?', [$on ? 1 : 0, $id, $orgId]);
    }

    /**
     * Turn a product's answer into items we are willing to show. Anything unexpected is dropped, never trusted: titles are plain text (escaped on output),
     * links must be a path on that product's own address.
     * @return list<array{title:string,kind:string,due_on:?string,url:?string}>
     */
    public static function parse(string $body, string $baseUrl): array
    {
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['items']) || !is_array($j['items'])) {
            throw new DomainException('unexpected answer');
        }
        $out = [];
        foreach ($j['items'] as $it) {
            if (!is_array($it) || !is_string($it['title'] ?? null) || trim($it['title']) === '') {
                continue;
            }
            $due = $it['due_on'] ?? null;
            $due = is_string($due) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
            $path = $it['url'] ?? null;
            $link = is_string($path) && preg_match('~^/(?!/)[^\s\\\\]*$~', $path) ? $baseUrl . $path : null;
            $out[] = ['title' => mb_substr(trim($it['title']), 0, 200), 'kind' => mb_substr(is_string($it['kind'] ?? null) ? trim($it['kind']) : '', 0, 40), 'due_on' => $due, 'url' => $link];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Ask every active product at once. The answer is kept in the person's session for a minute so reloading the page is cheap.
     * @param array{uuid:string,email:string,person_uuid:?string} $who
     * @return list<array{name:string,url:string,items:list<array<string,mixed>>,error:?string}>
     */
    public static function fetchFor(int $orgId, array $who, bool $fresh = false): array
    {
        $key = 'work_' . $orgId;
        $c = $_SESSION[$key] ?? null;
        if (!$fresh && is_array($c) && ($c['at'] ?? 0) > time() - self::CACHE_SECONDS) {
            return $c['rows'];
        }
        $sources = DB::all('SELECT * FROM work_sources WHERE organisation_id = ? AND active = 1 ORDER BY name', [$orgId]);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($sources as $s) {
            $plain = Crypto::decrypt($s['key_sealed']);
            $ch = curl_init($s['base_url'] . '/api/v1/my-work?' . http_build_query(['person' => (string) ($who['person_uuid'] ?? ''), 'email' => (string) $who['email']]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . (string) $plain], CURLOPT_USERAGENT => 'CareCore-MyWork/1']);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $s['id']] = [$ch, $s];
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK);
        $rows = [];
        foreach ($handles as $id => [$ch, $s]) {
            $body = (string) curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = null;
            $items = [];
            if ($code === 401 || $code === 403) {
                $err = 'The key was refused. Make a new one in the product.';
            } elseif ($code !== 200) {
                $err = 'Did not answer.';
            } else {
                try {
                    $items = self::parse($body, $s['base_url']);
                } catch (DomainException $e) {
                    $err = 'Gave an answer we could not read.';
                }
            }
            curl_multi_remove_handle($mh, $ch);
            DB::run('UPDATE work_sources SET last_ok_at = IF(?, UTC_TIMESTAMP(), last_ok_at), last_error = ? WHERE id = ?', [$err === null ? 1 : 0, $err, $id]);
            $rows[] = ['name' => $s['name'], 'url' => $s['base_url'], 'items' => $items, 'error' => $err];
        }
        curl_multi_close($mh);
        $_SESSION[$key] = ['at' => time(), 'rows' => $rows];
        return $rows;
    }
}
