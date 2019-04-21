<?
$allow_extensions = array(
  'php', 'js', 'css', 'html', 'htm', 'shtml',
  'txt', 'md', 'conf', 'ini', 'htaccess',
  'pl', 'cgi', 'asp', 'py', 'sh', 'bat',
  'xml', 'json', 'svg',
  // 'pas', 'c', 'cpp', 'h', // NO! Some legacy IDEs doesn't supports /n without /r.
);


// gettings arguments
if (!is_array($argv) || ($i = count($argv)) < 2) {
  print <<<END
Usage: r0d.php [options] [filename or mask] [output filename (optionally)]
r0d.php [mask, like *.php, or * to find all files with allowed extensions, or .* to find hidden files] [-r (to process subdirectories, if directory names match mask)]

Options:
  -s or -r: process subdirectories
  -c:src_charset~target_charset: convert from specified charset into another. If target_charset not specified, file converted to UTF-8.
                                 WARNING! double conversion is possible if conversion is not from or into UTF-8.
  -i: inform about possible optimization/convertion without optimization/convertion.
END;
  exit;
}

$source_file = '';
$target_file = '';
$process_subdirectories = false;
$convert_charset = false;
$inform_only = false;

unset($argv[0]);
foreach ($argv as $arg) {
  if (($arg[0] == '-') && ($option = strtolower(substr($arg, 1)))) {
    if ($option == 's' || $option == 'r') {
      $process_subdirectories = true;
      continue;
    }
    if (substr($option, 0, 2) == 'c:') {
      $charsets = explode("~", substr($option, 2));
      $convert_charset = strtolower($charsets[0]);
      $convert_charset_target = isset($charsets[1]) ? strtolower($charsets[1]) : 'utf-8';

      continue;
    }
    if ($option == 'i') {
      $inform_only = true;
      continue;
    }
  }

  // non-options
  if (!$source_file)
    $source_file = $arg;
  elseif (!$target_file)
    $target_file = $arg;

}


// Check
if ((!$is_wildcard = (strpos($source_file, '*') !== false)) && (!file_exists($source_file) || is_dir($source_file))) {
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
  global $convert_charset, $convert_charset_target, $inform_only;

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


    // now try to recode the charset, if required
    if ($convert_charset) {

      // already in target encoding?
      $skip_encoding = (($convert_charset_target == 'utf-8') && mb_check_encoding($data, $convert_charset_target)) ||
                       (($convert_charset == 'utf-8') && !mb_check_encoding($data, $convert_charset));

      if (!$skip_encoding && ($r = iconv($convert_charset, $convert_charset_target, $data)))
        $data = $r;
    }

    $target_size = strlen($data);
  }

  // Result...
  if ($data_changed = ($data_changed || ($data && ($source_size != $target_size)))) {
    if ($inform_only)
      $inform_only = ' (unchanged)';
    else
      file_put_contents($target_file ? $target_file : $source_file, $data);

    $out = "$source_file: Original size: $source_size, Result size: $target_size.$inform_only\n";
  }else
    $out = "Nothing changed. Same size: $target_size.\n";
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
