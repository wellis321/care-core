<?php
declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Check;
use App\Core\Clock;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Events;
use App\Core\Uuid;

/** Logins. A login belongs to a person; when the person leaves, access ends on their last day without any job having to run. */
final class UserRepo
{
    public const ROLES = ['admin', 'manager', 'staff'];

    /** @return array{id:int,uuid:string,invite_token:?string} */
    public static function create(int $orgId, ?int $personId, string $email, string $role, Actor $actor, bool $invite = true, ?string $password = null): array
    {
        $email = strtolower(trim($email));
        $msgs = Check::all([Check::required($email, 'Email'), Check::email($email), Check::oneOf($role, self::ROLES, 'a role')]);
        if ($password !== null && ($m = self::passwordProblem($password))) {
            $msgs[] = $m;
        }
        if ($msgs) {
            throw DomainException::of($msgs);
        }
        if (DB::value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw new DomainException('There is already a login for that email address.');
        }
        if ($personId !== null && !DB::value('SELECT 1 FROM persons WHERE id = ? AND organisation_id = ?', [$personId, $orgId])) {
            throw new DomainException('Person not found.');
        }
        return DB::transaction(function () use ($orgId, $personId, $email, $role, $actor, $invite, $password) {
            $uuid  = Uuid::v4();
            $token = null;
            $row = [
                'uuid' => $uuid, 'organisation_id' => $orgId, 'person_id' => $personId, 'email' => $email, 'role' => $role,
                'status' => $invite ? 'invited' : 'active',
                'password_hash' => $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null,
            ];
            if ($invite) {
                $token = bin2hex(random_bytes(24));
                $row['invite_token_hash'] = hash('sha256', $token);
                $row['invite_expires_at'] = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
            }
            $id = DB::insert('users', $row);
            Audit::record($orgId, $actor, 'user.created', 'user', $uuid, 'Created a login for ' . $email . ' (' . $role . ')');
            Events::emit($orgId, 'user.created', $personId ? (string) DB::value('SELECT uuid FROM persons WHERE id = ?', [$personId]) : null, ['user' => $uuid]);
            return ['id' => $id, 'uuid' => $uuid, 'invite_token' => $token];
        });
    }

    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'The password must be at least 10 characters.';
        }
        if (mb_strlen($password) > 200) {
            return 'The password is too long.';
        }
        return null;
    }

    /** Accept an invite: set a password and activate. */
    public static function acceptInvite(string $token, string $password): array
    {
        if ($m = self::passwordProblem($password)) {
            throw new DomainException($m);
        }
        $user = DB::one("SELECT * FROM users WHERE invite_token_hash = ? AND status = 'invited' AND invite_expires_at > UTC_TIMESTAMP()", [hash('sha256', $token)])
            ?? throw new DomainException('This invitation has expired or has already been used.');
        DB::transaction(function () use ($user, $password) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active', 'invite_token_hash' => null, 'invite_expires_at' => null], ['id' => $user['id']]);
            Audit::record((int) $user['organisation_id'], Actor::system('invite'), 'user.activated', 'user', $user['uuid'], $user['email'] . ' accepted their invitation');
        });
        return $user;
    }

    public static function setAccessEnd(int $orgId, int $personId, string $date, Actor $actor): int
    {
        $users = DB::all('SELECT id, uuid, email FROM users WHERE person_id = ? AND organisation_id = ? AND (access_ends_on IS NULL OR access_ends_on > ?)', [$personId, $orgId, $date]);
        foreach ($users as $u) {
            DB::update('users', ['access_ends_on' => $date], ['id' => $u['id']]);
            Audit::record($orgId, $actor, 'user.access_ends', 'user', $u['uuid'], 'Access for ' . $u['email'] . ' ends on ' . $date, ['date' => $date]);
        }
        return count($users);
    }

    public static function disable(int $orgId, int $userId, Actor $actor): void
    {
        $u = DB::one('SELECT id, uuid, email FROM users WHERE id = ? AND organisation_id = ?', [$userId, $orgId]) ?? throw new DomainException('Login not found.');
        DB::transaction(function () use ($orgId, $u, $actor) {
            DB::update('users', ['status' => 'disabled', 'access_ends_on' => Clock::today()], ['id' => $u['id']]);
            Audit::record($orgId, $actor, 'user.disabled', 'user', $u['uuid'], 'Disabled the login for ' . $u['email']);
        });
    }

    /** Whether this login may sign in on a date: active, and access has not ended. */
    public static function canSignIn(array $user, ?string $on = null): bool
    {
        $on ??= Clock::today();
        if ($user['status'] !== 'active') {
            return false;
        }
        return $user['access_ends_on'] === null || $user['access_ends_on'] >= $on;
    }

    /** @return list<array<string,mixed>> */
    public static function forOrg(int $orgId): array
    {
        return DB::all(
            'SELECT u.*, p.uuid AS person_uuid, p.first_name, p.last_name FROM users u LEFT JOIN persons p ON p.id = u.person_id WHERE u.organisation_id = ? ORDER BY u.email',
            [$orgId]
        );
    }
}
