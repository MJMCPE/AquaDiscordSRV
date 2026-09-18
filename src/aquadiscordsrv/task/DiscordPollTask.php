<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function json_decode;

class DiscordPollTask extends AsyncTask{

	/** @var string */
	private $botToken;
	/** @var string */
	private $channelId;
	/** @var string|null */
	private $afterId;
	/** @var bool */
	private $seedOnly;
	/** @var string */
	private $mode;

	/**
	 * @param string $mode either "chat" (relay into the game) or "console" (execute as a console command)
	 */
	public function __construct(string $botToken, string $channelId, ?string $afterId, bool $seedOnly, string $mode = "chat"){
		$this->botToken = $botToken;
		$this->channelId = $channelId;
		$this->afterId = $afterId;
		$this->seedOnly = $seedOnly;
		$this->mode = $mode;
	}

	public function onRun() : void{
		$url = "https://discord.com/api/v10/channels/" . $this->channelId . "/messages?limit=50";
		if($this->afterId !== null){
			$url .= "&after=" . $this->afterId;
		}

		$messages = [];
		$errorBody = null;

		[$body, $httpCode, $error] = DiscordHttp::request("GET", $url, ["Authorization: Bot " . $this->botToken]);

		if($error === null && $httpCode === 200 && $body !== null){
			$decoded = json_decode($body, true);
			if(is_array($decoded)){
				//Discord returns newest-first; put them in chronological order
				foreach(array_reverse($decoded) as $msg){
					if(!is_array($msg) || !isset($msg["id"], $msg["content"], $msg["author"])){
						continue;
					}

					$messages[] = [
						"id" => (string) $msg["id"],
						"content" => (string) $msg["content"],
						"username" => (string) ($msg["author"]["global_name"] ?? $msg["author"]["username"] ?? "Unknown"),
						"authorId" => (string) ($msg["author"]["id"] ?? ""),
						"bot" => (bool) ($msg["author"]["bot"] ?? false),
						"webhookId" => isset($msg["webhook_id"]) ? (string) $msg["webhook_id"] : null,
					];
				}
			}
		}elseif($error === null && $httpCode !== 200){
			$errorBody = substr((string) $body, 0, 300);
		}

		$this->setResult([
			"messages" => $messages,
			"seedOnly" => $this->seedOnly,
			"httpCode" => $httpCode,
			"errorBody" => $errorBody,
			"error" => $error
		]);
	}

	public function onCompletion(Server $server) : void{
		$plugin = Main::getInstance();
		if($plugin === null || !$plugin->isEnabled()){
			return;
		}

		$data = $this->getResult();

		if($data["error"] !== null){
			$plugin->maybeLogPollWarning("Could not reach Discord while polling " . $this->mode . " channel " . $this->channelId . " (network error): " . $data["error"]);
			return;
		}
		if($data["httpCode"] !== null && $data["httpCode"] !== 200){
			$plugin->maybeLogPollWarning("Discord rejected a poll of channel " . $this->channelId . " (HTTP " . $data["httpCode"] . "): " . $data["errorBody"]
				. ($data["httpCode"] === 403 || $data["httpCode"] === 404 ? " - check the bot can see that channel and the ID is correct" : ""));
			return;
		}

		$messages = $data["messages"] ?? [];
		$seedOnly = $data["seedOnly"] ?? false;

		if(empty($messages)){
			return;
		}

		$lastId = end($messages)["id"];
		//advance the "last seen" cursor regardless, so we never re-process these messages
		if($this->mode === "console"){
			$plugin->setLastConsoleMessageId($lastId);
		}else{
			$plugin->setLastMessageId($lastId);
		}

		if($seedOnly){
			//first poll after startup: just establish the cursor, don't replay old history
			return;
		}

		foreach($messages as $message){
			if($this->mode === "console"){
				$plugin->executeDiscordConsoleCommand($message);
			}else{
				$plugin->relayDiscordMessageToGame($message);
			}
		}
	}
}
