<?php
class ProcessWithdrawal
{

	protected $coin    = null;

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
			require_once ('../../libs/sqlite/sqlite.php');
			$this->conn = new Sqlite($this->config['database']);
		}
		catch(Exception $exception)
		{
			die($exception->getMessage());
		}

		$this->conn->busyTimeout(5000);
		$this->conn->exec(
			'PRAGMA cache_size = '.$this->config['dbcachesize'].
			';PRAGMA synchronous=OFF;PRAGMA temp_store=2;'
		);
	}


/**
 * main function
 * 
 * @return mixed output
 */
	public function run()
	{
		$withdrawal = $this->conn->fromdb(
			"SELECT processed FROM withdrawals WHERE id=".$this->conn->escape($this->request['id']).";"
		);
		if($withdrawal[0]['processed'] == 0)
		{
			switch($this->config['coins'][$this->request['coin']]['cointype'])
			{
				case 'btc':
					$this->processBTC();
					break;
				case 'nxt':
					$this->processNXT();
					break;
				case 'bts':
					$this->processBTS();
					break;
			}
		}
	}


/**
 * process btc payout
 * 
 * @return void
 */
	public function processBTC()
	{
		require_once ('../../libs/bitcoin/jsonRPCClient.php');
		$this->coin = new jsonRPCClient($this->config['coins'][$this->request['coin']]['url']);

		if(isset($this->request['sendid']))
		{
			file_put_contents(
				'../data/withdrawlog',
				date('d.m. H:i:s').': '.$_SERVER['REMOTE_ADDR'].' signed and sent '.$this->request['coin'].' '.$this->request['sendid']."\n",
				FILE_APPEND
			);
			$tx = $this->request['sendid'];
			$this->conn->update(
				'withdrawals',
				array(
					'sendid'=>$tx,
					'processed'=>1
				),
				array(
					'id'=>$this->request['id']
				)
			);

			$this->saveMultisigIns($this->request['hex']);
		}

		elseif($this->request['complete'] == 1)
		{
			$tx = $this->coin->sendrawtransaction($this->request['hex']);
			if(!array($tx))
			{
				file_put_contents(
					'../data/withdrawlog',
					date('d.m. H:i:s').' ('.$this->request['coin'].'): '.$_SERVER['REMOTE_ADDR'].' signed completely. Tx sent: '.$tx."\n",
				FILE_APPEND
				);
				$this->conn->update(
					'withdrawals',
					array(
						'sendid'=>$tx,
						'processed'=>1
					),
					array(
						'id'=>$this->request['id']
					)
				);

				$this->saveMultisigIns($this->request['hex']);
			} else {
				file_put_contents(
					'../data/withdrawlog',
					date('d.m. H:i:s').' ('.$this->request['coin'].'): '.$_SERVER['REMOTE_ADDR'].' replied, but tx could not be sent ('.$this->request['hex'].')'."\n",
				FILE_APPEND
				);
			}
		}

		elseif(!empty($this->request['hex']))
		{
			file_put_contents(
				'../data/withdrawlog',
				date('d.m. H:i:s').' ('.$this->request['coin'].'): '.$_SERVER['REMOTE_ADDR'].' answered with '.$this->request['hex']."\n",
				FILE_APPEND
			);
			$this->conn->update(
				'withdrawals',
				array(
					'sendid'=>$this->request['hex'],
					'processed'=>0
				),
				array(
					'id'=>$this->request['id']
				)
			);
		}

		else
		{
			file_put_contents(
				'../data/withdrawlog',
				date('d.m. H:i:s').' ('.$this->request['coin'].'): '.$_SERVER['REMOTE_ADDR'].' sent an empty reply'."\n",
				FILE_APPEND
			);
		}
	}


/**
 * save incoming tx for multisig address
 * 
 * @param string $rawtx hex of tx
 * 
 * @return void
 */
	public function saveMultisigIns($rawtx)
	{
		$transaction = $this->coin->decoderawtransaction($rawtx);
		$storageaddress = $this->getStorageAddress($this->request['coin']);
		foreach($transaction['vout'] as $vout)
		{
			if($vout['scriptPubKey']['addresses'][0] == $storageaddress)
			{
				$this->conn->save(
					'multisig_ins',
					array(
						'id'=>$transaction['txid'],
						'coin'=>$this->request['coin'],
						'amount'=>$vout['value']
					)
				);
			}
		}
	}


/**
 * process nxt payout
 * 
 * @return void
 */
	public function processNXT()
	{
		require_once ('../../libs/nhz/nhz.php');
		$this->coin = new NHZ($this->config['coins'][$this->request['coin']]['url']);
		$data = file('../data/pairing'.$this->request['coin']);
		$snippets = array();
		foreach($data as $line)
		{
			$snip = explode(':',$line);
			if(isset($snip[1]))
			{
				$snippets[$snip[0]] = trim($snip[1]);
			}
		}

		$snippets = $snippets + json_decode($this->request['snippets'],1);
		ksort($snippets);
		if(count($snippets) == 3)
		{
			$passphrase = implode('',$snippets);

			$withdrawals = json_decode(
				$this->conn->fromdb(
					"SELECT sendid FROM withdrawals WHERE id=".
					$this->conn->escape($this->request['id'])." AND coin=".
					$this->conn->escape($this->request['coin']).";"
				)[0]['sendid'],
				1
			);
			$send = array();
			foreach($withdrawals as $key=>$withdrawal)
			{
				$withdrawal['secretPhrase'] = $passphrase;
				$send[$key] = $this->coin->sendMoney($withdrawal);
			}

			$this->conn->update(
				'withdrawals',
				array(
					'sendid'=>$send[0]->transaction,
					'processed'=>1
				),
				array(
					'id'=>$this->request['id']
				)
			);
		}
	}


/**
 * process bts payout
 * 
 * @return void
 */
	public function processBTS()
	{
		require_once ('../../libs/bitshares/btsRPCClient.php');
		$this->coin = new btsRPCClient($this->config['coins'][$this->request['coin']]['url']);

		if(isset($this->request['id']) && isset($this->request['sendid']))
		{
			$result = $this->coin->wallet_builder_add_signature(
				json_decode($this->request['sendid']),
				true
			);

			if(count($result['transaction_record']['trx']['signatures']) == 2)
			{
				$this->conn->update(
					'withdrawals',
					array(
						'sendid'=>$result['transaction_record']['record_id'],
						'processed'=>1
					),
					array(
						'id'=>$this->request['id']
					)
				);
			}
		}
	}


/**
 * get the storage address of a coin
 * 
 * @param string $coin coin
 * 
 * @return string address
 */
	public function getStorageAddress($coin)
	{
		$data = file('../data/pairing'.$coin);

		switch($this->config['coins'][$coin]['cointype'])
		{
			case 'btc':
				$storageaddress = explode('::|::', $data[3])[0];
				break;
			case 'nxt':
				$storageaddress = trim($data[2]);
				break;
			case 'bts':
				$storageaddress = trim($data[3]);
				break;
		}

		return $storageaddress;
	}
}

require_once ('../config/config.php');

$wd = new ProcessWithdrawal();
echo $wd->run()."\n";