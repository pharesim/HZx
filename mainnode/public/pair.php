<?php
class PairNodeMain
{

	protected $config  = null;

	protected $conn    = null;

	protected $request = null;

	protected $write   = null;


/**
 * constructor
 *
 * provides config data
 */
	public function __construct()
	{
		$this->config  = $GLOBALS['config'];
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
 * main function
 * 
 * @return mixed output
 */
	public function run()
	{
		if(isset($this->request['coin']))
		{
			$file = '../data/pairing'.$this->request['coin'];
			if(!file_exists($file))
			{
				touch($file);
			}

			$data   = file($file);

			switch($this->config['coins'][$this->request['coin']]['cointype'])
			{
				case 'btc':
					$result = $this->pairBTC($data);
					break;
				case 'nxt':
					$result = $this->pairNXT($data);
					break;
				case 'bts':
					$result = $this->pairBTS($data);
					break;
			}

			unlink($file);
			$handle = fopen($file, "a");
			fwrite($handle, $this->write);
			fclose($handle);
			print_r($result);
			exit("\n");
		}

		exit('pairing failed'."\n");
	}


/**
 * pair with btc node
 * 
 * @param array $data data from pairing file
 *
 * @return OK or done
 */
	public function pairBTC($data)
	{
		if(isset($this->request['key']) && !empty($this->request['key']))
		{
			$string = $this->request['key'].'::|::'.$_SERVER['REMOTE_ADDR'];
			if(!in_array($string, $data))
			{
				$data[] = $string;
			}
		}
		else
		{
			exit('No pubkey submitted');
		}

		$this->write = '';
		$keys  = count($data);
		if($keys == 1)
		{
			$this->write = $data[0];
		}

		elseif($keys == 2)
		{
			include_once ('../../libs/bitcoin/jsonRPCClient.php');
			$coin = new jsonRPCClient(
				$this->config['coins'][$this->request['coin']]['url']
			);

			$address = $coin->getaccountaddress('multisignode0');
			$pubkey  = $coin->validateaddress($address)['pubkey'];

			$data[] = $pubkey.'::|::127.0.0.1';

			$keys = array();
			foreach($data as $value)
			{
				$this->write .= trim($value)."\n";
				$value = explode('::|::',$value);
				$keys[] = $value[0];
			}

			if(isset($this->config['coins'][$this->request['coin']]['oldmultisig']) && $this->config['coins'][$this->request['coin']]['oldmultisig'] == true)
			{
				$multisig = $coin->addmultisigaddress(2,$keys,'multisigaddress');
				$address  = $coin->validateaddress($multisig);
				$this->write .= $multisig.'::|::'.$address['hex']."\n";
			}
			else
			{
				$multisig = $coin->createmultisig(2,$keys);
				$this->write .= $multisig['address'].'::|::'.$multisig['redeemScript']."\n";
			}
		}

		else
		{
			unset($data[4]);
			$this->write = implode('',$data);
			return 'done';
		}

		return 'OK';
	}


/**
 * pair with nxt node
 *
 * @param array $data data from pairing file
 *
 * @return OK or done
 */
	public function pairNXT($data)
	{
		if(!isset($this->request['snippet']) || empty($this->request['snippet']))
		{
			exit('No snippet submitted');
		}

		if(count($data) == 0)
		{
			$this->write  = '1: '.$this->request['snippet']."\n";
			$this->write .= '2: '.$this->generateRandomString(50)."\n";
			$this->debug($this->write);
		}

		elseif(count($data) == 2)
		{
			$this->write  = $data[0];
			$this->write .= '3: '.$this->request['snippet']."\n";
			$this->debug($this->write);
			$this->write = $data[1];
			$this->write .= '3: '.$this->request['snippet']."\n";
			$url = $this->config['coins'][$this->request['coin']]['url'];
			include_once ('../../libs/nhz/nhz.php');
			$coin = new NHZ($url);
			$passphrase = trim(substr($data[0],3)).trim(substr($data[1],3)).$this->request['snippet'];
			$address = $coin->getAddressRS($passphrase);
			$this->write .= $address."\n";
			$this->debug($address);
		}

		else
		{
			$this->write = implode('',$data);
			return 'done';
		}

		return 'OK';
	}


/**
 * pair with bts node
 *
 * @param array $data data from pairing file
 *
 * @return OK or done
 */
	public function pairBTS($data)
	{
		if(!isset($this->request['address']) || empty($this->request['address']))
		{
			exit('No address submitted');
		}

		include_once ('../../libs/bitshares/btsRPCClient.php');
		$coin = new btsRPCClient($this->config['coins'][$this->request['coin']]['url']);

		if(count($data) == 0)
		{
			$this->write = '1: '.$this->request['address']."\n";
			$btsacc = $this->config['coins'][$this->request['coin']]['multisigacc'];
			$coin->checkAccountOrCreate($btsacc);
			$address = $coin->wallet_address_create($btsacc);
			$this->write .= '2: '.$address."\n";
			$this->debug($this->write);
		}

		elseif(count($data) == 2)
		{
			$this->write = $data[0].$data[1];
			$this->write .= '3: '.$this->request['address']."\n";
			$id = $coin->wallet_multisig_get_balance_id(
				'BTS',
				2,
				array(
					trim(substr($data[0],3)),
					trim(substr($data[1],3)),
					$this->request['address']
				)
			);
			$this->write .= $id."\n";
			$this->debug($this->write);
		}

		else
		{
			$this->write = implode('',$data);
			return 'done';
		}

		return 'OK';
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

require_once ('../config/config.php');

$pair = new PairNodeMain();
echo $pair->run()."\n";
