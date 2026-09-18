<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function json_encode;

class DiscordSendTask extends AsyncTask{

	/** @var string */
	private $botToken;
	/** @var string */
	private $channelId;
	/** @var string */
	private $webhookUrl;
	/** @var string */
	private $content;
	/** @var string|null */
	private $username;
	/** @var string|null */
	private $avatarUrl;

	public function __construct(string $botToken, string $channelId, string $webhookUrl, string $content, ?string $username = null, ?string $avatarUrl = null){
		$this->botToken = $botToken;
		$this->channelId = $channelId;
		$this->webhookUrl = $webhookUrl;
		$this->content = $content;
		$this->username = $username;
		$this->avatarUrl = $avatarUrl;
	}

	public function onRun() : void{
		if($this->webhookUrl !== "" && $this->username !== null){
			//Send "as the player" through the webhook, DiscordSRV-style
			$payload = ["content" => $this->content, "username" => $this->username];
			if($this->avatarUrl !== null){
				$payload["avatar_url"] = $this->avatarUrl;
			}

			[$body, $httpCode, $error] = DiscordHttp::request(
				"POST",
				$this->webhookUrl . "?wait=false",
				["Content-Type: application/json"],
				json_encode($payload)
			);
		}else{
			//Send as the bot directly to the channel
			[$body, $httpCode, $error] = DiscordHttp::request(
				"POST",
				"https://discord.com/api/v10/channels/" . $this->channelId . "/messages",
				[
					"Authorization: Bot " . $this->botToken,
					"Content-Type: application/json"
				],
				json_encode(["content" => $this->content])
			);
		}

		$this->setResult([
			"httpCode" => $httpCode,
			"body" => $httpCode !== null && $httpCode >= 300 ? $body : null,
			"error" => $error
		]);
	}

	public function onCompletion(Server $server) : void{
		$plugin = Main::getInstance();
		if($plugin === null){
			return;
		}

		$data = $this->getResult();
		$httpCode = $data["httpCode"] ?? null;
		$error = $data["error"] ?? null;

		if($error !== null){
			$plugin->getLogger()->warning("Could not reach Discord (network error): " . $error);
		}elseif($httpCode !== null && $httpCode >= 300){
			$plugin->getLogger()->warning("Discord rejected a message (HTTP " . $httpCode . "): " . substr((string) ($data["body"] ?? ""), 0, 300));
		}
	}
}
