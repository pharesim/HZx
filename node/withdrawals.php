<?php
class Withdrawals
{

	protected $config = null;

	protected $conn   = null;

	protected $http   = null;

	protected $nhz    = null;


/**
 * constructor
 *
 * provides config data
 */
	public function __construct()
	{
		$this->config = $GLOBALS['config'];

		require_once ("../libs/nhz/nhz.php");
		$this->nhz = new NHZ($this->config['hz']);
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
		$result = $this->nhz->request(
			$this->config['mainnode'].'/withdrawals.php',
			array('coin'=>$this->config['coin']),
			array('type'=>'POST','ssl'=>true)
		);
		$result = json_decode($result);
		$results = count($result);
		if($results > 0)
		{
			$this->debug($results.' '.$this->config['coin'].' withdrawals pending');
			switch($this->config['cointype'])
			{
				case 'btc':
					$this->processBTC($result);
					break;
				case 'nxt':
					$this->processNXT($result);
					break;
				case 'bts':
					$this->processBTS($result);
					break;
			}
		}

		exit();
	}


/**
 * process BTC withdrawals
 * 
 * @param array $results withdrawals
 * 
 * @return boolean always true
 */
	public function processBTC($results)
	{
		foreach($results as $withdrawal)
		{
			if(!empty($withdrawal->sendid))
			{
				require_once ('../libs/bitcoin/jsonRPCClient.php');
				$coin = new jsonRPCClient($this->config['url']);
				$tx = $coin->decoderawtransaction($withdrawal->sendid);
				if(empty($tx))
				{
					exit('Seems like the '.strtoupper($this->config['coin']).' server is down');
				}

				$transaction = $this->nhz->getTransaction(array('transaction'=>$withdrawal->id));
				if($transaction->attachment->asset == $this->config['asset'] && count($tx['vout']) <= 3)
				{
					$sum = 0;
					$recipient = $this->nhz->readMessage($transaction);
					if(!$recipient)
					{
						$recipient = $withdrawal->message;
					}

					$recipients = array();
					foreach($tx['vout'] as $voutkey=>$vout)
					{
						if($vout['scriptPubKey']['addresses'][0] != $this->config['address'])
						{
							$sum += $vout['value'];
							$recipients[$vout['scriptPubKey']['addresses'][0]] = $vout['value'];
						}
					}

					arsort($recipients);
					$i = 0;
					$process = false;
					foreach($recipients as $key=>$value)
					{
						if($i == 0)
						{
							if($key == $recipient)
							{
								$process = true;
								break;
							}
						}

						$i++;
					}

					$decimals = $this->nhz->getAsset(array('asset'=>$this->config['asset']))->decimals;
					$qty = $transaction->attachment->quantityQNT / pow(10,$decimals);
					if($process && $sum <= $qty)
					{
						$in = array();
						foreach($tx['vin'] as $incoming)
						{
							$in[] = array(
								'txid'=>$incoming['txid'],
								'vout'=>$incoming['vout'],
								'redeemScript'=>$this->config['redeemScript'],
								'scriptPubKey'=>$coin->decoderawtransaction(
									$coin->getrawtransaction(
										$incoming['txid']
									)
								)['vout'][$incoming['vout']]['scriptPubKey']['hex']
							);
						}

						$multisigaddress = $coin->getaccountaddress('multisignode1');
						$signed = $coin->signrawtransaction(
							$withdrawal->sendid,
							$in,
							array($coin->dumpprivkey($multisigaddress))
						);
						if($signed['hex'] != $withdrawal->sendid)
						{
							$signed['coin'] = $this->config['coin'];
							$signed['id'] = $withdrawal->id;
							if($signed['complete'] == 1)
							{
								$signed['sendid'] = $coin->sendrawtransaction($signed['hex']);
								$this->debug($this->config['coin'].' withdrawal completed with txid '.$signed['sendid']);
							}

							$result = $this->nhz->request(
								$this->config['mainnode'].'/process.php',
								$signed,
								array('type'=>'POST','ssl'=>true)
							);
						}
					}
				}
			}
		}

		return true;
	}


/**
 * process NXT withdrawals
 * 
 * @param array $results withdrawals
 * 
 * @return boolean always true
 */
	public function processNXT($results)
	{
		foreach($results as $withdrawal)
		{
			if(!empty($withdrawal->sendid))
			{
				require_once ('../libs/nhz/nhz.php');
				$coin = new NHZ($this->config['url']);
				$tx = json_decode($withdrawal->sendid);
				$transaction = $this->nhz->getTransaction(array('transaction'=>$withdrawal->id));
				if($transaction->attachment->asset == $this->config['asset'] && count($tx) == 2)
				{
					$sum = 0;
					foreach($tx as $tmp)
					{
						$sum += $tmp->amountNQT/pow(10,8);
					}

					$recipient = $this->nhz->readMessage($transaction);
					if(!$recipient)
					{
						$recipient = $withdrawal->message;
					}

					$decimals = $this->nhz->getAsset(array('asset'=>$this->config['asset']))->decimals;
					$qty = $transaction->attachment->quantityQNT / pow(10,$decimals);
					if($sum <= $qty && $withdrawal->message == $recipient)
					{
						$signed = array();
						$signed['id'] = $withdrawal->id;
						$signed['coin'] = $this->config['coin'];
						$signed['snippets'] = json_encode($this->config['snippets']);
						$result = $this->nhz->request(
							$this->config['mainnode'].'/process.php',
							$signed,
							array('type'=>'POST','ssl'=>true)
						);
						$this->debug($this->config['coin'].' withdrawal completed');
					}
				}
			}
		}

		return true;
	}


	public function processBTS($results)
	{
		foreach($results as $withdrawal)
		{
			if(!empty($withdrawal->sendid))
			{
				$transaction = $this->nhz->getTransaction(array('transaction'=>$withdrawal->id));
				require_once ('../libs/bitshares/btsRPCClient.php');
				$coin = new btsRPCClient($this->config['url']);
				$data = json_decode($withdrawal->sendid);
				if(isset($transaction->attachment) && $transaction->attachment->asset == $this->config['asset'])
				{
					if(count($data->transaction_record->trx->operations) == 2)
					{
						foreach($data->transaction_record->trx->operations as $op)
						{
							if($op->type == 'deposit_op_type')
							{
								$txrecipient = $coin->blockchain_get_account(
									$op->data->condition->data->owner
								);
								$withdrawrecipient = $this->nhz->readMessage($transaction);
								if(empty($withdrawrecipient) || in_array($withdrawrecipient,array($txrecipient['name'],$op->data->condition->data->owner)))
								{
									$decimals = $this->nhz->getAsset(
										array('asset'=>$this->config['asset'])
									)->decimals;
									$qty = $transaction->attachment->quantityQNT / pow(10,$decimals);
									$amount = $op->data->amount / pow(10,5);
									if($amount < $qty)
									{
										$result = $coin->wallet_builder_add_signature(
											$data
										);

										$signed = array(
											'id'=>$withdrawal->id,
											'coin'=>$this->config['coin'],
											'sendid'=>json_encode($result)
										);
										$result = $this->nhz->request(
											$this->config['mainnode'].'/process.php',
											$signed,
											array('type'=>'POST','ssl'=>true)
										);
										$this->debug($this->config['coin'].' withdrawal completed');
									}
								}
							}
						}
					}
				}

				elseif(substr($withdrawal->id,-3) == 'fee')
				{
					if(count($data->transaction_record->trx->operations) == 2)
					{
						foreach($data->transaction_record->trx->operations as $op)
						{
							if($op->type == 'deposit_op_type')
							{
								$txrecipient = $coin->blockchain_get_account(
									$op->data->condition->data->owner
								);
								$feesave = $coin->blockchain_get_account($this->config['feesave']);
								if($txrecipient['owner_key'] == $feesave['owner_key'])
								{
									$result = $coin->wallet_builder_add_signature(
										$data
									);

									$signed = array(
										'id'=>$withdrawal->id,
										'coin'=>$this->config['coin'],
										'sendid'=>json_encode($result)
									);
									$result = $this->nhz->request(
										$this->config['mainnode'].'/process.php',
										$signed,
										array('type'=>'POST','ssl'=>true)
									);
									$this->debug($this->config['coin'].' fees stored');
								}
							}
						}
					}
				}
			}
		}

		return true;
	}
}

require_once ('./config/config.php');

$withdrawals = new Withdrawals();
echo $withdrawals->run()."\n";