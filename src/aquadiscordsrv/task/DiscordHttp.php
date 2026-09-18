<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

/**
 * A minimal, self-contained cURL wrapper for talking to the Discord API from inside an AsyncTask.
 *
 * This deliberately does NOT use pocketmine\utils\Internet::simpleCurl(): that helper sends a
 * User-Agent that spoofs a desktop Firefox browser, and Discord's Cloudflare front-end now
 * rejects "browser-looking" User-Agents on bot API endpoints with a 403 and the misleading body
 * {"message":"internal network error","code":40333}. Discord's own API docs ask bot clients to
 * identify themselves with a plain, honest User-Agent instead - so that's what this sends.
 */
class DiscordHttp{

	/**
	 * @param string[] $headers extra raw header lines, e.g. "Authorization: Bot xyz"
	 * @return array{0:?string,1:?int,2:?string} [response body, HTTP status code, error message]
	 */
	public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 10) : array{
		$ch = curl_init($url);
		if($ch === false){
			return [null, null, "Unable to initialise cURL"];
		}

		$allHeaders = array_merge(["User-Agent: DiscordBot (https://github.com/, 1.0) AquaDiscordSRV"], $headers);

		$opts = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
			CURLOPT_TIMEOUT => $timeoutSeconds,
			CURLOPT_HTTPHEADER => $allHeaders
		];
		if($body !== null){
			$opts[CURLOPT_POSTFIELDS] = $body;
		}

		curl_setopt_array($ch, $opts);

		$result = curl_exec($ch);
		if($result === false || !is_string($result)){
			$error = curl_error($ch);
			curl_close($ch);
			return [null, null, $error !== "" ? $error : "unknown cURL error"];
		}

		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return [$result, $httpCode, null];
	}
}
