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

namespace pocketmine\block;

use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\BlockSupportRegistry;
use pocketmine\block\utils\RedstoneWireConnection;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use function array_reverse;
use function array_search;
use function intdiv;

class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter{
	use AnalogRedstoneSignalEmitterTrait;
	use StaticSupportTrait {
		onNearbyBlockChange as protected checkSupport;
	}

	/**
	 * @var RedstoneWireConnection[] facing => connection
	 * @phpstan-var array<Facing::NORTH|Facing::SOUTH|Facing::WEST|Facing::EAST, RedstoneWireConnection>
	 */
	protected array $connections = [
		Facing::NORTH => RedstoneWireConnection::NONE,
		Facing::SOUTH => RedstoneWireConnection::NONE,
		Facing::WEST => RedstoneWireConnection::NONE,
		Facing::EAST => RedstoneWireConnection::NONE,
	];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, 15, $this->signalStrength);

		//4 sides with 3 values each need 8 bits as separate enums, which together with the signal strength exceeds the
		//11-bit state data limit, so pack them into a single base-3 number instead (81 values, 7 bits) like walls do
		$cases = RedstoneWireConnection::cases();
		$packed = 0;
		foreach(array_reverse(Facing::HORIZONTAL) as $facing){
			$packed = ($packed * 3) + (int) array_search($this->connections[$facing], $cases, true);
		}
		$w->boundedIntAuto(0, (3 ** 4) - 1, $packed);
		foreach(Facing::HORIZONTAL as $facing){
			$this->connections[$facing] = $cases[$packed % 3];
			$packed = intdiv($packed, 3);
		}
	}

	/**
	 * @param int $facing one of Facing::NORTH/EAST/SOUTH/WEST
	 */
	public function getConnection(int $facing) : RedstoneWireConnection{
		return $this->connections[$facing] ?? throw new \InvalidArgumentException("Facing must be horizontal");
	}

	/**
	 * @param int $facing one of Facing::NORTH/EAST/SOUTH/WEST
	 * @return $this
	 */
	public function setConnection(int $facing, RedstoneWireConnection $connection) : self{
		if(!isset($this->connections[$facing])){
			throw new \InvalidArgumentException("Facing must be horizontal");
		}
		$this->connections[$facing] = $connection;
		return $this;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->checkSupport();
		}elseif($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	/**
	 * Since 1.26.60 the client renders dust from its connection states, so they are part of the persisted state and
	 * must be recomputed when neighbours change. This matches the way vanilla computes them.
	 *
	 * @return bool whether any connection changed
	 */
	protected function recalculateConnections() : bool{
		$aboveConducts = self::conductsRedstone($this->getSide(Facing::UP));

		$connections = [];
		foreach(Facing::HORIZONTAL as $facing){
			$connections[$facing] = $this->getConnectingSide($facing, $aboveConducts);
		}

		//dust which only connects along one axis (or not at all) extends straight through to the opposite side(s)
		$connected = fn(int $facing) : bool => $connections[$facing] !== RedstoneWireConnection::NONE;
		$noneNorthSouth = !$connected(Facing::NORTH) && !$connected(Facing::SOUTH);
		$noneEastWest = !$connected(Facing::EAST) && !$connected(Facing::WEST);
		foreach(Facing::HORIZONTAL as $facing){
			if(!$connected($facing) && (Facing::axis($facing) === Axis::X ? $noneNorthSouth : $noneEastWest)){
				$connections[$facing] = RedstoneWireConnection::SIDE;
			}
		}

		if($connections === $this->connections){
			return false;
		}
		$this->connections = $connections;
		return true;
	}

	private function getConnectingSide(int $facing, bool $aboveConducts) : RedstoneWireConnection{
		$side = $this->getSide($facing);
		if(
			!$aboveConducts &&
			$side->getSupportType(Facing::UP) === SupportType::FULL &&
			$side->getSide(Facing::UP) instanceof RedstoneWire
		){
			return $side->getSupportType(Facing::opposite($facing)) === SupportType::FULL ? RedstoneWireConnection::UP : RedstoneWireConnection::SIDE;
		}

		if(self::connectsTo($side, $facing) || (!self::conductsRedstone($side) && $side->getSide(Facing::DOWN) instanceof RedstoneWire)){
			return RedstoneWireConnection::SIDE;
		}
		return RedstoneWireConnection::NONE;
	}

	/**
	 * Returns whether dust will point towards the given block when it is on the given side of the dust.
	 */
	private static function connectsTo(Block $block, int $facing) : bool{
		if($block instanceof RedstoneRepeater){
			return Facing::axis($block->getFacing()) === Facing::axis($facing);
		}
		return
			$block instanceof RedstoneWire ||
			$block instanceof Lever ||
			$block instanceof Button ||
			$block instanceof RedstoneTorch ||
			$block instanceof Redstone ||
			$block instanceof PressurePlate ||
			$block instanceof DaylightSensor ||
			$block instanceof TrappedChest ||
			$block instanceof TripwireHook ||
			$block instanceof RedstoneComparator ||
			$block instanceof DetectorRail ||
			$block instanceof LightningRod ||
			$block instanceof Lectern ||
			$block instanceof Jukebox;
	}

	/**
	 * Opaque full blocks stop dust from climbing over them. Transparent full blocks like glass don't.
	 */
	private static function conductsRedstone(Block $block) : bool{
		return $block->isFullCube() && !$block->isTransparent();
	}

	private function canBeSupportedAt(Block $block) : bool{
		return BlockSupportRegistry::getInstance()->isTypeSupported($this, $block);
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}
