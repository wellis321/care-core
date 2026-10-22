<?php
declare(strict_types=1);

namespace App\Modules\Import\Exporters;

use PDO;

/**
 * Reads the older People Service database (people we support) and describes it as an import bundle. Refs are
 * "people:<people.id>"; key workers point at "staff:<id>", the Staff Service's ids.
 */
final class PeopleService
{
    /** @param array{org:int} $o @return array<string,mixed> */
    public static function export(PDO $db, array $o): array
    {
        $org = (int) $o['org'];
        $notes = [];
        $q = $db->prepare('SELECT * FROM people WHERE organisation_id = ? ORDER BY id');
        $q->execute([$org]);
        $people = [];
        $noStart = 0;
        $moreNotes = 0;
        $assumedEnd = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $start = self::d($r['support_start'] ?? null);
            if ($start === null) {
                $start = self::d($r['created_at']);
                $noStart++;
            }
            $end = self::d($r['support_end'] ?? null);
            if ($end === null && in_array($r['status'] ?? 'active', ['inactive', 'archived'], true)) {
                $end = self::d($r['updated_at']) ?? $start;
                $assumedEnd++;
            }
            $extra = [];
            foreach (['gender' => 'Gender', 'pronouns' => 'Pronouns'] as $col => $label) {
                if (!empty($r[$col])) {
                    $extra[] = "$label: {$r[$col]}";
                }
            }
            if ($extra) {
                $moreNotes++;
            }
            $people[$id] = [
                'ref' => 'people:' . $id, 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'preferred_name' => $r['preferred_name'] ?? null, 'date_of_birth' => self::d($r['date_of_birth']),
                'notes' => trim(implode("\n", array_filter([$r['notes'] ?? null, $extra ? implode('. ', $extra) . '.' : null]))) ?: null,
                'profile' => array_filter(['address_line1' => $r['address_line1'] ?? null, 'address_line2' => $r['address_line2'] ?? null, 'address_city' => $r['city'] ?? null, 'address_county' => $r['county'] ?? null, 'address_postcode' => $r['postcode'] ?? null], static fn($v) => $v !== null && $v !== ''),
                'photo_file' => $r['photo_path'] ?? null,
                'support' => [['ref' => 'support:' . $id, 'start_date' => $start, 'end_date' => $end, 'nhs_number' => $r['nhs_number'] ?: null, 'local_authority_ref' => $r['local_authority_ref'] ?: null]],
                'needs' => [], 'contacts' => [],
            ];
        }
        foreach (self::rows($db, 'care_needs', $org) as $r) {
            if (isset($people[(int) $r['person_id']])) {
                $people[(int) $r['person_id']]['needs'][] = ['category' => $r['category'], 'description' => $r['description'], 'severity' => $r['severity'], 'review_date' => self::d($r['review_date'])];
            }
        }
        foreach (self::rows($db, 'person_contacts', $org) as $r) {
            if (isset($people[(int) $r['person_id']])) {
                $label = trim(implode(' ', array_filter([$r['label'] ?? null, !empty($r['notes']) ? '(' . $r['notes'] . ')' : null])));
                $people[(int) $r['person_id']]['contacts'][] = ['type' => $r['contact_type'], 'value' => $r['value'], 'label' => $label ?: null, 'primary' => (bool) $r['is_primary']];
            }
        }
        $kw = [];
        foreach (self::rows($db, 'person_keyworkers', $org) as $r) {
            if (!isset($people[(int) $r['person_id']])) {
                continue;
            }
            $kw[] = ['ref' => 'keyworker:' . (int) $r['id'], 'supported_ref' => 'people:' . (int) $r['person_id'], 'staff_ref' => 'staff:' . (int) $r['staff_id'], 'role_label' => $r['role_label'] ?: null, 'start_date' => self::d($r['assigned_at']), 'end_date' => self::d($r['ended_at'])];
        }
        if ($noStart) {
            $notes[] = "$noStart people had no support start date; their record's creation date was used.";
        }
        if ($assumedEnd) {
            $notes[] = "$assumedEnd people are marked inactive or archived with no support end date; the date their record was last changed was used. Check these.";
        }
        if ($moreNotes) {
            $notes[] = "$moreNotes people have gender or pronouns recorded: Care Core has no field for these, so they were added to the person's notes.";
        }
        return ['format' => 'carecore-import', 'version' => 1, 'source' => 'People Service export ' . gmdate('Y-m-d'), 'teams' => [], 'people' => array_values($people), 'team_places' => [], 'key_workers' => $kw, '_notes' => $notes];
    }

    private static function d(mixed $v): ?string
    {
        $s = $v === null ? '' : substr((string) $v, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $s !== '0000-00-00' ? $s : null;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(PDO $db, string $table, int $org): array
    {
        try {
            $q = $db->prepare("SELECT * FROM `$table` WHERE organisation_id = ? ORDER BY id");
            $q->execute([$org]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }
}
