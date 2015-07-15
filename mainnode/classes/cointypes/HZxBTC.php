<?php
require_once ('../libs/bitcoin/jsonRPCClient.php');
class HZxBTC extends jsonRPCClient
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
 * @param array $existing deposits in database
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

		$transactions = $this->listtransactions();
		foreach($transactions as $transaction)
		{
			if($transaction['category'] == 'receive' &&
				isset($transaction['txid']) &&
				!in_array($transaction['txid'],$existing)
			)
			{
				$account = $this->conn->fromdb(
					"SELECT nhz FROM deposit_addresses WHERE address='".$transaction['address']."';"
				);

				$new[] = array('account'=>$account,'id'=>$transaction['txid']);
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
					$store = $this->sendtoaddress(
						$this->storage,
						$amount
					);

					$this->conn->save(
						'multisig_ins',
						array(
							'id'=>$store,
							'coin'=>$this->coin,
							'amount'=>$amount
						)
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
		$fee = 0+$fee;
		$total = $amount + $fee;
		$recipients = array();
		if($amount > 0)
		{
			$recipients[$recipient] = $amount;
		}

		$feesave = $this->getaccountaddress($this->config['wallet_account']);
		if($fee > 0)
		{
			$recipients[$feesave] = $fee;
		}

		$transactions = $this->conn->fromdb(
			"SELECT id, amount FROM multisig_ins WHERE withdrawn IS NULL AND coin='".
			$this->coin."' ORDER BY amount ASC;"
		);
		$useTx = array();
		$totalout = 0;
		$withdrawing = array();
		foreach($transactions as $tx)
		{
			$withdrawing[] = $tx['id'];

			$vouts = $this->decoderawtransaction(
				$this->getrawtransaction($tx['id'])
			);
			foreach($vouts['vout'] as $key=>$vout)
			{
				if($vout['scriptPubKey']['addresses'][0] == $this->storage)
				{
					$tmp = array(
						'txid'=>$vouts['txid'],
						'vout'=>$key,
						'redeemScript'=>$this->getRedeemScript(),
						'scriptPubKey'=>$vout['scriptPubKey']['hex']
					);

					$useTx[] = $tmp;
					$totalout += $vout['value'];
				}
			}

			if($totalout > $total)
			{
				$rest = $totalout - $total;
				if($rest > 0.0001)
				{
					$recipients[$this->storage] = $rest;
				}

				break;
			}
		}

		if($totalout >= $total)
		{
			$ins = count($useTx);
			$outs = count($recipients);
			if($ins > 0 && $outs > 0)
			{
				$kbytes = ($ins*148 + $outs*34 + 10 + $ins)/1000;
				$fee = round($kbytes*$this->config['feeperkb'],8);
				$recipients[$feesave] -= $fee;
				$raw = $this->createrawtransaction($useTx, $recipients);
				if(isset($config['oldmultisig']) && $config['oldmultisig'] == true) {
					$signed = $this->signrawtransaction(
						$raw,
						$useTx
					)['hex'];
				} else {
					$multisigaddress = $this->getaccountaddress('multisignode0');
					$signed = $this->signrawtransaction(
						$raw,
						$useTx,
						array($this->dumpprivkey($multisigaddress))
					)['hex'];
				}

				foreach($withdrawing as $val)
				{
					$this->conn->update(
						'multisig_ins',
						array('withdrawn'=>1),
						array('id'=>$val)
					);
				}

				return $this->conn->update(
					'withdrawals',
					array(
						'sendid'=>$signed,
						'processed'=>0
					),
					array(
						'id'=>$id
					)
				);
			}
		}

		return false;
	}


/**
 * get redeem script of multisig-address
 * 
 * @return string redeemScript
 */
	public function getRedeemScript()
	{
		$data = file('./data/pairing'.$this->coin);
		$script = explode('::|::', $data[3])[1];

		return trim($script);
	}


}