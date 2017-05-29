<?php
define('CALLS_FILE', 'api_throttle.calls');
define('TIME_FILE', 'api_throttle.time');
define('MAX_CALLS', 10);
define('TIME_PERIOD', 60);

require_once("MD.php");

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
	public static function call($url, $command, $params = null, $params_format = "%s=%s", $params_prefix = "?", $param_separator = "&")
	{
		$url .= "$command";

		if ($params != null) 
		{
			$url_params = [];
			if (is_array($params))
			{
				foreach ($params as $key => $value) 
				{
					$url_params[] = sprintf($params_format, $key, $value);
				}				
			}
			else
			{
				$url_params[] = $params;
			}
			$url .= $params_prefix . implode($param_separator, $url_params);	
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

class BitonicAPIResult
{
	public $type = null;
	public $success = false;
	public $btc = null;
	public $eur = null;
	public $price = null;
	public $volume = null;

	public function __construct($response)
	{
		$response = json_decode($response);
		if ($response == null || (property_exists($response, "success") && $response->success != true)) return;

		if (property_exists($response, "method"))
		{
			$this->type = "buy";
			$this->success = $response->success;
			$this->btc = $response->btc;
			$this->eur = $response->eur;
			$this->price = $response->price;
		}
		else if (property_exists($response, "volume"))
		{
			// EXCHANGE RATE
			$this->type = "exchange_rate";
			$this->success = true;
			$this->price = $response->price;
		}
		else
		{
			// SELL
			$this->type = "sell";
			$this->success = $response->success;
			$this->btc = $response->btc;
			$this->eur = $response->eur;
			$this->price = $response->price;
		}
	}
}

class BitonicAPIWrapper
{
	public static function api($command, $params = null)
	{
	    return new BitonicAPIResult(APICaller::call("https://bitonic.nl/api/", $command, $params));
	}

	public static function buy_btc($btc)
	{
		return self::api("buy", [ "btc" => $btc ]);
	} 

	public static function buy_eur($eur)
	{
		return self::api("buy", [ "eur" => $eur ]);
	} 

	public static function sell_btc($btc)
	{
		return self::api("sell", [ "btc" => $btc ]);
	} 

	public static function sell_eur($eur)
	{
		return self::api("sell", [ "eur" => $eur ]);
	} 

	public static function price_average()
	{
		return self::api("price");
	} 

	// 2 API calls
	public static function prices($buy, $sell, $currency = "btc")
	{
		$data = [ 
			"time" => time()
		];

		if ($buy == null && $sell == null)
		{
			$buy = 1;			
			$sell = 1;			
		}

		if ($buy != null)
		{
			switch ($currency) 
			{
				case 'btc': $rb = self::buy_btc($buy); break;
				case 'eur': $rb = self::buy_eur($buy); break;
				default: 	return [ "error" => "Currency $currency is not available" ]; break;
			}

			$data["buy"] = [
				"btc"  => $rb->btc,
				"euro" => $rb->eur,
				"rate" => $rb->price
			];			
		}

		if ($sell != null)
		{
			switch ($currency) 
			{
				case 'btc': $rs = self::sell_btc($sell); break;
				case 'eur': $rs = self::sell_eur($sell); break;
				default: 	return [ "error" => "Currency $currency is not available" ]; break;
			}

			$data["sell"] = [
				"btc"  => $rs->btc,
				"euro" => $rs->eur,
				"rate" => $rs->price
			];
		}

		return $data;
	}

	// 4 API calls
	public static function rates()
	{
		return [
			"time" => time(), 
			"btc" => [
				"low" => 0.01,
				"high" => 1.00
			],
			"buy" => [
				"low" => self::buy_btc(0.01)->price,
				"high" => self::buy_btc(1.00)->price
			],
			"sell" => [
				"low" => self::sell_btc(0.01)->price,
				"high" => self::sell_btc(1.00)->price
			]
		];
	}
}

$data = '';

foreach ($_GET as $key => $value) 
{
	switch ($key) 
	{
		case 'rates':		define('RATES', true); break;
		case 'prices':		define('PRICES', true); break;
		case 'buy':			define('BUY', $value); break;
		case 'sell':		define('SELL', $value); break;
		case 'euro':		define('EURO', true); break;
		
		default:			break;
	} 
}

define("CURRENCY", defined('EURO') ? 'eur' : 'btc');

if (defined('RATES'))												$data = BitonicAPIWrapper::rates();									//?rates
else if (defined('PRICES') && defined('BUY') && defined('SELL'))	$data = BitonicAPIWrapper::prices(BUY, SELL, CURRENCY);				//?prices&buy=n&sell=n
else if (defined('PRICES') && defined('BUY'))						$data = BitonicAPIWrapper::prices(BUY, null, CURRENCY);				//?prices&buy=n
else if (defined('PRICES') && defined('SELL'))						$data = BitonicAPIWrapper::prices(null, SELL, CURRENCY);			//?prices&sell=n
else if (defined('PRICES'))											$data = BitonicAPIWrapper::prices(1,1);								//?prices
else																
{
	?>
	<html>
		<head>
			<title>Bitonic Rates API</title>
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