<?php
class Http
{

	function request($url, $data, $options = array('type'=>'POST','ssl'=>false))
	{
		$ch          = curl_init($url);
		$data_string = '';

		foreach($data as $key=>$value) { $data_string .= $key.'='.$value.'&'; }
		$data_string = substr($data_string,0,-1);

		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux i686; rv:20.0) Gecko/20121230 Firefox/20.0');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		if(isset($options['ssl']) && $options['ssl'])
		{
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			if($options['ssl'] == 'v3')
			{
				curl_setopt($ch, CURLOPT_SSLVERSION, 3);
			}
		}

		if(isset($options['type']) && $options['type'] == 'POST')
		{
			curl_setopt($ch, CURLOPT_POST, count($data));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		}

		$result = curl_exec($ch);
		$error  = curl_error($ch);

		curl_close($ch);

		if(!empty($error)) return $error;
		return $result;
	}

}
