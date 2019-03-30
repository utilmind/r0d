<?
$allow_extensions = array(
  'php', 'js', 'css', 'html', 'htm', 'shtml',
  'txt', 'md', 'conf', 'ini', 'htaccess',
  'pl', 'cgi', 'asp', 'py', 'sh', 'bat',
  'xml', 'json', 'svg',
  // 'pas', 'c', 'cpp', 'h', // NO! Some legacy IDEs doesn't supports /n without /r.
);

if (!isset($argv[1])) {
  print "Usage: r0d.php [filename] [output filename (optionally)]\n";
  print "       r0d.php [mask, like *.php, or * to find all files with allowed extensions, or .* to find hidden files] [-r (to process subdirectories, if directory names match mask)]";
  exit;
}

$source_file = $argv[1];
$target_file = isset($argv[2]) ? $argv[2] : false;

if ((!$is_wildcard = (strpos($source_file, '*') !== false)) && !file_exists($source_file)) {
  print "File \"$source_file\" not found.\n";
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

function r0d_file($source_file, $target_file = false) {
  $data_changed = false;
  if ($data = file_get_contents($source_file)) {
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
  }

  // Result...
  if ($data_changed = ($data_changed || ($data && ($source_size != $target_size)))) {
    file_put_contents($target_file ? $target_file : $source_file, $data);
    $out = "$source_file: Original size: $source_size, Result size: $target_size.\n";
  }else
    $out = "Nothing changed.\n";
  return array($data_changed, $out);
}

function r0d_dir($dir_mask, $check_subdirs = false) {
  global $allow_extensions;

  if ($fn = glob($dir_mask))
    foreach ($fn as $f)
      if (($f != '.') && ($f != '..'))
        if (!is_dir($f)) {
          if (!in_array(pathinfo($f, PATHINFO_EXTENSION), $allow_extensions))
            continue;

          $out = r0d_file($f);
          if ($out[0])
            print $out[1];
        }elseif ($check_subdirs)
          r0d_dir($f.'/'.$dir_mask, $check_subdirs);
}

// GO!
if ($is_wildcard)
  r0d_dir($source_file, $target_file == '-r');
else {
  $out = r0d_file($source_file, $target_file);
  print $out[1];
}
