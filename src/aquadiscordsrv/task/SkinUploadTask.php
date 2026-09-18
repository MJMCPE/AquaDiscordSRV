<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function base64_encode;
use function json_decode;
use function json_encode;
use function rtrim;

class SkinUploadTask extends AsyncTask{

	/** @var string */
	private $renderBaseUrl;
	/** @var string */
	private $apiKey;
	/** @var string */
	private $playerName;
	/** @var string raw RGBA skin bytes */
	private $skinData;
	/** @var int */
	private $width;
	/** @var int */
	private $height;

	public function __construct(string $renderBaseUrl, string $apiKey, string $playerName, string $skinData, int $width, int $height){
		$this->renderBaseUrl = rtrim($renderBaseUrl, "/");
		$this->apiKey = $apiKey;
		$this->playerName = $playerName;
		$this->skinData = $skinData;
		$this->width = $width;
		$this->height = $height;
	}

	public function onRun() : void{
		$headers = ["Content-Type: application/json"];
		if($this->apiKey !== ""){
			$headers[] = "x-api-key: " . $this->apiKey;
		}

		$payload = json_encode([
			"player" => $this->playerName,
			"width" => $this->width,
			"height" => $this->height,
			"skin" => base64_encode($this->skinData)
		]);

		[$body, $httpCode, $error] = DiscordHttp::request(
			"POST",
			$this->renderBaseUrl . "/render",
			$headers,
			$payload,
			8
		);

		$url = null;
		if($error === null && $httpCode === 200 && $body !== null){
			$decoded = json_decode($body, true);
			if(is_array($decoded) && ($decoded["ok"] ?? false) === true && isset($decoded["url"])){
				$url = $this->renderBaseUrl . $decoded["url"];
			}
		}

		$this->setResult(["url" => $url, "player" => $this->playerName]);
	}

	public function onCompletion(Server $server) : void{
		$plugin = Main::getInstance();
		if($plugin === null || !$plugin->isEnabled()){
			return;
		}

		$data = $this->getResult();
		if(is_string($data["url"] ?? null)){
			//success: replace whatever fallback avatar was set on join with the real skin render
			$plugin->setPlayerAvatarUrl($data["player"], $data["url"]);
		}
		//on any failure, we simply leave the minotar fallback that was set at join time in place -
		//no warning spam here, since a skin-render service being off is an expected, fine state
	}
}
