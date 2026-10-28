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

use pocketmine\block\utils\BlockSupportRegistry;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use function mt_rand;

final class ChorusPlant extends Flowable{
	use StaticSupportTrait {
		onNearbyBlockChange as protected checkSupport;
	}

	/**
	 * @var int[] facing => facing
	 * @phpstan-var array<int, int>
	 */
	protected array $connections = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingFlags($this->connections);
	}

	public function isConnectedAt(int $facing) : bool{
		return isset($this->connections[$facing]);
	}

	/** @return $this */
	public function setConnectedAt(int $facing, bool $connected) : self{
		if($connected){
			$this->connections[$facing] = $facing;
		}else{
			unset($this->connections[$facing]);
		}
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$bb = AxisAlignedBB::one();
		foreach(Facing::ALL as $facing){
			if(!isset($this->connections[$facing])){
				$bb->trim($facing, 2 / 16);
			}
		}

		return [$bb];
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->checkSupport();
		}elseif($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	/**
	 * Since 1.26.60 the client renders chorus plants from their connection states, so they are part of the persisted
	 * state and must be recomputed when neighbours change.
	 *
	 * @return bool whether any connection changed
	 */
	protected function recalculateConnections() : bool{
		$changed = false;
		foreach(Facing::ALL as $facing){
			$connected = match($this->getSide($facing)->getTypeId()){
				BlockTypeIds::CHORUS_FLOWER, $this->getTypeId() => true,
				BlockTypeIds::END_STONE => $facing === Facing::DOWN,
				default => false
			};
			if($connected !== $this->isConnectedAt($facing)){
				$this->setConnectedAt($facing, $connected);
				$changed = true;
			}
		}
		return $changed;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return BlockSupportRegistry::getInstance()->isTypeSupported($this, $block);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(mt_rand(0, 1) === 1){
			return [VanillaItems::CHORUS_FRUIT()];
		}

		return [];
	}
}
