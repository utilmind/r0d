<?php
/**
 * r0d.php
 *
 * (c) utilmind, 1999-2025
 *
 * Command-line utility to normalize line endings in text files.
 * It replaces Windows CRLF (\r\n) and classic Mac CR (\r) line endings
 * with Unix-style LF (\n) only and can optionally convert file encodings.
 *
 * The script can process a single file or a set of files by wildcard mask,
 * recursively scanning subdirectories if requested. It also supports
 * reporting-only mode without actually modifying the files.
 */

static $allow_extensions = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'css', 'scss', 'less', 'html', 'htm', 'shtml', 'phtml',
        'txt', 'md', 'conf', 'ini', 'htaccess', 'htpasswd', 'gitignore', 'sql',
        'pl', 'cgi', 'asp', 'py', 'go', 'sh', 'bat', 'ps1', // 'pas',
        'xml', 'csv', 'json', 'yaml', 'svg', 'glsl',
        'pem', 'ppk', 'yml',
        // 'pas', 'c', 'cpp', 'h', // NO! Some legacy IDEs doesn't supports /n without /r.
    ];


// gettings arguments
if (!is_array($argv) || ($i = count($argv)) < 2) {
    $base_php = basename($argv[0]);
    echo <<<END
r0d: Remover of 0x0D characters from text/code files.
Converts Windows and Mac-specific linebreaks (\r\n and \r) into Unix-style (\n).
Also it trims the lines, removing odd spaces before the linebreaks.

Usage: $base_php [options] [filename or mask] [output filename (optionally)]
r0d.php [mask, like *.php, or * to find all files with allowed extensions (including hidden files, .htaccess, etc)] [-r (to process subdirectories, if directory names match mask)]

ATTN! It skips too large files which PHP obviously can't process due to memory limit. Increase memory limit to process huge files.

Options:
  -s or -r: process subdirectories
  -c:src_charset~target_charset: convert from specified charset into another. If target_charset not specified, file converted to UTF-8.
                                 WARNING! double conversion is possible if conversion is not from or into UTF-8.
END;
  exit;
}

$source_file = '';
$target_file = '';
$process_subdirectories = false;
$convert_charset = false;

unset($argv[0]);
foreach ($argv as $arg) {
    if (($arg[0] === '-') && ($option = strtolower(substr($arg, 1)))) {
        if ($option === 's' || $option === 'r') {
            $process_subdirectories = true;
            continue;
        }

        if (substr($option, 0, 2) === 'c:') {
            $charsets = explode('~', substr($option, 2));
            $convert_charset = strtolower($charsets[0]);
            $convert_charset_target = isset($charsets[1]) ? strtolower($charsets[1]) : 'utf-8';
            continue;
        }
    }

    // non-options
    if (!$source_file) {
        $source_file = $arg;
    }elseif (!$target_file) {
        $target_file = $arg;
    }
}



// Check
if ((!$is_wildcard = (strpos($source_file, '*') !== false)) && (!file_exists($source_file) || is_dir($source_file))) {
    die("File \"$source_file\" not found.\n");
}


/**
 * Streamed line-ending normalization: CRLF -> LF, then lone CR -> LF.
 * Uses chunked I/O to avoid loading the whole file into memory.
 * Returns [bool $changed, string $message].
 */
function r0d_file_stream(string $source,            // source file name
                        ?string $target = null,     // target file name (source_name.tmp if not specified)
                        ?string $src_encoding = null,            // source encoding
                        ?string $dst_encoding = null): array {   // target encoding
    $in = fopen($source, 'rb');
    if (!$in) {
        return [false, "Failed to open \"$source\" for reading.\n"];
    }
    $source_size = filesize($source);

    $tmp = $target ?: $source . '.tmp';
    $out = fopen($tmp, 'wb');
    if (!$out) {
        fclose($in);
        return [false, "Failed to open \"$tmp\" for writing.\n"];
    }

    $changed = false;
    $carry = '';              // keep trailing \r across chunk boundaries
    $chunkSize = 4 * 1024 * 1024; // 4MB chunks; adjust if needed

   // Strip UTF-8 BOM if present (EF BB BF) ---
    $prefix = fread($in, 3);
    if ($prefix === "\xEF\xBB\xBF") { // BOM found — skip it
        $changed = true;

    // No BOM — keep whatever we read as the start of the stream
    }elseif ($prefix !== false) {
        $carry = $prefix; // put first 3 bytes to output stream.
    }

    while (!feof($in)) {
        $buf = fread($in, $chunkSize);
        if ($buf === false) {
            $buf = ''; // it must be a string
        }

        if ($carry !== '') {
            $buf = $carry . $buf;
            $carry = '';
        }

        // CRLF (Windows linebreaks) -> LF first
        $buf2 = str_replace("\r\n", "\n", $buf);

        // If chunk ends with \r, postpone it (could be part of CRLF split across chunks)
        if ($buf2 !== '' && substr($buf2, -1) === "\r") {
            $carry = "\r"; // move to the next chunk
            $buf2 = substr($buf2, 0, -1);
        }

        // Lone CR (Mac linebreaks) -> LF
        $buf2 = str_replace("\r", "\n", $buf2);

        // remove spaces and tabs before the end of each line.
        //$data = preg_replace('/[ \x00\xa0\t]+(\n|$)/', "$1", $data);

        // Optional: encoding conversion (streamed best-effort)
        // AK 2025-11-12: we did it differently in older versions. See Git repo before 2025.
        if ($src_encoding && $dst_encoding) {
            $converted = @iconv($src_encoding, $dst_encoding . '//TRANSLIT', $buf2);
            if ($converted !== false) {
                if ($converted !== $buf2) {
                    $changed = true;
                }
                $buf2 = $converted;
            }
        }

        if ($buf2 !== $buf) {
            $changed = true;
        }

        if (fwrite($out, $buf2) === false) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return [false, "Failed to write to \"$tmp\".\n"];
        }
    }

    if ($carry === "\r") {
        fwrite($out, "\n");
        $changed = true;
    }

    fclose($in);
    fclose($out);

    if (!$target && !@rename($tmp, $source)) {
        @unlink($tmp);
        return [false, "Failed to overwrite \"$source\".\n"];
    }

    if ($changed) {
        $target_size = filesize($tmp);
        $out = "Original size: $source_size, Result size: $target_size";
    }else {
        $msg = 'not changed';
    }
    return [$changed, "$source: $msg.\n"];
}

function r0d_dir($dir_mask, $check_subdirs = false) {
    global $allow_extensions;

    if ($fn = glob($dir_mask)) {
        foreach ($fn as $f) {
            if (!is_dir($f)) {
                if (!in_array(pathinfo($f, PATHINFO_EXTENSION), $allow_extensions)) {
                    continue;
                }
                $out = r0d_file_stream($f);
                if ($out[0]) {
                    echo $out[1];
                }
            }elseif ($check_subdirs) {
                // if (($f !== '.') && ($f !== '..'))
                r0d_dir($f . '/' . basename($dir_mask), $check_subdirs);
            }
        }
    }
}

// GO!
if ($is_wildcard) {
    r0d_dir(/*getcwd().'/'.*/$source_file, $process_subdirectories);

    /* // AK 2023-01-18: I want to find .htaccess too, even if I looking for "*", not ".*".
       // UPD. it never worked properly :(
    if ($source_file === '*') {
        r0d_dir('.*', $process_subdirectories);
    }
    */
}else {
    $out = r0d_file_stream($source_file, $target_file);
    echo $out[1];
}