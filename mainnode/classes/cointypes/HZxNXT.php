<?php
require_once ('../libs/nhz/nhz.php');
class HZxNXT extends NHZ
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
			"SELECT address FROM deposit_addresses WHERE coin='".$this->coin."';"
		);
		foreach($addresses as $address)
		{
			$depositAddresses[] = $address['address'];
		}

		$new = array();
		foreach($depositAddresses as $depositAddress)
		{
			$transactions = $this->getAccountTransactions(
				array(
					'account'=>$depositAddress,
					'timestamp'=>$this->lastcoin
				)
			);

			if(isset($transactions->transactions))
			{
				foreach($transactions->transactions as $transaction)
				{
					if($transaction->type == 0 && $transaction->subtype == 0 &&
						isset($transaction->transaction) && !in_array($transaction->transaction,$existing) &&
						(!isset($transaction->attachment->message) || $transaction->attachment->message != 'fill')
					)
					{
						$account = $this->conn->fromdb(
							"SELECT nhz FROM deposit_addresses WHERE address='".$transaction->recipientRS."';"
						);

						$new[] = array('account'=>$account,'id'=>$transaction->transaction);
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
		$secretPhrase = $this->conn->fromdb(
			"SELECT passphrase FROM deposit_addresses WHERE nhz='".$transaction['account']."' AND coin='".$this->coin."';"
		)[0]['passphrase'];
		$minconfirmations = $this->hz->getAlias(
			array(
				'aliasName'=>$this->config['minconfirmationsalias']
			)
		)->aliasURI;
		$tx = $this->getTransaction(array('transaction'=>$transaction['txid']));
		if($tx->confirmations >= $minconfirmations)
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

			$amount = $tx->amountNQT/pow(10,8);
			$fee = $amount*($fee/100);
			if($fee < $minfee)
			{
				$fee = $minfee;
			}

			$amount = $amount - $fee;
			$feefee = 1;
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
					$store = $this->sendMoney(
						array(
							'recipient'=>$this->storage,
							'amountNQT'=>bcmul($amount,pow(10,8)),
							'feeNQT'=>pow(10,8),
							'deadline'=>60,
							'secretPhrase'=>$secretPhrase
						)
					);
					$feefee++;
				}
			}

			$feeamount = $fee-$feefee;
			if($amount > 0)
			{
				$feestore = $this->sendMoney(
					array(
						'recipient'=>$this->config['wallet_account'],
						'amountNQT'=>bcmul($feeamount,pow(10,8)),
						'feeNQT'=>pow(10,8),
						'deadline'=>60,
						'secretPhrase'=>$secretPhrase
					)
				);
			}

			$this->conn->update(
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
		$fee = 0+$fee;
		$total = $amount + $fee;
		$recipients = array();
		if($amount > 0)
		{
			$recipients[$recipient] = $amount;
		}

		$feesave = $this->config['wallet_account'];
		if($fee > 0)
		{
			$recipients[$feesave] = $fee-2;
		}

		$transactions = array();
		foreach($recipients as $recipient=>$amount)
		{
			$transactions[] = array(
				'recipient'=>$recipient,
				'amountNQT'=>$amount*pow(10,8),
				'feeNQT'=>pow(10,8),
				'deadline'=>60
			);
		}

		return $this->conn->update(
			'withdrawals',
			array(
				'sendid'=>json_encode($transactions),
				'processed'=>0
			),
			array(
				'id'=>$id
			)
		);

		return true;
	}


}