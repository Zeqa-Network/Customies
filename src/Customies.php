<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\BlockPalette;
use customiesdevs\customies\block\CustomiesBlockFactory;
use customiesdevs\customies\item\CustomiesItemFactory;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Customies {

	/**
	 * Initialize Customies as a library within a host plugin.
	 * Call this from your plugin's onEnable() AFTER registering all custom blocks/items.
	 */
	public static function init(PluginBase $plugin): void {
		$plugin->getServer()->getPluginManager()->registerEvents(new CustomiesListener(), $plugin);
		CustomiesBlockFactory::getInstance()->registerSharedBlocks();
		CustomiesItemFactory::getInstance()->registerSharedItems();
		SharedRegistry::getInstance()->save();

		$cachePath = $plugin->getDataFolder() . "customies_idcache";
		$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($cachePath): void {
			BlockPalette::getInstance()->sortStates();
			CustomiesBlockFactory::getInstance()->addWorkerInitHook($cachePath);
		}), 0);
	}
}
