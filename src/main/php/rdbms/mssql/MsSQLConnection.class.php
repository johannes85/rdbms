<?php namespace rdbms\mssql;

use rdbms\DBConnection;
use rdbms\Transaction;
use rdbms\StatementFormatter;
use rdbms\QuerySucceeded;

/**
 * Connection to MsSQL databases using client libraries
 *
 * @see      http://mssql.com/
 * @ext      mssql
 * @test     xp://net.xp_framework.unittest.rdbms.TokenizerTest
 * @test     xp://net.xp_framework.unittest.rdbms.DBTest
 * @purpose  Database connection
 */
class MsSQLConnection extends DBConnection {

  static function __static() {
    if (extension_loaded('mssql')) {
      \rdbms\DriverManager::register('mssql+std', new \lang\XPClass(__CLASS__));
      \rdbms\DriverManager::register('sybase+ms', new \lang\XPClass(__CLASS__));
    }
  }

  /**
   * Constructor
   *
   * @param   rdbms.DSN dsn
   */
  public function __construct($dsn) {
    parent::__construct($dsn);
    $this->formatter= new StatementFormatter($this, new MsSQLDialect());
  }

  /**
   * Set Timeout
   *
   * @param   int timeout
   */
  public function setTimeout($timeout) {
    ini_set('mssql.connect_timeout', $timeout);
    parent::setTimeout($timeout);
  }

  /**
   * Connect
   *
   * @param   bool reconnect default FALSE
   * @return  bool success
   * @throws  rdbms.SQLConnectException
   */
  public function connect($reconnect= false) {
    if (is_resource($this->handle)) return true;  // Already connected
    if (!$reconnect && (false === $this->handle)) return false;    // Previously failed connecting

    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECT, $reconnect));
    if ($this->flags & DB_PERSISTENT) {
      $this->handle= mssql_pconnect(
        $this->dsn->getHost(), 
        $this->dsn->getUser(), 
        $this->dsn->getPassword()
      );
    } else {
      $this->handle= mssql_connect(
        $this->dsn->getHost(), 
        $this->dsn->getUser(), 
        $this->dsn->getPassword()
      );
    }

    if (!is_resource($this->handle)) {
      $e= new \rdbms\SQLConnectException(trim(mssql_get_last_message()), $this->dsn);
      \xp::gc(__FILE__);
      throw $e;
    }
    \xp::gc(__FILE__);

    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECTED, $reconnect));
    return parent::connect();
  }
  
  /**
   * Disconnect
   *
   * @return  bool success
   */
  public function close() { 
    $this->handle && mssql_close($this->handle);
    $this->handle= null;
    return true;
  }
  
  /**
   * Select database
   *
   * @param   string db name of database to select
   * @return  bool success
   * @throws  rdbms.SQLStatementFailedException
   */
  public function selectdb($db) {
    if (!mssql_select_db($db, $this->handle)) {
      throw new \rdbms\SQLStatementFailedException(
        'Cannot select database: '.trim(mssql_get_last_message()),
        'use '.$db,
        current(mssql_fetch_row(mssql_query('select @@error', $this->handle)))
      );
    }
    return true;
  }
  
  /**
   * Retrieve identity
   *
   * @return  var identity value
   */
  public function identity($field= null) {
    $i= $this->query('select @@identity as i')->next('i');
    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::IDENTITY, $i));
    return $i;
  }

  /**
   * Retrieve number of affected rows for last query
   *
   * @return  int
   */
  protected function affectedRows() {
    return mssql_rows_affected($this->handle);
  }
  
  /**
   * Execute any statement
   *
   * @param   string sql
   * @param   bool buffered default TRUE
   * @return  rdbms.ResultSet
   * @throws  rdbms.SQLException
   */
  protected function query0($sql, $buffered= true) {
    if (!is_resource($this->handle)) {
      if (!($this->flags & DB_AUTOCONNECT)) throw new \rdbms\SQLStateException('Not connected');
      $c= $this->connect();
      
      // Check for subsequent connection errors
      if (false === $c) throw new \rdbms\SQLStateException('Previously failed to connect');
    }
    
    $result= mssql_query($sql, $this->handle);

    if (false === $result) {
      $message= 'Statement failed: '.trim(mssql_get_last_message()).' @ '.$this->dsn->getHost();
      if (!is_resource($error= mssql_query('select @@error', $this->handle))) {
      
        // The only case selecting @@error should fail is if we receive a
        // disconnect. We could also check on the warnings stack if we can
        // find the following:
        //
        // MsSQL:  Client message:  Read from SQL server failed. (severity 78)
        //
        // but that seems a bit errorprone. 
        throw new \rdbms\SQLConnectionClosedException($message, $sql);
      }

      $code= current(mssql_fetch_row($error));
      switch ($code) {
        case 1205:    // Deadlock
          throw new \rdbms\SQLDeadlockException($message, $sql, $code);

        default:      // Other error
          throw new \rdbms\SQLStatementFailedException($message, $sql, $code);
      }
    } else if (true === $result) {
      return new QuerySucceeded(mssql_rows_affected($this->handle));
    } else {
      return new MsSQLResultSet($result, $this->tz);
    }  
  }
  
  /**
   * Begin a transaction
   *
   * @param   rdbms.Transaction transaction
   * @return  rdbms.Transaction
   */
  public function begin($transaction) {
    $this->query('begin transaction xp_%c', $transaction->name);
    $transaction->db= $this;
    return $transaction;
  }
  
  /**
   * Retrieve transaction state
   *
   * @param   string name
   * @return  var state
   */
  public function transtate($name) { 
    return $this->query('select @@transtate as transtate')->next('transtate');
  }
  
  /**
   * Rollback a transaction
   *
   * @param   string name
   * @return  bool success
   */
  public function rollback($name) { 
    return $this->query('rollback transaction xp_%c', $name);
  }
  
  /**
   * Commit a transaction
   *
   * @param   string name
   * @return  bool success
   */
  public function commit($name) { 
    return $this->query('commit transaction xp_%c', $name);
  }
}
