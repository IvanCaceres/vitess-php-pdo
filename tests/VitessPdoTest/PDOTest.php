<?php
/**
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 */

namespace VitessPdoTest;

use VitessPdo\PDO;
use VitessPdo\PDO\PDOStatement;
use VitessPdo\PDO\Exception as VitessPDOException;
use VitessPdoTest\Helper\CustomPDOStatement;
use VitessPdoTest\Helper\VTComboRunner;
use Exception;
use PDOException;
use PDO as CorePDO;

/**
 * Class PDOTest
 *
 * @package VitessPdoTest
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PDOTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var string
     */
    private $dsn = "vitess:dbname=" . VTComboRunner::KEYSPACE
            . ";host=" . VTComboRunner::HOST . ";port=" . VTComboRunner::PORT;

    /**
     * @var string
     */
    private $dsnWithVtctld = "vitess:dbname=" . VTComboRunner::KEYSPACE
            . ";host=" . VTComboRunner::HOST . ";port=" . VTComboRunner::PORT
            . ";vtctld_host=" . VTComboRunner::HOST . ";vtctld_port=" . VTComboRunner::PORT;

    /**
     * @var array
     */
    private $errors;

    /**
     * @var VTComboRunner
     */
    private static $comboRunner;

    /**
     * @const int
     */
    const TEST_USER_ID1 = 4;

    /**
     * @const int
     */
    const TEST_USER_ID2 = 5;

    /**
     * @throws Exception
     */
    public static function setUpBeforeClass()
    {
        self::$comboRunner = new VTComboRunner();
        self::$comboRunner->run();
    }

    /**
     *
     */
    public static function tearDownAfterClass()
    {
        self::$comboRunner->stop();
    }

    /**
     *
     */
    protected function setUp()
    {
        $this->errors = [];
        set_error_handler([$this, "errorHandler"]);
    }

    /**
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param array $errcontext
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
    {
        $this->errors[] = compact("errno", "errstr", "errfile", "errline", "errcontext");
    }

    /**
     * @param int $errno
     */
    public function assertError($errno)
    {
        foreach ($this->errors as $error) {
            if ($error["errno"] === $errno) {
                return;
            }
        }
        self::fail(
            "Error with level " . $errno . " not found in " .
            var_export($this->errors, true)
        );
    }

    /**
     *
     */
    public function testCorrectConstruct()
    {
        try {
            $pdo = $this->getPdo();
            self::assertInstanceOf(PDO::class, $pdo);
        } catch (Exception $e) {
            self::fail(sprintf("Failed creating the PDO instance with an exception: '%s'", $e->getMessage()));
        }
    }

//    /**
//     * Vitess doesn't support SET NAMES queries
//     */
//    public function testCorrectConstructInitQuery()
//    {
//        $dsn = "vitess:dbname=test_keyspace;host=localhost;port=15991";
//        $options = [
//            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8 COLLATE 'utf8_bin', time_zone='+0:00';",
//        ];
//
//        try {
//            $pdo = new PDO($dsn, null, null, $options);
//            $this->assertInstanceOf(PDO::class, $pdo);
//        } catch (Exception $e) {
//            print_r($e->getPrevious());
//            $this->fail(sprintf("Failed creating the PDO instance with an exception: '%s'", $e->getMessage()));
//        }
//    }

    public function testExecFunctionInsertAndStmtDeleteReused()
    {
        $pdo = $this->getPdo();
        $insertIds = [];

        for ($i = 0; $i < 3; $i++) {
            $rows = $pdo->exec("INSERT INTO user (name) VALUES ('test_user')");
            $insertIds[] = $pdo->lastInsertId();

            self::assertEquals(1, $rows);
        }

        $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = :id");

        foreach ($insertIds as $id) {
            $result = $stmt->execute(['id' => $id]);
            self::assertTrue($result);
            self::assertEquals(1, $stmt->rowCount());
        }
    }

    public function testTransactions()
    {
        $pdo = $this->getPdo();

        self::assertEquals(false, $pdo->inTransaction());

        $commitResult = $pdo->commit();
        self::assertFalse($commitResult);

        $pdo->beginTransaction();
        self::assertEquals(true, $pdo->inTransaction());
        $rows = $pdo->exec("INSERT INTO user (name) VALUES ('test_user')");
        self::assertEquals(1, $rows);
        self::assertEquals(true, $pdo->inTransaction());
        $commitResult = $pdo->commit();
        self::assertTrue($commitResult);
        self::assertEquals(false, $pdo->inTransaction());
    }

    public function testReadWhileInTransactions()
    {
        $pdo = $this->getPdo();

        self::assertEquals(false, $pdo->inTransaction());

        $commitResult = $pdo->commit();
        self::assertFalse($commitResult);

        $pdo->beginTransaction();
        self::assertEquals(true, $pdo->inTransaction());
        $rows = $pdo->exec("INSERT INTO user (name) VALUES ('test_user')");
        $lastId = $pdo->lastInsertId();
        self::assertEquals(1, $rows);
        self::assertEquals(true, $pdo->inTransaction());
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = {$lastId}");
        $stmt->execute();
        $user = $stmt->fetch();
        self::assertEquals((string) $lastId, $user['user_id']);
        $commitResult = $pdo->commit();
        self::assertTrue($commitResult);
        self::assertEquals(false, $pdo->inTransaction());
    }

    public function testTransactionRollbackException()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_EXCEPTION);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("No transaction is active.");
        $rollbackResult = $pdo->rollback();
        self::assertFalse($rollbackResult);
    }

    public function testTransactionRollback()
    {
        $pdo = $this->getPdo();
        $name = 'test_user_rollback';

        $pdo->beginTransaction();
        $rows = $pdo->exec("INSERT INTO user (name) VALUES ('{$name}')");
        self::assertEquals(1, $rows);
        $rollbackResult = $pdo->rollback();
        self::assertTrue($rollbackResult);
        self::assertEquals(false, $pdo->inTransaction());

        $stmt = $pdo->prepare("SELECT * FROM user WHERE name = :name");
        $result = $stmt->execute(['name' => $name]);
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertEmpty($users);
    }

    public function testLastInsertId()
    {
        $pdo = $this->getPdo();

        $pdo->beginTransaction();
        $rows = $pdo->exec("INSERT INTO user (name) VALUES ('test_user')");
        self::assertEquals(1, $rows);
        self::assertNotEquals('0', $pdo->lastInsertId());
        $pdo->commit();
        self::assertEquals('0', $pdo->lastInsertId());
    }

    public function testPrepare()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
    }

    public function testPrepareWithUnnamedParams()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (?, ?)");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $result = $stmt->execute([self::TEST_USER_ID1, self::TEST_USER_ID2]);
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertCount(2, $users);
    }

    public function testPrepareWithNamedParams()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $result = $stmt->execute(['id1' => self::TEST_USER_ID1, 'id2' => self::TEST_USER_ID2]);
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertCount(2, $users);
    }

    public function testPrepareWithNamedParamsString()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE name = :name");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $result = $stmt->execute(['name' => 'test_user']);
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
    }

    public function testPrepareWithMixedParams()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, ?)");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $this->expectException(PDOException::class);
        $stmt->execute(['id1' => self::TEST_USER_ID1, self::TEST_USER_ID2]);
    }

    public function testPrepareWithUnnamedParamsBoundExtra()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (?, ?)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $stmt->bindValue(1, self::TEST_USER_ID1, CorePDO::PARAM_INT);
        $stmt->bindValue(2, self::TEST_USER_ID2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertCount(2, $users);
    }

    public function testPrepareWithNamedParamsBoundExtra()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $stmt->bindValue('id1', self::TEST_USER_ID1, CorePDO::PARAM_INT);
        $stmt->bindValue('id2', self::TEST_USER_ID2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertCount(2, $users);
    }

    public function testPrepareWithUnnamedParams2BoundExtra()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (?, ?)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam(1, $id1, CorePDO::PARAM_INT);
        $stmt->bindParam(2, $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertCount(2, $users);
    }

    public function testPrepareWithNamedParams2BoundExtra()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam('id1', $id1, CorePDO::PARAM_INT);
        $stmt->bindParam('id2', $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        $count = 0;

        self::assertInternalType('array', $users);
        foreach ($users as $user) {
            $count++;
            self::assertInternalType('array', $user);
            self::assertNotEmpty($user);
            self::assertArrayHasKey('user_id', $user);
            self::assertArrayHasKey(0, $user);
        }

        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertEquals(2, $count);
    }

    public function testPrepareWithNamedParams2BoundExtraFetchAllAssoc()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam('id1', $id1, CorePDO::PARAM_INT);
        $stmt->bindParam('id2', $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll(CorePDO::FETCH_ASSOC);
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);

        $count = 0;

        foreach ($users as $user) {
            $count++;
            self::assertInternalType('array', $user);
            self::assertNotEmpty($user);
            self::assertArrayHasKey('user_id', $user);
            self::assertArrayNotHasKey(0, $user);
        }

        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertEquals(2, $count);
    }

    public function testPrepareWithNamedParams2BoundExtraFetchAllNum()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam('id1', $id1, CorePDO::PARAM_INT);
        $stmt->bindParam('id2', $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll(CorePDO::FETCH_NUM);
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);

        $count = 0;

        foreach ($users as $user) {
            $count++;
            self::assertInternalType('array', $user);
            self::assertNotEmpty($user);
            self::assertArrayNotHasKey('user_id', $user);
            self::assertArrayHasKey(0, $user);
        }

        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertEquals(2, $count);
    }

    public function testPrepareWithNamedParams2BoundExtraFetch()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam('id1', $id1, CorePDO::PARAM_INT);
        $stmt->bindParam('id2', $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $count = 0;

        while (($user = $stmt->fetch()) !== false) {
            $count++;
            self::assertInternalType('array', $user);
            self::assertNotEmpty($user);
        }

        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertEquals(2, $count);
    }

    public function testPrepareWithNamedParams2BoundExtraFetchColumn()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id IN (:id1, :id2)");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $id1 = self::TEST_USER_ID1;
        $id2 = self::TEST_USER_ID2;
        $stmt->bindParam('id1', $id1, CorePDO::PARAM_INT);
        $stmt->bindParam('id2', $id2, CorePDO::PARAM_INT);

        $result = $stmt->execute();
        self::assertTrue($result);

        $count = 0;

        while (($userId = $stmt->fetchColumn()) !== false) {
            self::assertInternalType('string', $userId);
            // order is not ensured and ORDER BY cannot be used because of the multi shard query
            self::assertTrue(in_array($userId, [$id1, $id2]));
            $count++;
        }

        // warning! this doesn't have to work on sharded tables, if the data is in multiple shards
        self::assertEquals(2, $count);
    }

    public function testPrepareWithEmptyResult()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM user WHERE name = :name");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $result = $stmt->execute(['name' => 'non_existent_user']);
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertEmpty($users);

        $users = $stmt->fetchAll(CorePDO::FETCH_ASSOC);
        self::assertInternalType('array', $users);
        self::assertEmpty($users);
    }

    public function testSetAttributeNotImplemented()
    {
        $this->expectException(VitessPDOException::class);
        $this->expectExceptionMessageRegExp('/^PDO parameter not implemented/');
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_CASE, CorePDO::CASE_LOWER);
    }

    public function testSetAttribute()
    {
        $pdo = $this->getPdo();
        $result = $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_SILENT);
        self::assertTrue($result);
    }

    public function testSetAttributeErrModeSilent()
    {
        $pdo = $this->getPdo();

        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_SILENT);
        $stmt = $pdo->prepare("SELECT * FROM non_existent_table");
        $result = $stmt->execute();
        self::assertFalse($result);
    }

    /**
     *
     */
    public function testSetAttributeErrModeWarning()
    {
        $pdo = $this->getPdo();

        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_WARNING);
        $stmt = $pdo->prepare("SELECT * FROM non_existent_table");
        $result = $stmt->execute();
        $this->assertError(E_WARNING);
        self::assertFalse($result);
    }

    public function testSetAttributeErrModeException()
    {
        $pdo = $this->getPdo();

        $this->expectException(PDOException::class);

        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("SELECT * FROM non_existent_table");
        $result = $stmt->execute();
        self::assertFalse($result);
    }

    public function testGetAttribute()
    {
        $pdo = $this->getPdo();

        $result = $pdo->getAttribute(CorePDO::ATTR_ERRMODE);
        self::assertEquals(CorePDO::ERRMODE_SILENT, $result);

        $result = $pdo->getAttribute(CorePDO::ATTR_ORACLE_NULLS);
        self::assertNull($result);
    }

    public function testGetAttributeDriverName()
    {
        $pdo = $this->getPdo();

        $driverName = $pdo->getAttribute(CorePDO::ATTR_DRIVER_NAME);
        self::assertEquals(PDO\Attributes::DRIVER_NAME, $driverName);
    }

    public function testQuote()
    {
        $pdo = $this->getPdo();

        $str1 = $pdo->quote('Nice');
        self::assertEquals("'Nice'", $str1);

        $str2 = $pdo->quote('Naughty \' string');
        self::assertEquals("'Naughty '' string'", $str2);

        $str3 = $pdo->quote("Co'mpl''ex \"st'\"ring");
        self::assertEquals("'Co''mpl''''ex \"st''\"ring'", $str3);
    }

    public function testQuery()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("SELECT * FROM user");

        self::assertInstanceOf(PDOStatement::class, $stmt);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
    }

    public function testQueryErrorSilent()
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("SELECT * FROM non_existent_table");

        self::assertFalse($stmt);
    }

    public function testQueryErrorWarning()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_WARNING);
        $stmt = $pdo->query("SELECT * FROM non_existent_table");
        $this->assertError(E_WARNING);

        self::assertFalse($stmt);
    }

    public function testQueryErrorException()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_EXCEPTION);
        $this->expectException(PDOException::class);
        $stmt = $pdo->query("SELECT * FROM non_existent_table");

        self::assertFalse($stmt);
    }

    public function testErrorInfo()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_ERRMODE, CorePDO::ERRMODE_WARNING);
        $pdo->exec("INSERT INTO non_existent_table VALUES (1, 2)");
        $this->assertError(E_WARNING);
        $error = $pdo->errorInfo();

        self::assertInternalType('array', $error);
        self::assertNotEmpty($error);
        self::assertArrayHasKey(0, $error);
        self::assertArrayHasKey(1, $error);
        self::assertArrayHasKey(2, $error);
    }

    public function testUseDb()
    {
        $pdo = $this->getPdoWithVctldSupport();
        $stmt = $pdo->query("USE tsm");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        self::assertEquals(0, $stmt->rowCount());
    }

    public function testShowTables()
    {
        $pdo = $this->getPdoWithVctldSupport();
        $stmt = $pdo->query("SHOW TABLES");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(CorePDO::FETCH_BOTH);
        self::assertCount(6, $rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey('Tables_in_user', $row);
            self::assertArrayHasKey(0, $row);
        }
    }

    public function testShowCollation()
    {
        $pdo = $this->getPdoWithVctldSupport();
        $stmt = $pdo->query("SHOW COLLATION");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(CorePDO::FETCH_BOTH);
        self::assertCount(1, $rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey('Collation', $row);
            self::assertArrayHasKey(0, $row);
            self::assertEquals('utf8_bin', $row['Collation']);
            self::assertEquals('utf8_bin', $row[0]);

            self::assertArrayHasKey('Charset', $row);
            self::assertArrayHasKey(1, $row);
            self::assertEquals('utf8', $row['Charset']);
            self::assertEquals('utf8', $row[1]);

            self::assertArrayHasKey('Id', $row);
            self::assertArrayHasKey(2, $row);
            self::assertEquals('83', $row['Id']);
            self::assertEquals('83', $row[2]);

            self::assertArrayHasKey('Default', $row);
            self::assertArrayHasKey(3, $row);
            self::assertEquals('Yes', $row['Default']);
            self::assertEquals('Yes', $row[3]);

            self::assertArrayHasKey('Compiled', $row);
            self::assertArrayHasKey(4, $row);
            self::assertEquals('Yes', $row['Compiled']);
            self::assertEquals('Yes', $row[4]);

            self::assertArrayHasKey('Sortlen', $row);
            self::assertArrayHasKey(5, $row);
            self::assertEquals('1', $row['Sortlen']);
            self::assertEquals('1', $row[5]);
        }
    }

    public function testShowCreateDatabase()
    {
        $pdo = $this->getPdoWithVctldSupport();
        $stmt = $pdo->query("SHOW CREATE DATABASE `user`");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(CorePDO::FETCH_BOTH);
        self::assertCount(1, $rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey('Database', $row);
            self::assertArrayHasKey(0, $row);
            self::assertEquals('user', $row['Database']);
            self::assertEquals('user', $row[0]);

            self::assertArrayHasKey('Create Database', $row);
            self::assertArrayHasKey(1, $row);
            self::assertEquals(
                'CREATE DATABASE `user` /*!40100 DEFAULT CHARACTER SET utf8 */',
                $row['Create Database']
            );
            self::assertEquals('CREATE DATABASE `user` /*!40100 DEFAULT CHARACTER SET utf8 */', $row[1]);
        }
    }

    /**
     * @throws Exception
     * @throws VitessPDOException
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testShowTableStatus()
    {
        $pdo = $this->getPdoWithVctldSupport();
        $stmt = $pdo->query("SHOW TABLE STATUS");

        self::assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(CorePDO::FETCH_BOTH);
        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey('Name', $row);
            self::assertArrayHasKey(0, $row);
            self::assertNotEmpty($row['Name']);
            self::assertNotEmpty($row[0]);

            self::assertArrayHasKey('Engine', $row);
            self::assertArrayHasKey(1, $row);
            self::assertEquals('InnoDB', $row['Engine']);
            self::assertEquals('InnoDB', $row[1]);

            self::assertArrayHasKey('Version', $row);
            self::assertArrayHasKey(2, $row);
            self::assertNotEmpty($row['Version']);
            self::assertNotEmpty($row[2]);

            self::assertArrayHasKey('Row_format', $row);
            self::assertArrayHasKey(3, $row);
            self::assertEquals('Compact', $row['Row_format']);
            self::assertEquals('Compact', $row[3]);

            self::assertArrayHasKey('Rows', $row);
            self::assertArrayHasKey(4, $row);
            self::assertEquals('0', $row['Rows']);
            self::assertEquals('0', $row[4]);

            self::assertArrayHasKey('Avg_row_length', $row);
            self::assertArrayHasKey(5, $row);
            self::assertEquals('0', $row['Avg_row_length']);
            self::assertEquals('0', $row[5]);

            self::assertArrayHasKey('Data_length', $row);
            self::assertArrayHasKey(6, $row);
            self::assertEquals('0', $row['Data_length']);
            self::assertEquals('0', $row[6]);

            self::assertArrayHasKey('Max_data_length', $row);
            self::assertArrayHasKey(7, $row);
            self::assertEquals('0', $row['Max_data_length']);
            self::assertEquals('0', $row[7]);

            self::assertArrayHasKey('Index_length', $row);
            self::assertArrayHasKey(8, $row);
            self::assertEquals('0', $row['Index_length']);
            self::assertEquals('0', $row[8]);

            self::assertArrayHasKey('Data_free', $row);
            self::assertArrayHasKey(9, $row);
            self::assertEquals('0', $row['Data_free']);
            self::assertEquals('0', $row[9]);

            self::assertArrayHasKey('Auto_increment', $row);
            self::assertArrayHasKey(10, $row);
            self::assertNull($row['Auto_increment']);
            self::assertNull($row[10]);

            self::assertArrayHasKey('Create_time', $row);
            self::assertArrayHasKey(11, $row);
            self::assertEquals('2016-06-15 13:12:59', $row['Create_time']);
            self::assertEquals('2016-06-15 13:12:59', $row[11]);

            self::assertArrayHasKey('Update_time', $row);
            self::assertArrayHasKey(12, $row);
            self::assertNull($row['Update_time']);
            self::assertNull($row[12]);

            self::assertArrayHasKey('Check_time', $row);
            self::assertArrayHasKey(13, $row);
            self::assertNull($row['Check_time']);
            self::assertNull($row[13]);

            self::assertArrayHasKey('Collation', $row);
            self::assertArrayHasKey(14, $row);
            self::assertEquals('utf8_bin', $row['Collation']);
            self::assertEquals('utf8_bin', $row[14]);

            self::assertArrayHasKey('Checksum', $row);
            self::assertArrayHasKey(15, $row);
            self::assertNull($row['Checksum']);
            self::assertNull($row[15]);

            self::assertArrayHasKey('Create_options', $row);
            self::assertArrayHasKey(16, $row);
            self::assertEquals('', $row['Create_options']);
            self::assertEquals('', $row[16]);

            self::assertArrayHasKey('Comment', $row);
            self::assertArrayHasKey(17, $row);
            self::assertEquals('', $row['Comment']);
            self::assertEquals('', $row[17]);
        }
    }

    public function testStmtClass()
    {
        $pdo = $this->getPdo();
        $pdo->setAttribute(CorePDO::ATTR_STATEMENT_CLASS, [CustomPDOStatement::class]);
        $stmt = $pdo->prepare("SELECT * FROM user");

        self::assertInstanceOf(CustomPDOStatement::class, $stmt);

        $result = $stmt->execute();
        self::assertTrue($result);

        $users = $stmt->fetchAll();
        self::assertInternalType('array', $users);
        self::assertNotEmpty($users);
    }

    /**
     * @return PDO
     */
    private function getPdo()
    {
        return new PDO($this->dsn);
    }

    /**
     * @return PDO
     */
    private function getPdoWithVctldSupport()
    {
        return new PDO($this->dsnWithVtctld);
    }
}
