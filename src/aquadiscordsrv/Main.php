<?php

declare(strict_types=1);

namespace aquadiscordsrv;

use aquadiscordsrv\task\DiscordBotInfoTask;
use aquadiscordsrv\task\DiscordSendTask;
use aquadiscordsrv\task\PollSchedulerTask;
use aquadiscordsrv\task\SkinUploadTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase{

	/** @var Main|null */
	private static $instance = null;

	/** @var bool whether the bridge is turned on in config.yml (separate from the plugin itself being loaded) */
	private $bridgeEnabled = false;

	private $botToken = "";
	private $chatChannelId = "";
	private $consoleChannelId = "";
	private $webhookUrl = "";
	private $skinRenderUrl = "";
	private $skinRenderApiKey = "";
	private $ignoreBotMessages = true;
	private $consoleCommandExecution = false;
	private $pollIntervalTicks = 100;
	/** @var string[] */
	private $formats = [];

	/** @var string|null */
	private $botUserId = null;

	/** @var string|null */
	private $lastMessageId = null;
	private $seeded = false;

	/** @var string|null */
	private $lastConsoleMessageId = null;
	private $consoleSeeded = false;

	private $consoleTailOffset = 0;

	/** @var int unix timestamp of the last time a poll failure was logged, to avoid spamming the console */
	private $lastPollWarningTime = 0;

	/** @var array<string, string> player name => webhook avatar URL currently in use for them */
	private $playerAvatarUrls = [];

	public static function getInstance() : ?Main{
		return self::$instance;
	}

	public function onLoad(){
		self::$instance = $this;
	}

	public function onEnable(){
		$this->saveDefaultConfig();
		$cfg = $this->getConfig();

		$this->bridgeEnabled = (bool) $cfg->get("Enabled", false);
		$this->botToken = trim((string) $cfg->get("BotToken", ""));
		$this->chatChannelId = trim((string) $cfg->getNested("Channels.Chat", ""));
		$this->consoleChannelId = trim((string) $cfg->getNested("Channels.Console", ""));
		$this->webhookUrl = trim((string) $cfg->get("WebhookUrl", ""));
		$this->skinRenderUrl = trim((string) $cfg->get("SkinRenderUrl", ""));
		$this->skinRenderApiKey = trim((string) $cfg->get("SkinRenderApiKey", ""));
		$this->ignoreBotMessages = (bool) $cfg->get("IgnoreBotMessages", true);
		$this->consoleCommandExecution = (bool) $cfg->get("ConsoleCommandExecution", false);
		$this->pollIntervalTicks = max(20, (int) round(((float) $cfg->get("PollIntervalSeconds", 5)) * 20));
		$this->formats = (array) $cfg->get("Formats", []);

		//reset runtime state in case this is a /discordsrv reload, not a fresh boot
		$this->botUserId = null;
		$this->lastMessageId = null;
		$this->seeded = false;
		$this->lastConsoleMessageId = null;
		$this->consoleSeeded = false;
		$this->consoleTailOffset = is_file($this->getConsoleLogFile()) ? (int) filesize($this->getConsoleLogFile()) : 0;
		$this->lastPollWarningTime = 0;
		$this->playerAvatarUrls = [];

		if(!$this->bridgeEnabled){
			$this->getLogger()->info(TF::YELLOW . "Disabled - set Enabled: true in config.yml to turn on the Discord bridge.");
			return;
		}

		if($this->botToken === "" || $this->chatChannelId === "" || $this->chatChannelId === "000000000000000000"){
			$this->getLogger()->warning("BotToken and/or Channels.Chat are not configured - the bridge will stay off until config.yml is filled in.");
			$this->bridgeEnabled = false;
			return;
		}

		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

		//find our own bot's user id, so replies from our own bot never get relayed back into the game
		$this->getScheduler()->scheduleAsyncTask(new DiscordBotInfoTask($this->botToken));

		//Aquamarine's plugin API doesn't expose a persistent Discord Gateway connection,
		//so incoming messages are picked up by polling the REST API on an interval instead.
		$this->getScheduler()->scheduleRepeatingTask(new PollSchedulerTask($this), $this->pollIntervalTicks);

		$this->sendDiscordStatusMessage(":white_check_mark: Server has started.");

		$this->getLogger()->info(TF::GREEN . "Bridge is up - relaying chat with Discord channel " . $this->chatChannelId);
	}

	public function onDisable(){
		if($this->bridgeEnabled){
			//best-effort: the async pool may not get a chance to finish this before the process exits
			$this->sendDiscordStatusMessage(":octagonal_sign: Server has stopped.");
		}
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args){
		if(strtolower($command->getName()) !== "discordsrv"){
			return false;
		}

		$sub = strtolower($args[0] ?? "status");

		switch($sub){
			case "reload":
				$sender->sendMessage(TF::YELLOW . "Reloading AquaDiscordSRV...");
				$this->getServer()->getPluginManager()->disablePlugin($this);
				$this->getServer()->getPluginManager()->enablePlugin($this);
				$sender->sendMessage(TF::GREEN . "Done.");
				return true;

			case "status":
			default:
				if($this->bridgeEnabled){
					$sender->sendMessage(TF::GREEN . "AquaDiscordSRV is active." . TF::RESET
						. " Chat channel: " . $this->chatChannelId
						. ($this->botUserId !== null ? TF::GRAY . " (bot id " . $this->botUserId . ")" : ""));
				}else{
					$sender->sendMessage(TF::RED . "AquaDiscordSRV is not active - check Enabled/BotToken/Channels.Chat in config.yml.");
				}
				return true;
		}
	}

	// ------------------------------------------------------------------
	// Config accessors
	// ------------------------------------------------------------------

	public function getBotToken() : string{
		return $this->botToken;
	}

	public function getChatChannelId() : string{
		return $this->chatChannelId;
	}

	public function getConsoleChannelId() : string{
		return $this->consoleChannelId;
	}

	public function getWebhookUrl() : string{
		return $this->webhookUrl;
	}

	public function isConsoleCommandExecutionEnabled() : bool{
		return $this->consoleCommandExecution;
	}

	public function getConsoleLogFile() : string{
		return rtrim($this->getServer()->getDataPath(), "/") . "/server.log";
	}

	public function getFormat(string $key, string $default) : string{
		$value = $this->formats[$key] ?? $default;
		return is_string($value) ? $value : $default;
	}

	// ------------------------------------------------------------------
	// Poll cursor state (read/written from the main thread only, via AsyncTask::onCompletion)
	// ------------------------------------------------------------------

	public function getLastMessageId() : ?string{
		return $this->lastMessageId;
	}

	public function setLastMessageId(string $id) : void{
		$this->lastMessageId = $id;
	}

	public function isSeeded() : bool{
		return $this->seeded;
	}

	public function markSeeded() : void{
		$this->seeded = true;
	}

	public function getLastConsoleMessageId() : ?string{
		return $this->lastConsoleMessageId;
	}

	public function setLastConsoleMessageId(string $id) : void{
		$this->lastConsoleMessageId = $id;
	}

	public function isConsoleSeeded() : bool{
		return $this->consoleSeeded;
	}

	public function markConsoleSeeded() : void{
		$this->consoleSeeded = true;
	}

	public function getConsoleTailOffset() : int{
		return $this->consoleTailOffset;
	}

	public function setConsoleTailOffset(int $offset) : void{
		$this->consoleTailOffset = $offset;
	}

	public function setBotUserId(string $id) : void{
		$this->botUserId = $id;
	}

	public function getBotUserId() : ?string{
		return $this->botUserId;
	}

	/**
	 * Logs a poll/connection problem, but at most once a minute, so a persistent outage
	 * (e.g. outbound HTTPS blocked by a host firewall) doesn't flood the console every
	 * PollIntervalSeconds while still making the problem visible instead of silent.
	 */
	public function maybeLogPollWarning(string $message) : void{
		$now = time();
		if($now - $this->lastPollWarningTime >= 60){
			$this->lastPollWarningTime = $now;
			$this->getLogger()->warning($message);
		}
	}

	// ------------------------------------------------------------------
	// Outbound: Minecraft -> Discord
	// ------------------------------------------------------------------

	public function getSkinAvatarUrl(string $playerName) : string{
		//generic fallback used when no skin-render service is configured, or it didn't respond in
		//time. This is a JAVA EDITION skin lookup by username (minotar.net) - Aquamarine is a
		//Bedrock (Minecraft PE) server, so this will often show a same-named stranger's real Java
		//skin or a default Steve, not the player's actual skin. See registerPlayerSkin() for the
		//real-skin path via the optional standalone skin-render service.
		return "https://minotar.net/avatar/" . rawurlencode($playerName) . "/128.png";
	}

	/**
	 * Called on join: immediately sets the generic fallback avatar for this player, then - if a
	 * skin-render service is configured - kicks off an upload of their actual Bedrock skin bytes,
	 * which will silently upgrade the avatar to the real render if/when that succeeds.
	 */
	public function registerPlayerSkin(Player $player) : void{
		if(!$this->bridgeEnabled || $this->webhookUrl === ""){
			return; //avatars are only ever used in webhook mode
		}

		$name = $player->getName();
		$this->playerAvatarUrls[$name] = $this->getSkinAvatarUrl($name);

		if($this->skinRenderUrl === ""){
			return;
		}

		$skinData = $player->getSkinData();
		if(!is_string($skinData) || $skinData === ""){
			return;
		}

		$size = strlen($skinData);
		$width = 64;
		if($size !== $width * 64 * 4 && $size !== $width * 32 * 4){
			return; //unexpected skin size - don't guess, just keep the fallback avatar
		}
		$height = (int) ($size / ($width * 4));

		$this->getScheduler()->scheduleAsyncTask(new SkinUploadTask(
			$this->skinRenderUrl,
			$this->skinRenderApiKey,
			$name,
			$skinData,
			$width,
			$height
		));
	}

	public function setPlayerAvatarUrl(string $playerName, string $url) : void{
		$this->playerAvatarUrls[$playerName] = $url;
	}

	public function clearPlayerAvatarUrl(string $playerName) : void{
		unset($this->playerAvatarUrls[$playerName]);
	}

	public function sendDiscordChatMessage(string $rawMessage, string $playerName) : void{
		if(!$this->bridgeEnabled){
			return;
		}

		$usingWebhook = $this->webhookUrl !== "";

		//when a webhook is configured, Discord already shows the player's name (and avatar) as the
		//sender, so the message content is just the raw message; otherwise it's posted as the bot,
		//so the player's name needs to be baked into the text via the Chat format template
		$content = $usingWebhook
			? $rawMessage
			: strtr($this->getFormat("Chat", "**{player}**: {message}"), ["{player}" => $playerName, "{message}" => $rawMessage]);

		$this->getScheduler()->scheduleAsyncTask(new DiscordSendTask(
			$this->botToken,
			$this->chatChannelId,
			$this->webhookUrl,
			$content,
			$usingWebhook ? $playerName : null,
			$usingWebhook ? ($this->playerAvatarUrls[$playerName] ?? $this->getSkinAvatarUrl($playerName)) : null
		));
	}

	public function sendDiscordStatusMessage(string $content) : void{
		if(!$this->bridgeEnabled){
			return;
		}
		$this->getScheduler()->scheduleAsyncTask(new DiscordSendTask(
			$this->botToken,
			$this->chatChannelId,
			"", //status lines are always sent as the bot, never impersonated through the webhook
			$content
		));
	}

	public function relayConsoleLinesToDiscord(array $lines) : void{
		if(!$this->bridgeEnabled || $this->consoleChannelId === "" || empty($lines)){
			return;
		}

		//Discord messages cap out at 2000 characters; batch lines into chunks that fit
		//comfortably inside a code block, splitting into multiple messages if needed.
		$chunks = [];
		$current = "";
		foreach($lines as $line){
			if(strlen($current) + strlen($line) + 1 > 1900){
				$chunks[] = $current;
				$current = "";
			}
			$current .= $line . "\n";
		}
		if($current !== ""){
			$chunks[] = $current;
		}

		foreach($chunks as $chunk){
			$this->getScheduler()->scheduleAsyncTask(new DiscordSendTask(
				$this->botToken,
				$this->consoleChannelId,
				"",
				"```\n" . $chunk . "```"
			));
		}
	}

	// ------------------------------------------------------------------
	// Inbound: Discord -> Minecraft
	// ------------------------------------------------------------------

	/**
	 * @param array{id:string,content:string,username:string,authorId:string,bot:bool,webhookId:?string} $message
	 */
	public function relayDiscordMessageToGame(array $message) : void{
		if($message["content"] === ""){
			return;
		}
		if($message["authorId"] === $this->botUserId){
			return; //never echo our own messages back
		}
		if($this->ignoreBotMessages && $message["bot"]){
			return;
		}

		$format = $this->getFormat("DiscordToGame", "&9[Discord] &b{player}&f: {message}");
		$line = strtr($format, [
			"{player}" => $message["username"],
			"{message}" => $message["content"]
		]);

		//this fork's TextFormat has no colorize() helper, so translate &-codes to section-sign codes ourselves
		$line = preg_replace('/&([0-9a-fk-or])/i', TF::ESCAPE . '$1', $line);
		$this->getServer()->broadcastMessage($line);
	}

	/**
	 * @param array{id:string,content:string,username:string,authorId:string,bot:bool,webhookId:?string} $message
	 */
	public function executeDiscordConsoleCommand(array $message) : void{
		if(!$this->consoleCommandExecution){
			return;
		}
		if($message["authorId"] === $this->botUserId || $message["bot"]){
			return;
		}
		if(trim($message["content"]) === ""){
			return;
		}

		$this->getLogger()->info(TF::AQUA . "[Discord:" . $message["username"] . "] " . TF::RESET . $message["content"]);
		$this->getServer()->dispatchCommand(new ConsoleCommandSender(), $message["content"]);
	}
}
