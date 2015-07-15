<?php
class Withdrawals
{

	protected $config  = null;

	protected $conn    = null;

	protected $http    = null;


/**
 * constructor
 *
 * provides config data
 */
	public function __construct()
	{
		$this->config = $GLOBALS['config'];

		require_once ("../../libs/nhz/http_curl.php");
		$this->http = new Http();
		$this->dbconnect();
		$this->request = array_merge($_GET,$_POST);
	}


/**
 * destructor
 *
 * closes database connection
 */
	public function __destruct()
	{
		if($this->conn)
		{
			$this->conn->close();
		}
	}


/**
 * debug messages
 * 
 * @param string $text debug message
 * 
 * @return boolean always true
 */
	public function debug($text)
	{
		if($this->config['debug'])
		{
			echo $text."\n";
		}

		return true;
	}


/**
 * connect to database
 * 
 * @return boolean always true
 */
	public function dbconnect()
	{
		try
		{
			$this->conn = new SQLite3($this->config['database']);
		}
		catch(Exception $exception)
		{
			die($exception->getMessage());
		}

		$this->conn->busyTimeout(5000);
		$this->todb(
			'PRAGMA cache_size = '.$this->config['dbcachesize'].
			';PRAGMA synchronous=OFF;PRAGMA temp_store=2;'
		);
	}


/**
 * select from database and return as array
 * 
 * @param string $query escaped sql query
 * 
 * @return array result
 */
	public function fromdb($query)
	{
		$result = $this->conn->query($query);
		$rows = [];
		while($row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}

		return $rows;
	}


/**
 * save to db
 * 
 * @param array $query query to send
 * 
 * @return boolean executed or not
 */
	public function todb($query)
	{
		$executed = false;
		while(!$executed)
		{
			if($this->conn->exec($query))
			{
				return true;
			}

			sleep(1);
		}
	}


/**
 * escape for sql query
 * 
 * @param string $string string to escape
 * 
 * @return string escaped
 */
	public function sqlEscape($string)
	{
		return $this->conn->escapeString($string);
	}


/**
 * main function
 * 
 * @return mixed output
 */
	public function run()
	{
		$withdrawals = $this->fromdb(
			"SELECT * FROM withdrawals WHERE coin='".
			$this->sqlEscape($this->request['coin'])."' AND processed=0;"
		);

		file_put_contents(
			'../data/withdrawlog',
			date('d.m. H:i:s').': '.$_SERVER['REMOTE_ADDR'].' asked for '.$this->request['coin'].' withdrawals'."\n",
			FILE_APPEND
		);
		if(count($withdrawals) > 0) {
			file_put_contents(
				'../data/withdrawlog',
				date('d.m. H:i:s').': '.$_SERVER['REMOTE_ADDR'].' received '.count($withdrawals).' '.$this->request['coin'].' transaction(s) to sign'."\n",
				FILE_APPEND
			);
		}

		print_r(json_encode($withdrawals));
	}
}

require_once ('../config/config.php');

$withdrawals = new Withdrawals();
echo $withdrawals->run()."\n";