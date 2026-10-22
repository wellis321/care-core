<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Created;
use App\Api\Ctx;
use App\Api\Present;
use App\Core\Database as DB;
use App\Modules\Staff\Compliance;

final class ComplianceApi extends BaseApi
{
    public const TAG = 'Compliance';

    /** URL name => internal kind */
    private const KINDS = ['registrations' => 'registration', 'supervisions' => 'supervision', 'appraisals' => 'appraisal', 'learning' => 'learning'];

    public static function routes(): array
    {
        $kinds = implode(', ', array_keys(self::KINDS));
        $routes = [];
        foreach (self::KINDS as $url => $kind) {
            $routes[] = self::route('GET', "/people/{id}/$url", 'compliance.read', 'list' . ucfirst($url), 'List ' . $url, [], [], 'list:' . ucfirst($kind), 200, 'Newest first.');
            $routes[] = self::route('POST', "/people/{id}/$url", 'compliance.write', 'add' . ucfirst($url), 'Add a ' . $kind . ' record', [], self::spec($kind), ucfirst($kind), 201);
            $routes[] = self::route('DELETE', "/people/{id}/$url/{record}", 'compliance.write', 'remove' . ucfirst($url), 'Remove a ' . $kind . ' record', [], [], '', 204, 'Compliance records can be added and removed, not edited. Remove and add the corrected one.');
        }
        $routes[] = self::route('GET', '/compliance/due', 'compliance.read', 'due', 'What is overdue or due soon', ['days' => ['integer', 'Look this many days ahead, 1 to 365. Default 60.']], [], 'list:DueItem', 200, 'Current staff only. Registrations and learning by expiry; supervisions and appraisals by their most recent next-due date.');
        return $routes;
    }

    public function __call(string $name, array $args): mixed
    {
        $c = $args[0];
        foreach (self::KINDS as $url => $kind) {
            foreach (['list' => 'list', 'add' => 'add', 'remove' => 'remove'] as $prefix => $method) {
                if ($name === $prefix . ucfirst($url)) {
                    return $this->{'do' . ucfirst($method)}($c, $kind);
                }
            }
        }
        throw new \BadMethodCallException($name);
    }

    private function doList(Ctx $c, string $kind): array
    {
        $p = $c->person();
        return ['data' => array_map(static fn($r) => self::out($kind, $r, $p['uuid']), Compliance::list($c->org(), (int) $p['id'], $kind))];
    }

    private function doAdd(Ctx $c, string $kind): Created
    {
        $p = $c->person();
        $in = $c->input(self::spec($kind));
        $form = [];
        foreach ($in as $k => $v) {
            $form[$k] = is_bool($v) ? ($v ? '1' : '') : ($v === null ? '' : (string) $v);
        }
        [$clean, $errors] = Compliance::clean($c->org(), $kind, $form);
        if ($errors) {
            throw ApiException::invalid(implode(' ', $errors), array_map(static fn($m) => ['field' => null, 'message' => $m], $errors));
        }
        $uuid = Compliance::add($c->org(), $p, $kind, $clean, $c->actor());
        $url = array_search($kind, self::KINDS, true);
        $row = DB::one('SELECT * FROM ' . Compliance::KINDS[$kind]['table'] . ' WHERE uuid = ?', [$uuid]);
        return new Created(self::out($kind, $row, $p['uuid']), "/people/{$p['uuid']}/$url/$uuid");
    }

    private function doRemove(Ctx $c, string $kind): null
    {
        $p = $c->person();
        Compliance::remove($c->org(), $p, $kind, strtolower($c->params['record'] ?? ''), $c->actor());
        return null;
    }

    public function due(Ctx $c): array
    {
        $days = $c->q('days', '60');
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 365) {
            throw ApiException::badRequest('days must be a whole number from 1 to 365.');
        }
        return ['data' => array_map(static fn($r) => ['person_id' => $r['person_uuid'], 'kind' => $r['kind'], 'what' => $r['what'], 'label' => $r['label'], 'due' => $r['due'], 'overdue' => $r['overdue']], Compliance::dueSoon($c->org(), (int) $days))];
    }

    /** @return array<string,array<string,mixed>> */
    private static function spec(string $kind): array
    {
        $spec = [];
        foreach (Compliance::KINDS[$kind]['fields'] as $f => [$type, $label, $required]) {
            $s = ['required' => $required, 'desc' => $label . ($type === 'person' ? ' (a person id)' : '')];
            if ($type === 'bool') {
                $s['type'] = 'bool';
            } elseif ($type === 'date') {
                $s['type'] = 'date';
            } elseif ($type === 'int') {
                $s['type'] = 'int';
                $s['min'] = 0;
                $s['max'] = 1440;
            } elseif ($type === 'person') {
                $s['type'] = 'uuid';
            } elseif (str_starts_with($type, 'enum:')) {
                $s['type'] = 'enum';
                $s['enum'] = explode(',', substr($type, 5));
            } else {
                $s['type'] = 'string';
                $s['max'] = $type === 'longtext' ? 5000 : 190;
            }
            if (!$required) {
                $s['nullable'] = true;
            }
            $spec[$f] = $s;
        }
        return $spec;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function out(string $kind, array $row, string $personUuid): array
    {
        $out = ['id' => $row['uuid'], 'person_id' => $personUuid];
        foreach (Compliance::KINDS[$kind]['fields'] as $f => [$type]) {
            $v = $row[$f] ?? null;
            $out[$f] = match (true) {
                $type === 'bool' => (bool) (int) $v,
                $type === 'int' => $v === null ? null : (int) $v,
                $type === 'person' => $v ? DB::value('SELECT uuid FROM persons WHERE id = ?', [$v]) : null,
                default => $v,
            };
        }
        $out['created_at'] = Present::time($row['created_at']);
        return $out;
    }
}
