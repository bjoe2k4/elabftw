<?php
/**
 * \Elabftw\Elabftw\Database
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see http://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
namespace Elabftw\Elabftw;

use \PDO;
use \Exception;

/**
 * All about the database items
 */
class Database
{
    /** pdo object */
    private $pdo;

    /** id of the item */
    private $id;

    /** id of the team */
    private $team;

    /**
     * Constructor
     *
     */
    public function __construct($id, $team)
    {
        $this->id = $id;
        $this->team = $team;

        $this->pdo = Db::getConnection();

        // permission check
        // you can only see items from your team
        if (!$this->isInTeam()) {
            throw new Exception(_('This section is out of your reach.'));
        }

    }

    /**
     * Check if the item we want to view is in the team
     *
     */
    public function isInTeam()
    {
        $sql = "SELECT team FROM items WHERE id = :id";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $req->execute();

        return $req->fetchColumn() == $this->team;
    }

    /**
     * Read an item
     *
     * @throws Exception if empty results
     * @return array
     */
    public function read()
    {
        $sql = "SELECT items.id AS itemid,
            experiments_links.id AS linkid,
            experiments_links.*,
            items.*,
            items_types.*,
            users.lastname,
            users.firstname
            FROM items
            LEFT JOIN experiments_links ON (experiments_links.link_id = items.id)
            LEFT JOIN items_types ON (items.type = items_types.id)
            LEFT JOIN users ON (items.userid = users.userid)
            WHERE items.id = :id";
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':id', $this->id, PDO::PARAM_INT);
        $req->execute();

        if ($req->rowCount() === 0) {
            throw new Exception('Nothing to show with this id.');
        }

        return $req->fetch();
    }
}
