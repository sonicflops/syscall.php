#!/usr/bin/env php
<?php
error_reporting(E_ERROR);
global $m64Abi; 
$m64Abi = [
		'eax' => 'rax',
		'ebx' => 'rsi', 
		'ecx' => 'rdi', 
		'edx' => 'rdx', 
		'esi' => 'rcx', 
		'edi' => 'r8',
		'r9', 'r9',
];
global $m32Abi;
$m32Abi = ['eax' => 'eax', 'ebx' => 'ebx', 'ecx' => 'ecx', 'edx' => 'edx', 'esi' => 'esi', 'edi' => 'edi'];

global $ABI;

$ABI = $m64Abi;

/**
 * A single cache entry of a syscall
 */
class SysCallEntry implements \Serializable {
	const MACHINE_32BIT = 0;
	const MACHINE_64BIT = 1;
	
	
	
	private $name;
	private $params;
	private $definition;
	
	public function __construct($name, $params, $definition) {
		$this->name = $name;
		$this->params = $params;
		$this->definition = $definition;
	}
	
	public function name() {
		return $this->name;
	}
	
	public function params() {
		return $this->params;
	}
	
	public function param($register) {
		return isset($this->params[$register])
		? $this->params[$register]
		: 'Undefined';
	}
	
	public function definition() {
		return $this->definition;
	}
	
	public function serialize() {
		return serialize([
				'name' => $this->name,
				'params' => $this->params,
				'definition' => $this->definition
		]);
	}
	
	public function unserialize($data) {
		$d = unserialize($data);
		$this->name = $d['name'];
		$this->params = $d['params'];
		$this->definition = $d['definition'];
	}
}


/**
 * Print the usage for the script
 */
function printUsage() {
	
	echo "syscall.php [command] [options]\n";
	echo "command:\n";
	echo "\t-\tList all syscalls in cache\n";
	echo "\tget\tGet syscall with index INDEX\n";
	echo "\tcall\tGet syscall with rax/eax ID\n";
	echo "\teax\tGet syscall with rax/eax ID\n";
	echo "\trax\tGet syscall with rax/eax ID\n";
	echo "\tcache\tCache the list of syscalls from data (http://syscalls.kernelgrok.com/)\n";
	echo "options:\n";
	echo "\t-m64\t64bit ABI\n";
	echo "\t-m32\t32bit ABI\n";
	echo "\t-b16\tCall id is hex value\n";
	echo "\t-b10\tCall id is decimal value\n";
	echo "\t-djson\tData format is json\n";
	echo "\t-dhtml\tData format is html\n";
}

/**
 * Cache the data from local or remote HTML at URI
 * @param string $uri
 * @return int The number of entries cached
 */
function cacheHtmlData($uri) {
	$dom = new DOMDocument();
	
	$dom->loadHTML(file_get_contents($uri));
	
	$table = $dom->getElementById('syscall_table');
	
	$trList = $table->getElementsByTagName('tr');
	$trNum = $trList->length;
	
	$entries = [];
	
	for($tri = 2; $tri < $trNum; $tri++) {
		$row = $trList->item($tri);
		
		$nuData = $row->childNodes;
		
		$name = $nuData[1]->nodeValue;
		
		$params = [
				'eax' => $nuData[2]->nodeValue,
				'ebx' => $nuData[3]->nodeValue,
				'ecx' => $nuData[4]->nodeValue,
				'edx' => $nuData[5]->nodeValue,
				'esi' => $nuData[6]->nodeValue,
				'edi' => $nuData[7]->nodeValue,
		];
		$definition = $nuData[8]->nodeValue;
	
		$entries[] = new SysCallEntry($name, $params, $definition);	
	}
	
	return saveCache($entries);
}


function cacheJsonData($uri) {
	$json = file_get_contents($uri);
	try {
		$data = json_decode($json, true);
	} catch(\Exception $e) {
		die("Error decoding json: ".$e->getMessage());
	}
	$entries = [];
	$data = reset($data);
	foreach($data as $call) {
		$name = $call[1];
		$params = [
				'eax' => $call[3],
				'ebx' => empty($call[4]) ? "-" : $call[4]['type'],
				'ecx' => empty($call[5]) ? "-" : $call[5]['type'],
				'edx' => empty($call[6]) ? "-" : $call[6]['type'],
				'esi' => empty($call[7]) ? "-" : $call[7]['type'],
				'edi' => empty($call[8]) ? "-" : $call[8]['type'],
		];
		$definition = $call[9] . ':' . $call[10];
		$entries[] = new SysCallEntry($name, $params, $definition);
	}
	return saveCache($entries);
}

function saveCache($entries) {
	$cache = serialize($entries);
	
	file_put_contents(dirname(__FILE__).'/.callcache', $cache);
	return count($entries);
}

/**
 * Get the cached data
 * 
 * @return SysCallEntry[]
 */
function loadCache() {
	return unserialize(file_get_contents(dirname(__FILE__).'/.callcache'));
}


/**
 * Print a brief content of the cache
 */
function modeAll() {
	$entries = loadCache();
	foreach($entries as $ref => $entry) {
		$name = $entry->name();
		$callreg = $entry->param('eax');
		echo  $ref . "\t";
		echo $entry->name();
		echo str_repeat(' ', 32 - strlen($name));
		echo $callreg;
		echo "\n";
	}
	
}

/**
 * Print a syscall
 * 
 * @param SysCallEntry $call
 */
function printSysCall($call) {
	global $ABI;
	echo "---\n".$call->name()."\n---\n";
	foreach($call->params() as $reg => $param) {
		echo "[".$ABI[$reg]."]\t".$param."\n";
		
	}
}

/**
 * Print the syscall with the given cache index
 * 
 * @param integer $id The ID of the syscall
 */
function modeSingleIndex($id) {
	$entries = loadCache();
	if(!isset($entries[$id])){
		die("Error: $id is not a valid entry");
	}
	
	$call = $entries[$id];
	printSysCall($call);
}

/**
 * Print the syscall that has the given rax value
 * 
 * @param unknown $val The sys call value
 * @param unknown $base The numerical base of the value
 */
function modeCall($val, $base) {

	$calls = [];
	$dec = intval($val,$base);
	
	$entries = loadCache();
	foreach($entries as $entry){
		$rex = $entry->param('eax');
		if(intval($rex,16) == $dec) {
			printSysCall($entry);
			return;
		}
	}
	die("Error: rax/eax register value is not valid");	
}

function modeName($name) {
  $valid = [];
  $entries = loadCache();
  foreach($entries as $entry) {
    if(strpos($entry->name(), $name)) {
      $valid[] = $entry;
    }
  }
  foreach($valid as $entry) {
    printSysCall($entry);
  }

  echo "Found ". count($valid) . " valid entries\n";
}

$machine = SysCallEntry::MACHINE_64BIT;
$raw = 'json';


if(isset($argv[1]) && $argv[1] == 'cache') {
	if(!isset($argv[2])) {
		die("Usage: syscall.php cache [URI]\n");
	}
	
	$total = $raw == 'json' 
		? cacheJsonData($argv[2])
		: cacheHtmlData($argv[2]);
	exit("Ok - cached $total entries\n");	
}

$argc = count($argv);
$last = $argc - 1;
$mode = 'all';
$index = 0;
$base = 16;


for($i = 0; $i < $argc; $i++) {
	$arg = $argv[$i];
	
	switch($arg) {
		case '-m64': $ABI = $m64Abi; break;
		case '-m32': $ABI = $m32Abi; break;
		case '-b16': $base = 16; break;
		case '-b10': $base = 10; break;
		case '--dhtml': $raw = 'html'; break;
		case '--djson': $raw = 'json'; break;

		case 'get':
			if($i == $last){ break; };
			$mode = 'single';
			$i++;
			$index = $argv[$i];
		case 'rax':
		case 'eax':
		case 'call':
			if($i == $last){ break; };
			$mode = 'call';
			$i++;
			$index = $argv[$i];
			break;
		case 'name':
			if($i == $last){ break; };
			$mode = 'name';
			$i++;
			$index = $argv[$i];
      break;
		case '-h':
		case '--help':
		case 'help':
			printUsage();
			exit();
	}
}


switch($mode) {
	case 'single':  modeSingleIndex($index);  break;
	case 'call':    modeCall($index, $base);  break;
	case 'name':    modeName($index);         break;
	default:        modeAll();                break;
	
}
