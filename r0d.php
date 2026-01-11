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

// Directories that should be skipped during recursive processing (-s / -r)
$exclude_directories = [
    'cache',
    '.cache',
    '__pycache__',
    '.git',
    '_temp',
    'temp',
    'tmp',
    'node_modules',
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
  -linebreak=0d|0a|0d0a|cr|lf|crlf|unix|mac|windows: desired linebreak bytes (case-insensitive). Default is 0d (aka LF).
END;
  exit;
}

$source_file = '';
$target_file = '';
$process_subdirectories = false;
$convert_charset = false;

$linebreak_hex = '0d';          // Default linebreak: 0d (CR) unless overridden by --linebreak=
$linebreak_seq = "\n";          // Actual bytes to write as linebreak

unset($argv[0]);
foreach ($argv as $arg) {
    if (($arg[0] === '-') && ($arg !== '-')) {

        // Support both "-x" and "--x" forms
        $option = strtolower(substr($arg, substr($arg, 0, 2) === '--' ? 2 : 1));

        // Long option: -linebreak=... (case-insensitive)
        // Supported values:
        //   0d0a | crlf | windows  -> "\r\n"
        //   0d   | cr   | unix     -> "\n"
        //   0a   | lf   | mac      -> "\r"
        if (stripos($option, 'linebreak=') === 0) {
            $lb = strtolower(substr($option, strlen('linebreak=')));
            if ($lb === '0d0a' || $lb === 'crlf' || $lb === 'windows') {
                $linebreak_hex = '0d0a';
                $linebreak_seq = "\r\n";
            }elseif ($lb === '0a' || $lb === 'lf' || $lb === 'mac') {
                $linebreak_hex = '0a';
                $linebreak_seq = "\r";
            //}elseif ($lb === '0d' || $lb === 'cr' || $lb === 'unix') {
            //    $linebreak_hex = '0d';
            //    $linebreak_seq = "\n";
            }else {
                die("Invalid -linebreak value: $lb. Use 0d0a|crlf|windows, 0d|cr|unix, or 0a|lf|mac.");
            }
            continue;
        }

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
 * Get Windows file attributes via "attrib" command.
 * Returns an associative array like ['R'=>true,'H'=>true,'S'=>false,'A'=>true] or null on non-Windows/failure.
 */
function win_get_attrib_flags(string $path): ?array {
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        return null;
    }

    $cmd = 'attrib ' . escapeshellarg($path);
    $out = @shell_exec($cmd);
    if (!is_string($out) || $out === '') {
        return null;
    }

    // Typical output starts with something like: "A  H   C:\path\file"
    // We'll read flags from the beginning of the line.
    $line = trim(str_replace(["\r", "\n"], '', $out));
    $prefix = substr($line, 0, 10);

    return [
        'R' => (strpos($prefix, 'R') !== false), // Read-only
        'H' => (strpos($prefix, 'H') !== false), // Hidden
        'S' => (strpos($prefix, 'S') !== false), // System
        'A' => (strpos($prefix, 'A') !== false), // Archive
    ];
}

/**
 * Apply Windows file attributes via "attrib" command.
 * $flags is an array produced by win_get_attrib_flags().
 * Returns true on success (or if not on Windows), false on failure.
 */
function win_set_attrib_flags(string $path, ?array $flags): bool {
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        return true;
    }
    if (!$flags) {
        return true;
    }

    // Build attrib arguments: +H -H +R -R etc.
    $args = [];
    foreach (['R', 'H', 'S', 'A'] as $k) {
        $args[] = ($flags[$k] ? "+$k" : "-$k");
    }

    $cmd = 'attrib ' . implode(' ', $args) . ' ' . escapeshellarg($path);
    @shell_exec($cmd);

    // Quick verification is optional; consider it "best effort"
    return true;
}


/**
 * Streamed line-ending normalization and trailing whitespace trim.
 *
 * Internally normalizes input to LF (\n) for processing, then outputs the desired
 * linebreak sequence ($linebreak_seq) to support Windows (CRLF), Unix (LF),
 * and classic Mac (CR).
 *
 * Also trims trailing spaces/tabs/NUL/NBSP at end of each line (stream-safe).
 * Optionally converts encoding.
 *
 * Returns [bool $changed, string $message].
 */
function r0d_file_stream(
                    string $source,                 // source file name
                    ?string $target = null,         // target file name (source_dir\source_name.tmp if not specified)
                    ?string $src_encoding = null,   // source encoding
                    ?string $dst_encoding = null    // target encoding
                ): array {

    // Use the desired output EOL from global config (set by CLI option).
    // Fallback to LF if not set.
    global $linebreak_seq;
    $eol = isset($linebreak_seq) ? (string)$linebreak_seq : "\n";
    if ($eol !== "\n" && $eol !== "\r" && $eol !== "\r\n") {
        $eol = "\n";
    }

    // Helper: robust write that handles partial writes and retries.
    $write_all = function ($handle, $data): bool {
            $data = (string)$data;
            $len  = strlen($data);
            if ($len === 0) {
                return true; // nothing to write
            }

            $offset  = 0;
            $retries = 0;

            while ($offset < $len) {
                $chunk = substr($data, $offset);
                $written = @fwrite($handle, $chunk);

                if ($written === false || $written === 0) {
                    // Allow a few short retries in case of transient issues (non-blocking I/O, AV, etc.)
                    if ($retries < 5) {
                        usleep(2000); // 2ms
                        ++$retries;
                        continue;
                    }
                    return false;
                }

                $offset  += $written;
                $retries  = 0;
            }

            return true;
        };

    $in = fopen($source, 'rb');
    if (!$in) {
        return [false, "Failed to open \"$source\" for reading.\n"];
    }

    $source_size = @filesize($source);

    // Place tmp file in the same directory as the source to avoid cross-volume issues.
    if ($target) {
        $tmp = $target;
    }else {
        $dir  = dirname($source);
        $base = basename($source);
        $tmp  = $dir . DIRECTORY_SEPARATOR . $base . '.tmp';
    }

    $out = fopen($tmp, 'wb');
    if (!$out) {
        fclose($in);
        return [false, "Failed to open \"$tmp\" for writing.\n"];
    }

    $changed    = false;
    $carry      = '';               // carries trailing "\r" or initial prefix bytes
    $lineCarry  = '';               // carries an unfinished line between chunks
    $chunkSize  = 4 * 1024 * 1024;  // 4 MB chunks; adjust as needed

    // For CRLF detection across chunk boundaries (original stream analysis).
    $prevWasCR_in_original = false;

    // Strip UTF-8 BOM (EF BB BF) if present at the very beginning.
    $prefix = fread($in, 3);
    if ($prefix === "\xEF\xBB\xBF") {
        $changed = true; // BOM removed
    }elseif ($prefix !== false && $prefix !== '') {
        $carry = $prefix; // No BOM found. Prepend read byes to first chunk.
    }

    while (!feof($in)) {
        $buf = fread($in, $chunkSize);
        if ($buf === false) {
            $buf = ''; // it must be a string
        }

        // --- Decide whether EOL conversion is needed based on ORIGINAL data ---
        // If output EOL is LF: any CR implies changes
        // If output EOL is CR: any LF implies changes
        // If output EOL is CRLF: any lone LF or lone CR implies changes
        if ($eol === "\n") {
            if (strpos($buf, "\r") !== false) {
                $changed = true;
            }
        }elseif ($eol === "\r") {
            if (strpos($buf, "\n") !== false) {
                $changed = true;
            }
        }else { // $eol === "\r\n"
            // Streaming check: mark changed only if we see a lone LF or lone CR.
            // State machine is based on original bytes and supports chunk boundaries.
            $len = strlen($buf);
            for ($i = 0; $i < $len; ++$i) {
                $ch = $buf[$i];
                if ($ch === "\r") {
                    $prevWasCR_in_original = true;
                    continue;
                }
                if ($ch === "\n") {
                    // LF not preceded by CR -> lone LF -> must change
                    if (!$prevWasCR_in_original) {
                        $changed = true;
                    }
                    $prevWasCR_in_original = false;
                    continue;
                }

                // Any non-LF char after CR -> lone CR -> must change
                if ($prevWasCR_in_original) {
                    $changed = true;
                    $prevWasCR_in_original = false;
                }
            }
            // If the chunk ends with CR, keep state for the next chunk (could be CRLF split).
        }
        // --- End original EOL analysis ---

        // Prepend carried bytes (BOM-less prefix or split CR)
        if ($carry !== '') {
            $buf = $carry . $buf;
            $carry = '';
        }

        // Normalize line endings internally to LF for trimming and streaming logic.
        $buf2 = str_replace("\r\n", "\n", $buf);

        // If the chunk ends with a bare "\r", carry it over (could be split CRLF).
        if ($buf2 !== '' && substr($buf2, -1) === "\r") {
            $carry = "\r";
            $buf2  = substr($buf2, 0, -1);
        }

        // Convert any remaining lone CR -> LF.
        $buf2 = str_replace("\r", "\n", $buf2);

        // --- Streaming trim of trailing spaces/tabs/NUL/NBSP at end of each line ---
        if ($lineCarry !== '') {
            $buf2      = $lineCarry . $buf2;
            $lineCarry = '';
        }

        $parts = explode("\n", $buf2);
        $last  = array_pop($parts); // may be an incomplete line

        $outChunk = '';
        foreach ($parts as $line) {
            // Remove trailing: space, tab, NUL, and UTF-8 NBSP (\xC2\xA0)
            $clean = preg_replace('/(?:[ \t\x00]|(?:\xC2\xA0))+$/', '', $line);
            if ($clean !== $line) {
                $changed = true;
            }

            // IMPORTANT: write the desired output EOL (CRLF/CR/LF), not always LF.
            $outChunk .= $clean . $eol;
        }

        $lineCarry = $last;
        // --- End streaming trim ---

        // Optional: encoding conversion on the chunk ready to be written.
        if ($src_encoding && $dst_encoding && $outChunk !== '') {
            $converted = @iconv($src_encoding, $dst_encoding . '//TRANSLIT', $outChunk);
            if ($converted !== false) {
                if ($converted !== $outChunk) {
                    $changed = true;
                }
                $outChunk = $converted;
            }
        }

        if ($outChunk !== '') {
            if (!$write_all($out, $outChunk)) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                return [false, "Failed to write to \"$tmp\".\n"];
            }
        }
    }

    // Flush the last (possibly unterminated) line without appending EOL.
    if ($lineCarry !== '') {
        $tail = preg_replace('/(?:[ \t\x00]|(?:\xC2\xA0))+$/', '', $lineCarry);
        if ($tail !== $lineCarry) {
            $changed = true;
        }

        if ($src_encoding && $dst_encoding && $tail !== '') {
            $conv = @iconv($src_encoding, $dst_encoding . '//TRANSLIT', $tail);
            if ($conv !== false) {
                if ($conv !== $tail) {
                    $changed = true;
                }
                $tail = $conv;
            }
        }

        if ($tail !== '') {
            if (!$write_all($out, $tail)) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                return [false, "Failed to write to \"$tmp\".\n"];
            }
        }

        $lineCarry = '';
    }

    // If a bare "\r" remained at the end (split CRLF that never got LF), finalize it.
    // Output the chosen EOL sequence here as well.
    if ($carry === "\r") {
        if (!$write_all($out, $eol)) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return [false, "Failed to write final newline to \"$tmp\".\n"];
        }
        $changed = true;
    }

    fflush($out);
    fclose($in);
    fclose($out);

    if (!$target) {
        // In-place mode: do not touch the original file if nothing actually changed.
        if (!$changed) { // Remove temp file and keep the original file (mtime stays unchanged).
            @unlink($tmp);

        }else { // Replace original file only when changes were made.
            if (!@rename($tmp, $source)) {
                @unlink($tmp);
                return [false, "Failed to overwrite \"$source\".\n"];
            }

            // Restore basic permissions if possible (mostly for non-Windows)
            if ($orig_perms !== false) {
                @chmod($source, $orig_perms & 0777);
            }

            // Restore Windows attributes (hidden/readonly/system/archive)
            win_set_attrib_flags($source, $orig_win_attrib);
        }
        $result_path = $source;
    }else { // Target mode: keep the produced output file. (Optional: you can also skip write here if you want.)
        $result_path = $target;
    }

    clearstatcache(true, $result_path);

    // Safely get target size; handle failure explicitly
    $target_size = @filesize($result_path);
    $target_size_str = $target_size === false ? 'unknown' : $target_size;

    $msg = $changed
        ? "Original size: $source_size, Result size: $target_size_str"
        : 'not changed';

    return [$changed, "$source: $msg.\n"];
}

/**
 * Check whether the given path is inside an excluded directory.
 *
 * The check is based on individual path segments (case-insensitive),
 * so any path containing one of the excluded directory names as a segment
 * will be treated as excluded. For example:
 *   /project/node_modules/package/index.js
 * will be excluded if "node_modules" is in $exclude_directories.
 */
function is_path_excluded(string $path): bool {
    global $exclude_directories;

    if (empty($exclude_directories)) {
        return false;
    }

    // Normalize path separators and trim leading/trailing slashes
    $normalized = str_replace('\\', '/', $path);
    $normalized = trim($normalized, '/');
    $normalized = strtolower($normalized);

    // Split normalized path into segments
    $segments = explode('/', $normalized);

    // Build a lookup set of excluded directory names (lowercased)
    static $excludedSet = null;
    if ($excludedSet === null) {
        $excludedSet = [];
        foreach ($exclude_directories as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            $excludedSet[strtolower($dir)] = true;
        }
    }

    // If any path segment matches an excluded directory, the path is excluded
    foreach ($segments as $segment) {
        if (isset($excludedSet[$segment])) {
            return true;
        }
    }

    return false;
}

function r0d_dir($dir_mask, $check_subdirs = false) {
    global $allow_extensions;

    if ($fn = glob($dir_mask)) {
        foreach ($fn as $f) {
            // Skip any paths that are inside excluded directories
            if (is_path_excluded($f)) {
                continue;
            }

            if (!is_dir($f)) {
                // Process only files with allowed extensions
                $ext = pathinfo($f, PATHINFO_EXTENSION);
                if ($ext === '') {
                    continue;
                }
                if (!in_array($ext, $allow_extensions, true)) {
                    continue;
                }

                $out = r0d_file_stream($f);
                if ($out[0]) {
                    echo $out[1];
                }
            }elseif ($check_subdirs) {
                // When recursive mode is enabled, do not descend into excluded directories
                if (is_path_excluded($f)) {
                    continue;
                }

                // Use the same basename pattern for subdirectories
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