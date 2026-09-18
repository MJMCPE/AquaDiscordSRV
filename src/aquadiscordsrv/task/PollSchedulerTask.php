<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\PluginTask;

class PollSchedulerTask extends PluginTask{

	public function __construct(Main $owner){
		parent::__construct($owner);
	}

	public function onRun(int $currentTick) : void{
		/** @var Main $plugin */
		$plugin = $this->getOwner();
		if(!$plugin->isEnabled()){
			return;
		}

		$plugin->getScheduler()->scheduleAsyncTask(new DiscordPollTask(
			$plugin->getBotToken(),
			$plugin->getChatChannelId(),
			$plugin->getLastMessageId(),
			!$plugin->isSeeded(),
			"chat"
		));
		$plugin->markSeeded();

		$consoleChannel = $plugin->getConsoleChannelId();
		if($consoleChannel !== ""){
			$plugin->getScheduler()->scheduleAsyncTask(new ConsoleTailTask(
				$plugin->getConsoleLogFile(),
				$plugin->getConsoleTailOffset()
			));

			if($plugin->isConsoleCommandExecutionEnabled()){
				$plugin->getScheduler()->scheduleAsyncTask(new DiscordPollTask(
					$plugin->getBotToken(),
					$consoleChannel,
					$plugin->getLastConsoleMessageId(),
					!$plugin->isConsoleSeeded(),
					"console"
				));
				$plugin->markConsoleSeeded();
			}
		}
	}
}
