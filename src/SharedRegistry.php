<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use GlobalLogger;
use pmmp\thread\Thread as NativeThread;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\SingletonTrait;
use function base64_decode;
use function base64_encode;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function uasort;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

final class SharedRegistry {
	use SingletonTrait;

	private const FIRST_ID = 25000;

	/** @var array<string, array{name: string, id: int, component_based: bool, version: int, nbt: string}> */
	private array $items = [];
	/** @var array<string, array{name: string, properties: string, states: list<array{meta: int, states: string}>}> */
	private array $blocks = [];
	private bool $loaded = false;
	private ?string $updatePath = null;

	public function __construct() {
		$env = getenv("CUSTOMIES_REGISTRY_UPDATE");
		if($env !== false && $env !== ""){
			$this->updatePath = $env;
		}
		$path = $this->updatePath ?? dirname(__DIR__) . "/registry.json";
		if(!is_file($path)){
			return;
		}
		/** @var array{items: list<array{name: string, id: int, component_based: bool, version: int, nbt: string}>, blocks: list<array{name: string, properties: string, states: list<array{meta: int, states: string}>}>} $data */
		$data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
		foreach($data["items"] as $item){
			$this->items[$item["name"]] = $item;
		}
		foreach($data["blocks"] as $block){
			$this->blocks[$block["name"]] = $block;
		}
		$this->loaded = true;
	}

	public function getItemId(string $identifier, int $fallback): int {
		if(isset($this->items[$identifier])){
			return $this->items[$identifier]["id"];
		}
		if($this->updatePath === null){
			return $fallback;
		}
		$next = self::FIRST_ID;
		foreach($this->items as $item){
			$next = max($next, $item["id"] + 1);
		}
		$this->items[$identifier] = ["name" => $identifier, "id" => $next, "component_based" => false, "version" => 0, "nbt" => ""];
		return $next;
	}

	public function checkItem(ItemTypeEntry $entry): void {
		if(NativeThread::getCurrentThread() !== null){
			return;
		}
		$identifier = $entry->getStringId();
		$item = [
			"name" => $identifier,
			"id" => $entry->getNumericId(),
			"component_based" => $entry->isComponentBased(),
			"version" => $entry->getVersion(),
			"nbt" => self::encode($entry->getComponentNbt()->getRoot())
		];
		if($this->updatePath !== null){
			$this->items[$identifier] = $item;
		}elseif(!isset($this->items[$identifier])){
			$this->warn("item $identifier is not in the shared registry");
		}elseif($this->items[$identifier] !== $item){
			$this->warn("item $identifier differs from the shared registry");
		}
	}

	/**
	 * @param list<array{int, CompoundTag}> $states
	 */
	public function checkBlock(string $name, CompoundTag $properties, array $states): void {
		if(NativeThread::getCurrentThread() !== null){
			return;
		}
		$encodedStates = [];
		foreach($states as [$meta, $state]){
			$encodedStates[] = ["meta" => $meta, "states" => self::encode($state)];
		}
		$block = ["name" => $name, "properties" => self::encode($properties), "states" => $encodedStates];
		if($this->updatePath !== null){
			$this->blocks[$name] = $block;
		}elseif(!isset($this->blocks[$name])){
			$this->warn("block $name is not in the shared registry");
		}elseif($this->blocks[$name] !== $block){
			$this->warn("block $name differs from the shared registry");
		}
	}

	/**
	 * @return array<string, ItemTypeEntry>
	 */
	public function getItemEntries(): array {
		$entries = [];
		foreach($this->items as $name => $item){
			if($item["nbt"] === ""){
				continue;
			}
			$entries[$name] = new ItemTypeEntry($name, $item["id"], $item["component_based"], $item["version"], new CacheableNbt(self::decode($item["nbt"])));
		}
		return $entries;
	}

	/**
	 * @return array<string, array{CompoundTag, list<array{int, CompoundTag}>}>
	 */
	public function getBlocks(): array {
		$blocks = [];
		foreach($this->blocks as $name => $block){
			$states = [];
			foreach($block["states"] as $state){
				$states[] = [$state["meta"], self::decode($state["states"])];
			}
			$blocks[$name] = [self::decode($block["properties"]), $states];
		}
		return $blocks;
	}

	public function save(): void {
		if($this->updatePath === null){
			return;
		}
		uasort($this->items, static fn(array $a, array $b): int => $a["id"] <=> $b["id"]);
		ksort($this->blocks);
		$items = [];
		foreach($this->items as $item){
			if($item["nbt"] === ""){
				continue;
			}
			$items[] = "\t\t" . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}
		$blocks = [];
		foreach($this->blocks as $block){
			$blocks[] = "\t\t" . json_encode($block, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}
		file_put_contents($this->updatePath, "{\n\t\"items\": [\n" . implode(",\n", $items) . "\n\t],\n\t\"blocks\": [\n" . implode(",\n", $blocks) . "\n\t]\n}\n");
		GlobalLogger::get()->info("[Customies] wrote " . count($this->items) . " items and " . count($this->blocks) . " blocks to $this->updatePath");
	}

	private function warn(string $message): void {
		if($this->loaded){
			GlobalLogger::get()->warning("[Customies] $message, players moving between games will see it wrong");
		}
	}

	private static function encode(CompoundTag $tag): string {
		return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($tag)));
	}

	private static function decode(string $data): CompoundTag {
		return (new LittleEndianNbtSerializer())->read((string) base64_decode($data, true))->mustGetCompoundTag();
	}
}
