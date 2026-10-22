<?php
declare(strict_types=1);

namespace App\Modules\Import;

use App\Core\DomainException;

/**
 * The import bundle: one JSON document that describes people, teams and everything hanging off them, whatever system it
 * came from. Every record carries a "ref": a stable identifier from the source system (for example "staff:123"). Refs let
 * records in different files point at each other, and let a second import skip what the first already brought in.
 */
final class Bundle
{
    public const FORMAT = 'carecore-import';
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function parse(string $json): array
    {
        $d = json_decode($json, true, 64);
        if (!is_array($d)) {
            throw new DomainException('That file is not valid JSON.');
        }
        return self::normalise($d);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    public static function normalise(array $d): array
    {
        if (($d['format'] ?? '') !== self::FORMAT) {
            throw new DomainException('That file is not a Care Core import bundle (its "format" should be "' . self::FORMAT . '").');
        }
        if ((int) ($d['version'] ?? 0) !== self::VERSION) {
            throw new DomainException('That bundle is version ' . (int) ($d['version'] ?? 0) . '; this Care Core reads version ' . self::VERSION . '.');
        }
        foreach (['teams', 'people', 'team_places', 'key_workers'] as $list) {
            if (isset($d[$list]) && (!is_array($d[$list]) || ($d[$list] && !array_is_list($d[$list])))) {
                throw new DomainException("\"$list\" must be a list.");
            }
        }
        $out = ['format' => self::FORMAT, 'version' => self::VERSION, 'source' => (string) ($d['source'] ?? ''), 'teams' => [], 'people' => [], 'team_places' => [], 'key_workers' => [], 'notes' => array_values(array_filter((array) ($d['_notes'] ?? []), 'is_string'))];
        foreach (['teams', 'people', 'team_places', 'key_workers'] as $list) {
            foreach ($d[$list] ?? [] as $i => $row) {
                if (!is_array($row)) {
                    throw new DomainException("Entry " . ($i + 1) . " of \"$list\" is not an object.");
                }
                $out[$list][] = $row;
            }
        }
        return $out;
    }

    /**
     * Combine several bundles (for example one from each old system). People with the same ref are merged, the first
     * file's values winning and later files filling gaps and adding child records.
     *
     * @param list<array<string,mixed>> $bundles @return array<string,mixed>
     */
    public static function merge(array $bundles): array
    {
        $out = ['format' => self::FORMAT, 'version' => self::VERSION, 'source' => '', 'teams' => [], 'people' => [], 'team_places' => [], 'key_workers' => [], 'notes' => []];
        $sources = [];
        $personIdx = [];
        $teamIdx = [];
        foreach ($bundles as $b) {
            if ($b['source'] !== '') {
                $sources[] = $b['source'];
            }
            $out['notes'] = array_merge($out['notes'], $b['notes'] ?? []);
            foreach ($b['teams'] as $t) {
                $ref = (string) ($t['ref'] ?? '');
                if ($ref !== '' && isset($teamIdx[$ref])) {
                    continue;
                }
                $teamIdx[$ref ?: uniqid('t', true)] = count($out['teams']);
                $out['teams'][] = $t;
            }
            foreach ($b['people'] as $p) {
                $ref = (string) ($p['ref'] ?? '');
                if ($ref !== '' && isset($personIdx[$ref])) {
                    $out['people'][$personIdx[$ref]] = self::mergePerson($out['people'][$personIdx[$ref]], $p);
                    continue;
                }
                if ($ref !== '') {
                    $personIdx[$ref] = count($out['people']);
                }
                $out['people'][] = $p;
            }
            $out['team_places'] = array_merge($out['team_places'], $b['team_places']);
            $out['key_workers'] = array_merge($out['key_workers'], $b['key_workers']);
        }
        $out['source'] = implode(' + ', array_unique($sources));
        return $out;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b @return array<string,mixed> */
    private static function mergePerson(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (in_array($k, ['employments', 'support', 'needs', 'contacts', 'registrations', 'supervisions', 'appraisals', 'learning'], true)) {
                $a[$k] = array_merge($a[$k] ?? [], is_array($v) ? $v : []);
            } elseif ($k === 'profile') {
                $a[$k] = ($a[$k] ?? []) + (is_array($v) ? $v : []);
            } elseif (!isset($a[$k]) || $a[$k] === '' || $a[$k] === null) {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    /** Counts for a quick look at what a bundle holds. @param array<string,mixed> $b @return array<string,int> */
    public static function counts(array $b): array
    {
        $c = ['teams' => count($b['teams']), 'people' => count($b['people']), 'team_places' => count($b['team_places']), 'key_workers' => count($b['key_workers']),
            'employments' => 0, 'support_episodes' => 0, 'needs' => 0, 'contacts' => 0, 'registrations' => 0, 'supervisions' => 0, 'appraisals' => 0, 'learning' => 0];
        foreach ($b['people'] as $p) {
            $c['employments'] += count($p['employments'] ?? []);
            $c['support_episodes'] += count($p['support'] ?? []);
            foreach (['needs', 'contacts', 'registrations', 'supervisions', 'appraisals', 'learning'] as $k) {
                $c[$k] += count($p[$k] ?? []);
            }
        }
        return $c;
    }
}
