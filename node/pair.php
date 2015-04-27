<?php
class PairNode
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

		require_once ("../libs/nhz/http_curl.php");
		$this->http = new Http();
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
 * main function
 * 
 * @return mixed output
 */
	public function run()
	{
		switch($this->config['cointype'])
		{
			case 'btc':
				$data = $this->pairBTC();
				break;
			case 'nxt':
				$data = $this->pairNXT();
				break;
			case 'bts':
				$data = $this->pairBTS();
				break;
			default:
				$data = array();
				break;
		}

		$result = $this->http->request(
			$this->config['mainnode'].'/pair.php',
			$data,
			array('type'=>'POST','ssl'=>true)
		);

		exit($result);
	}


/**
 * get data for BTC pairing
 * 
 * @return array data
 */
	public function pairBTC()
	{
		include_once ('../libs/bitcoin/jsonRPCClient.php');
		$coin    = new jsonRPCClient($this->config['url']);
		$address = $coin->getaccountaddress('multisignode1');
		$pubkey  = $coin->validateaddress($address)['pubkey'];
		$data    = array(
			'key'=>$pubkey,
			'coin'=>$this->config['coin']
		);

		return $data;
	}


/**
 * get data for NXT pairing
 * 
 * @return array data
 */
	public function pairNXT()
	{
		$snippet = $this->generateRandomString(50);
		$data = array(
			'snippet'=>$snippet,
			'coin'=>'nxt'
		);

		return $data;
	}


/**
 * get data for BTS pairing
 * 
 * @return array data
 */
	public function pairBTS()
	{
		include_once ('../libs/bitshares/btsRPCClient.php');
		$coin = new btsRPCClient($this->config['url']);
		$random = strtolower($this->generateRandomString());
		$account = 'multisignode'.$random;
		$coin->checkAccountOrCreate($account);
		$address = $coin->wallet_address_create($account);
		$data = array(
			'address'=>$address,
			'coin'=>'bts'
		);

		return $data;
	}


/**
 * generate a random string
 * 
 * @param integer $length string length
 * 
 * @return string
 */
	public function generateRandomString($length = 10)
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}

		return $randomString;
	}


}

require_once ('./config/config.php');

$pair = new PairNode();
echo $pair->run()."\n";