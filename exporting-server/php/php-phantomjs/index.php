<?php
/**
 * This file is part of the exporting module for Highcharts JS.
 * www.highcharts.com/license
 *
 * !!!  TODO: make this list complete !!!!!!
 * $filename  string   The desired filename without extension
 * $type      string   The MIME type for export.
 * $width     int      The pixel width of the exported raster image. The height is calculated.
 * $svg       string   The SVG source code to convert.
 */

///////////////////////////////////////////////////////////////////////////////
define ('PHANTOMJS_PATH', '/usr/local/bin/phantomjs');
define ("PHANTOMJS_SCRIPT", 'highcharts-convert.js');

function strip_magic_slashes($str)
{
	return get_magic_quotes_gpc() ? stripslashes($str) : $str;
}

function execute($cmd){
	$descriptors = array(
			0 => array('pipe', 'r'),  // stdin
			1 => array('pipe', 'w'),  // stdout
			2 => array('pipe', 'w')   // stderr
	);
	$stdout = '';	
	$proc = proc_open($cmd, $descriptors,$pipes);

	if (!is_resource($proc)) {
		throw new \Exception('Could not execute process');
	}

	// Set the stdout stream to none-blocking.
	stream_set_blocking($pipes[1], 0);

	$timeout = 3000; // miliseconds.
	$forceKill = true;	

	while ($timeout > 0) {
		$start = round(microtime(true) * 1000);

		// Wait until we have output or the timer expired.
		$status = proc_get_status($proc);
		$read = array($pipes[1]);
		stream_select($read, $other, $other, 0, $timeout);

		$stdout .= stream_get_contents($pipes[1]);

		if (!$status['running']) {
			// Break from this loop if the process exited before the timeout.
			$forceKill = false;
			break;
		}

		// Subtract the number of microseconds that we waited.
		$timeout -= round(microtime(true) * 1000) - $start;
	}

	if($forceKill == true) {
		$ppid = $status['pid'];
		echo "killing overexecuted process \n";
		//use ps to get all the children of this process, and kill them
		$pids = preg_split('/\s+/', `ps -o pid --no-heading --ppid $ppid`);
		foreach($pids as $pid) {
			if(is_numeric($pid)) {
				posix_kill($pid, 9); //9 is the SIGKILL signal
			}
		}
	}

	return $stdout;
}

if( ! is_executable (PHANTOMJS_PATH)){
	die("PhantomJs path: '" . PHANTOMJS_PATH . "' does not appear to be executable.  Please set it properly in '"  . PHP_EOL);
}

$svg = strip_magic_slashes($_POST['svg']);
$type = strip_magic_slashes($_POST['type']);
$options = strip_magic_slashes((string) $_POST['options']);
$filename = strip_magic_slashes((string) $_POST['filename']);
$callback = strip_magic_slashes((string) $_POST['callback']);
$cmd = "";

// prepare variables
if (empty($filename)) {
	$filename = 'chart';
}

$tmpName = md5(rand());

// define infile name
if(empty($options) || $options == '') {
	$infile = "tmp/$tmpName.svg";
	//check for malicious attack in SVG
	if(strpos($svg,"<!ENTITY") !== false) {
		// TODO: THis was an exploit in Apache/Batik. Possibly better to remove this in Phantom.js?
		exit("Execution is stopped, the posted SVG could contain code for a mailcious attack");
	}
	$filecontent = $svg;
} else {
	$infile = "tmp/$tmpName.json";
	$filecontent = $options;
}

$cmd = "$cmd -infile $infile";

if(! empty($callback)){
	$callbackFile = "tmp/$tmpName.cb.js";
	$cmd = "$cmd -callback $callbackFile";
}

// allow no other than predefined types, default = 'png'
if ($type == 'image/jpeg') {
	$ext = 'jpg';
} elseif ($type == 'application/pdf') {
	$ext = 'pdf';

} elseif ($type == 'image/svg+xml') {
	$ext = 'svg';
} else {
	$type == 'image/png';
	$ext = 'png';
}

$outfile = "tmp/$tmpName.$ext";
$cmd = "$cmd -outfile $outfile";

if (isset($ext) && (empty($options) && $ext == 'svg') == 0) {
	// width
	if ($_POST['width']) {
		$width = "-width " . (int)$_POST['width'];
	}

	if ($_POST['scale']) {
		$scale = "-scale " . (double)$_POST['scale'];
	}

	// constructor
	if ($_POST['constr']) {
		$constr = "-constr " . (string)$_POST['constr'];
	} else {
		$constr = "-constr Chart";
	}

	// stripslashes and generate the temporary file
	if (get_magic_quotes_gpc()) {
		$filecontent = stripslashes($filecontent);
	}

	if (!file_put_contents($infile, $filecontent)) {
		die("Couldn't create temporary file. Check that the directory permissions for
				the /tmp directory are set to 777.");
	}

	if (!empty($callback) && !file_put_contents("tmp/$tmpName.cb.js", $callback)) {
		die("Couldn't create temporary file. Check that the directory permissions for
				the /tmp directory are set to 777.");
	}

	// do the conversion
	/*Usage: 'Usage: highcharts-convert.js -infile URL -outfile filename
	-scale 2.5 -width 300 -constr Chart -callback callback.js'*/
	$command = PHANTOMJS_PATH . " " . PHANTOMJS_SCRIPT . " $cmd $width $scale $constr";
	$output = execute($command);

	// catch error
	if (!is_file($outfile) || filesize($outfile) < 10) {
		echo "<pre>$output</pre>";
		echo "Error while converting SVG. ";
		// TODO: add debug message for javascript .. not only for svg
		if (strpos($output, 'SVGConverter.error.while.rasterizing.file') !== false) {
			echo "
			<h4>Debug steps</h4>
			<ol>
			<li>Copy the SVG:<br/><textarea rows=5>" . htmlentities(str_replace('>', ">\n", $filecontent)) . "</textarea></li>
			<li>Go to <a href='http://validator.w3.org/#validate_by_input' target='_blank'>validator.w3.org/#validate_by_input</a></li>
			<li>Paste the SVG</li>
			<li>Click More Options and select SVG 1.1 for Use Doctype</li>
			<li>Click the Check button</li>
			</ol>";
		}
	}
	// stream it
	else {
		header("Content-Disposition: attachment; filename=\"$filename.$ext\"");
		header("Content-Type: $type");
		echo file_get_contents($outfile);
	}

	// delete it
	unlink($infile);
	unlink($outfile);
	if (!empty($callback)){
		unlink($callbackFile);
	}

	// SVG can be streamed directly back
} else if ($ext == 'svg' && empty($options)) {
	header("Content-Disposition: attachment; filename=\"$filename.$ext\"");
	header("Content-Type: $type");
	echo $svg;

} else {
	echo "Invalid type";
}
?>