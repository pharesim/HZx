<?php
require_once ('../libs/bitshares/btsRPCClient.php');
class HZxBTS extends btsRPCClient
{

	public $asset    = null;

	public $coin     = null;

	public $config   = null;

	public $conn     = null;

	public $hz       = null;

	public $lastcoin = null;

	public $storage  = null;


/**
 * check new deposits
 * 
 * @param string $existing deposits in database
 * 
 * @return boolean always true
 */
	public function newDeposits($existing)
	{
		$depositAddresses = array();
		$addresses = $this->conn->fromdb(
			"SELECT address, nhz FROM deposit_addresses WHERE coin='".$this->coin."';"
		);
		foreach($addresses as $address)
		{
			if($address['nhz'] != 'duplicate')
			{
				$depositAddresses[] = $address;
			}
		}

		$new = array();

		foreach($depositAddresses as $depositAddress)
		{
			$account = $this->config['wallet_account'].strtolower(
				str_replace('-', '', $depositAddress['nhz'])
			);
			$transactions = $this->listtransactions($account);

			foreach($transactions as $transaction)
			{
				if(isset($transaction['ledger_entries'][0]['amount']['amount']) &&
					isset($transaction['ledger_entries'][0]['to_account']) &&
					isset($transaction['trx_id']) && !in_array($transaction['trx_id'],$existing)
				)
				{
					$proceed = false;
					foreach($transaction['ledger_entries'] as $entry)
					{
						if(!in_array(trim($entry['to_account']),array('','unknown','UNKNOWN')))
						{
							$recipient = $this->getaccountaddress(
								$entry['to_account']
							);
							if($recipient == $this->getaccountaddress($account))
							{
								$proceed = true;
								break;
							}
						}
					}

					if($proceed)
					{
						$account = $this->conn->fromdb(
							"SELECT nhz FROM deposit_addresses WHERE address='".$recipient."';"
						);

						$new[] = array('account'=>$account,'id'=>$transaction['trx_id']);
					}
				}
			}
		}

		return $new;
	}


/**
 * process deposit
 *
 * @param array $transaction transaction from database
 * 
 * @return boolean true if saved
 */
	public function processDeposit($transaction)
	{
		$minconfirmations = $this->hz->getAlias(
			array(
				'aliasName'=>$this->config['minconfirmationsalias']
			)
		)->aliasURI;
		$tx = $this->gettransaction($transaction['txid']);
		if($tx['confirmations'] >= $minconfirmations)
		{
			$fee = $this->hz->getAlias(
				array(
					'aliasName'=>$this->config['feeinalias']
				)
			)->aliasURI;
			$minfee = $this->hz->getAlias(
				array(
					'aliasName'=>$this->config['minfeealias']
				)
			)->aliasURI;
			$fee = $tx['amount']*($fee/100);
			if($fee < $minfee)
			{
				$fee = $minfee;
			}

			$amount = $tx['amount'] - $fee;
			if($amount > 0)
			{
				$qnt = round($amount,$this->asset->decimals)*pow(10,$this->asset->decimals);
				$send = $this->hz->transferAsset(
					$this->config['assetId'],
					$qnt,
					$transaction['account'],
					$this->config['assetPassphrase']
				);
				if(isset($send->transaction))
				{
					$data = file('./data/pairing'.$this->coin);
					$keys = array();
					foreach($data as $value)
					{
						$tmp = explode(':',$value);
						if(isset($tmp[1]))
						{
							$keys[] = trim($tmp[1]);
						}
					}

					$multisig = $this->wallet_multisig_deposit(
						$amount,
						'BTS',
						$this->config['wallet_account'].strtolower(
							str_replace('-','',$transaction['account'])
						),
						2,
						$keys
					);

					$this->wallet_transfer(
						$fee-1.5,
						'BTS',
						$this->config['wallet_account'].strtolower(
							str_replace('-','',$transaction['account'])
						),
						$this->config['wallet_account']
					);

					return $this->conn->update(
						'deposits',
						array(
							'sendid'=>$send->transaction,
							'processed'=>1,
							'send_time'=>$send->transactionJSON->timestamp
						),
						array(
							'txid'=>$transaction['txid']
						)
					);
				}
			}
		}

		return false;
	}


/**
 * process withdrawal
 * 
 * @param integer $amount    amount to withdraw
 * @param integer $fee       fee for withdrawal
 * @param string  $recipient recipient for withdrawal
 * @param string  $id        id of withdrawal tx
 * 
 * @return boolean true if saved to db
 */
	public function processWithdrawal($amount,$fee,$recipient,$id)
	{
		$valid = $this->validateaddress($recipient);
		if(isset($valid['address']))
		{
			$fee = $fee-1;
			if($fee > 0)
			{
				$feesto = $this->validateaddress($this->config['wallet_account']);
				$tx = $this->wallet_multisig_withdraw_start(
					$fee,
					strtoupper($this->coin),
					$this->storage,
					$feesto['address']
				);
				$this->conn->save(
					'withdrawals',
					array(
						'sendid'=>json_encode($tx),
						'processed'=>0,
						'valid'=>1,
						'id'=>$id.'fee',
						'coin'=>$this->coin,
						'message'=>$this->config['wallet_account']
					)
				);
			}

			$tx = $this->wallet_multisig_withdraw_start(
				$amount,
				strtoupper($this->coin),
				$this->storage,
				$valid['address']
			);

			return $this->conn->update(
				'withdrawals',
				array(
					'sendid'=>json_encode($tx),
					'processed'=>0
				),
				array(
					'id'=>$id
				)
			);
		}
	}


}