<?php namespace rdbms\sqlsrv;

use rdbms\DBConnection;
use rdbms\Transaction;
use rdbms\StatementFormatter;
use rdbms\QuerySucceeded;

/**
 * Connection to SqlSrv databases using client libraries
 *
 * @see   http://mssql.com/
 * @ext   sqlsrv
 * @test  xp://net.xp_framework.unittest.rdbms.TokenizerTest
 * @test  xp://net.xp_framework.unittest.rdbms.DBTest
 * @test  xp://net.xp_framework.unittest.rdbms.integration.MsSQLIntegrationTest
 */
class SqlSrvConnection extends DBConnection {
  protected $result= false;

  static function __static() {
    if (extension_loaded('sqlsrv')) {
      \rdbms\DriverManager::register('mssql+ms', new \lang\XPClass(__CLASS__));
    }
  }

  /**
   * Constructor
   *
   * @param   rdbms.DSN dsn
   */
  public function __construct($dsn) {
    parent::__construct($dsn);
    $this->formatter= new StatementFormatter($this, new SqlSrvDialect());
  }

  /**
   * Returns all errors as a string
   *
   * @return  string
   */
  protected function errors() {
    $string= ''; 
    foreach (sqlsrv_errors() as $error) {
      $string.= '['.$error[0].':'.$error[1].']: '.$error[2].', ';
    }
    return substr($string, 0, -2);
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
    $spec= $this->dsn->getHost();
    if (-1 != ($port= $this->dsn->getPort(-1))) {
       $spec.= ', '.$port;
    }
    $this->handle= sqlsrv_connect($spec, $a= [
      'Database'     => $this->dsn->getDatabase(),
      'LoginTimeout' => $this->timeout,
      'UID'          => $this->dsn->getUser(),
      'PWD'          => $this->dsn->getPassword(),
      'MultipleActiveResultSets' => false
    ]);

    if (!is_resource($this->handle)) {
      throw new \rdbms\SQLConnectException($this->errors(), $this->dsn);
    }
    
    $this->_obs && $this->notifyObservers(new \rdbms\DBEvent(\rdbms\DBEvent::CONNECTED, $reconnect));
    return true;
  }
  
  /**
   * Disconnect
   *
   * @return  bool success
   */
  public function close() { 
    $r= $this->handle && sqlsrv_close($this->handle);
    $this->handle= null;
    return (bool)$r;
  }
  
  /**
   * Select database
   *
   * @param   string db name of database to select
   * @return  bool success
   * @throws  rdbms.SQLStatementFailedException
   */
  public function selectdb($db) {
    if (!sqlsrv_select_db($db, $this->handle)) {
      throw new \rdbms\SQLStatementFailedException(
        'Cannot select database: '.$this->errors(),
        'use '.$db,
        current(sqlsrv_fetch_row(sqlsrv_query('select @@error', $this->handle)))
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
    return sqlsrv_rows_affected($this->result);
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

    // Cancel pending result sets. TODO: Look into using MARS (Multiple
    // Active Result Sets) feature, but this was causing problems in other
    // places.
    if (false !== $this->result) {
      sqlsrv_free_stmt($this->result);
    }

    $this->result= sqlsrv_query($this->handle, $sql);
    if (false === $this->result) {
      $message= 'Statement failed: '.$this->errors().' @ '.$this->dsn->getHost();
      if (!is_resource($error= sqlsrv_query($this->handle, 'select @@error'))) {
      
        // The only case selecting @@error should fail is if we receive a
        // disconnect. We could also check on the warnings stack if we can
        // find the following:
        //
        // SqlSrv:  Client message:  Read from SQL server failed. (severity 78)
        //
        // but that seems a bit errorprone. 
        throw new \rdbms\SQLConnectionClosedException($message, $sql);
      }

      $code= current(sqlsrv_fetch_array($error, SQLSRV_FETCH_NUMERIC));
      switch ($code) {
        case 1205:    // Deadlock
          throw new \rdbms\SQLDeadlockException($message, $sql, $code);

        default:      // Other error
          throw new \rdbms\SQLStatementFailedException($message, $sql, $code);
      }
    } else if (sqlsrv_num_fields($this->result)) {
      return new SqlSrvResultSet($this->result, $this->tz);
    } else {
      return new QuerySucceeded(sqlsrv_rows_affected($this->result));
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
