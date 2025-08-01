<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012, 2022 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Enums\Action;
use Elabftw\Enums\State;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Interfaces\QueryParamsInterface;
use Elabftw\Models\Notifications\OnboardingEmail;
use Elabftw\Params\TeamParam;
use Elabftw\Services\Filter;
use Elabftw\Services\TeamsHelper;
use Elabftw\Services\UsersHelper;
use Elabftw\Traits\SetIdTrait;
use Override;
use PDO;
use RuntimeException;

use function array_diff;
use function trim;

/**
 * All about the teams
 */
final class Teams extends AbstractRest
{
    use SetIdTrait;

    public array $teamArr = array();

    public function __construct(public Users $Users, ?int $id = null, public bool $bypassWritePermission = false)
    {
        parent::__construct();
        $this->setId($id);
        if ($this->id) {
            $this->teamArr = $this->readOneComplete();
        }
    }

    /**
     * Make sure that the teams exist. Input can be an array of team name, id or orgid
     * and the response is an array of teams, with id and name for each
     * Input can come from external auth and reference an uncreated team
     * so with this the team will be created on the fly (if it's allowed)
     */
    public function getTeamsFromIdOrNameOrOrgidArray(array $teams, bool $allowTeamCreation = false): array
    {
        $res = array();
        $sql = 'SELECT id, name FROM teams WHERE id = :query OR name = :query OR orgid = :query';
        $req = $this->Db->prepare($sql);
        foreach ($teams as $query) {
            $req->bindValue(':query', trim((string) $query));
            $this->Db->execute($req);
            $team = $req->fetch();
            // team was not found, we need to create it, but only if we're allowed to
            if ($team === false && $allowTeamCreation === true) {
                $this->bypassWritePermission = true;
                $freshTeam = new self($this->Users, $this->create($query));
                $team = $freshTeam->readOneComplete();
            }
            // this prevents adding a bool(false)
            if (is_array($team)) {
                $res[] = $team;
            }
        }
        if (empty($res)) {
            throw new ImproperActionException('At least one team must be provided: none were found.');
        }
        return $res;
    }

    /**
     * When the user logs in, make sure that the teams they are part of
     * are the same teams than the one sent by an external auth
     *
     * @param array<array-key, mixed> $teams
     */
    public function synchronize(int $userid, array $teams): void
    {
        $Users2Teams = new Users2Teams($this->Users);
        // send onboarding email of teams newly added to a user
        if ($this->Users->userData['validated']) {
            $Users2Teams->sendOnboardingEmailOfTeams = true;
        }
        $teamIdArr = array_column($teams, 'id');
        // get the difference between the teams sent by idp
        // and the teams that the user is in
        $UsersHelper = new UsersHelper($userid);
        $currentTeams = $UsersHelper->getTeamsIdFromUserid();

        $addToTeams = array_diff($teamIdArr, $currentTeams);
        $Users2Teams->addUserToTeams($userid, $addToTeams);
        $currentTeams = $UsersHelper->getTeamsIdFromUserid();

        $rmFromTeams = array_diff($currentTeams, $teamIdArr);
        $Users2Teams->rmUserFromTeams($userid, $rmFromTeams);
    }

    #[Override]
    public function getApiPath(): string
    {
        return 'api/v2/teams/';
    }

    #[Override]
    public function postAction(Action $action, array $reqBody): int
    {
        $this->canWriteOrExplode();
        return match ($action) {
            Action::Create => $this->create($reqBody['name'] ?? 'New team name'),
            default => throw new ImproperActionException('Incorrect action for teams.'),
        };
    }

    /**
     * Read one team
     */
    #[Override]
    public function readOne(): array
    {
        // allow sysadmin to read any team from api, but a non sysadmin can only query a team they are part of
        $TeamsHelper = new TeamsHelper($this->id ?? -1);

        if (!$TeamsHelper->isUserInTeam($this->Users->userid ?? -1) && !$this->Users->userData['is_sysadmin']) {
            throw new ImproperActionException('Cannot query team information if not part of that team or not Sysadmin!');
        }
        return $this->readOneComplete();
    }

    public function readOneComplete(): array
    {
        $sql = 'SELECT * FROM `teams` WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);

        return $this->Db->fetch($req);
    }

    /**
     * Read all teams: Sysadmin will get a complete response with all columns, user will get simple result
     */
    #[Override]
    public function readAll(?QueryParamsInterface $queryParams = null): array
    {
        if ($this->Users->requester->userData['is_sysadmin']) {
            return $this->readAllComplete();
        }
        return $this->readAllVisible();
    }

    // for sysadmin via api or if we need all
    public function readAllComplete(): array
    {
        $sql = 'SELECT * FROM teams ORDER BY name ASC';
        $req = $this->Db->prepare($sql);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    public function readNamesFromIds(array $idArr): array
    {
        if (empty($idArr)) {
            return array();
        }
        $onlyIds = array_map('intval', $idArr);
        $sql = 'SELECT teams.name FROM teams WHERE id IN (' . implode(',', $onlyIds) . ') ORDER BY name ASC';
        $req = $this->Db->prepare($sql);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    // if all you need is a name/id for all visible teams
    public function readAllVisible(): array
    {
        $sql = 'SELECT id, name FROM teams WHERE visible = 1 ORDER BY name ASC';
        $req = $this->Db->prepare($sql);
        $this->Db->execute($req);

        return $req->fetchAll();
    }

    #[Override]
    public function patch(Action $action, array $params): array
    {
        $this->canWriteOrExplode();

        match ($action) {
            Action::Archive => throw new ImproperActionException('Feature not implemented.'),
            Action::Update => (
                function () use ($params) {
                    foreach ($params as $key => $value) {
                        $this->update(new TeamParam($key, (string) $value));
                    }
                }
            )(),
            Action::SendOnboardingEmails => $this->sendOnboardingEmails($params['userids']),
            default => throw new ImproperActionException('Incorrect action for teams.'),
        };
        return $this->readOne();
    }

    /**
     * Delete a team only if all the stats are at zero
     */
    #[Override]
    public function destroy(): bool
    {
        // check for stats, should be 0
        $count = $this->getStats($this->id ?? 0);

        if ($count['totxp'] !== 0 || $count['totdb'] !== 0 || $count['all_users_count'] !== 0) {
            throw new ImproperActionException('The team is not empty! Aborting deletion!');
        }

        /*
         * foreign keys will take care of deleting associated data (like status or experiments_templates)
         * IMPORTANT NOTE: the deletion of status will delete the experiments that have this status, too!
         * so even if the experiments have been moved around, if the status still belongs to the deleted team,
         * the experiment will get deleted.
         * so don't rely on fk to delete the status, but run it through the Status->delete first,
         * it will check if experiments have the status and show an error
         */
        $sql = 'SELECT id FROM experiments_status WHERE team = :team';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':team', $this->id, PDO::PARAM_INT);
        $this->Db->execute($req);
        $statusArr = $req->fetchAll();
        $Status = new ExperimentsStatus($this);
        foreach ($statusArr as $status) {
            $Status->setId($status['id']);
            $Status->destroy();
        }

        $sql = 'DELETE FROM teams WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        return $this->Db->execute($req);
    }

    /**
     * Get statistics for a team
     */
    public function getStats(?int $team = null): array
    {
        $sql = 'SELECT
            (
                SELECT COUNT(users2teams.users_id) FROM users2teams WHERE users2teams.teams_id = :team AND users2teams.is_archived = 0
            ) AS active_users_count,
            (SELECT COUNT(users.userid)
                FROM users
                CROSS JOIN users2teams
                    ON (users2teams.users_id = users.userid)
                WHERE users2teams.teams_id = :team
            ) AS all_users_count,
            (SELECT COUNT(users.userid)
                FROM users
                CROSS JOIN users2teams
                    ON (users2teams.users_id = users.userid)
                WHERE users2teams.teams_id = :team AND users2teams.is_admin = 1
            ) AS active_admins_count,
            (SELECT COUNT(items.id)
                FROM items
                WHERE items.team = :team
                    AND items.state IN (:state_normal, :state_archived)
            ) AS totdb,
            (SELECT COUNT(experiments.id)
                FROM experiments
                WHERE experiments.team = :team
                    AND experiments.state IN (:state_normal, :state_archived)
            ) AS totxp,
            (SELECT COUNT(experiments.id)
                FROM experiments
                WHERE experiments.team = :team
                    AND experiments.state IN (:state_normal, :state_archived)
                    AND experiments.timestamped = 1
            ) AS totxpts';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':team', $team ?? $this->id, PDO::PARAM_INT);
        $req->bindValue(':state_normal', State::Normal->value, PDO::PARAM_INT);
        $req->bindValue(':state_archived', State::Archived->value, PDO::PARAM_INT);
        $this->Db->execute($req);

        $res = $req->fetch(PDO::FETCH_NAMED);
        if ($res === false) {
            return array();
        }

        return $res;
    }

    public function canWriteOrExplode(): void
    {
        if ($this->bypassWritePermission || ($this->Users->userData['is_sysadmin'] ?? 0) === 1) {
            return;
        }
        if ($this->id === null) {
            throw new RuntimeException('Cannot check permissions in team because the team id is null.');
        }
        $TeamsHelper = new TeamsHelper($this->id);

        if ($TeamsHelper->isAdminInTeam($this->Users->userData['userid'])) {
            return;
        }
        throw new IllegalActionException('User tried to update a team setting but they are not admin of that team.');
    }

    public function create(string $name): int
    {
        $name = Filter::title($name);

        $sql = 'INSERT INTO teams (name, common_template, common_template_md) VALUES (:name, :common_template, :common_template_md)';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':name', $name);
        $req->bindValue(':common_template', Templates::defaultBody);
        $req->bindValue(':common_template_md', Templates::defaultBodyMd);
        $this->Db->execute($req);
        // grab the team ID
        $newId = $this->Db->lastInsertId();
        $this->setId($newId);

        // create default experiments status set
        $Status = new ExperimentsStatus($this);
        $Status->createDefault();

        return $newId;
    }

    private function update(TeamParam $params): bool
    {
        $sql = 'UPDATE teams SET ' . $params->getColumn() . ' = :content WHERE id = :id';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':content', $params->getContent());
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        return $this->Db->execute($req);
    }

    private function sendOnboardingEmails(array $userids): void
    {
        // validate that userid is part of team and active
        foreach (array_intersect(array_column($this->Users->readAllActiveFromTeam(), 'userid'), $userids) as $userid) {
            /** @psalm-suppress PossiblyNullArgument */
            (new OnboardingEmail($this->id))->create($userid);
        }
    }
}
