<?php
declare(strict_types=1);

namespace App\Modules\Docs;

use App\Core\View;

/** The documentation catalogue. Each page is a plain HTML file in app/Views/docs/pages/{slug}.php. */
final class Docs
{
    /** @return array<string,array{title:string,blurb:string,pages:array<string,array{0:string,1:string}>}> group => pages (slug => [title, summary]) */
    public static function groups(): array
    {
        return [
            'start' => ['title' => 'Start here', 'blurb' => 'What Care Core is and the ideas behind it.', 'pages' => [
                'overview'    => ['What Care Core is', 'One record of your people, your teams and the people you support.'],
                'concepts'    => ['Core ideas', 'People, relationships, dates and why nothing is ever deleted.'],
                'roles'       => ['Roles and permissions', 'What administrators, managers and staff can see and do.'],
                'signing-in'  => ['Signing in', 'Passwords, invitations, Microsoft sign-in, sessions and lock-outs.'],
            ]],
            'guides' => ['title' => 'Everyday guides', 'blurb' => 'Step by step, for the people who use it every day.', 'pages' => [
                'dashboard'    => ['The dashboard', 'Who is starting, who is leaving, what needs doing.'],
                'people'       => ['Finding people and profiles', 'Searching, the filters and everything on a profile page.'],
                'recruitment'  => ['Recruitment', 'Job roles, vacancies, candidates and hiring someone into a role.'],
                'joiners'      => ['Starting someone', 'The joiner process, rehires and logins.'],
                'movers'       => ['Changing role, hours or team', 'The mover process and its history.'],
                'leavers'      => ['Recording someone leaving', 'The leaver process, with a preview of what will happen.'],
                'staff-record' => ['The staff record', 'Personal details, pay and tax, TUPE, working time, visas, apprenticeships and leave.'],
                'compliance'   => ['Registrations, supervision and learning', 'The records an inspector asks for, and what is due soon.'],
                'my-profile'   => ['My profile', 'Keeping your own details, photo and signature up to date.'],
                'approvals'    => ['Reviewing approvals', 'How requests are approved or declined, and by whom.'],
                'teams'        => ['Teams', 'Hierarchies, members, stints and staff-only teams.'],
                'care'         => ['People we support', 'Support episodes, needs, contacts and key workers.'],
                'tasks'        => ['Tasks and checklists', 'The work that joiners, movers and leavers create.'],
            ]],
            'admin' => ['title' => 'Administration', 'blurb' => 'Setting Care Core up for your organisation.', 'pages' => [
                'admin-users'      => ['Logins', 'Creating, inviting and disabling logins.'],
                'admin-modules'    => ['Modules', 'Switching Staff, Teams and Care on and off.'],
                'admin-checklists' => ['Checklists', 'Editing the tasks that onboarding, role changes and offboarding create.'],
                'admin-reconcile'  => ['Consistency checks', 'The safety net, and how to fix what it finds.'],
                'admin-activity'   => ['The activity log', 'The audit trail of every change.'],
                'import'           => ['Importing data', 'Bringing people, staff and teams in from other systems, safely and repeatably.'],
                'import-format'    => ['The import format', 'The JSON file that describes what to import.'],
                'import-migrating' => ['Moving from the older Staff, People and Team services', 'A step-by-step runbook, with what maps to what.'],
            ]],
            'integrations' => ['title' => 'Integrations', 'blurb' => 'Connecting Care Core to the rest of your systems.', 'pages' => [
                'entra-signin'    => ['Sign in with Microsoft', 'Setting up Entra single sign-on.'],
                'scim'            => ['Provisioning from Entra (SCIM)', 'Pushing joiners and leavers in automatically.'],
                'webhooks'        => ['Events and webhooks', 'Telling other systems what changed, safely.'],
                'event-catalogue' => ['Event catalogue', 'Every event, with its payload.'],
            ]],
            'api' => ['title' => 'API', 'blurb' => 'For developers connecting other systems to Care Core.', 'pages' => [
                'api'             => ['The Care Core API', 'Read and change everything Care Core holds, from your own systems.'],
                'api-auth'        => ['API keys, scopes and authentication', 'Creating keys, what they may do, and keeping them safe.'],
                'api-conventions' => ['API conventions', 'Formats, pagination, filtering, rate limits, idempotency and versioning.'],
                'api-reference'   => ['API reference', 'Every endpoint, with its scope, parameters and fields.'],
                'api-errors'      => ['API errors', 'The problem documents the API returns, and how to retry safely.'],
                'api-recipes'     => ['API recipes', 'Worked examples: syncing staff, starting and ending employment, compliance, teams, care.'],
                'api-clients'     => ['API client examples', 'Small, correct clients in PHP, Node.js and Python.'],
            ]],
            'reference' => ['title' => 'Reference', 'blurb' => 'The detail, for when you need to be certain.', 'pages' => [
                'data-model'      => ['Data model', 'The tables and how they relate.'],
                'rules'           => ['Rules that are enforced', 'Every check Care Core makes before it lets a change through.'],
                'field-reference' => ['Field reference', 'Every field, its format and who can change it.'],
                'security'        => ['Security and privacy', 'How personal data is protected, and what to do about the rest.'],
                'glossary'        => ['Glossary', 'The words Care Core uses.'],
                'faq'             => ['Questions and answers', 'The things people ask most.'],
                'limits'          => ['What it does not do yet', 'Known limits and what is planned.'],
            ]],
            'running' => ['title' => 'Running it', 'blurb' => 'For whoever hosts and looks after the system.', 'pages' => [
                'install'         => ['Installing', 'Requirements, setup and every setting.'],
                'operations'      => ['Day-to-day operations', 'Scheduled jobs, backups, upgrades and monitoring.'],
                'testing'         => ['Tests and demo data', 'How it is tested and how the demo works.'],
                'troubleshooting' => ['Troubleshooting', 'When something does not work.'],
            ]],
        ];
    }

    /** @return array<string,array{0:string,1:string,2:string}> slug => [title, summary, group slug], in reading order */
    public static function flat(): array
    {
        $out = [];
        foreach (self::groups() as $g => $grp) {
            foreach ($grp['pages'] as $slug => [$t, $s]) {
                $out[$slug] = [$t, $s, $g];
            }
        }
        return $out;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::flat()[$slug]) && is_file(ROOT_PATH . '/app/Views/docs/pages/' . $slug . '.php');
    }

    public static function body(string $slug): string
    {
        // Code blocks can scroll sideways, so they must be reachable by keyboard.
        return str_replace('<pre>', '<pre tabindex="0">', View::partial('docs/pages/' . $slug));
    }

    /** Pages whose text contains every word of the query, best matches (title hits) first. @return list<array{slug:string,title:string,summary:string,snippet:string}> */
    public static function search(string $q): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($q))) ?: [], static fn($w) => mb_strlen($w) >= 2));
        if (!$words) {
            return [];
        }
        $hits = [];
        foreach (self::flat() as $slug => [$title, $summary]) {
            if (!is_file(ROOT_PATH . '/app/Views/docs/pages/' . $slug . '.php')) {
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(self::body($slug)), ENT_QUOTES | ENT_HTML5)) ?? '');
            $hay = mb_strtolower($title . ' ' . $summary . ' ' . $text);
            $score = 0;
            foreach ($words as $w) {
                $n = substr_count($hay, $w);
                if ($n === 0) {
                    continue 2;
                }
                $score += min($n, 15) + (str_contains(mb_strtolower($title), mb_strlen($w) > 5 ? mb_substr($w, 0, mb_strlen($w) - 2) : $w) ? 100 : 0) + (str_contains(mb_strtolower($summary), $w) ? 40 : 0);   // a word in the title beats any amount of repetition in the body
            }
            $pos = mb_stripos($text, $words[0]);
            $snippet = $pos === false ? $summary : ($pos > 60 ? '…' : '') . mb_substr($text, max(0, $pos - 60), 200) . '…';
            $hits[] = ['slug' => $slug, 'title' => $title, 'summary' => $summary, 'snippet' => $snippet, 'score' => $score];
        }
        usort($hits, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $hits;
    }
}
