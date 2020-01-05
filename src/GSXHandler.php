<?php
namespace UPCC;
require_once("PDOHandler.php");

class GSXHandler {
	private const INI_PATH = "../config/config.ini";
	
	private $CERT_PATH;
	private $CERT_PASS;
	
	private $BASE_URL;
	private $SOLD_TO;
	private $ACCEPT_LANGUAGE;
	
	private $gsxUserEmail;
	private $gsxShipTo;
	private $activationToken;
	private $isActivationTokenConsumed;
	private $authToken;
	private $authTokenCreatedTs;
	private $authTokenLastUsedTs;
	private $pdoHandler;
	
	public function __construct($gsxUserEmail, $gsxShipTo) {
		$this->gsxUserEmail = $gsxUserEmail;
		$this->gsxShipTo = $gsxShipTo;
		
		$config = parse_ini_file(self::INI_PATH);
		$this->pdoHandler = new PDOHandler($config["HOST"], $config["DB"], $config["USER"], $config["PASS"], $config["PORT"]);
		$this->CERT_PATH = $config["CERT_PATH"];
		$this->CERT_PASS = $config["CERT_PASS"];
		$this->BASE_URL = $config["BASE_URL"];
		$this->SOLD_TO = $config["SOLD_TO"];
		$this->ACCEPT_LANGUAGE = $config["ACCEPT_LANGUAGE"];
		
		$this->testConfig();
		$this->loadFromDB();
		$this->curlSend(1,2,3,4);
	}
	
	private function testConfig() {
		if (!function_exists("curl_version"))
			throw new \Exception("cURL is not enabled in your php.ini, it is required.");
		if (!isset($this->CERT_PATH) or !file_exists($this->CERT_PATH))
			throw new \Exception("Invalid certificate path set in config.ini!");
		if (!isset($this->CERT_PASS) or strlen($this->CERT_PASS) === 0)
			throw new \Exception("No certificate password set in config.ini!");
		if (!isset($this->BASE_URL) or !preg_match("/https:\/\/partner-connect(?:-uat)?\.apple\.com\/gsx\/api/", $this->BASE_URL))
			throw new \Exception("Invalid Base URL set in config.ini!");
		if (!isset($this->SOLD_TO) or strlen($this->SOLD_TO) !== 10)
			throw new \Exception("Invalid GSX Sold-To account number specified in config.ini!");
		if (!isset($this->gsxShipTo) or strlen($this->gsxShipTo)  !== 10)
			throw new \Exception("Invalid GSX Ship-To number specified in config.ini!");
		if (!isset($this->gsxUserEmail) or strlen($this->gsxUserEmail) === 0)
			throw new \Exception("Invalid GSX User Email provided.");
		if (!isset($this->ACCEPT_LANGUAGE) or strlen($this->ACCEPT_LANGUAGE) === 0 or !preg_match("/[a-z]{2}_[A-Z]{2}/", $this->ACCEPT_LANGUAGE))
			throw new \Exception("Invalid Accept-Language header specified in config.ini! (Default: en_US)");
	}
	
	private function loadFromDB() {
		$tokenDetails = $this->pdoHandler->fetchTokenDetails($this->gsxUserEmail);
		if ($tokenDetails) {
			$this->activationToken = $tokenDetails["activationToken"];
			$this->isActivationTokenConsumed = (bool) $tokenDetails["isActivationTokenConsumed"];
			$this->authToken = $tokenDetails["authToken"];
			$this->authTokenCreatedTs = $tokenDetails["authTokenCreatedTs"];
			$this->authTokenLastUsedTs = $tokenDetails["authTokenLastUsedTs"];
		}
		else {
			$this->activationToken = null;
			$this->isActivationTokenConsumed = false;
			$this->authToken = null;
			$this->authTokenCreatedTs = null;
			$this->authTokenLastUsedTs = null;
		}
	}
	
	public function setActivationToken($activationToken) {
		if (self::validateGuid($activationToken)) {
			$this->activationToken = $activationToken;
			$this->pdoHandler->storeActivationToken($this->gsxUserEmail, $activationToken);
			return true;
		}
		else
			throw new \Exception("Tried to store an invalidly-formatted Activation Token!");
	}
	
	private function setAuthToken($authToken) {
		if (self::validateGuid($authToken)) {
			$this->authToken = $authToken;
			$this->pdoHandler->storeAuthToken($this->gsxUserEmail, $authToken);
		}
		else
			throw new \Exception("Tried to store an invalidly-formatted Auth Token!");
	}
	/*
		Note that Apple's "GUIDs" go all the way A-Z instead of A-F
	*/
	private static function validateGuid($guid) {
		return (bool) preg_match("/[a-zA-Z0-9]{8}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{12}/", $guid);
	}

	private function curlSend($method, $endpoint, $body = null, $additionalHeaders = null) {
		//start by setting headers array
		$responseHeaders = array();
		$headers = array(
			"X-Apple-SoldTo: " . $this->SOLD_TO,
			"X-Apple-ShipTo: " . $this->gsxShipTo,
			"X-Operator-User-ID: " . $this->gsxUserEmail,
			"Content-Type: application/json",
			"Accept: application/json",
			"Accept-Language: " . $this->ACCEPT_LANGUAGE
		);
		if (is_array($additionalHeaders) and count($additionalHeaders))
			$headers = array_merge($headers, $additionalHeaders);
		if (is_array($body) and count($body))
			$headers[] = "Content-Length: " . strlen(json_encode($body));
		if ($this->authToken)
			$headers[] = "X-Apple-Auth-Token: " . $this->authToken;
		
		//done setting headers array, begin preparing curl
		if (strpos($endpoint, "/") !== 0)
			$endpoint = "/" . $endpoint;
		$default_charset = ini_get("default_charset"); #store current charset, because...
		ini_set('default_charset', NULL); #cURL tries to add boundaries which GSX isn't expecting
		$ch = curl_init($this->BASE_URL . $endpoint);
		curl_setopt_array($ch, array(
			CURLINFO_HEADER_OUT => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSLCERT => $this->CERT_PATH,
			CURLOPT_SSLCERTPASSWD => $this->CERT_PASS
		));
		if (is_array($body) and count($body))
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		if ($method == "POST")
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
			$length = strlen($header);
			$header = explode(":", $header, 2);
			if (count($header) < 2)
				return $length;
			$header_variable = strtolower(trim($header[0]));
			$header_value = trim($header[1]);
			$responseHeaders[$header_variable] = $header_value;
			return $length;
		});
		
		//done building curl object, send it
		$response = curl_exec($ch);
		ini_set("default_charset", $default_charset); #return this back to what it was
		$this->logCurlRequest($ch, $headers, $responseHeaders, $response);
	}
	
	private function logCurlRequest($ch, $headers, $responseHeaders, $response) {
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?? null;
		$curlErrorNo = curl_errno($ch);
		$curlError = curl_error($ch);
		//error_log("$httpCode\n$curlErrorNo\n$curlError");
		//error_log(var_export($headers, true));
		//error_log(var_export($responseHeaders, true));
		//error_log(var_export($response, true));
	}
	
	public function testAuthentication() {
		$this->curlSend("GET", "/authenticate/check");
	}
	
	public function fetchAuthToken() {
		
	}
}