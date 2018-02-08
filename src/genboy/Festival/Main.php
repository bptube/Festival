<?php
/* src/genboy/Festival/Main.php */

declare(strict_types = 1);

namespace genboy\Festival;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\entity\Entity;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\Server;

use pocketmine\event\player\PlayerMoveEvent; // G 2018 01 19

class Main extends PluginBase implements Listener{

	/** @var array */
	private $levels = [];
	/** @var Area[] */
	public $areas = [];

	/** @var bool */
	private $god = false;
	/** @var bool */
	private $edit = false;
	/** @var bool */
	private $touch = false;
	/** @var string */
	private $msg = false;  // area enter/leave messages display on/off or op only

	/** @var bool[] */
	private $selectingFirst = [];
	/** @var bool[] */
	private $selectingSecond = [];

	/** @var Vector3[] */
	private $firstPosition = [];
	/** @var Vector3[] */
	private $secondPosition = [];


	/** @var string[] */
	private $inArea = []; // array of area's player is in



	public function onEnable() : void{

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		if(!is_dir($this->getDataFolder())){
			mkdir($this->getDataFolder());
		}

		if(!file_exists($this->getDataFolder() . "areas.json")){
			file_put_contents($this->getDataFolder() . "areas.json", "[]");
		}

		if(!file_exists($this->getDataFolder() . "config.yml")){
			$c = $this->getResource("config.yml");
			$o = stream_get_contents($c);
			fclose($c);
			file_put_contents($this->getDataFolder() . "config.yml", str_replace("DEFAULT", $this->getServer()->getDefaultLevel()->getName(), $o));
		}

		$data = json_decode(file_get_contents($this->getDataFolder() . "areas.json"), true);
		foreach($data as $datum){
			new Area($datum["name"], $datum["desc"], $datum["flags"], new Vector3($datum["pos1"]["0"], $datum["pos1"]["1"], $datum["pos1"]["2"]), new Vector3($datum["pos2"]["0"], $datum["pos2"]["1"], $datum["pos2"]["2"]), $datum["level"], $datum["whitelist"], $datum["commands"], $datum["events"], $this);
		}

		$c = yaml_parse_file($this->getDataFolder() . "config.yml");

		$this->god = $c["Default"]["God"];
		$this->edit = $c["Default"]["Edit"];
		$this->touch = $c["Default"]["Touch"];
		$this->msg = $c["Default"]["Msg"];

		foreach($c["Worlds"] as $level => $flags){
			$this->levels[$level] = $flags;
		}

		$c = 0;
		foreach( $this->areas as $a ){
			$c = $c + count( $a->getCommands() );
		}
		$this->getLogger()->info(TextFormat::GREEN . "Festival plugin has " . count($this->areas) . " areas and ". $c ." commands set.");

	}

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args) : bool{

		if(!($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "Command must be used in-game.");

			return true;
		}
		if(!isset($args[0])){
			return false;
		}
		$playerName = strtolower($sender->getName());
		$action = strtolower($args[0]);
		$o = "";

		switch($action){
			case "pos1":

				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.pos1")){
					if(isset($this->selectingFirst[$playerName]) || isset($this->selectingSecond[$playerName])){
						$o = TextFormat::RED . "You're already selecting a position!";
					}else{
						$this->selectingFirst[$playerName] = true;
						$o = TextFormat::GREEN . "Please place or break the first position.";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;

			case "pos2":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.pos2")){
					if(isset($this->selectingFirst[$playerName]) || isset($this->selectingSecond[$playerName])){
						$o = TextFormat::RED . "You're already selecting a position!";
					}else{
						$this->selectingSecond[$playerName] = true;
						$o = TextFormat::GREEN . "Please place or break the second position.";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;

			case "create":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.area") || $sender->hasPermission("festival.command.fe.create")){
					if(isset($args[1])){
						if(isset($this->firstPosition[$playerName], $this->secondPosition[$playerName])){
							if(!isset($this->areas[strtolower($args[1])])){
								new Area(strtolower($args[1]), "add description here",["edit" => true, "god" => false, "touch" => true, "msg" => false], $this->firstPosition[$playerName], $this->secondPosition[$playerName], $sender->getLevel()->getName(), [$playerName], [], [], $this);
								$this->saveAreas();
								unset($this->firstPosition[$playerName], $this->secondPosition[$playerName]);
								$o = TextFormat::AQUA . "Area created!";
							}else{
								$o = TextFormat::RED . "An area with that name already exists.";
							}
						}else{
							$o = TextFormat::RED . "Please select both positions first.";
						}
					}else{
						$o = TextFormat::RED . "Please specify a name for this area.";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;

			case "desc":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.desc")){

					if(isset($args[1])){

						if(isset($this->areas[strtolower($args[1])])){

							if(isset($args[2])){
								$ar = $args[1];
								unset($args[0]);
								unset($args[1]);
								$desc = implode(" ", $args);
								$area = $this->areas[strtolower($ar)];
								$area->desc = $desc;
								$this->saveAreas();
								$o = TextFormat::GREEN . "Area ". TextFormat::LIGHT_PURPLE . $area->getName() . TextFormat::GREEN . " description saved";
							}else{
								$o = TextFormat::RED . "Please write the description. Usage /fe desc <areaname> <..>";
							}

						}else{
							$o = TextFormat::RED . "Area does not exist.";
						}
					}else{
						$o = TextFormat::RED . "Please specify an area to edit the description. Usage: /fe desc <areaname> <desc>";
					}


				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;

			case "list":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.list")){


					$lvls = $this->getServer()->getLevels();
					$o = '';
					foreach( $lvls as $lvl ){
						//$server->getLevels()
						///$area->getLevelName()

						$i = 0;
						$t = '';
						foreach($this->areas as $area){
							if( $area->getLevelName() == $lvl->getName() ){
								if($area->isWhitelisted($playerName)){
									$t .= TextFormat::WHITE . $area->getName() . TextFormat::LIGHT_PURPLE . " (" . implode(", ", $area->getWhitelist()) . ") \n";
									$i++;
								}
							}
						}
						if($i > 0){
							$o .= TextFormat::AQUA . "Level " . TextFormat::WHITE . $lvl->getName() .":\n". $t;
						}
					}
					if($i === 0){
						$o = "There are no areas that you can edit";
					}
				}
				break;

			case "here":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.here")){
					$o = "";
					foreach($this->areas as $area){
						if($area->contains($sender->getPosition(), $sender->getLevel()->getName()) && $area->getWhitelist() !== null){
							$o .= TextFormat::AQUA . "-- Area " . TextFormat::WHITE . $area->getName() . TextFormat::AQUA . " --";
							$o .= TextFormat::AQUA . "\nEditors: " . TextFormat::WHITE . implode(", ", $area->getWhitelist());

							if( $cmds = $area->getCommands() && count( $area->getCommands() ) > 0 ){
								$o .= "\n". TextFormat::AQUA . "Commands:";
								foreach( $area->getEvents() as $type => $list ){
									$ids = explode(",",$list);
									$o .= "\n". TextFormat::YELLOW . "On ". $type;
									foreach($ids as $cmdid){
										if( isset($area->commands[$cmdid]) ){
											$o .= "\n". TextFormat::LIGHT_PURPLE . $cmdid . ": ".$area->commands[$cmdid];
										}
									}
								}

							}else{
								$o .= "\nNo commands attachted";
							}

							$o .= "\n";
						}
					}
					if($o === "") {
						$o = TextFormat::RED . "You are in an unknown area";
					}
				}
				break;

			case "tp":
				if (!isset($args[1])){
					$o = TextFormat::RED . "You must specify an existing Area name";
					break;
				}
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.tp")){
					$area = $this->areas[strtolower($args[1])];
					if($area !== null && $area->isWhitelisted($playerName)){
						$levelName = $area->getLevelName();
						if(isset($levelName) && Server::getInstance()->loadLevel($levelName) != false){
							$o = TextFormat::GREEN . "You are teleporting to Area " . $args[1];
							$sender->teleport(new Position($area->getFirstPosition()->getX(), $area->getFirstPosition()->getY() + 0.5, $area->getFirstPosition()->getZ(), $area->getLevel()));
						}else{
							$o = TextFormat::RED . "The level " . $levelName . " for Area ". $args[1] ." cannot be found";
						}
					}else{
						$o = TextFormat::RED . "The Area " . $args[1] . " could not be found ";
					}
				}
				break;

			case "flag":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.flag")){
					if(isset($args[1])){
						if(isset($this->areas[strtolower($args[1])])){
							$area = $this->areas[strtolower($args[1])];
							if(isset($args[2])){
								if(isset($area->flags[strtolower($args[2])])){
									$flag = strtolower($args[2]);
									if(isset($args[3])){
										$mode = strtolower($args[3]);
										if($mode === "true" || $mode === "on"){
											$mode = true;
										}else{
											$mode = false;
										}
										$area->setFlag($flag, $mode);
									}else{
										$area->toggleFlag($flag);
									}
									if($area->getFlag($flag)){
										$status = "on";
									}else{
										$status = "off";
									}
									$o = TextFormat::GREEN . "Flag " . $flag . " set to " . $status . " for area " . $area->getName() . "!";
								}else{
									$o = TextFormat::RED . "Flag not found. (Flags: edit, god, touch, msg)";
								}
							}else{
								$o = TextFormat::RED . "Please specify a flag. (Flags: edit, god, touch, msg)";
							}
						}else{
							$o = TextFormat::RED . "Area doesn't exist.";
						}
					}else{
						$o = TextFormat::RED . "Please specify the area you would like to flag.";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;

			case "delete":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.delete")){
					if(isset($args[1])){
						if(isset($this->areas[strtolower($args[1])])){
							$area = $this->areas[strtolower($args[1])];
							$area->delete();
							$o = TextFormat::GREEN . "Area deleted!";
						}else{
							$o = TextFormat::RED . "Area does not exist.";
						}
					}else{
						$o = TextFormat::RED . "Please specify an area to delete.";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;
			case "whitelist":
				if($sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.whitelist")){
					if(isset($args[1], $this->areas[strtolower($args[1])])){
						$area = $this->areas[strtolower($args[1])];
						if(isset($args[2])){
							$action = strtolower($args[2]);
							switch($action){
								case "add":
									$w = ($this->getServer()->getPlayer($args[3]) instanceof Player ? strtolower($this->getServer()->getPlayer($args[3])->getName()) : strtolower($args[3]));
									if(!$area->isWhitelisted($w)){
										$area->setWhitelisted($w);
										$o = TextFormat::GREEN . "Player $w has been whitelisted in area " . $area->getName() . ".";
									}else{
										$o = TextFormat::RED . "Player $w is already whitelisted in area " . $area->getName() . ".";
									}
									break;
								case "list":
									$o = TextFormat::AQUA . "Area " . $area->getName() . "'s whitelist:" . TextFormat::RESET;
									foreach($area->getWhitelist() as $w){
										$o .= " $w;";
									}
									break;
								case "delete":
								case "remove":
									$w = ($this->getServer()->getPlayer($args[3]) instanceof Player ? strtolower($this->getServer()->getPlayer($args[3])->getName()) : strtolower($args[3]));
									if($area->isWhitelisted($w)){
										$area->setWhitelisted($w, false);
										$o = TextFormat::GREEN . "Player $w has been unwhitelisted in area " . $area->getName() . ".";
									}else{
										$o = TextFormat::RED . "Player $w is already unwhitelisted in area " . $area->getName() . ".";
									}
									break;
								default:
									$o = TextFormat::RED . "Please specify a valid action. Usage: /area whitelist " . $area->getName() . " <add/list/remove> [player]";
									break;
							}
						}else{
							$o = TextFormat::RED . "Please specify an action. Usage: /area whitelist " . $area->getName() . " <add/list/remove> [player]";
						}
					}else{
						$o = TextFormat::RED . "Area doesn't exist. Usage: /area whitelist <area> <add/list/remove> [player]";
					}
				}else{
					$o = TextFormat::RED . "You do not have permission to use this subcommand.";
				}
				break;
			case "c":
			case "cmd":
			case "command":
					/* /fe command <areaname> <add|list|edit|del> <commandindex> <commandstring> */

								if( isset($args[1]) && (  $sender->hasPermission("festival") || $sender->hasPermission("festival.command") || $sender->hasPermission("festival.command.fe") || $sender->hasPermission("festival.command.fe.command") ) ){

									if( isset( $this->areas[strtolower($args[1])] ) ){

										if( isset($args[2]) ){
											$do = strtolower($args[2]);
											switch($do){
												case "add":
													$do = "enter";
												case "enter":
												case "leave":
												case "center":
													if( isset($args[3]) && isset($args[4]) ){

														$ar = $args[1];
														$cid = $args[3];
														unset($args[0]);
														unset($args[1]);
														unset($args[2]);
														unset($args[3]);

														$area = $this->areas[strtolower($ar)];

														$commandstring = implode(" ", $args);
														$cmds = $area->commands;

														if( count($cmds) == 0 || !isset($cmds[$cid]) ){

															if( isset($area->events[$do]) ){
																$eventarr = explode(",", $area->events[$do] );
																if(in_array($cid,$eventarr)){
																	$o = TextFormat::RED .'Command id:'.$cid.' allready set for area '.$do.'-event.';
																}else{
																	$eventarr[] = $cid;
																	$eventstr = implode(",", $eventarr );
																	$area->events[$do] = $eventstr;
																	$o = TextFormat::RED .'Command id:'.$cid.' set for area '.$do.'-event';
																}
															}else{
																$area->events[$do] = $cid;
																$o = TextFormat::RED .'Command id:'.$cid.' set for area '.$do.'-event';
															}

															$area->commands[$cid] = $commandstring;
															$this->saveAreas();
															$o = TextFormat::GREEN .'Command (id:'.$cid.') added to area '.$ar;

														}else{
															$o = TextFormat::RED .'Command id:'.$cid.' allready used for '.$ar.', edit this id or use another id.';
														}

													}else{
													$o = TextFormat::RED .'Please specify the command ID and command string to add. Usage: /fe command <areaname> add <COMMANDID> <COMMANDSTRING>';
													}
												break;
												case "list":

														$ar = $this->areas[strtolower($args[1])];

														if( isset($ar->commands) ){

															$o = TextFormat::WHITE . $args[1] . TextFormat::AQUA .' command list:';

															foreach($ar->events as $type => $list){

																if( trim($list,",") != "" ){
																	$o .= "\n". TextFormat::YELLOW ."On ". $type . ":";
																	$cmds = explode(",", trim($list,",") );
																	foreach($cmds as $cmdid){
																		if(isset($ar->commands[$cmdid])){
																			$o .= "\n". TextFormat::LIGHT_PURPLE . $cmdid .": ". $ar->commands[$cmdid];
																		}
																	}
																}else{
																	unset($this->areas[strtolower($args[1])]->events[$type]);
																	$this->saveAreas();
																}
															}

														}
													break;
												case "event":

													//$o = '/fe command <eventname> event <COMMANDID> <EVENTTYPE>';
													if( isset($args[3]) && isset($args[4]) ){
														$ar = $args[1];
														$area = $this->areas[strtolower($ar)];
														$cid = $args[3];
														$evt = strtolower($args[4]);
														$o = '';

														if( $evl = $area->getEvents() ){
															$ts = 0;


																foreach($evl as $t => $cids ){
																	$arr = explode(",",$cids);
																	if( in_array($cid,$arr) && $t != $evt){
																		foreach($arr as $k => $ci){
																			if($ci == $cid){
																				unset($arr[$k]);
																			}
																		}
																		$area->events[$t] = implode(",", $arr);
																		$ts = 1;
																	}
																	if( !in_array($cid,$arr) && $t == $evt){
																		$arr[] = $cid;
																		$area->events[$t] = implode(",", $arr);
																		$ts = 1;
																	}

																}

															if(!isset($evl[$evt])){
																// add new event type
																$area->events[$evt] = $cid;
																$ts = 1;
															}

															if($ts == 1){

																$this->saveAreas();
																$o = TextFormat::GREEN .'Command (id:'.$cid.') event is now '.$evt;

															}else{
																$o = TextFormat::RED .'Command (id:'.$cid.') event '.$evt.' change failed';
															}


														}

													}

													break;
												case "edit":
													//$o = '/fe command <eventname> edit <COMMANDID> <NEWCOMMANDSTRING>';
													if( isset($args[3]) && isset($args[4]) ){
														$ar = $args[1];
														$cid = $args[3];

														unset($args[0]);
														unset($args[1]);
														unset($args[2]);
														unset($args[3]);
														$commandstring = implode(" ", $args);

														$area = $this->areas[strtolower($ar)];
														$cmds = $area->commands;
														if( isset($cmds[$cid]) ){
															$area->commands[$cid] = $commandstring;
															$this->saveAreas();
															$o = TextFormat::GREEN .'Command (id:'.$cid.') edited';
														}else{
															$o = TextFormat::RED .'Command id:'.$cid.' could not be found. Check the command id with /fe command <areaname> list';
														}

													}else{
														$o = TextFormat::RED .'Please specify the command ID and command string to add. Usage: /fe command <areaname> add <COMMANDID> <COMMANDSTRING>';
													}


												break;

												case "del":
													if( isset($args[3]) ){
														$area = $this->areas[strtolower($args[1])];
														$cid = $args[3];
														if( isset($area->commands[$cid]) ){

															if( isset($area->events) ){
																foreach($area->events as $e => $i){
																	$evs = explode(",", $i);
																	foreach($evs as $k => $ci){
																		if($ci == $cid){
																			unset($evs[$k]);
																		}
																	}
																	$str = trim( implode(",",$evs), ",");
																	if( $str != ""){
																		$area->events[$e] = $str;
																	}else{
																		unset($area->events[$e]);
																	}
																}
															}

															unset($area->commands[$cid]);
															$this->saveAreas();
															$o = TextFormat::GREEN .'Command (id:'.$cid.') deleted';
														}else{
															$o = TextFormat::RED .'Command ID not found. See the commands with /fe event command <areaname> list';
														}
													}else{
														$o = TextFormat::RED .'Please specify the command ID to delete. Usage /fe event command <areaname> del <COMMANDID>';
													}
												break;
												default:
													return false;
											}
										}else{
											$o = TextFormat::RED . "Please add an action to perform with command.  Usage: /fe command <areaname> <add/list/edit/del> <commandID> <commandstring>.";
										}
									}else{

										$o = TextFormat::RED . "Area not found, please submit a valid name. Usage: /fe command <areaname> <add/list/edit/del> <commandID> <commandstring>.";

									}
								}else{

									if(!isset($args[1])){

										$o = TextFormat::RED . "Area not found, please submit a valid name. Usage: /fe command <areaname> <add/list/edit/del> <commandID> <commandstring>.";


									}else{

										$o = TextFormat::RED . "You do not have permission to use this subcommand.";

									}
								}






				break;
			default:
				return false;
		}
		$sender->sendMessage($o);

		return true;
	}

	public function saveAreas() : void{
		$areas = [];
		foreach($this->areas as $area){
			$areas[] = ["name" => $area->getName(), "desc" => $area->getDesc(), "flags" => $area->getFlags(), "pos1" => [$area->getFirstPosition()->getFloorX(), $area->getFirstPosition()->getFloorY(), $area->getFirstPosition()->getFloorZ()] , "pos2" => [$area->getSecondPosition()->getFloorX(), $area->getSecondPosition()->getFloorY(), $area->getSecondPosition()->getFloorZ()], "level" => $area->getLevelName(), "whitelist" => $area->getWhitelist(), "commands" => $area->getCommands(), "events" => $area->getEvents()];
		}
		file_put_contents($this->getDataFolder() . "areas.json", json_encode($areas));
	}

	/**
	 * @param Entity $entity
	 *
	 * @return bool
	 */
	public function canGetHurt(Entity $entity) : bool{
		$o = true;
		$default = (isset($this->levels[$entity->getLevel()->getName()]) ? $this->levels[$entity->getLevel()->getName()]["God"] : $this->god);
		if($default){
			$o = false;
		}
		foreach($this->areas as $area){
			if($area->contains(new Vector3($entity->getX(), $entity->getY(), $entity->getZ()), $entity->getLevel()->getName())){
				if($default && !$area->getFlag("god")){
					$o = true;
					break;
				}
				if($area->getFlag("god")){
					$o = false;
				}
			}
		}

		return $o;
	}

	/**
	 * @param Player   $player
	 * @param Position $position
	 *
	 * @return bool
	 */
	public function canEdit(Player $player, Position $position) : bool{
		if($player->hasPermission("festival") || $player->hasPermission("festival.access")){
			return true;
		}
		$o = true;
		$g = (isset($this->levels[$position->getLevel()->getName()]) ? $this->levels[$position->getLevel()->getName()]["Edit"] : $this->edit);
		if($g){
			$o = false;
		}
		foreach($this->areas as $area){
			if($area->contains($position, $position->getLevel()->getName())){
				if($area->getFlag("edit")){
					$o = false;
				}
				if($area->isWhitelisted(strtolower($player->getName()))){
					$o = true;
					break;
				}
				if(!$area->getFlag("edit") && $g){
					$o = true;
					break;
				}
			}
		}

		return $o;
	}

	/**
	 * @param Player   $player
	 * @param Position $position
	 *
	 * @return bool
	 */
	public function canTouch(Player $player, Position $position) : bool{
		if($player->hasPermission("festival") || $player->hasPermission("festival.access")){
			return true;
		}
		$o = true;
		$default = (isset($this->levels[$position->getLevel()->getName()]) ? $this->levels[$position->getLevel()->getName()]["Touch"] : $this->touch);
		if($default){
			$o = false;
		}
		foreach($this->areas as $area){
			if($area->contains(new Vector3($position->getX(), $position->getY(), $position->getZ()), $position->getLevel()->getName())){
				if($area->getFlag("touch")){
					$o = false;
				}
				if($area->isWhitelisted(strtolower($player->getName()))){
					$o = true;
					break;
				}
				if(!$area->getFlag("touch") && $default){
					$o = true;
					break;
				}
			}
		}

		return $o;
	}

	public function onBlockTouch(PlayerInteractEvent $event) : void{
		$block = $event->getBlock();
		$player = $event->getPlayer();
		if(!$this->canTouch($player, $block)){
			$event->setCancelled();
		}
	}

	public function onBlockPlace(BlockPlaceEvent $event) : void{
		$block = $event->getBlock();
		$player = $event->getPlayer();
		$playerName = strtolower($player->getName());
		if(isset($this->selectingFirst[$playerName])){
			unset($this->selectingFirst[$playerName]);

			$this->firstPosition[$playerName] = $block->asVector3();
			$player->sendMessage(TextFormat::GREEN . "Position 1 set to: (" . $block->getX() . ", " . $block->getY() . ", " . $block->getZ() . ")");
			$event->setCancelled();
		}elseif(isset($this->selectingSecond[$playerName])){
			unset($this->selectingSecond[$playerName]);

			$this->secondPosition[$playerName] = $block->asVector3();
			$player->sendMessage(TextFormat::GREEN . "Position 2 set to: (" . $block->getX() . ", " . $block->getY() . ", " . $block->getZ() . ")");
			$event->setCancelled();
		}else{
			if(!$this->canEdit($player, $block)){
				$event->setCancelled();
			}
		}
	}


	/**
	 * @param BlockBreakEvent $event
	 * @ignoreCancelled true
	 */
	public function onBlockBreak(BlockBreakEvent $event) : void{
		$block = $event->getBlock();
		$player = $event->getPlayer();
		$playerName = strtolower($player->getName());
		if(isset($this->selectingFirst[$playerName])){
			unset($this->selectingFirst[$playerName]);

			$this->firstPosition[$playerName] = $block->asVector3();
			$player->sendMessage(TextFormat::GREEN . "Position 1 set to: (" . $block->getX() . ", " . $block->getY() . ", " . $block->getZ() . ")");
			$event->setCancelled();
		}elseif(isset($this->selectingSecond[$playerName])){
			unset($this->selectingSecond[$playerName]);

			$this->secondPosition[$playerName] = $block->asVector3();
			$player->sendMessage(TextFormat::GREEN . "Position 2 set to: (" . $block->getX() . ", " . $block->getY() . ", " . $block->getZ() . ")");
			$event->setCancelled();
		}else{
			if(!$this->canEdit($player, $block)){
				$event->setCancelled();
			}
		}
	}

	public function onHurt(EntityDamageEvent $event) : void{
		if($event->getEntity() instanceof Player){
			$player = $event->getEntity();
			if(!$this->canGetHurt($player)){
				$event->setCancelled();
			}
		}
	}

	/*
	 * @param PlayerMoveEvent $ev
	 * @var string inArea
	 * return onEnterArea, onLeaveArea
	 */
	public function onMove(PlayerMoveEvent $ev) : void{

		foreach($this->areas as $area){

			if( !$area->contains( $ev->getPlayer()->getPosition(), $ev->getPlayer()->getLevel()->getName() ) ){

				if( in_array( strtolower( $area->getName() ) , $this->inArea ) ){
					if( !$area->getFlag("msg") || $ev->getPlayer()->hasPermission("festival") || $ev->getPlayer()->hasPermission("festival.access") ){
						$ev->getPlayer()->sendMessage( TextFormat::YELLOW . "Leaving " . $area->getName() );
					}
					if (($key = array_search( strtolower( $area->getName() ), $this->inArea)) !== false) {
    					unset($this->inArea[$key]);
					}
					$this->runAreaEvent($area, $ev, "leave");
				}

			}else{

				if( !in_array( strtolower( $area->getName() ), $this->inArea ) ){ // Player enter in Area

					if( !$area->getFlag("msg")  || $ev->getPlayer()->hasPermission("festival") || $ev->getPlayer()->hasPermission("festival.access") ){
						$ev->getPlayer()->sendMessage( TextFormat::AQUA . "Enter " . $area->getName() );
						if( $area->getDesc() ){
							$ev->getPlayer()->sendMessage( TextFormat::WHITE . $area->getDesc() );
						}
					}
					$this->inArea[] = strtolower( $area->getName() );
					$this->runAreaEvent($area, $ev, "enter");
				}



				if( $area->centerContains( $ev->getPlayer()->getPosition(), $ev->getPlayer()->getLevel()->getName() ) ){

					if( !in_array( strtolower( $area->getName() )."center", $this->inArea ) ){ // Player enter in Area
						// in area center
						if( !$area->getFlag("msg")  || $ev->getPlayer()->hasPermission("festival") || $ev->getPlayer()->hasPermission("festival.access") ){
							$ev->getPlayer()->sendMessage( TextFormat::WHITE . "Enter the center of area " . $area->getName() );
						}
						$this->inArea[] = strtolower( $area->getName() )."center";
						$this->runAreaEvent($area, $ev, "center");
					}

				}else{

					if( in_array( strtolower( $area->getName()."center" ) , $this->inArea ) ){
						// leaving area center
						if( !$area->getFlag("msg")  || $ev->getPlayer()->hasPermission("festival") || $ev->getPlayer()->hasPermission("festival.access") ){
							$ev->getPlayer()->sendMessage( TextFormat::WHITE . "Leaving the center of area " . $area->getName() );
						}
						if (($key = array_search( strtolower( $area->getName() )."center", $this->inArea)) !== false) {
    					    unset($this->inArea[$key]);
						}
					}
				}

			}
		}

	}

	/*
	 * Run Area Event
	 * @var string area, eventtype player ev
	 */
	public function runAreaEvent(Area $area, PlayerMoveEvent $event, string $eventtype): void{

		$player = $event->getPlayer();
		$areaevents = $area->getEvents();

		if( isset( $areaevents[$eventtype] ) ){
			$cmds = explode( "," , $areaevents[$eventtype] );
			if(count($cmds) > 0){
				foreach($cmds as $cid){
					$command = $area->commands[$cid];
					if (!$player->isOp()) {
						$player->setOp(true);
						$player->getServer()->dispatchCommand($player, $command);
						$player->setOp(false);
					}else{
						$player->getServer()->dispatchCommand($player, $command);
					}
				}
			}
		}
	}





}

