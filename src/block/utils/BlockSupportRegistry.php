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

namespace pocketmine\block\utils;

use pocketmine\block\Bamboo;
use pocketmine\block\BambooSapling;
use pocketmine\block\BaseCake;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeTags;
use pocketmine\block\Button;
use pocketmine\block\Crops;
use pocketmine\block\Door;
use pocketmine\block\Flowable;
use pocketmine\block\NetherRoots;
use pocketmine\block\NetherVines;
use pocketmine\block\PressurePlate;
use pocketmine\block\Sapling;
use pocketmine\block\TallGrass;
use pocketmine\block\Torch;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\utils\SingletonTrait;
use function assert;
use function get_class;
use function is_array;

final class BlockSupportRegistry{
	use SingletonTrait;

	public const GROUP_BAMBOO = 0;
	public const GROUP_CAKE = 1;
	public const GROUP_BUTTON = 2;
	public const GROUP_CROPS = 3;
	public const GROUP_DOOR = 4;
	public const GROUP_FLOWER = 5;
	public const GROUP_NETHER_ROOTS = 6;
	public const GROUP_NETHER_VINES = 7;
	public const GROUP_PRESSURE_PLATE = 8;
	public const GROUP_SAPLING = 9;
	public const GROUP_TALL_GRASS = 10;
	public const GROUP_TORCH = 11;

	/** @var array<int, \Closure> Mapping of block type IDs to their support handlers. */
	private array $supportTypes = [];
	/** @var array<int, \Closure> */
	private array $supportTypesClosures = [];

	public function __construct(){
		$this->register([VanillaBlocks::AMETHYST_CLUSTER()], function (Block $blockIn, Block $block, int $facing) : bool {
			return $this->getAdjacentSupportType($block, $facing) === SupportType::FULL;
		});

		$this->register(fn(Block $b) => $b instanceof Bamboo || $b instanceof BambooSapling, function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return
				$supportBlock->hasSameTypeId($blockIn) ||
				$supportBlock->getTypeId() === BlockTypeIds::GRAVEL ||
				$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
				$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
				$supportBlock->hasTypeTag(BlockTypeTags::SAND);
		}, false, self::GROUP_BAMBOO);

		$this->register(fn(Block $b) => $b instanceof BaseCake, function (Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() !== BlockTypeIds::AIR;
		}, false, self::GROUP_CAKE);

		$this->register([VanillaBlocks::BED()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN) !== SupportType::NONE;
		});

		$this->register([VanillaBlocks::BELL()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face) !== SupportType::NONE;
		});

		$this->register(fn(Block $b) => $b instanceof Button, function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, Facing::opposite($face))->hasCenterSupport();
		}, false, self::GROUP_BUTTON);

		$this->register([VanillaBlocks::CACTUS()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			if(!$supportBlock->hasSameTypeId($blockIn) && !$supportBlock->hasTypeTag(BlockTypeTags::SAND)){
				return false;
			}
			foreach(Facing::HORIZONTAL as $side){
				if($block->getSide($side)->isSolid()){
					return false;
				}
			}

			return true;
		});

		$this->register([VanillaBlocks::CARPET()], function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() !== BlockTypeIds::AIR;
		});

		$this->register([VanillaBlocks::CAVE_VINES()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::UP);
			return $supportBlock->getSupportType(Facing::DOWN) === SupportType::FULL || $supportBlock->hasSameTypeId($blockIn);
		});

		$this->register([VanillaBlocks::CHORUS_FLOWER()], function (Block $blockIn, Block $block){
			$position = $block->getPosition();
			$world = $position->getWorld();
			$down = $world->getBlock($position->down());

			if($down->getTypeId() === BlockTypeIds::END_STONE || $down->getTypeId() === BlockTypeIds::CHORUS_PLANT){
				return true;
			}

			$plantAdjacent = false;
			foreach($position->sidesAroundAxis(Axis::Y) as $sidePosition){
				$block = $world->getBlock($sidePosition);

				if($block->getTypeId() === BlockTypeIds::CHORUS_PLANT){
					if($plantAdjacent){ //at most one plant may be horizontally adjacent
						return false;
					}
					$plantAdjacent = true;
				}elseif($block->getTypeId() !== BlockTypeIds::AIR){
					return false;
				}
			}

			return $plantAdjacent;
		});

		$this->register([VanillaBlocks::CHORUS_PLANT()], function (Block $blockIn, Block $block){
			$position = $block->getPosition();
			$world = $position->getWorld();

			$down = $world->getBlock($position->down());
			$verticalAir = $down->getTypeId() === BlockTypeIds::AIR || $world->getBlock($position->up())->getTypeId() === BlockTypeIds::AIR;

			// this method already exists in ChorusPlant (@see ChorusPlant->canBeSupportedBy())
			$canBeSupportedBy = fn(Block $block) => $block->hasSameTypeId($blockIn) || $block->getTypeId() === BlockTypeIds::END_STONE;

			foreach($position->sidesAroundAxis(Axis::Y) as $sidePosition){
				$block = $world->getBlock($sidePosition);

				if($block->getTypeId() === BlockTypeIds::CHORUS_PLANT){
					if(!$verticalAir){
						return false;
					}

					if($canBeSupportedBy($block->getSide(Facing::DOWN))){
						return true;
					}
				}
			}

			return $canBeSupportedBy($down);
		});

		$this->register([VanillaBlocks::CORAL()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN)->hasCenterSupport();
		});

		$this->register(fn(Block $b) => $b instanceof Crops, function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::FARMLAND;
		}, false, self::GROUP_CROPS);

		$this->register([VanillaBlocks::DEAD_BUSH()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return
				$supportBlock->hasTypeTag(BlockTypeTags::SAND) ||
				$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
				match($supportBlock->getTypeId()){
					//can't use DIRT tag here because it includes farmland
					BlockTypeIds::PODZOL,
					BlockTypeIds::MYCELIUM,
					BlockTypeIds::DIRT,
					BlockTypeIds::GRASS,
					BlockTypeIds::HARDENED_CLAY,
					BlockTypeIds::STAINED_CLAY => true,
					//TODO: moss block
					default => false,
				};
		});

		$this->register(fn(Block $b) => $b instanceof Door, function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN)->hasEdgeSupport();
		}, false, self::GROUP_DOOR);

		$this->register([VanillaBlocks::CORAL_FAN()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN)->hasCenterSupport();
		});

		$this->register(fn(Block $b) => $b instanceof Flowable, function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN)->hasCenterSupport();
		}, false, self::GROUP_FLOWER);

		$this->register([VanillaBlocks::HANGING_ROOTS()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::UP)->hasCenterSupport(); //weird I know, but they can be placed on the bottom of fences
		});

		$this->register([VanillaBlocks::ITEM_FRAME()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face) !== SupportType::NONE;
		});

		$this->register([VanillaBlocks::LADDER()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face) === SupportType::FULL;
		});

		$this->register([VanillaBlocks::LANTERN()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face)->hasCenterSupport();
		});

		$this->register([VanillaBlocks::LEVER()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face)->hasCenterSupport();
		});

		$this->register(fn(Block $b) => $b instanceof NetherRoots, function (Block $blockIn, Block $block){
			//TODO: nylium, moss
			$supportBlock = $block->getSide(Facing::DOWN);
			return
				$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
				$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
				$supportBlock->getTypeId() === BlockTypeIds::SOUL_SOIL;
		}, false, self::GROUP_NETHER_ROOTS);

		$this->register(fn(Block $b) => $b instanceof NetherVines, function (Block $blockIn, Block $block, int $growthFace){
			$supportBlock = $block->getSide(Facing::opposite($growthFace));
			return $supportBlock->getSupportType($growthFace)->hasCenterSupport() || $supportBlock->hasSameTypeId($block);
		}, false, self::GROUP_NETHER_VINES);

		$this->register([VanillaBlocks::NETHER_WART()], function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::SOUL_SAND;
		});

		$this->register([VanillaBlocks::PINK_PETALS()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			//TODO: Moss block
			return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
		});

		$this->register([VanillaBlocks::PITCHER_CROP()], function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::FARMLAND;
		});

		$this->register(fn(Block $b) => $b instanceof PressurePlate, function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN) !== SupportType::NONE;
		}, false, self::GROUP_PRESSURE_PLATE);

		$this->register([VanillaBlocks::REDSTONE_COMPARATOR()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN) !== SupportType::NONE;
		});

		$this->register([VanillaBlocks::REDSTONE_REPEATER()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN) !== SupportType::NONE;
		});

		$this->register([VanillaBlocks::REDSTONE_WIRE()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN)->hasCenterSupport();
		});

		$this->register(fn(Block $b) => $b instanceof Sapling, function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
		}, false, self::GROUP_SAPLING);

		$this->register([VanillaBlocks::SNOW_LAYER()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::DOWN) === SupportType::FULL;
		});

		$this->register([VanillaBlocks::SPORE_BLOSSOM()], function (Block $blockIn, Block $block){
			return $this->getAdjacentSupportType($block, Facing::UP) === SupportType::FULL;
		});

		$this->register([VanillaBlocks::SUGARCANE()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return $supportBlock->hasSameTypeId($blockIn) ||
				$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
				$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
				$supportBlock->hasTypeTag(BlockTypeTags::SAND);
		});

		$this->register([VanillaBlocks::SWEET_BERRY_BUSH()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			// this method already exists in SweetBerryBush (@see SweetBerryBush->canBeSupportedBy())
			$canBeSupportedBy = fn(Block $block) => $block->getTypeId() !== BlockTypeIds::FARMLAND && //bedrock-specific thing (bug?)
				($block->hasTypeTag(BlockTypeTags::DIRT) || $block->hasTypeTag(BlockTypeTags::MUD));
			return $canBeSupportedBy($supportBlock);
		});

		$this->register(fn(Block $b) => $b instanceof TallGrass, function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
		}, false, self::GROUP_TALL_GRASS);

		$this->register(fn(Block $b) => $b instanceof Torch, function (Block $blockIn, Block $block, int $face){
			return $face === Facing::DOWN ?
				$this->getAdjacentSupportType($block, $face)->hasCenterSupport() :
				$this->getAdjacentSupportType($block, $face) === SupportType::FULL;
		}, false, self::GROUP_TORCH);

		$this->register([VanillaBlocks::TORCHFLOWER_CROP()], function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::FARMLAND;
		});

		$this->register([VanillaBlocks::WALL_CORAL_FAN()], function (Block $blockIn, Block $block, int $face){
			return $this->getAdjacentSupportType($block, $face)->hasCenterSupport();
		});

		$this->register([VanillaBlocks::LILY_PAD()], function (Block $blockIn, Block $block){
			return $block->getSide(Facing::DOWN) instanceof Water;
		});

		$this->register([VanillaBlocks::WITHER_ROSE()], function (Block $blockIn, Block $block){
			$supportBlock = $block->getSide(Facing::DOWN);
			return
				$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
				$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
				match($supportBlock->getTypeId()){
					BlockTypeIds::NETHERRACK,
					BlockTypeIds::SOUL_SAND,
					BlockTypeIds::SOUL_SOIL => true,
					default => false
				};
		});
	}

	/**
	 * Registers a support handler for a specific block type.
	 *
	 * @param \Closure $handler  The handler to determine if the block can be supported.
	 * @param bool     $override Whether to override an existing handler for the block type.
	 * @throws \InvalidArgumentException If the block type or group id is already registered and override is false.
	 */
	public function register(array|\Closure $blocks, \Closure $handler, bool $override = false, ?int $group = null) : void{
		if (is_array($blocks)){
			foreach ($blocks as $block){
				if (!$override && isset($this->supportTypes[$block->getTypeId()])) {
					throw new \InvalidArgumentException("Block support type for " . get_class($block) . " is already registered");
				}

				$this->supportTypes[$block->getTypeId()] = $handler;
			}
		} else {
			assert($group === null, new \InvalidArgumentException("Group ID must not be null"));

			if (!$override && isset($this->supportTypesClosures[$group])) {
				throw new \InvalidArgumentException("Block support type for the group with id {$group} is already registered");
			}

			$this->supportTypesClosures[$group] = $handler;
		}
	}

	/**
	 * Unregisters the support handler for a specific block type.
	 *
	 * @param Block $block The block to unregister the handler for.
	 */
	public function unregister(Block $block) : void{
		if (isset($this->supportTypes[$block->getTypeId()])) {
			unset($this->supportTypes[$block->getTypeId()]);
		}
	}

	/**
	 * Checks if a block type is supported based on its registered handler.
	 *
	 * @param Block $block   The block to check.
	 * @param mixed ...$args Additional arguments to pass to the handler.
	 * @return bool Whether the block type is supported.
	 */
	public function isTypeSupported(Block $block, ...$args) : bool{
		if (isset($this->supportTypes[$block->getTypeId()])) {
			return $this->supportTypes[$block->getTypeId()]($block, ...$args);
		}

		if (isset($this->supportTypesClosures[($group = self::getBlockGroup($block))])){
			return $this->supportTypesClosures[$group]($block, ...$args);
		}

		return false;
	}

	/**
	 * Gets the support type of the adjacent block in the specified facing direction.
	 *
	 * This method is copied from {@see Block->getAdjacentSupportType()} because the original method is protected
	 * and cannot be used in closures.
	 *
	 * @param Block $block  The block to check.
	 * @param int   $facing The facing direction to check.
	 * @return SupportType The support type of the adjacent block.
	 * @see Block->getAdjacentSupportType()
	 */
	private function getAdjacentSupportType(Block $block, int $facing) : SupportType {
		return $block->getSide($facing)->getSupportType(Facing::opposite($facing));
	}

	public static function getBlockGroup(Block $block) : int
	{
		return match (true){
			$block instanceof Bamboo => self::GROUP_BAMBOO,
			$block instanceof BaseCake => self::GROUP_CAKE,
			$block instanceof Button => self::GROUP_BUTTON,
			$block instanceof Crops => self::GROUP_CROPS,
			$block instanceof Door => self::GROUP_DOOR,
			$block instanceof Flowable => self::GROUP_FLOWER,
			$block instanceof NetherRoots => self::GROUP_NETHER_ROOTS,
			$block instanceof NetherVines => self::GROUP_NETHER_VINES,
			$block instanceof PressurePlate => self::GROUP_PRESSURE_PLATE,
			$block instanceof Sapling => self::GROUP_SAPLING,
			$block instanceof TallGrass => self::GROUP_TALL_GRASS,
			$block instanceof Torch => self::GROUP_TORCH,
			default => -1
		};
	}
}
