<?php

declare(strict_types=1);

namespace aquadiscordsrv\task;

use aquadiscordsrv\Main;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

/**
 * Reads whatever has been appended to the server log file since the last check.
 *
 * This deliberately does NOT hook into MainLogger/AttachableThreadedLogger: that logger is a
 * threaded object shared across the server's threads, and attaching a plain PHP object to it
 * from a plugin is a good way to introduce a hard-to-debug pthreads crash. Reading the log file
 * from disk inside an AsyncTask is plain, boring file I/O and can't touch the live server state.
 */
class ConsoleTailTask extends AsyncTask{

	/** @var string */
	private $logFile;
	/** @var int */
	private $offset;

	public function __construct(string $logFile, int $offset){
		$this->logFile = $logFile;
		$this->offset = $offset;
	}

	public function onRun() : void{
		$result = ["offset" => $this->offset, "lines" => []];

		if(!is_file($this->logFile)){
			$this->setResult($result);
			return;
		}

		$size = filesize($this->logFile);
		if($size === false){
			$this->setResult($result);
			return;
		}

		//log file was rotated/truncated since we last checked - start over from the top
		if($size < $this->offset){
			$this->offset = 0;
		}

		if($size === $this->offset){
			$this->setResult($result);
			return;
		}

		$fh = fopen($this->logFile, "rb");
		if($fh === false){
			$this->setResult($result);
			return;
		}

		fseek($fh, $this->offset);
		$chunk = stream_get_contents($fh);
		fclose($fh);

		$result["offset"] = $size;

		if($chunk !== false && $chunk !== ""){
			$lines = preg_split('/\r\n|\r|\n/', $chunk);
			foreach($lines as $line){
				$line = TextFormat::clean($line);
				if(trim($line) !== ""){
					$result["lines"][] = $line;
				}
			}
		}

		$this->setResult($result);
	}

	public function onCompletion(Server $server) : void{
		$plugin = Main::getInstance();
		if($plugin === null || !$plugin->isEnabled()){
			return;
		}

		$data = $this->getResult();
		$plugin->setConsoleTailOffset((int) $data["offset"]);

		if(!empty($data["lines"])){
			$plugin->relayConsoleLinesToDiscord($data["lines"]);
		}
	}
}
