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
 * Trims trailing spaces/tabs/NUL/NBSP before LF in a streaming-safe way.
 * Uses chunked I/O to avoid loading the whole file into memory.
 * Returns [bool $changed, string $message].
 */
function r0d_file_stream(
                    string $source,                 // source file name
                    ?string $target = null,         // target file name (source_dir\source_name.tmp if not specified)
                    ?string $src_encoding = null,   // source encoding
                    ?string $dst_encoding = null    // target encoding
                ): array {

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
    } else {
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
    $carry      = '';                  // carries trailing "\r" or initial prefix bytes
    $lineCarry  = '';                  // carries an unfinished line between chunks
    $chunkSize  = 4 * 1024 * 1024;     // 4 MB chunks; adjust as needed

    // Strip UTF-8 BOM (EF BB BF) if present at the very beginning
    $prefix = fread($in, 3);
    if ($prefix === "\xEF\xBB\xBF") {
        // BOM found — skip it
        $changed = true;
    } elseif ($prefix !== false && $prefix !== '') {
        // No BOM — prepend these bytes to the first chunk
        $carry = $prefix;
    }

    while (!feof($in)) {
        $buf = fread($in, $chunkSize);
        if ($buf === false) {
            $buf = ''; // it must be a string
        }

        // Detect CR/CRLF in the original chunk before any modifications.
        // If there was at least one "\r", line endings will be normalized.
        $hadCR = (strpos($buf, "\r") !== false);

        // Prepend any carried-over prefix (BOM-less prefix or split CR)
        if ($carry !== '') {
            $buf = $carry . $buf;
            $carry = '';
        }

        // Normalize line endings: first CRLF -> LF
        $buf2 = str_replace("\r\n", "\n", $buf);

        // If the chunk ends with a bare "\r", carry it over (could be split CRLF)
        if ($buf2 !== '' && substr($buf2, -1) === "\r") {
            $carry = "\r";
            $buf2  = substr($buf2, 0, -1);
        }

        // Then convert any remaining lone CR -> LF
        $buf2 = str_replace("\r", "\n", $buf2);

        // Mark that line endings were changed if original chunk contained CR
        if ($hadCR) {
            $changed = true;
        }

        // --- Streaming trim of trailing spaces/tabs/NUL/NBSP at end of each line ---
        // Prepend any unfinished line from previous chunk
        if ($lineCarry !== '') {
            $buf2      = $lineCarry . $buf2;
            $lineCarry = '';
        }

        // Split by LF, keep the last fragment as a potentially incomplete line
        $parts = explode("\n", $buf2);
        $last  = array_pop($parts); // may be an incomplete line (no trailing LF)

        $outChunk = '';

        foreach ($parts as $line) {
            // Remove trailing: space, tab, NUL, and UTF-8 NBSP (\xC2\xA0)
            $clean = preg_replace('/(?:[ \t\x00]|(?:\xC2\xA0))+$/', '', $line);
            if ($clean !== $line) {
                $changed = true;
            }
            $outChunk .= $clean . "\n";
        }

        // Keep the last fragment as unfinished line for the next iteration
        $lineCarry = $last;
        // --- End streaming trim ---

        // Optional: encoding conversion on the chunk that is ready to be written
        if ($src_encoding && $dst_encoding && $outChunk !== '') {
            $converted = @iconv($src_encoding, $dst_encoding . '//TRANSLIT', $outChunk);
            if ($converted !== false) {
                if ($converted !== $outChunk) {
                    $changed = true;
                }
                $outChunk = $converted;
            }
        }

        // Write out the processed chunk, if any
        if ($outChunk !== '') {
            if (!$write_all($out, $outChunk)) {
                fclose($in);
                fclose($out);
                @unlink($tmp);
                return [false, "Failed to write to \"$tmp\".\n"];
            }
        }
    }

    // Flush the last (possibly unterminated) line:
    // apply trailing-space trim and optional encoding conversion, then write it without adding LF.
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

    // If a bare "\r" was left in carry at the very end, finalize it as LF
    if ($carry === "\r") {
        if (!$write_all($out, "\n")) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            return [false, "Failed to write final newline to \"$tmp\".\n"];
        }
        $changed = true;
    }

    // Close handles and decide what to do with tmp file
    fflush($out);
    fclose($in);
    fclose($out);

    if ($target === null) {
        // In-place mode: if nothing changed, remove tmp and keep original as-is
        if (!$changed) {
            @unlink($tmp);
            $result_path = $source;
        } else {
            if (!@rename($tmp, $source)) {
                @unlink($tmp);
                return [false, "Failed to overwrite \"$source\".\n"];
            }
            $result_path = $source;
        }
    } else {
        // Target path explicitly specified: tmp == target, keep it even if unchanged
        $result_path = $target;
    }

    clearstatcache(true, $result_path);
    $target_size = @filesize($result_path);
    $msg = $changed
        ? "Original size: $source_size, Result size: $target_size"
        : "not changed";

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