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

namespace pocketmine\network\mcpe\handler;

use pocketmine\inventory\Inventory;
use pocketmine\item\Durable;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseContainerInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseSlotInfo;
use pocketmine\utils\AssumptionFailedError;

final class ItemStackResponseBuilder{

	/**
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $changedSlots = [];

	public function __construct(
		private int $requestId,
		private InventoryManager $inventoryManager
	){}

	public function addSlot(int $containerInterfaceId, int $slotId) : void{
		$this->changedSlots[$containerInterfaceId][$slotId] = $slotId;
	}

	/**
	 * @phpstan-return array{Inventory, int}
	 */
	private function getInventoryAndSlot(int $containerInterfaceId, int $slotId) : ?array{
		[$windowId, $slotId] = ItemStackContainerIdTranslator::translate($containerInterfaceId, $this->inventoryManager->getCurrentWindowId(), $slotId);
		$windowAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slotId);
		if($windowAndSlot === null){
			return null;
		}
		[$inventory, $slot] = $windowAndSlot;
		if(!$inventory->slotExists($slot)){
			return null;
		}

		return [$inventory, $slot];
	}

	public function build() : ItemStackResponse{
		$responseInfosByContainer = [];
		foreach($this->changedSlots as $containerInterfaceId => $slotIds){
			if($containerInterfaceId === ContainerUIIds::CREATED_OUTPUT){
				continue;
			}
			foreach($slotIds as $slotId){
				$inventoryAndSlot = $this->getInventoryAndSlot($containerInterfaceId, $slotId);
				if($inventoryAndSlot === null){
					//a plugin may have closed the inventory during an event, or the slot may have been invalid
					continue;
				}
				[$inventory, $slot] = $inventoryAndSlot;

				$itemStackInfo = $this->inventoryManager->getItemStackInfo($inventory, $slot);
				if($itemStackInfo === null){
					throw new AssumptionFailedError("ItemStackInfo should never be null for an open inventory");
				}
				$item = $inventory->getItem($slot);

				$responseInfosByContainer[$containerInterfaceId][] = new ItemStackResponseSlotInfo(
					$slotId,
					$slotId,
					$item->getCount(),
					$itemStackInfo->getStackId(),
					$item->getCustomName(),
					//filteredCustomName is the profanity-filtered form of the name. We don't do any
					//filtering, so we have nothing to put here, and sending the unfiltered name is
					//wrong twice over: a client with the filter enabled reads this field in
					//preference to customName, and any non-empty value here makes clients on
					//1.21.50 and up drop the connection with "An error occurred" the moment a named
					//item is moved. The name still reaches the client in the item's NBT.
					"",
					$item instanceof Durable ? $item->getDamage() : 0,
				);
			}
		}

		$responseContainerInfos = [];
		foreach($responseInfosByContainer as $containerInterfaceId => $responseInfos){
			$responseContainerInfos[] = new ItemStackResponseContainerInfo(new FullContainerName($containerInterfaceId), $responseInfos);
		}

		return new ItemStackResponse(ItemStackResponse::RESULT_OK, $this->requestId, $responseContainerInfos);
	}
}
