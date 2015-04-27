<?php
class btsRPCClient {

	private $sock;

	public function __construct($url)
	{
		// get rpc options from url
		$path = explode('//',$url)[1];
		$split = explode('@',$path);
		$userpass = explode(':',$split[0]);
		$user = $userpass[0];
		$pass = $userpass[1];
		$urlport = explode(':',$split[1]);
		$url = $urlport[0];
		$port = rtrim($urlport[1],'/');

		$this->sock = socket_create(AF_INET, SOCK_STREAM, getprotobyname("tcp"));
		socket_connect($this->sock, $url, $port);
		$this->login($user, $pass);

	}

	public function __call($method,$params)
	{
		$json = json_encode(array('method'=>$method,'params'=>$params,'id'=>1));
		socket_write($this->sock, $json);
		$json = json_decode(socket_read($this->sock, 99999, PHP_NORMAL_READ), true);
		if(!isset($json['result'])) { return $json['error']; }
		return $json["result"];
	}

	public function checkAccountOrCreate($account)
	{
		$account = strtolower(str_replace('-', '', $account));
		// check if account exists
		$create   = true;
		$accounts = $this->wallet_list_accounts();
		foreach($accounts as $value)
		{
			if(isset($value['name']) && $value['name'] == $account)
			{
				$create = false;
				break;
			}
		}

		// create account if not exists
		if($create == true)
		{
			$create = $this->wallet_account_create($account);
		}

		return $create;
	}

	public function getaccountaddress($account)
	{
		$account = strtolower(str_replace('-', '', $account));
		$this->checkAccountOrCreate($account);
		$accounts = $this->wallet_get_account($account);
		return $accounts['owner_key'];
	}


	public function gettransaction($txid)
	{
		$tx = $this->get_transaction($txid);
		$tx['confirmations'] = 0;
		if($tx['is_confirmed'])
		{
			$tx['confirmations'] = 1;
		}

		$tx['amount'] = $tx['ledger_entries'][0]['amount']['amount']/pow(10,5);
		return $tx;
	}


	public function validateaddress($account)
	{
		$address = $this->wallet_get_account_public_address($account);
		if(!empty($address))
		{
			return array('isvalid'=>1,'address'=>$address);
		}

		return array('isvalid'=>0);
	}
}
