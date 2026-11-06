<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

/**
 * Set-up wizard used on the first run
 * Can be disabled with --no-wizard
 */
namespace pocketmine\wizard;

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Language;
use pocketmine\lang\LanguageNotFoundException;
use pocketmine\lang\Translatable;
use pocketmine\player\GameMode;
use pocketmine\Server;
use pocketmine\ServerProperties;
use pocketmine\utils\Config;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function array_values;
use function fgets;
use function file_exists;
use function file_put_contents;
use function sleep;
use function str_replace;
use function strtolower;
use function trim;
use const PHP_EOL;
use const STDIN;

class SetupWizard{
	/** @deprecated */
	public const DEFAULT_NAME = Server::DEFAULT_SERVER_NAME;
	/** @deprecated */
	public const DEFAULT_PORT = Server::DEFAULT_PORT_IPV4;
	/** @deprecated */
	public const DEFAULT_PLAYERS = Server::DEFAULT_MAX_PLAYERS;

	private Language $lang;

	public function __construct(
		private string $dataPath
	){}

	public function run() : bool{
		$this->message(VersionInfo::NAME . " set-up wizard");

		try{
			$langs = Language::getLanguageList();
		}catch(LanguageNotFoundException $e){
			$this->error("No language files found, please use provided builds or clone the repository recursively.");
			return false;
		}

		$this->message("Please select a language");
		foreach(Utils::stringifyKeys($langs) as $short => $native){
			$this->writeLine(" $native => $short");
		}

		do{
			$lang = strtolower($this->getInput("Language", "eng"));
			if(!isset($langs[$lang])){
				$this->error("Couldn't find the language");
				$lang = null;
			}
		}while($lang === null);

		$this->lang = new Language($lang);

		$this->message($this->lang->translate(KnownTranslationFactory::language_has_been_selected()));

		if(!$this->showLicense()){
			return false;
		}

		//This has to happen here to prevent user avoiding agreeing to license
		$config = new Config(Path::join($this->dataPath, "server.properties"), Config::PROPERTIES);
		$config->set(ServerProperties::LANGUAGE, $lang);
		$config->save();

		if(strtolower($this->getInput($this->lang->translate(KnownTranslationFactory::skip_installer()), "n", "y/N")) === "y"){
			$this->printIpDetails();
			return true;
		}

		$this->writeLine();
		$this->welcome();

		$this->generateBaseConfig($config);
		$this->generateUserFiles($config);
		$this->networkFunctions($config);
		$config->save();

		$this->printIpDetails();

		$this->endWizard();

		return true;
	}

	private function showLicense() : bool{
		$this->message($this->lang->translate(KnownTranslationFactory::welcome_to_pocketmine(VersionInfo::NAME)));
		echo <<<LICENSE

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU Lesser General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

LICENSE;
		$this->writeLine();
		if(strtolower($this->getInput($this->lang->translate(KnownTranslationFactory::accept_license()), "n", "y/N")) !== "y"){
			$this->error($this->lang->translate(KnownTranslationFactory::you_have_to_accept_the_license(VersionInfo::NAME)));
			sleep(5);

			return false;
		}

		return true;
	}

	private function welcome() : void{
		$this->message($this->lang->translate(KnownTranslationFactory::setting_up_server_now()));
		$this->message($this->lang->translate(KnownTranslationFactory::default_values_info()));
		$this->message($this->lang->translate(KnownTranslationFactory::server_properties()));
	}

	/**
	 * Asks the main NetherNet settings and writes them to pocketmine.yml (normally created by the server on first start)
	 */
	private function netherNetConfig(int $serverPort) : void{
		$path = Path::join($this->dataPath, "pocketmine.yml");
		if(file_exists($path)){
			return;
		}

		$this->message("NetherNet is the WebRTC transport Minecraft uses next to RakNet. It needs the nethernet PHP extension.");
		do{
			$transport = strtolower($this->getInput("Transport (raknet, nethernet or both)", "both"));
		}while($transport !== "raknet" && $transport !== "nethernet" && $transport !== "both");

		$replace = ["\n  transport: both\n" => "\n  transport: $transport\n"];
		if($transport !== "raknet"){
			$signalingPort = $this->askPort("NetherNet signaling port (TCP, can be the same as the server port)", $serverPort);
			$this->message("WebRTC traffic uses one UDP port. 0 picks a random one, which DDoS-protected hosts usually drop: set a fixed port and allow it on your host.");
			do{
				$icePort = (int) $this->getInput("NetherNet ICE port (UDP, 0 = random)", "0");
			}while($icePort < 0 || $icePort > 65535 || ($transport === "both" && $icePort === $serverPort));
			$this->message("With a certificate for your domain (e.g. Let's Encrypt) signaling uses https and players aren't asked to trust the server. Leave empty for plain http.");
			$tlsCert = $this->getInput("TLS certificate file (fullchain.pem)", "");
			$tlsKey = $tlsCert !== "" ? $this->getInput("TLS private key file (privkey.pem)", "") : "";

			$replace["\n    url: \"0.0.0.0:19132\"\n"] = "\n    url: \"0.0.0.0:$signalingPort\"\n";
			$replace["\n    ice-port: 0\n"] = "\n    ice-port: $icePort\n";
			if($tlsCert !== "" && $tlsKey !== ""){
				$replace["\n    tls-cert: \"\"\n"] = "\n    tls-cert: \"" . str_replace(["\\", "\""], ["/", ""], $tlsCert) . "\"\n";
				$replace["\n    tls-key: \"\"\n"] = "\n    tls-key: \"" . str_replace(["\\", "\""], ["/", ""], $tlsKey) . "\"\n";
			}
		}

		$content = Filesystem::fileGetContents(Path::join(\pocketmine\RESOURCE_PATH, "pocketmine.yml"));
		$content = str_replace("\r\n", "\n", $content);
		$content = str_replace(array_keys($replace), array_values($replace), $content);
		if(VersionInfo::IS_DEVELOPMENT_BUILD){
			$content = str_replace("preferred-channel: stable", "preferred-channel: beta", $content);
		}
		file_put_contents($path, $content);
	}

	private function askPort(Translatable|string $prompt, int $default) : int{
		while(true){
			$port = (int) $this->getInput($prompt instanceof Translatable ? $this->lang->translate($prompt) : $prompt, (string) $default);
			if($port <= 0 || $port > 65535){
				$this->error($this->lang->translate(KnownTranslationFactory::invalid_port()));
				continue;
			}

			return $port;
		}
	}

	private function generateBaseConfig(Config $config) : void{
		$config->set(ServerProperties::MOTD, ($name = $this->getInput($this->lang->translate(KnownTranslationFactory::name_your_server()), Server::DEFAULT_SERVER_NAME)));

		$this->message($this->lang->translate(KnownTranslationFactory::port_warning()));

		$config->set(ServerProperties::SERVER_PORT_IPV4, $portV4 = $this->askPort(KnownTranslationFactory::server_port_v4(), Server::DEFAULT_PORT_IPV4));
		$config->set(ServerProperties::SERVER_PORT_IPV6, $this->askPort(KnownTranslationFactory::server_port_v6(), Server::DEFAULT_PORT_IPV6));
		$this->netherNetConfig($portV4);

		$this->message($this->lang->translate(KnownTranslationFactory::gamemode_info()));

		do{
			$input = (int) $this->getInput($this->lang->translate(KnownTranslationFactory::default_gamemode()), "0");
			$gamemode = match($input){
				0 => GameMode::SURVIVAL,
				1 => GameMode::CREATIVE,
				default => null
			};
		}while($gamemode === null);
		//TODO: this probably shouldn't use the enum name directly
		$config->set(ServerProperties::GAME_MODE, $gamemode->name);

		$config->set(ServerProperties::MAX_PLAYERS, (int) $this->getInput($this->lang->translate(KnownTranslationFactory::max_players()), (string) Server::DEFAULT_MAX_PLAYERS));

		$config->set(ServerProperties::VIEW_DISTANCE, (int) $this->getInput($this->lang->translate(KnownTranslationFactory::view_distance()), (string) Server::DEFAULT_MAX_VIEW_DISTANCE));
	}

	private function generateUserFiles(Config $config) : void{
		$this->message($this->lang->translate(KnownTranslationFactory::op_info()));

		$op = strtolower($this->getInput($this->lang->translate(KnownTranslationFactory::op_who()), ""));
		if($op === ""){
			$this->error($this->lang->translate(KnownTranslationFactory::op_warning()));
		}else{
			$ops = new Config(Path::join($this->dataPath, "ops.txt"), Config::ENUM);
			$ops->set($op, true);
			$ops->save();
		}

		$this->message($this->lang->translate(KnownTranslationFactory::whitelist_info()));

		if(strtolower($this->getInput($this->lang->translate(KnownTranslationFactory::whitelist_enable()), "n", "y/N")) === "y"){
			$this->error($this->lang->translate(KnownTranslationFactory::whitelist_warning()));
			$config->set(ServerProperties::WHITELIST, true);
		}else{
			$config->set(ServerProperties::WHITELIST, false);
		}
	}

	private function networkFunctions(Config $config) : void{
		$this->error($this->lang->translate(KnownTranslationFactory::query_warning1()));
		$this->error($this->lang->translate(KnownTranslationFactory::query_warning2()));
		if(strtolower($this->getInput($this->lang->translate(KnownTranslationFactory::query_disable()), "n", "y/N")) === "y"){
			$config->set(ServerProperties::ENABLE_QUERY, false);
		}else{
			$config->set(ServerProperties::ENABLE_QUERY, true);
		}
	}

	private function printIpDetails() : void{
		$this->message($this->lang->translate(KnownTranslationFactory::ip_get()));

		$externalIP = Internet::getIP();
		if($externalIP === false){
			$externalIP = "unknown (server offline)";
		}
		try{
			$internalIP = Internet::getInternalIP();
		}catch(InternetException $e){
			$internalIP = "unknown (" . $e->getMessage() . ")";
		}

		$this->error($this->lang->translate(KnownTranslationFactory::ip_warning($externalIP, $internalIP)));
		$this->error($this->lang->translate(KnownTranslationFactory::ip_confirm()));
		$this->readLine();
	}

	private function endWizard() : void{
		$this->message($this->lang->translate(KnownTranslationFactory::you_have_finished()));
		$this->message($this->lang->translate(KnownTranslationFactory::pocketmine_plugins()));
		$this->message($this->lang->translate(KnownTranslationFactory::pocketmine_will_start(VersionInfo::NAME)));

		$this->writeLine();
		$this->writeLine();

		sleep(4);
	}

	private function writeLine(string $line = "") : void{
		echo $line . PHP_EOL;
	}

	private function readLine() : string{
		return trim((string) fgets(STDIN));
	}

	private function message(string $message) : void{
		$this->writeLine("[*] " . $message);
	}

	private function error(string $message) : void{
		$this->writeLine("[!] " . $message);
	}

	private function getInput(string $message, string $default = "", string $options = "") : string{
		$message = "[?] " . $message;

		if($options !== "" || $default !== ""){
			$message .= " (" . ($options === "" ? $default : $options) . ")";
		}
		$message .= ": ";

		echo $message;

		$input = $this->readLine();

		return $input === "" ? $default : $input;
	}
}
