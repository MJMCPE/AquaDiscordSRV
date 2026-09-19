<?php

declare(strict_types=1);

namespace aquadiscordsrv;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\TextContainer;
use pocketmine\utils\TextFormat;

class EventListener implements Listener{

	/** @var Main */
	private $plugin;

	public function __construct(Main $plugin){
		$this->plugin = $plugin;
	}

	private function placeholders(array $extra = []) : array{
		$server = $this->plugin->getServer();
		return $extra + [
			"{online}" => (string) count($server->getOnlinePlayers()),
			"{max}" => (string) $server->getMaxPlayers()
		];
	}

	/**
	 * @ignoreCancelled true
	 */
	public function onChat(PlayerChatEvent $event) : void{
		$player = $event->getPlayer();

		//deliberately NOT pre-formatting "**{player}**: {message}" here - when a webhook is
		//configured, Discord already shows the player's name as the sender, so baking it into
		//the message text too would show it twice. Main decides the final format per send mode.
		$this->plugin->sendDiscordChatMessage(TextFormat::clean($event->getMessage()), $player->getName());
	}

	/**
	 * @ignoreCancelled true
	 */
	public function onJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();

		$this->plugin->registerPlayerSkin($player);

		$format = $this->plugin->getFormat("Join", ":green_circle: **{player}** joined the game.");
		$placeholders = $this->placeholders(["{player}" => $player->getName()]);

		$this->plugin->sendDiscordStatusMessage(strtr($format, $placeholders));
	}

	public function onQuit(PlayerQuitEvent $event) : void{
		$player = $event->getPlayer();

		$this->plugin->clearPlayerAvatarUrl($player->getName());

		$format = $this->plugin->getFormat("Leave", ":red_circle: **{player}** left the game.");
		$placeholders = $this->placeholders(["{player}" => $player->getName()]);

		$this->plugin->sendDiscordStatusMessage(strtr($format, $placeholders));
	}

	/**
	 * @priority LOWEST
	 *
	 * Deliberately as early as possible: LocalDeathMessages (also installed on this server)
	 * runs at HIGHEST priority and blanks the death message there to replace the global
	 * broadcast with its own nearby-only one. Running at LOWEST guarantees this handler
	 * always sees the original message regardless of what any other plugin does to it later.
	 */
	public function onDeath(PlayerDeathEvent $event) : void{
		$deathMessage = $this->resolveDeathMessage($event->getDeathMessage());
		if($deathMessage === ""){
			return;
		}

		$format = $this->plugin->getFormat("Death", ":skull: {deathmessage}");
		$placeholders = $this->placeholders(["{deathmessage}" => $deathMessage]);

		$this->plugin->sendDiscordStatusMessage(strtr($format, $placeholders));
	}

	/**
	 * @param TextContainer|string $deathMessage
	 */
	private function resolveDeathMessage($deathMessage) : string{
		if(!($deathMessage instanceof TextContainer)){
			return TextFormat::clean((string) $deathMessage);
		}

		//use the server's own language file to resolve the key + params - the exact same
		//mechanism the (also installed) LocalDeathMessages plugin uses for its local broadcast,
		//so this stays correct for every death cause any plugin can produce, in whatever
		//language the server is actually configured for, without a hand-maintained list here
		$text = $this->plugin->getServer()->getLanguage()->translate($deathMessage);
		return TextFormat::clean((string) $text);
	}
}
