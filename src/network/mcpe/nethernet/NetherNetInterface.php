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

namespace pocketmine\network\mcpe\nethernet;

use Laith98Dev\nethernet\NetherNetListener;
use Laith98Dev\nethernet\NetherNetServer;
use Laith98Dev\nethernet\NetherNetSession;
use Laith98Dev\nethernet\ServerInformation;
use NetherNet\NetherNetException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\NetworkInterface;
use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\BanList;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\Utils;
use function hash;
use function hrtime;
use function implode;
use function is_int;
use function is_string;
use function strval;

class NetherNetInterface implements NetworkInterface, NetherNetListener{
	/** how often bans and the whitelist are pushed to NetherNet (only when changed) */
	private const REJECT_LIST_SYNC_INTERVAL_NS = 5_000_000_000;
	/** after a command, the sync runs on the next tick, but command-triggered syncs at most this often */
	private const REJECT_LIST_COMMAND_SYNC_MIN_NS = 250_000_000;

	private NetherNetServer $nethernet;

	/** @var NetworkSession[] */
	private array $sessions = [];
	/** @var true[] session ID => login identity already checked */
	private array $loginChecked = [];

	private int $rejectListSyncedAt = 0;
	private bool $rejectListSyncRequested = false;
	private ?string $bannedNamesHash = null;
	private ?string $bannedIpsHash = null;
	private ?string $whitelistHash = null;
	private ?bool $whitelistEnabled = null;

	public function __construct(
		private Server $server,
		string $url,
		string $operatorKeyPath,
		string $operatorDomain,
		bool $operatorCpkSpki,
		bool $allowUnauthenticated,
		?string $tlsCertPath,
		?string $tlsKeyPath,
		int $icePort,
		int $mtu,
		string $advertiseAddresses,
		string $trustedProxies,
		string $realIpHeader,
		private bool $rejectBanned,
		private bool $rejectNotWhitelisted,
		private bool $rejectWhenFull,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){
		$this->nethernet = new NetherNetServer(
			$url,
			$this->buildInformation(),
			$this,
			$operatorKeyPath,
			$operatorDomain,
			$operatorCpkSpki,
			$allowUnauthenticated,
			$tlsCertPath,
			$tlsKeyPath,
			$icePort,
			[
				"mtu" => $mtu,
				"advertise_addresses" => $advertiseAddresses,
				"trusted_proxies" => $trustedProxies,
				"real_ip_header" => $realIpHeader,
			]
		);
	}

	private function buildInformation() : ServerInformation{
		$info = $this->server->getQueryInformation();
		return new ServerInformation(
			$this->server->getMotd(),
			ProtocolInfo::CURRENT_PROTOCOL,
			ProtocolInfo::MINECRAFT_VERSION_NETWORK,
			$info->getWorld(),
			$info->getPlayerCount(),
			$info->getMaxPlayerCount(),
			$this->typeConverter->coreGameModeToProtocol($this->server->getGamemode())
		);
	}

	public function getUrl() : string{
		return $this->nethernet->getUrl();
	}

	public function isHttps() : bool{
		return $this->nethernet->isHttps();
	}

	public function start() : void{
		try{
			$this->nethernet->start();
			$this->nethernet->setRejectPolicy($this->rejectBanned, $this->rejectNotWhitelisted, $this->rejectWhenFull);
			if($this->hasRejectPolicy()){
				$this->syncRejectLists();
			}
		}catch(NetherNetException $e){
			throw new NetworkInterfaceStartException($e->getMessage(), 0, $e);
		}
	}

	public function setName(string $name) : void{
		$this->nethernet->getInformation()->setName($name);
	}

	public function tick() : void{
		$current = $this->nethernet->getInformation();
		$info = $this->server->getQueryInformation();
		$current->setPlayers($info->getPlayerCount());
		$current->setMaxPlayers($info->getMaxPlayerCount());
		$current->setLevel($info->getWorld());
		if($this->hasRejectPolicy()){
			$this->nethernet->setCapacity($info->getPlayerCount(), $info->getMaxPlayerCount());
			$sinceSync = hrtime(true) - $this->rejectListSyncedAt;
			if($sinceSync >= self::REJECT_LIST_SYNC_INTERVAL_NS || ($this->rejectListSyncRequested && $sinceSync >= self::REJECT_LIST_COMMAND_SYNC_MIN_NS)){
				$this->syncRejectLists();
			}
		}

		Timings::$connection->startTiming();
		try{
			$this->nethernet->tick();
		}finally{
			Timings::$connection->stopTiming();
		}
	}

	private function hasRejectPolicy() : bool{
		return $this->rejectBanned || $this->rejectNotWhitelisted || $this->rejectWhenFull;
	}

	/**
	 * @internal called by Server::dispatchCommand after every command.
	 */
	public function onCommandDispatched() : void{
		if($this->hasRejectPolicy()){
			$this->rejectListSyncRequested = true;
		}
	}

	private function syncRejectLists() : void{
		$this->rejectListSyncedAt = hrtime(true);
		$this->rejectListSyncRequested = false;
		$bannedNames = $this->getActiveBanNames($this->server->getNameBans());
		$hash = hash("xxh3", implode("\n", $bannedNames));
		if($hash !== $this->bannedNamesHash){
			$this->nethernet->setBannedNames($bannedNames);
			$this->bannedNamesHash = $hash;
		}

		$bannedIps = $this->getActiveBanNames($this->server->getIPBans());
		$hash = hash("xxh3", implode("\n", $bannedIps));
		if($hash !== $this->bannedIpsHash){
			$this->nethernet->setBannedIps($bannedIps);
			$this->bannedIpsHash = $hash;
		}

		$whitelist = [];
		foreach([$this->server->getWhitelisted(), $this->server->getOps()] as $list){
			foreach($list->getAll(true) as $name){
				if(is_string($name) || is_int($name)){
					$whitelist[] = strval($name);
				}
			}
		}
		$hash = hash("xxh3", implode("\n", $whitelist));
		if($hash !== $this->whitelistHash){
			$this->nethernet->setWhitelist($whitelist);
			$this->whitelistHash = $hash;
		}

		$whitelistEnabled = $this->server->hasWhitelist();
		if($whitelistEnabled !== $this->whitelistEnabled){
			$this->nethernet->setWhitelistEnabled($whitelistEnabled);
			$this->whitelistEnabled = $whitelistEnabled;
		}
	}

	/**
	 * @return string[]
	 */
	private function getActiveBanNames(BanList $banList) : array{
		$names = [];
		foreach($banList->getEntries() as $entry){
			if(!$entry->hasExpired()){
				$names[] = $entry->getName();
			}
		}
		return $names;
	}

	public function shutdown() : void{
		$this->nethernet->close();
	}

	public function onConnect(NetherNetSession $session) : void{
		$networkSession = new NetworkSession(
			$this->server,
			$this->server->getNetwork()->getSessionManager(),
			PacketPool::getInstance(),
			new NetherNetPacketSender($session, $this),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			ZlibCompressor::getInstance(),
			$this->typeConverter,
			($session->getAddress() !== "" ? $session->getAddress() : $session->getNetworkId()),
			$session->getPort()
		);
		$networkSession->setTransportEncrypted(true);
		$networkSession->getLogger()->info("Connected over NetherNet (network ID " . $session->getNetworkId() . ($this->nethernet->isHttps() ? ", https signaling)" : ", http signaling)"));
		$this->sessions[$session->getId()] = $networkSession;
	}

	public function onPacket(NetherNetSession $nethernetSession, string $payload, int $channel) : void{
		$session = $this->sessions[$nethernetSession->getId()] ?? null;
		if($session === null){
			return;
		}
		$name = $session->getDisplayName();
		try{
			$session->handleEncoded($payload);
			$this->checkLoginIdentity($nethernetSession, $session);
		}catch(PacketHandlingException $e){
			$session->disconnectWithError(reason: "Bad packet: " . $e->getMessage());
			$session->getLogger()->debug(implode("\n", Utils::printableExceptionInfo($e)));
		}catch(\Throwable $e){
			$this->server->getLogger()->emergency("Crash occurred while handling a packet from session: $name");
			throw $e;
		}
	}

	/**
	 * Kicks a login whose XUID differs from the one verified at signaling (stops replayed logins)
	 */
	private function checkLoginIdentity(NetherNetSession $nethernetSession, NetworkSession $session) : void{
		$id = $nethernetSession->getId();
		if(isset($this->loginChecked[$id]) || !$session->isConnected()){
			return;
		}
		$info = $session->getPlayerInfo();
		if($info === null){
			return;
		}
		$this->loginChecked[$id] = true;
		$verifiedXuid = $nethernetSession->getXuid();
		if($verifiedXuid === ""){
			return; //unauthenticated mode
		}
		$loginXuid = $info instanceof XboxLivePlayerInfo ? $info->getXuid() : "";
		if($loginXuid !== $verifiedXuid){
			$session->getLogger()->debug("NetherNet identity mismatch: signaling verified XUID '$verifiedXuid', login claims '$loginXuid'");
			$session->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_invalidSession_badSignature());
		}
	}

	public function onDisconnect(NetherNetSession $nethernetSession) : void{
		unset($this->loginChecked[$nethernetSession->getId()]);
		$session = $this->sessions[$nethernetSession->getId()] ?? null;
		if($session !== null){
			unset($this->sessions[$nethernetSession->getId()]);
			$session->onClientDisconnect("Client disconnected");
		}
	}

	/**
	 * @internal called by NetherNetPacketSender when pocketmine closes the session
	 */
	public function close(int $id) : void{
		unset($this->loginChecked[$id]);
		if(isset($this->sessions[$id])){
			unset($this->sessions[$id]);
			$this->nethernet->disconnect($id);
		}
	}
}
