<?
if (!isset($argv[1])) {
  print "Usage: r0d.php [filename] [output filename (optionally)]";
  exit;
}

if (!file_exists($argv[1])) {
  print "File \"$argv[1]\" not found.\n";
  exit;
}


// TOOLS
function remove_utf8_bom($t) {
  $bom = pack('H*', 'EFBBBF');
  return preg_replace("/^$bom/", '', $t);
}

function strip_char($t, $char) {
  return preg_replace("/$char/", '', $t);
}


// GO!
$data_changed = false;
$data = file_get_contents($argv[1]);
$source_size = strlen($data);

$data = remove_utf8_bom($data);

if (strpos($data, "\r") !== false) {
  // if file contains \r, but has no \n, let's replace all \r to \n.
  if (strpos($data, "\n") === false) {
    $data = str_replace("\r", "\n", $data);
    $data_changed = true;
  }else
    $data = strip_char($data, "\r"); // // \r = chr(13) = carriage return. (We don't want \r\n, we'd like to have only \n.)
}

// remove spaces and tabs before the end of each line.
$data = preg_replace("/[ \t]+(\n|$)/", "$1", $data);

$target_size = strlen($data);

// Result...
if (!$data_changed && ($source_size == $target_size))
  print "Nothing changed.\n";
else {
  file_put_contents(isset($argv[2]) ? $argv[2] : $argv[1], $data);
  print "Original size: $source_size, Result size: $target_size.\n";
}
