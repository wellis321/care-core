<?php
declare(strict_types=1);

namespace App\Api;

/** What an API key may do. A key carries a list of these; every endpoint names the one it needs. */
final class Scopes
{
    public const ALL = [
        'people.read'      => 'Read people (names, contact details, whether staff or supported).',
        'people.write'     => 'Create and edit people.',
        'staff.read'       => 'Read employments, employment terms, the non-sensitive staff profile and pending profile changes.',
        'staff.write'      => 'Start, change and end employments (joiner, mover, leaver); edit terms and the staff profile.',
        'staff.sensitive'  => 'Read and write National Insurance and bank details. Grant to as few keys as possible.',
        'compliance.read'  => 'Read registrations, supervisions, appraisals and learning, and what is due.',
        'compliance.write' => 'Add and remove registrations, supervisions, appraisals and learning records.',
        'teams.read'       => 'Read teams and their members.',
        'teams.write'      => 'Create, edit and close teams; add and end team places.',
        'care.read'        => 'Read support episodes, care needs, contacts and key workers.',
        'care.write'       => 'Start and end support; manage care needs, contacts and key workers.',
        'recruitment.read'  => 'Read job roles, vacancies, candidates and their checks.',
        'recruitment.write' => 'Create roles and vacancies, add and move candidates, record offers and checks, and hire.',
        'tasks.read'       => 'Read tasks.',
        'tasks.write'      => 'Mark tasks done or skipped.',
        'events.read'      => 'Read the event feed.',
        'webhooks.manage'  => 'Create, change and delete webhook endpoints and read their deliveries.',
        'audit.read'       => 'Read the activity log.',
        'admin.read'       => 'Read the logins list and the consistency checks.',
    ];

    /** @return list<string> */
    public static function parse(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), static fn($s) => isset(self::ALL[$s]))));
    }
}
