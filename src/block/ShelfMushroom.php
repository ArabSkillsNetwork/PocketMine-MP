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

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

class ShelfMushroom extends Flowable implements HorizontalFacing{
	use HorizontalFacingTrait;

	public const MAX_GROWTH = 1;

	private int $growth = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(0, self::MAX_GROWTH, $this->growth);
	}

	public function getGrowth() : int{ return $this->growth; }

	/** @return $this */
	public function setGrowth(int $growth) : self{
		if($growth < 0 || $growth > self::MAX_GROWTH){
			throw new \InvalidArgumentException("Growth must be in range 0 ... " . self::MAX_GROWTH);
		}
		$this->growth = $growth;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [
			AxisAlignedBB::one()
				->squash(Facing::axis(Facing::rotateY($this->facing, true)), ($this->growth === 0 ? 3 : 1) / 16)
				->trim(Facing::DOWN, 4 / 16)
				->trim(Facing::UP, 5 / 16)
				->trim($this->facing, ($this->growth === 0 ? 9 : 6) / 16)
		];
	}

	private function canAttachTo(Block $block) : bool{
		return $block->getSupportType($this->facing)->hasCenterSupport();
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(Facing::axis($face) === Axis::Y){
			return false;
		}
		$this->facing = $face;
		if(!$this->canAttachTo($blockClicked)){
			return false;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canAttachTo($this->getSide(Facing::opposite($this->facing)))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer && $this->growth < self::MAX_GROWTH){
			$block = clone $this;
			$block->growth++;
			if(BlockEventHelper::grow($this, $block, $player)){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function onEntityLand(Entity $entity) : ?float{
		if($entity instanceof Living && $entity->isSneaking()){
			return null;
		}
		$entity->fallDistance *= 0.5;
		return $entity->getMotion()->y * -0.75;
	}
}
