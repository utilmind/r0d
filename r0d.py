import sys
import os
import glob
import codecs

# Allowed file extensions
allow_extensions = [
    'php', 'js', 'jsx', 'vue', 'css', 'scss', 'less', 'html', 'htm', 'shtml', 'phtml',
    'txt', 'md', 'conf', 'ini', 'htaccess', 'htpasswd', 'gitignore', 'sql',
    'pl', 'cgi', 'asp', 'py', 'sh', 'bat', 'ps1',  # 'pas',
    'xml', 'csv', 'json', 'svg',
    'pem', 'ppk', 'yml'
]

# Get CLI arguments
if len(sys.argv) < 2:
    print("""
Usage: script.py [options] [filename or mask] [output filename (optionally)]
script.py [mask, like *.php, or * to find all files with allowed extensions (including hidden files, .htaccess, etc)]

Options:
  -s or -r: process subdirectories
  -c:src_charset~target_charset: convert from specified charset into another. If target_charset not specified, file converted to UTF-8.
                                 WARNING! double conversion is possible if conversion is not from or into UTF-8.
  -i: inform about possible optimization/conversion without optimization/conversion.
    """)
    sys.exit()


source_file = ''
target_file = ''
process_subdirectories = False
convert_charset = False
inform_only = False

# Parse CLI arguments
args = sys.argv[1:]
for arg in args:
    if arg.startswith('-'):
        option = arg[1:].lower()
        if option in ('s', 'r'):
            process_subdirectories = True
        elif option.startswith('c:'):
            charsets = option[2:].split('~')
            convert_charset = charsets[0].lower()
            convert_charset_target = charsets[1].lower() if len(charsets) > 1 else 'utf-8'
        elif option == 'i':
            inform_only = True
    else:
        if not source_file:
            source_file = arg
        elif not target_file:
            target_file = arg


# Check file
is_wildcard = '*' in source_file
if not is_wildcard and (not os.path.exists(source_file) or os.path.isdir(source_file)):
    print(f'File "{source_file}" not found.')
    sys.exit()


# Functions
def remove_utf8_bom(text):
    return text.encode().decode('utf-8-sig')

def strip_char(text, char):
    return text.replace(char, '')

def r0d_file(source_file, target_file = None):
    global convert_charset, convert_charset_target, inform_only

    data_changed = False
    data = ''

    with open(source_file, 'rb') as f:
        data = f.read().decode(errors='ignore')

    if data:
        source_size = len(data)
        data = remove_utf8_bom(data)

        if '\r' in data:
            if '\n' not in data:
                data = data.replace('\r', '\n')
                data_changed = True
            else:
                data = strip_char(data, '\r')

        data = '\n'.join([line.rstrip() for line in data.splitlines()])

        if convert_charset:
            try:
                if convert_charset_target == 'utf-8' and data.encode().decode('utf-8', errors='strict'):
                    pass
                else:
                    data = codecs.decode(data.encode(convert_charset), convert_charset_target)
            except Exception as e:
                print(f"Conversion failed: {e}")

        target_size = len(data)
    else:
        target_size = 0

    if data_changed or (data and source_size != target_size):
        if inform_only:
            inform_message = ' (unchanged)'
        else:
            with open(target_file or source_file, 'wb') as f:
                f.write(data.encode())
            inform_message = ''
        print(f"{source_file}: Original size: {source_size}, Result size: {target_size}.{inform_message}")
    else:
        print(f"Nothing changed. Same size: {target_size}.")

def r0d_dir(dir_mask, check_subdirs = False):
    global allow_extensions

    for f in glob.glob(dir_mask):
        if not os.path.isdir(f):
            if not os.path.splitext(f)[1][1:] in allow_extensions:
                continue
            changed, output = r0d_file(f)
            if changed:
                print(output)
        elif check_subdirs:
            r0d_dir(os.path.join(f, os.path.basename(dir_mask)), check_subdirs)


# GO!
if is_wildcard:
    r0d_dir(source_file, process_subdirectories)
else:
    r0d_file(source_file, target_file)
