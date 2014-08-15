<?php

function get_arguments($argv) {
	$argv_length = count($argv);

	if ($argv_length == 2) {
		return array("", "", $argv[1]);
	}
	elseif ($argv_length == 4) {
		return array($argv[1], $argv[2], $argv[3]);
	}
	else {
		echo "Error: Passing number of console parameters error.\n";
		exit;
	}
}


function parse_config($config_file) {
	// read config file and pasing config contents.
	if (!is_file($config_file)) {
		echo "Error: " . $config_file . " is not a file.\n";
		exit;
	}

	$contents = file_get_contents($config_file);
	$comments = array(
		'/\/\\*.*?\\*\//s',
		'/(?<!:)\/\/.*/m'
	);
	$contents = preg_replace($comments, '', $contents);
	$configs = json_decode($contents, true);
	if (!$configs) {
		echo "Error: Parsing config file '" . $config_file . "' is failed.\n";
		exit;
	}

	return $configs;
}


function wilds_to_regex($wilds) {
	$char = array(".", "*");
	$reg_str = array("\.", ".*");
	return "/^(" . implode("|", str_replace($char, $reg_str, $wilds)) . ")$/";
}


function copy_dir($source, $target, $pattern) {

	if(!is_dir($source)) {
		echo "Error: Source is not a dir: " . $source . "\n";
		return 0;
	}

	if(!is_dir($target) && !mkdir($target)) {
		echo "Error: Creating dir is failed: " . $target . "\n";
		return 0;
	}

	$dir = opendir($source);
	while(false !== ($file = readdir($dir))) {

		// really copy below.
		if (($file != '.') && ($file != '..') && !preg_match($pattern, $file)) {
			$source_path = $source . DIRECTORY_SEPARATOR . $file;
			$target_path = $target . DIRECTORY_SEPARATOR . $file;
			if (is_dir($source_path)) {
				copy_dir($source_path, $target_path, $pattern);
			}
			else {
				copy($source_path, $target_path);
			}
		}
	}

	closedir($dir);
	return 1;
}


function get_scan_files($target_dir, $configs) {

	// canonicalized all scan include paths.
	chdir($target_dir);
	$paths = $configs["scan_include_paths"];
	$scan_include_paths = array();
	foreach ($paths as $path) {
		$real_path = realpath($path);
		if ($real_path) {
			$scan_include_paths[] = $real_path;
		}
	}

	// convert patterns' strings.
	$pattern = wilds_to_regex($configs["scan_include_patterns"]);

	// get all scan files.
	$scan_files = array();
	foreach ($scan_include_paths as $path) {
		if (is_file($path)) {
			$scan_files[] = $path;
		}
		elseif (is_dir($path)) {
			$scan_files = array_merge($scan_files, get_scan_dir_files($path, $pattern));
		}
	}

	return array_unique($scan_files);
}


// get files inside directory, filtered by pattern.
function get_scan_dir_files($path, $pattern) {
	$files = array();
	$dir = opendir($path);
	while(false !== ($file = readdir($dir))) {
		if (($file != '.') && ($file != '..')) {
			$current = $path . DIRECTORY_SEPARATOR . $file;
			if (is_dir($current)) {
				$files = array_merge($files, get_scan_dir_files($current, $pattern));
			}
			elseif (is_file($current) && preg_match($pattern, $file)) {
				$files[] = $current;
			}
		}
	}

	closedir($dir);
	return $files;
}


// get url from a specified file.
function get_urls($file, $pattern) {

	$contents = file_get_contents($file);
	if (!preg_match_all($pattern, $contents, $matches)) {
		return null;
	}


	$urls = array();
	$length = count($matches);

	for ($i = 1; $i < $length; $i++) {
		$item_length = count($matches[$i]);

		if ($item_length > 0) {
			for ($j = 0; $j < $item_length; $j++) {
				if ($matches[$i][$j] != "") {
					$urls[] = $matches[$i][$j];
				}
			}
		}
	}

	return $urls;
}


// convert url to local file path.
function get_local_files($url_path, $local_path, $urls) {
	$local_files = array();
	$mapping_files = str_replace($url_path, $local_path, $urls);

	$length = count($mapping_files);
	for ($i = 0; $i < $length; $i++) {
		$local_file = realpath($mapping_files[$i]);
		if ($local_file) {
			$local_files[] = $local_file;
		}
	}

	return $local_files;
}


// get build info from file list.
function get_build_info($files, $configs) {
	// url mapping prepare.
	$url_path = array();
	$local_path = array();

	$length = count($configs["url_mapping"]);
	for ($i = 0; $i < $length; $i++) {
		$url_path[] = $configs["url_mapping"][$i]["url_path"];
		$local_path[] = $configs["url_mapping"][$i]["local_path"];
	}


	// script and link patterns.
	$url_patterns = array(
		'(?:<\\s*script[^>]+src\\s*=\\s*["\']?\\s*([^\\s"\'>]+)[^>]*data-build-id\\s*=\\s*["\']?\\s*{id}\\s*["\']?[^>]*>\\s*<\\s*\/\\s*script\\s*>)',
		'(?:<\\s*script[^>]+data-build-id\\s*=\\s*["\']?\\s*{id}\\s*["\']?[^>]+src\\s*=\\s*["\']?\\s*([^\\s"\'>]+)[^>]*>\\s*<\\s*\/\\s*script\\s*>)',
		'(?:<\\s*link[^>]+href\\s*=\\s*["\']?\\s*([^\\s"\']+)[^>]*data-build-id\\s*=\\s*["\']?\\s*{id}\\s*["\']?[^>]*>)',
		'(?:<\\s*link[^>]+data-build-id\\s*=\\s*["\']?\\s*{id}\\s*["\']?[^>]+href\\s*=\\s*["\']?\\s*([^\\s"\']+)[^>]*>)'
		);
	$url_pattern = '/' . implode("|", $url_patterns) . '/i';


	// html replacement pattern for js or css.
	$replace_pattern = '/<!--\\s*{id}\\s*-->/i';

	// prepare build items info array.
	$build_info = array();
	$length = count($configs["scan_to_build"]);

	for ($i = 0; $i < $length; $i++) {
		$build_info[$i] = $configs["scan_to_build"][$i];

		// genertate url_pattern and replace_pattern.
		$id = str_replace(".", "\.", $build_info[$i]["id"]);
		$build_info[$i]["url_pattern"] = str_replace("{id}", $id, $url_pattern);
		$build_info[$i]["replace_pattern"] = str_replace("{id}", $id, $replace_pattern);

		// get all urls relatived with each build id.
		$urls = array();
		$inside_files = array();

		foreach ($files as $file) {
			$file_urls = get_urls($file, $build_info[$i]["url_pattern"]);
			if ($file_urls) {
				$urls = array_merge($urls, $file_urls);
				$inside_files[] = $file;
			}
		}

		// "urls" for analysing urls log.
		$build_info[$i]["urls"] = array_unique($urls);

		// local css or js piece files should combinate together.
		$build_info[$i]["files"] = get_local_files($url_path, $local_path, $build_info[$i]["urls"]);

		// html files which containing css or js piece files.
		$build_info[$i]["inside_files"] = $inside_files;
	}

	return $build_info;
}


// normalize virtual path.
function normalize_path($path) {
	// array to build a new path from the good parts
	$parts = array();
	// replace backslashes with forwardslashes
	$path = str_replace('\\', '/', $path);
	// combine multiple slashes into a single slash
	$path = preg_replace('/\/+/', '/', $path);
	// collect path segments
	$segments = explode('/', $path);
	$test = '';

	// initialize testing variable
	foreach($segments as $segment) {
		if($segment != '.') {
			$test = array_pop($parts);

			if(is_null($test)) {
				$parts[] = $segment;
			}
			else if($segment == '..') {
				if($test == '..')
					$parts[] = $test;

				if($test == '..' || $test == '')
					$parts[] = $segment;
			}
			else {
				$parts[] = $test;
				$parts[] = $segment;
			}
		}
	}

	return implode(DIRECTORY_SEPARATOR, $parts);
}


// genertate relative url path for css refer with images.
function relative_url_path($from, $to) {
	// splite $from and $to path.
	$from_segments = explode(DIRECTORY_SEPARATOR, rtrim($from, DIRECTORY_SEPARATOR));
	$to_segments = explode(DIRECTORY_SEPARATOR, rtrim($to, DIRECTORY_SEPARATOR));

	while(count($from_segments) && count($to_segments) && ($from_segments[0] == $to_segments[0])) {
		array_shift($from_segments);
		array_shift($to_segments);
	}

	return str_pad("", (count($from_segments) - 1) * 3, "../") . implode("/", $to_segments);
}



// build a combo file contains piece files.
function build_file($files, $target) {
	// create dir first.
	$path = dirname($target);
	if(!is_dir($path) && !mkdir($path, 0777, true)) {
		echo "Error: Creating dir is failed: " . $path . "\n";
		return;
	}

	// create the combo file first.
	$handle = fopen($target, "w");
	$target = realpath($target);

	// put files contents together.
	$length = count($files);
	for ($i = 0; $i < $length; $i++) {
		$contents = file_get_contents($files[$i]);

		// replace url path, css file only.
		if (preg_match('/.+\\.css$/i', $files[$i])) {
			$file = $files[$i];

			// (?:url\(\s*["']?\s*)(?!https?://|/|data:)(.+?)(?:\s*["']?\s*\))
			$contents = preg_replace_callback('/(?:url\\(\\s*["\']?\\s*)(?!https?:\/\/|\/|data:)(.+?)(?:\\s*["\']?\\s*\\))/i',
				function($matches) use($file, $target) {

					// convert url() path from source file to new target file.
					$url_local = normalize_path(dirname($file) . DIRECTORY_SEPARATOR . $matches[1]);
					return "url(" . relative_url_path($target, $url_local) . ")";

				}, $contents);
		}

		// Write $somecontent to our opened file.
		if (fwrite($handle, $contents) === FALSE) {
			fclose($handle);
			echo "Error: Can't' write to file " . $files[$i] . "\n";
			return;
		}
	}
	fclose($handle);

	return $target;
}


// build files by build info.
function build_files($build_info) {
	$files = array();

	$length = count($build_info);
	for ($i = 0; $i < $length; $i++) {
		if (count($build_info[$i]["files"])) {
			$files[]= build_file($build_info[$i]["files"], $build_info[$i]["path"]);
		}
	}

	return $files;
}


// compress js or css files.
function compress($files, $script_dir) {
	$commands = array();
	$cmd_template = 'java -jar ' . $script_dir . 'yuicompressor.jar --charset utf-8 "{file}" -o "{file}"';

	$length = count($files);
	for ($i = 0; $i < $length; $i++) {
		$command = str_replace("{file}", $files[$i], $cmd_template);
		exec($command);

		$commands[] = $command;
	}

	return $commands;
}


// remove "data-build-id={id}" tags scaned before.
function remove_build_tags($build_info) {
	$files = array();

	$length = count($build_info);
	for ($i = 0; $i < $length; $i++) {
		$info = $build_info[$i];

		$file_length = count($info["inside_files"]);
		for ($j = 0; $j < $file_length; $j++) {
			$file = $info["inside_files"][$j];
			$files[] = $file;

			$contents = file_get_contents($file);
			$contents = preg_replace($info["url_pattern"], '', $contents);
			file_put_contents($file, $contents);
		}
	}

	return array_unique($files);
}


// replace "<!-- {id} -->"" comment tag placeholder.
function replace_comment_tags($scan_files, $build_info) {
	$files = array();

	$length = count($build_info);
	for ($i = 0; $i < $length; $i++) {
		$pattern = $build_info[$i]["replace_pattern"];
		$html = $build_info[$i]["html"];

		foreach ($scan_files as $file) {

			$contents = file_get_contents($file);
			if (preg_match($pattern, $contents)) {
				$contents = preg_replace($pattern, $html, $contents);
				file_put_contents($file, $contents);

				$files[] = $file;
			}
		}
	}

	return array_unique($files);
}

// generate log string.
function get_logs($title, $items) {
	$logs  = $title . "\n";
	$logs .= "-------------------------------------------\n";
	foreach ($items as $value) {
		$logs .= $value . "\n";
	}
	$logs .= "\n\n\n\n";

	return $logs;
}


/************************/
/*  Main Processing     */
/************************/
$script_dir = realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR;

// get console parameters.
$arguments = get_arguments($argv);
$source_dir = $arguments[0];
$target_dir = $arguments[1];
$config_file = $arguments[2];


// parsing config json file.
$configs = parse_config($config_file);


// check parameters.
if ($source_dir == "") {
	$source_dir = isset($configs["source_dir"]) ? $configs["source_dir"] : "";
}
if ($target_dir == "") {
	$target_dir = isset($configs["target_dir"]) ? $configs["target_dir"] : "";
}
if ($source_dir == "") {
	echo "Error: Missing source dir.\n";
	exit;
}
if ($target_dir == "") {
	echo "Error: Missing target dir.\n";
	exit;
}


// recursive copy source dir to target dir.
chdir(realpath(dirname($config_file)));
copy_dir($source_dir, $target_dir, wilds_to_regex($configs["copy_exclude_patterns"]));


$logs  = "-------------------------------------------\n";
$logs .= "Build Report Logs.\n";
$logs .= "-------------------------------------------\n\n\n\n";


// generate scan file list.
$scan_files = get_scan_files($target_dir, $configs);
$logs .= get_logs("Scaning file list", $scan_files);


// generate build info.
$build_info = get_build_info($scan_files, $configs);
$logs .= "Prepare build Info\n";
$logs .= "-------------------------------------------\n";
ob_start();
var_dump($build_info);
$logs .= ob_get_clean();
$logs .= "\n\n\n\n";


// build files by build info.
$build_files = build_files($build_info);
$logs .= get_logs("Build file list", $build_files);


// compress js or css files.
$commands = compress($build_files, $script_dir);
$logs .= get_logs("Compress command list", $commands);


// remove build tags inside html files by build info
$remove_tags_files = remove_build_tags($build_info);
$logs .= get_logs("Remove tags file list", $remove_tags_files);


// replace comments placeholder for combo html tags.
$replace_tags_files = replace_comment_tags($scan_files, $build_info);
$logs .= get_logs("Replace tags file list", $replace_tags_files);


// record to log file.
file_put_contents($script_dir . "wopt.log", $logs);
