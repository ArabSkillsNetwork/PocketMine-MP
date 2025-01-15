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
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use function mt_rand;

final class ChorusPlant extends Flowable{
	use StaticSupportTrait;

	protected function recalculateCollisionBoxes() : array{
		$bb = AxisAlignedBB::one();
		foreach($this->getAllSides() as $facing => $block){
			$id = $block->getTypeId();
			if($id !== BlockTypeIds::END_STONE && $id !== BlockTypeIds::CHORUS_FLOWER && !$block->hasSameTypeId($this)){
				$bb->trim($facing, 2 / 16);
			}
		}

		return [$bb];
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->hasSameTypeId($this) || $block->getTypeId() === BlockTypeIds::END_STONE;
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
