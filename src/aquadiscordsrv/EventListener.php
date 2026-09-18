<?php

declare(strict_types=1);

namespace aquadiscordsrv;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
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

	public function onDeath(PlayerDeathEvent $event) : void{
		$deathMessage = (string) $event->getDeathMessage();
		if($deathMessage === ""){
			return;
		}

		$format = $this->plugin->getFormat("Death", ":skull: {deathmessage}");
		$placeholders = $this->placeholders(["{deathmessage}" => TextFormat::clean($deathMessage)]);

		$this->plugin->sendDiscordStatusMessage(strtr($format, $placeholders));
	}
}
