<?php
define('CALLS_FILE', 'api_throttle.calls');
define('TIME_FILE', 'api_throttle.time');
define('MAX_CALLS', 1);
define('TIME_PERIOD', 10);

require_once("MD.php");
require_once("BitcoinAddressValidator.php");

class APICallThrottle
{
	private static function count($val = null)
	{
		if (!file_exists(CALLS_FILE) || $val !== null) file_put_contents(CALLS_FILE, $val === null ? 0 : $val, LOCK_EX);
		return (int) file_get_contents(CALLS_FILE);
	}

	private static function start_time($set = false)
	{
		if (!file_exists(TIME_FILE) || $set) file_put_contents(TIME_FILE, time(), LOCK_EX);
		$start_time = file_get_contents(TIME_FILE);
		if ($start_time == 0) self::start_time(true);
		return $start_time;
	}

	public static function increment()
	{
		$count = self::count();
		$count++;
		$run_time = time() - self::start_time();
		if ($count == 1)
		{
			self::start_time(true);
		}
		else if ($count >= MAX_CALLS)
		{
			if ($run_time < TIME_PERIOD)	sleep(TIME_PERIOD - $run_time);
			$count = 0;
		}
		self::count($count);
	}
}

class APICaller
{	
	public static function call($url, $command, $params = null)
	{
		$param_separator = "/";
		$url .= "$command";

		if ($params != null) 
		{
			$url_params = [];
			if (is_array($params))
			{
				foreach ($params as $param) 
				{
					$url_params[] = $param;
				}				
			}
			else
			{
				$url_params[] = $params;
			}
			$url .= $param_separator . implode($param_separator, $url_params);	
		}

	    $curl = curl_init();

	    curl_setopt($curl, CURLOPT_URL, $url);
	    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		APICallThrottle::increment();
	    
	    $result = curl_exec($curl);

	    curl_close($curl);

	    return $result;
	}
}

class BlockchainAPIWrapper
{
	public static function api($command, $params = null)
	{
	    return APICaller::call("https://blockchain.info/q/", $command, $params);
	}

	public static function balance($account)
	{
		return [ 
					"time" => time(), 
					"account" => $account, 
					"balance" => self::api("addressbalance", $account)/100000000 
			];
	}

	public static function received($account)
	{
		return [ 
					"time" => time(), 
					"account" => $account, 
					"received" => self::api("getreceivedbyaddress", $account)/100000000 
			];
	}

	public static function sent($account)
	{
		return [ 
					"time" => time(), 
					"account" => $account, 
					"sent" => self::api("getsentbyaddress", $account)/100000000 
			];
	}

	public static function first_seen($account)
	{
		$time = (int) self::api("addressfirstseen", $account);
		$timestamp = date('Y-m-d H:i:s', $time);
		return [ 
					"time" => time(), 
					"account" => $account, 
					"unix_time" => $time,
					"human_time" => $timestamp
			];
	}

	public static function account($account)
	{
		$time = (int) self::api("addressfirstseen", $account);
		$timestamp = date('Y-m-d H:i:s', $time);
		return [ 
					"time" => time(), 
					"account" => $account, 
					"balance" => self::api("addressbalance", $account)/100000000, 
					"received" => self::api("getreceivedbyaddress", $account)/100000000, 
					"sent" => self::api("getsentbyaddress", $account)/100000000, 
					"first_seen_unix_time" => $time,
					"first_seen_human_time" => $timestamp
			];
	}
}

$data = '';

foreach ($_GET as $key => $value) 
{
	switch ($key) 
	{
		case 'balance':		define('BALANCE', true); break;
		case 'first_seen':	define('FIRST_SEEN', true); break;
		case 'received':	define('RECEIVED', true); break;
		case 'sent':		define('SENT', true); break;
		default:			if (BitcoinAddressValidator::validate($key)) { define('ACCOUNT', $key); } break;							
	} 
}

if (defined('ACCOUNT'))	
{
	if (defined('BALANCE')) 		$data = BlockchainAPIWrapper::balance(ACCOUNT);		//?xx&balance
	else if (defined('FIRST_SEEN')) $data = BlockchainAPIWrapper::first_seen(ACCOUNT);	//?xx&first_seen
	else if (defined('RECEIVED')) 	$data = BlockchainAPIWrapper::received(ACCOUNT);	//?xx&received
	else if (defined('SENT')) 		$data = BlockchainAPIWrapper::sent(ACCOUNT);		//?xx&sent
	else                     		$data = BlockchainAPIWrapper::account(ACCOUNT);		//?xx
}
else																
{
	?>
	<html>
		<head>
			<title>Blockchain API</title>
			<style>
				@import url('https://fonts.googleapis.com/css?family=Droid+Sans|Droid+Sans+Mono|Bungee');

				body {
					font-family: 'Droid Sans', sans-serif;
					font-size: 14px;
					color: #CCCCCC;
					margin:0;
					height:100vh;
					background: #2d537d; /* Old browsers */
					background: hsla(236, 20%, 22%, 1);
					line-height: 180%;
				}

				table {
					width: 100%;
					-webkit-box-shadow: inset 0px 0px 3px 0px rgba(0, 0, 0, 1);
					-moz-box-shadow: inset 0px 0px 3px 0px rgba(0, 0, 0, 1);
					box-shadow: inset 0px 0px 3px 0px rgba(0, 0, 0, 1);
					border-radius: 5px;
					margin-top:10px;
				}

				th {
					font-weight: bold;
					padding-right: 14px;
					padding-left:14px;
					padding-bottom: 0;
					padding-top: 0;
					background-color: hsla(236, 56%, 47%, 0.5);
					text-align: left;
				}

				th,
				td {
					padding-right: 14px;
					padding-left:14px;
					padding-bottom: 10px;
					padding-top: 10px;
					line-height: 200%;
				}


				tr:nth-child(odd) {
					background-color: hsla(251, 25%, 32%, 0.5);
				}

				tr:nth-child(even) {
					background-color: hsla(251, 25%, 29%, 0.5);
				}


				td:first-child,
				td:nth-child(2) {
					width:33%;
				}

				.readme {
					text-align:left;
					max-height:calc(90vh - 10px);
					padding:5px;
					overflow-y:auto;
				}

				code {
					color: #ffcc00;
					background-color: rgba(0,0,0,0.25);
					padding:5px;
					border: 1px solid rgba(128,128,128,0.25);
					border-radius: 5px;
					margin:2px;
					margin-left:0px;
					font-weight: bold;
					-webkit-box-shadow: inset 0px 0px 3px 0px rgba(255, 255, 255, 0.125);
					-moz-box-shadow: 	inset 0px 0px 3px 0px rgba(255, 255, 255, 0.125);
					box-shadow: 		inset 0px 0px 3px 0px rgba(255, 255, 255, 0.125);
				}

				a {
					color: #0f0;
					background-color: rgba(0,0,0,0.125);
					padding:5px;
					border: none;
					border-radius: 5px;
					margin:5px;
					margin-left:0px;
				}
			</style>
		</head>
		<body>
			<div class="readme">
				<?php
					echo MD::render(file_get_contents('README.md'));
				?>
			</div>
		</body>
	</html>
	<?php
	exit;
}

header("Content-Type: application/json; charset=UTF-8");
echo json_encode($data, JSON_PRETTY_PRINT);

?>