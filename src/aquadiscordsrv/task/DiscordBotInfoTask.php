<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function json_decode;

class DiscordBotInfoTask extends AsyncTask{

	/** @var string */
	private $botToken;

	public function __construct(string $botToken){
		$this->botToken = $botToken;
	}

	public function onRun() : void{
		[$body, $httpCode, $error] = DiscordHttp::request(
			"GET",
			"https://discord.com/api/v10/users/@me",
			["Authorization: Bot " . $this->botToken]
		);

		$id = null;
		if($httpCode === 200 && $body !== null){
			$data = json_decode($body, true);
			if(is_array($data) && isset($data["id"])){
				$id = (string) $data["id"];
			}
		}

		$this->setResult(["id" => $id, "httpCode" => $httpCode, "error" => $error]);
	}

	public function onCompletion(Server $server) : void{
		$plugin = Main::getInstance();
		if($plugin === null || !$plugin->isEnabled()){
			return;
		}

		$data = $this->getResult();
		$id = $data["id"] ?? null;

		if(is_string($id) && $id !== ""){
			$plugin->setBotUserId($id);
			$plugin->getLogger()->info("Connected to Discord as bot user " . $id);
			return;
		}

		if($data["error"] !== null){
			$plugin->getLogger()->warning("Could not reach Discord to verify BotToken (network error): " . $data["error"]
				. " - if this server is behind a firewall, make sure outbound HTTPS (443) to discord.com is allowed");
		}elseif($data["httpCode"] === 401){
			$plugin->getLogger()->warning("Discord rejected BotToken (HTTP 401 - invalid token). Reset the token in the Developer Portal and update config.yml.");
		}elseif($data["httpCode"] !== null){
			$plugin->getLogger()->warning("Could not verify BotToken against the Discord API (HTTP " . $data["httpCode"] . ")");
		}else{
			$plugin->getLogger()->warning("Could not verify BotToken against the Discord API for an unknown reason");
		}
	}
}
