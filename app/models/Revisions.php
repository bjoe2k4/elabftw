<?php
/**
 * \Elabftw\Elabftw\Revisions
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
 * All about the revisions
 */
class Revisions
{
    /** pdo object */
    private $pdo;

    /** experiments or items */
    private $type;

    /** id of the item/exp */
    private $id;

    /**
     * Constructor
     *
     */
    public function __construct($id, $type)
    {
        $this->id = Tools::checkId($id);
        if ($this->id === false) {
            throw new Exception(_('The id parameter is not valid!'));
        }
        $this->pdo = Db::getConnection();
        $this->type = $type;
    }

    /**
     * Get how many revisions we have
     *
     */
    public function readCount()
    {
        if ($this->type === 'experiments') {
            $sql = "SELECT COUNT(*) FROM experiments_revisions
                WHERE item_id = :item_id ORDER BY savedate DESC";
        } else {
            $sql = "SELECT COUNT(*) FROM items_revisions
                WHERE item_id = :item_id ORDER BY savedate DESC";
        }
        $req = $this->pdo->prepare($sql);
        $req->bindParam(':item_id', $this->id);
        $req->execute();

        return (int) $req->fetchColumn();
    }

    public function show()
    {
        $html = '';
        $count = $this->readCount();

        if ($count > 0) {
            $html .= "<span class='align_right'>";
            $html .= $count . " " . ngettext('revision available.', 'revisions available.', $count);
            $html .= " <a href='revision.php?type=" . $this->type . "&item_id=" . $this->id . "'>" . _('Show history') . "</a>";
            $html .= "</span>";
        }

        return $html;
    }
}
