<?php
/**
 * @author     mfris
 * @copyright  Pixel federation
 * @license    Internal use only
 */

namespace VitessPdo;

use VitessPdo\PDO\Dsn;
use VitessPdo\PDO\QueryAnalyzer;
use VTContext;
use VTGateConn;
use VTGrpcClient;
use VTGateTx;
use topodata\TabletType;
use VTException;
use Grpc;
use PDO as CorePDO;
use PDOException;
use Exception;

/**
 * Description of class PDO
 *
 * @author  mfris
 * @package VitessPdo
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PDO
{

    /**
     * @var Dsn
     */
    private $dsn;

    /**
     * @var VTContext
     */
    private $vitessCtx;

    /**
     * @var VTGrpcClient
     */
    private $grpcClient;

    /**
     * @var VTGateConn
     */
    private $vtgateConnection;

    /**
     * @var VTGateTx
     */
    private $transaction = null;

    /**
     * @var QueryAnalyzer
     */
    private $queryAnalyzer;

    /**
     * PDO constructor.
     *
     * @param       $dsn
     * @param null  $username
     * @param null  $password
     * @param array $options
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct($dsn, $username = null, $password = null, array $options = [])
    {
        $this->dsn = new Dsn($dsn);
        $this->connect($options);
    }

    /**
     * @param array $options
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function connect(array $options)
    {
        try {
            $this->vitessCtx        = VTContext::getDefault();
            $host                   = $this->dsn->getConfig()->getHost();
            $port                   = $this->dsn->getConfig()->getPort();
            $this->grpcClient       = new VTGrpcClient("{$host}:{$port}");
            $this->vtgateConnection = new VTGateConn($this->grpcClient);
            $this->queryAnalyzer    = new QueryAnalyzer();
        } catch (Exception $e) {
            throw new PDOException("Error while connecting to vitess: " . $e->getMessage(), $e->getCode(), $e);
        }

        if (isset($options[CorePDO::MYSQL_ATTR_INIT_COMMAND])) {
            // Vitess doesn't support SET NAMES queries yet
            // $query = $options[CorePDO::MYSQL_ATTR_INIT_COMMAND];
            // $this->vtgateConnection->execute($this->vitessCtx, $query, [], TabletType::MASTER);
        }
    }

    /**
     * @param string $statement
     *
     * @return int
     */
    public function exec($statement)
    {
        $isInTransaction = $this->inTransaction();
        $transaction = $this->getTransaction();
        $cursor = $transaction->execute($this->vitessCtx, $statement, [], TabletType::MASTER);

        if (!$isInTransaction) {
            $this->commit();
        }

        return $cursor->getRowsAffected();
    }

    /**
     * @return bool
     */
    public function inTransaction()
    {
        return $this->transaction !== null;
    }

    /**
     * @return bool
     */
    public function beginTransaction()
    {
        if ($this->inTransaction()) {
            return false;
        }

        $this->getTransaction();

        return true;
    }

    /**
     * @return bool
     */
    public function commit()
    {
        if (!$this->inTransaction()) {
            return false;
        }

        try {
            $this->transaction->commit($this->vitessCtx);
        } catch (VTException $e) {
            return false;
        } finally {
            $this->resetTransaction();
        }

        return true;
    }

    /**
     * @return bool
     */
    public function rollback()
    {
        if (!$this->inTransaction()) {
            throw new PDOException("No transaction is active.");
        }

        try {
            $transaction = $this->getTransaction();
            $transaction->rollback($this->vitessCtx);
        } catch (VTException $e) {
            return false;
        } finally {
            $this->resetTransaction();
        }

        return true;
    }

    /**
     * @return VTGateTx
     */
    private function getTransaction()
    {
        if (!$this->transaction) {
            $this->transaction = $this->vtgateConnection->begin($this->vitessCtx);
        }

        return $this->transaction;
    }

    /**
     * @return void
     */
    private function resetTransaction()
    {
        $this->transaction = null;
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->vtgateConnection->close();
    }
}
