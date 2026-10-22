<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database as DB;
use App\Core\DomainException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\People\PersonRepo;
use App\Modules\Teams\AssignmentRepo;

/** "My profile": a member of staff keeps their own details up to date. Also approvals and private image files. */
final class SelfServiceController extends Controller
{
    public function me(Request $r, array $p = []): Response
    {
        return $this->renderMe([]);
    }

    public function saveDetails(Request $r, array $p): Response
    {
        $person = $this->myPerson();
        if (!$person) {
            return $this->redirect('/me');
        }
        $names = array_keys(array_filter(StaffRecord::FIELDS, static fn($m) => $m['self'] !== null));
        $posted = array_intersect_key($r->post, array_flip($names));
        foreach ($posted as $f => $v) {   // the National Insurance and bank boxes are shown empty: leaving one empty means "keep what is there"
            if (!empty(StaffRecord::FIELDS[$f]['secret']) && trim((string) $v) === '') {
                unset($posted[$f]);
            }
        }
        [$clean, $errors] = StaffRecord::cleanProfile($posted);
        if ($errors) {
            return $this->renderMe(['errors' => $errors, 'old' => $r->post], 422);
        }
        $u = Auth::user();
        $res = ProfileChanges::submit($this->orgId(), (int) $person['id'], (int) $u['id'], $clean, $this->actor());
        $parts = [];
        if ($res['applied']) {
            $parts[] = count($res['applied']) . ' saved straight away';
        }
        if ($res['pending']) {
            $parts[] = count($res['pending']) . ' sent to your line manager to approve';
        }
        $this->ok($parts ? ucfirst(implode(', ', $parts)) . '.' : 'Nothing was different, so nothing changed.');
        return $this->redirect('/me');
    }

    public function savePhoto(Request $r, array $p): Response
    {
        return $this->saveImage('photo_path', 'photo', 'photo', null);
    }

    public function saveSignature(Request $r, array $p): Response
    {
        $person = $this->myPerson();
        if (!$person) {
            return $this->redirect('/me');
        }
        try {
            $drawn = $r->input('signature_data');
            if ($drawn !== '') {
                $path = FileStore::saveDataUrl($this->orgId(), $drawn);
                $method = 'draw';
            } else {
                $path = self::storeUpload($this->orgId(), 'signature', 'signature_file');
                $method = 'upload';
            }
            ProfileChanges::submitFile($this->orgId(), (int) $person['id'], (int) Auth::user()['id'], 'signature_path', $path, $method, $this->actor());
            $this->ok('Your signature was sent to your line manager to approve.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/me#signature');
    }

    public function withdraw(Request $r, array $p): Response
    {
        $person = $this->myPerson();
        if ($person) {
            try {
                ProfileChanges::withdraw((int) $person['id'], $r->input('id'), $this->actor());
                $this->ok('Request withdrawn.');
            } catch (DomainException $e) {
                $this->fail($e->getMessage());
            }
        }
        return $this->redirect('/me');
    }

    public function approvals(Request $r, array $p = []): Response
    {
        $u = Auth::user();
        $queue = array_map(static fn($c) => ProfileChanges::shown($c, true), ProfileChanges::queueFor($u));
        return $this->view('staff/approvals', ['title' => 'Approvals', 'queue' => $queue]);
    }

    public function decide(Request $r, array $p): Response
    {
        $u = Auth::user();
        $change = ProfileChanges::byUuid($this->orgId(), $p['uuid']);
        if (!$change) {
            return Router::error(404, 'Not found', 'That request could not be found.');
        }
        try {
            if ($r->input('decision') === 'reject') {
                ProfileChanges::reject($change, $u, $r->input('reason'), $this->actor());
                $this->ok('Declined.');
            } else {
                ProfileChanges::approve($change, $u, $this->actor());
                $this->ok('Approved and applied.');
            }
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/approvals');
    }

    /** Photos, signatures and pending pictures: only to people allowed to see them. */
    public function file(Request $r, array $p): Response
    {
        $u = Auth::user();
        $org = $this->orgId();
        $kind = $p['kind'];
        $path = null;
        if ($kind === 'pending') {
            $c = ProfileChanges::byUuid($org, $p['uuid']);
            if ($c && $c['field_type'] === 'file' && $c['status'] === 'pending' && (ProfileChanges::canReview($u, $c) || ($u['person_id'] && (int) $u['person_id'] === (int) $c['person_id']))) {
                $path = explode('|', (string) $c['proposed_value'])[0];
            }
        } elseif (in_array($kind, ['photo', 'signature'], true)) {
            $person = PersonRepo::byUuid($org, $p['uuid']);
            if ($person && ($kind === 'photo' ? true : $this->canSeeSignature($u, $person))) {
                $path = StaffRecord::profile((int) $person['id'])[$kind . '_path'] ?? null;
            }
        }
        $file = FileStore::read($path);
        if (!$file) {
            return Router::error(404, 'Not found', 'There is no picture to show.');
        }
        return Response::file($file[0], $file[1]);
    }

    private function canSeeSignature(array $u, array $person): bool
    {
        if (in_array($u['role'], ['admin', 'manager'], true) || ($u['person_id'] && (int) $u['person_id'] === (int) $person['id'])) {
            return true;
        }
        return $u['person_id'] && (bool) DB::value('SELECT 1 FROM employments WHERE person_id = ? AND line_manager_person_id = ? AND (end_date IS NULL OR end_date >= CURDATE()) LIMIT 1', [$person['id'], $u['person_id']]);
    }

    private function saveImage(string $field, string $kind, string $input, ?string $method): Response
    {
        $person = $this->myPerson();
        if (!$person) {
            return $this->redirect('/me');
        }
        try {
            $path = self::storeUpload($this->orgId(), $kind, $input);
            ProfileChanges::submitFile($this->orgId(), (int) $person['id'], (int) Auth::user()['id'], $field, $path, $method, $this->actor());
            $this->ok('Your picture was sent to your line manager to approve.');
        } catch (DomainException $e) {
            $this->fail($e->getMessage());
        }
        return $this->redirect('/me#photo');
    }

    /** Take an uploaded picture from $_FILES and store it. */
    public static function storeUpload(int $orgId, string $kind, string $input): string
    {
        $f = $_FILES[$input] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new DomainException('Choose a picture to upload.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new DomainException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'That picture is too large.' : 'The upload did not work. Please try again.');
        }
        return FileStore::saveBytes($orgId, $kind, (string) file_get_contents($f['tmp_name']), $kind === 'photo' ? 3 * 1048576 : 1048576);
    }

    /** @return array<string,mixed>|null */
    private function myPerson(): ?array
    {
        $pid = Auth::user()['person_id'] ?? null;
        return $pid ? PersonRepo::byId($this->orgId(), (int) $pid) : null;
    }

    /** @param array<string,mixed> $extra */
    private function renderMe(array $extra, int $status = 200): Response
    {
        $person = $this->myPerson();
        if (!$person) {
            return $this->view('staff/me', ['title' => 'My profile', 'person' => null], $status);
        }
        $pid = (int) $person['id'];
        ProfileChanges::markSeen($pid);
        $org = $this->orgId();
        $current = Lifecycle::currentEmployment($pid);
        $changes = array_map(static fn($c) => ProfileChanges::shown($c), ProfileChanges::forPerson($pid));
        $lists = [];
        foreach (['registration', 'supervision', 'appraisal', 'learning'] as $k) {
            $lists[$k] = Compliance::list($org, $pid, $k);
        }
        return $this->view('staff/me', $extra + [
            'title' => 'My profile', 'person' => $person, 'profile' => StaffRecord::profile($pid), 'current' => $current,
            'manager' => $current && $current['line_manager_person_id'] ? PersonRepo::byId($org, (int) $current['line_manager_person_id']) : null,
            'teams' => array_filter(AssignmentRepo::forPerson($pid), static fn($t) => $t['valid_to'] === null),
            'pending' => array_values(array_filter($changes, static fn($c) => $c['status'] === 'pending')),
            'decided' => array_values(array_filter($changes, static fn($c) => in_array($c['status'], ['approved', 'rejected'], true))),
            'lists' => $lists, 'errors' => $extra['errors'] ?? [], 'old' => $extra['old'] ?? [],
        ], $status);
    }
}
