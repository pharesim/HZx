<?php
include_once 'http_curl.php';
class NHZ extends Http
{

	var $url = 'http://127.0.0.1:7776/nhz';

	public function __construct($url = false)
	{
		if($url)
		{
			$this->url = $url;
		}
	}


	public function __call($method,$params = array(array()))
	{
		if(!empty($params[0]))
		{
			$options = array_merge(array('requestType'=>$method),$params[0]);
		}
		else
		{
			$options = array('requestType'=>$method);
		}
		
		$request = $this->request(
			$this->url,
			$options
		);

		return json_decode($request);
	}


	function getAddressRS($passphrase)
	{
		$request = $this->request(
			$this->url,
			array(
				'requestType'=>'getAccountId',
				'secretPhrase'=>$passphrase
			)
		);

		$result = json_decode($request);
		return $result->accountRS;
	}


	function readMessage($transaction,$passphrase = null)
	{
		if(isset($transaction->attachment->message))
		{
			$message = $transaction->attachment->message;
		} elseif(isset($transaction->attachment->encryptedMessage))
		{
			$message = $this->decryptFrom(array(
				'account'=>$transaction->senderRS,
				'nonce'=>$transaction->attachment->encryptedMessage->nonce,
				'data'=>$transaction->attachment->encryptedMessage->data,
				'secretPhrase'=>$passphrase
			));

			if(isset($message->decryptedMessage))
			{
				$message = $message->decryptedMessage;
			}
			else
			{
				$message = false;
			}
		} else {
			$message = false;
		}

		return $message;
	}


	// function getAssetTransfers($accountId)
	// {
	// 	$request = $this->request(
	// 		$this->url,
	// 		array(
	// 			'requestType' => 'getAccountTransactionIds',
	// 			'account'     => $accountId,
	// 			'type'        => 2,
	// 			'subtype'     => 1
	// 		)
	// 	);

	// 	$result = json_decode($request);
	// 	return $result;
	// }


	function transferAsset($assetId,$amount,$recipient,$secret)
	{
		$request = $this->request(
			$this->url,
			array(
				'requestType'  => 'transferAsset',
				'secretPhrase' => $secret,
				'recipient'    => $recipient,
				'asset'        => $assetId,
				'quantityQNT'  => $amount,
     				'feeNQT'       => '100000000',
				'deadline'     => 60
			)
		);

		$result = json_decode($request);
		return $result;
	}


	function validateaddress($address)
	{
		$address = $this->request(
			$this->url,
			array(
				'requestType'=>'rsConvert',
				'account'=>$address
			)
		);
		$address = json_decode($address);

		if(isset($address->accountRS))
		{
			return array('isvalid'=>1);
		}

		return array('isvalid'=>0);
	}


	// function sendMessage($recipient,$message,$passphrase)
	// {

	// 	$options['requestType']  = 'sendMessage';
	//     $options['message']      = $this->strToHex($message);
	// 	$options['recipient']    = $recipient;
	// 	$options['feeNQT']       = 100000000;
	// 	$options['deadline']     = 60;
	// 	$options['secretPhrase'] = $passphrase;

	// 	$request = $this->request(
	// 		$this->url,
	// 		$options
	// 	);

	// 	$result = json_decode($request);
	// 	return $result;
	// }


	// function getAddressesFromMessages($recipient,$sender,$coin)
	// {
	// 	$ids = json_decode($this->request(
	// 		$this->url,
	// 		array(
	// 			'requestType' => 'getAccountTransactionIds',
	// 			'account'     => $recipient,
	// 			'timestamp'   => 0,
	// 			'type'        => 1,
	// 			'subtype'     => 0
	// 		)
	// 	));

	// 	$addresses = array();
	// 	foreach($ids->transactionIds as $tx)
	// 	{
	// 		$transaction = $this->getTransaction($tx);
	// 		if($transaction->sender == $sender)
	// 		{
	// 			$message = $this->hexToStr($transaction->attachment->message);
	// 			if(strpos($message,' -and- ') !== false)
	// 			{
	// 				$tmp = explode(' -and- ',$message);
	// 				$nhz = explode('::',$tmp[0]);
	// 				$nhz = $nhz[1];

	// 				$coindata    = explode('::',$tmp[1]);
	// 				$address = $coindata[1];
	// 				if($coindata[0] == $coin)
	// 				{
	// 					if(!isset($addresses[$coin][$nhz]) || !in_array($address,$addresses[$coin][$nhz]))
	// 					{
	// 						$addresses[$coin][$nhz][] = $address;
	// 					}
	// 				}
	// 			}
	// 		}
	// 	}

	// 	return $addresses[$coin];

	// }


	// function strToHex($string)
	// {
	// 	$hex = '';
	//     	for ($i=0; $i<strlen($string); $i++)
	// 	{
	// 		$ord     = ord($string[$i]);
	// 		$hexCode = dechex($ord);
	// 		$hex    .= substr('0'.$hexCode, -2);
	//     	}

	// 	return strToLower($hex);
	// }

	// function hexToStr($hex)
	// {
	// 	$string='';
	// 	for ($i=0; $i < strlen($hex)-1; $i+=2)
	// 	{
	// 		$string .= chr(hexdec($hex[$i].$hex[$i+1]));
	//     	}

	//     	return $string;
	// }
	// 
	// 
}
