<?php
class Mainnode
{

	// asset data from hz blockchain
	protected $asset  = null;

	// coin class
	protected $coin     = null;

	// config settings
	protected $config   = null;

	// database connection
	protected $conn     = null;

	// last check coin timestamp
	protected $lastcoin = null;

	// last check hz timestamp
	protected $lastnhz  = null;

	// hz class
	protected $nhz      = null;

	// current coin timestamp
	protected $nowcoin  = null;

	// current hz timestamp
	protected $nownhz   = null;


/**
 * constructor
 *
 * provides config data, loads nhz lib
 *
 * @uses  NHZ::getBlockchainStatus() check for HZ server
 * @uses  Mainnode::dbconnect()      connect to database
 */
	public function __construct()
	{
		$this->config  = $GLOBALS['config'];

		require_once ('../libs/nhz/nhz.php');
		$this->nhz = new NHZ($this->config['nhz']['url']);
		$time = $this->nhz->getBlockchainStatus();
		if(!isset($time->time))
		{
			exit('NHZ server not reachable');
		}

		$this->dbconnect();
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
		if($this->config['log'])
		{
			file_put_contents($this->config['logfile'], trim($text).PHP_EOL, FILE_APPEND);
		}

		if($this->config['debug'])
		{
			echo trim($text).PHP_EOL;
		}

		return true;
	}


/**
 * connect to database
 * 
 * @return boolean true or die
 */
	public function dbconnect()
	{
		try
		{
			require_once ('../libs/sqlite/sqlite.php');
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

		return true;
	}


/**
 * main function
 *
 * @uses  NHZ::getAsset()               get asset information
 * @uses  NHZ::getBlockchainStatus()    get hz blockchain status
 * @uses  Sqlite::fromdb()              get timestamps of last checks
 * @uses  Sqlite::save()                insert timestamps of last checks
 * @uses  Mainnode::checkConnectivity() check coin connectivity
 * @uses  Mainnode::deposits()          handle deposits
 * @uses  Mainnode::withdrawals()       handle withdrawals
 * @uses  Sqlite::update()              update timestamps of last checks
 * 
 * @return boolean true
 */
	public function run()
	{
		foreach($this->config['coins'] as $coin=>$options)
		{
			$this->asset = null;
			if(isset($options['assetId']))
			{
				$this->asset = $this->nhz->getAsset(array('asset'=>$options['assetId']));
			}

			if(isset($this->asset->asset))
			{
				$blockchainstatus = $this->nhz->getBlockchainStatus();
				$this->nownhz = $blockchainstatus->time;
				$last = $this->conn->fromdb(
					"SELECT timestamp_nhz, timestamp_coin from last_check where coin='".$coin."';"
				);

				if(!isset($last[0]['timestamp_nhz']))
				{
					$this->conn->save(
						'last_check',
						array(
							'timestamp_nhz'=>$this->nownhz,
							'timestamp_coin'=>0,
							'coin'=>$coin
						)
					);
					$last = array(
						array(
							'timestamp_nhz'=>$this->nownhz,
							'timestamp_coin'=>0
						)
					);
				}

				$this->lastnhz = $last[0]['timestamp_nhz']-3600;
				if($this->lastnhz < 0)
				{
					$this->lastnhz = 0;
				}

				$this->lastcoin = $last[0]['timestamp_coin']-3600;
				if($this->lastcoin < 0)
				{
					$this->lastcoin = 0;
				}

				$this->checkConnectivity($coin);

				$this->nowcoin = time();
				switch($this->config['coins'][$coin]['cointype'])
				{
					case 'nxt':
					if(isset($blockchainstatus->time))
					{
						$this->nowcoin = $blockchainstatus->time;
					}
				}

				$this->deposits();
				$this->withdrawals();

				$this->conn->update(
					'last_check',
					array(
						'timestamp_nhz'=>$this->nownhz,
						'timestamp_coin'=>$this->nowcoin
					),
					array(
						'coin'=>$coin
					)
				);
			}
		}

		return true;
	}


/**
 * check connectivity of coin api
 * 
 * @param string $coin coin to check
 *
 * @uses HZxBTC::getinfo()             check BTC type api
 * @uses HZxNXT::getBlockchainStatus() check NXT type api
 * @uses HZxBTS::info()                check BTS type api
 * @uses Mainnode::getStorageAddress() get storage address to set globally
 * 
 * @return boolean true or exit
 */
	public function checkConnectivity($coin)
	{
		$message = 'node not reachable';
		$exit = false;
		switch($this->config['coins'][$coin]['cointype'])
		{
			case 'btc':
				require_once ('./classes/cointypes/HZxBTC.php');
				$this->coin = new HZxBTC($this->config['coins'][$coin]['url']);
				$info = $this->coin->getinfo();
				if(!isset($info['blocks']))
				{
					$exit = true;
				}
				break;
			case 'nxt':
				require_once ('./classes/cointypes/HZxNXT.php');
				$this->coin = new HZxNXT($this->config['coins'][$coin]['url']);
				$info = $this->coin->getBlockchainStatus();
				if(!isset($info->time))
				{
					$exit = true;
				}
				break;
			case 'bts':
				require_once ('./classes/cointypes/HZxBTS.php');
				$this->coin = new HZxBTS($this->config['coins'][$coin]['url']);
				$info = $this->coin->info();
				if(!isset($info['wallet_open']))
				{
					$exit = true;
				}

				elseif($info['wallet_open'] == 0 || $info['wallet_unlocked'] == 0)
				{
					$exit = true;
					$message = 'wallet closed or locked';
				}

				break;
		}

		if($exit)
		{
			exit(strtoupper($coin).' '.$message."\n");
		}

		$this->coin->config   = $this->config['coins'][$coin];
		$this->coin->conn     = $this->conn;
		$this->coin->coin     = $coin;
		$this->coin->lastcoin = $this->lastcoin;
		$this->coin->hz       = $this->nhz;
		$this->coin->asset    = $this->asset;
		$this->coin->storage  = $this->getStorageAddress($coin);

		return true;
	}


/**
 * all deposit functions
 *
 * @uses Mainnode::newDeposits()         check for new deposits
 * @uses Mainnode::processDeposits()     process pending deposits
 * @uses Mainnode::newDepositAddresses() send requested deposit addresses
 * 
 * @return boolean true
 */
	public function deposits()
	{
		$this->newDeposits();
		$this->processDeposits();
		$this->newDepositAddresses();
		return true;
	}


/**
 * check new deposits
 *
 * @uses Sqlite::fromdb()            get pending deposits
 * @uses HZx<cointype>::newDeposit() process deposit
 * @uses Mainnode::saveDeposit()     save deposit to database
 * 
 * @return boolean always true
 */
	public function newDeposits()
	{
		$existing = array();
		$transactions = $this->conn->fromdb(
			"SELECT txid FROM deposits WHERE coin='".$this->coin->coin."';"
		);
		foreach($transactions as $transaction)
		{
			$existing[] = $transaction['txid'];
		}

		$new = $this->coin->newDeposits($existing);
		foreach($new as $tx)
		{
			$this->saveDeposit(
				$tx['account'],
				$tx['id']
			);
		}

		return true;
	}


/**
 * save a deposit
 *
 * @param array  $account account data
 * @param string $txid    id of deposit transaction
 *
 * @uses NHZ::rsConvert()   get account in RS format
 * @uses Sqlite::save()     save to db
 * @uses NHZ::sendMessage() send message to user if cointype not BTS (because they're confirmed instantly)
 * 
 * @return boolean true
 */
	public function saveDeposit($account,$txid)
	{
		if(isset($account[0]['nhz']))
		{
			$valid = 0;
			if(isset($this->nhz->rsConvert(
				array('account'=>$account[0]['nhz'])
			)->accountRS))
			{
				$valid = 1;
			}

			$this->conn->save(
				'deposits',
				array(
					'txid'=>$txid,
					'coin'=>$this->coin->coin,
					'valid'=>$valid,
					'account'=>$account[0]['nhz']
				)
			);

			if($this->coin->coin != 'bts')
			{
				$this->nhz->sendMessage(
					array(
						'recipient'=>$account[0]['nhz'],
						'secretPhrase'=>$this->coin->config['assetPassphrase'],
						'feeNQT'=>100000000,
						'deadline'=>60,
						'message'=>'A '.strtoupper($this->coin->coin).
							' deposit has been detected. You will receive your assets as soon as the transaction has enough confirmations.'
					)
				);
			}
		}

		return true;
	}


/**
 * process deposits
 *
 * @uses Sqlite::fromdb()                get pending deposits
 * @uses HZx<cointype>::processDeposit() process deposits
 * 
 * @return boolean true
 */
	public function processDeposits()
	{
		$transactions = $this->conn->fromdb(
			"SELECT txid, account FROM deposits WHERE coin='".$this->coin->coin."' AND processed IS NULL;"
		);
		foreach($transactions as $transaction)
		{
			$this->coin->processDeposit($transaction);
		}

		return true;
	}


/**
 * get new deposit addresses
 *
 * @uses Sqlite::fromdb()                   get answered requests
 * @uses Mainnode::checkIncomingMessages()  check for new requests
 * @uses HZx<cointype>::getaccountaddress() get a deposit address for bts and btc types
 * @uses Mainnode::generateRandomString()   generate a passphrase for nxt type
 * @uses HZxNXT::getAccountId()             get deposit address for that passphrase
 * @uses NHZ::sendMessage()                 send message with deposit account to user
 * @uses Sqlite::save()                     save deposit address to database
 * 
 * @return boolean true
 */
	public function newDepositAddresses()
	{
		$depositMessages = array();
		$messages = $this->conn->fromdb(
			"SELECT id FROM deposit_addresses WHERE coin='".$this->coin->coin."';"
		);
		foreach($messages as $message)
		{
			$depositMessages[] = $message['id'];
		}

		$newDepositAddresses = $this->checkIncomingMessages();
		foreach($newDepositAddresses as $transaction=>$address)
		{
			if(!in_array($transaction, $depositMessages))
			{
				$passphrase = '';
				$save = '';
				switch($this->coin->config['cointype'])
				{
					case 'btc':
					case 'bts':
						$exists = $this->conn->fromdb(
							"SELECT nhz, coin, address FROM deposit_addresses WHERE nhz='".
							$address."' AND coin='".$this->coin->coin.
							"' AND address != 'duplicate';"
						);
						if(isset($exists[0]['nhz']))
						{
							$new = $exists[0]['address'];
							$save = 'duplicate';
						}

						else
						{
							$new = $this->coin->getaccountaddress(
								$this->coin->config['wallet_account'].$address
							);
						}
						break;
					case 'nxt':
						$exists = $this->conn->fromdb(
							"SELECT nhz, coin, address FROM deposit_addresses WHERE nhz='".
							$address."' AND coin='".$this->coin->coin.
							"' AND address != 'duplicate';"
						);
						if(isset($exists[0]['nhz']))
						{
							$new = $exists[0]['address'];
							$save = 'duplicate';
						}

						else
						{
							$passphrase = $this->generateRandomString(rand(50,100));
							$new = $this->coin->getAccountId(
								array('secretPhrase'=>$passphrase)
							);
							$pubkey = $new->publicKey;
							$new = $new->accountRS;
						}
						break;
				}

				$message = 'Your deposit address for '.$this->asset->name.' is '.$new;
				if(isset($pubkey))
				{
					$message .= ' (public key: '.$pubkey.')';
					unset($pubkey);
				}

				if($save == 'duplicate')
				{
					$new = $save;
				}

				$this->nhz->sendMessage(
					array(
						'recipient'=>$address,
						'secretPhrase'=>$this->coin->config['assetPassphrase'],
						'feeNQT'=>100000000,
						'deadline'=>60,
						'message'=>$message
					)
				);

				$this->conn->save(
					'deposit_addresses',
					array(
						'id'=>$transaction,
						'nhz'=>$address,
						'coin'=>$this->coin->coin,
						'address'=>$new,
						'passphrase'=>$passphrase
					)
				);
			}
		}

		return false;
	}


/**
 * check for incoming messages
 *
 * @uses NHZ::getAccountTransactions() check for messages
 * 
 * @return array addresses to generate new deposit addresses for, with transaction as key
 */
	public function checkIncomingMessages()
	{
		$messages = $this->nhz->getAccountTransactions(
			array(
				'account'=>$this->asset->account,
				'timestamp'=>$this->lastnhz,
				'type'=>1
			)
		)->transactions;
		$newAddresses = array();
		foreach($messages as $message)
		{
			if($message->senderRS != $this->asset->accountRS)
			{
				$newAddresses[$message->transaction] = $message->senderRS;
			}
		}

		return $newAddresses;
	}


/**
 * withdrawal functions
 *
 * @uses Mainnode::newWithdrawals()     check for new withdrawals
 * @uses Mainnode::processWithdrawals() process pending withdrawals
 * @return always true
 */
	public function withdrawals()
	{
		$this->newWithdrawals();
		$this->processWithdrawals();
		return true;
	}


/**
 * get new withdrawals
 *
 * @uses Sqlite::fromdb()                 get pending withdrawals
 * @uses NHZ::getAccountTransactions()    get withdrawals
 * @uses NHZ::readMessage()               read encrypted message
 * @uses HZx<cointype>::validateaddress() check if withdrawal address is valid
 * @uses Sqlite::save()                   save withdrawal to db
 * @uses NHZ::sendMessage()               send a message to withdrawing user
 * 
 * @return boolean true
 */
	public function newWithdrawals()
	{
		$existing = array();
		$withdrawals = $this->conn->fromdb(
			"SELECT id FROM withdrawals WHERE coin='".$this->coin->coin."';"
		);
		foreach($withdrawals as $withdrawal)
		{
			$existing[] = $withdrawal['id'];
		}

		$options = array(
			'timestamp'=>$this->lastnhz,
			'account'=>$this->asset->accountRS,
			'type'=>2,
			'subtype'=>1
		);
		$transactions = $this->nhz->getAccountTransactions($options);
		foreach($transactions->transactions as $key=>$transaction)
		{
			if(isset($transaction->transaction) && !in_array($transaction->transaction,$existing) &&
				$transaction->senderRS != $this->asset->accountRS &&
				$transaction->attachment->asset == $this->asset->asset
			)
			{
				$message = $this->nhz->readMessage(
					$transaction,
					$this->coin->config['assetPassphrase']
				);
				$valid = $this->coin->validateaddress($message)['isvalid'];
				if(!$valid)
				{
					$valid = 0;
				}

				$this->conn->save(
					'withdrawals',
					array(
						'id'=>$transaction->transaction,
						'message'=>$message,
						'coin'=>$this->coin->coin,
						'valid'=>$valid
					)
				);

				$this->nhz->sendMessage(
					array(
						'recipient'=>$transaction->senderRS,
						'secretPhrase'=>$this->coin->config['assetPassphrase'],
						'feeNQT'=>100000000,
						'deadline'=>60,
						'message'=>'A '.strtoupper($this->coin->coin).
							' withdrawal has been detected. You will receive your coins as soon as the transaction has '.
							$this->config['nhz']['confirmations'].' confirmations.'
					)
				);
			}
		}

		return true;
	}


/**
 * process withdrawals
 *
 * @uses Sqlite::fromdb()                   get pending withdrawals
 * @uses NHZ::getAlias()                    get asset fee aliases
 * @uses HZx<cointype>::processWithdrawal() process withdrawal
 * 
 * @return always true
 */
	public function processWithdrawals()
	{
		$withdrawals = $this->conn->fromdb(
			"SELECT id, message FROM withdrawals WHERE coin='".$this->coin->coin.
			"' AND valid=1 AND processed IS NULL;"
		);

		foreach($withdrawals as $withdrawal)
		{
			$transaction = $this->nhz->getTransaction(array('transaction'=>$withdrawal['id']));
			if($transaction->confirmations >= $this->config['nhz']['confirmations'] &&
				$transaction->attachment->asset == $this->asset->asset
			)
			{
				$amount = $transaction->attachment->quantityQNT / pow(10,$this->asset->decimals);
				$recipient = $withdrawal['message'];
				$fee = $this->nhz->getAlias(
					array(
						'aliasName'=>$this->coin->config['feeoutalias']
					)
				)->aliasURI;
				$minfee = $this->nhz->getAlias(
					array(
						'aliasName'=>$this->coin->config['minfeealias']
					)
				)->aliasURI;
				$fee = $amount*($fee/100);
				if($fee < $minfee)
				{
					$fee = $minfee;
				}

				$amount = $amount - $fee;

				$this->coin->processWithdrawal($amount,$fee,$recipient,$withdrawal['id']);
			}
		}

		return true;
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
		$data = file('./data/pairing'.$coin);

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


/**
 * generates a random string containing upper- and lowercase letters and numbers
 * 
 * @param integer $length desired length of string
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

$app = new Mainnode();
echo $app->run()."\n";