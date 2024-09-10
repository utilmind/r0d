# r0d
r0d is a tiny command-line tool that strips odd characters from code or text files.

# FILES:
 * `r0d.py` — main script which makes required actions done.
 * `r0d.php` — (legacy) the main script originally written in PHP. But most likely we’ll support Python script in case if future versions will be ever released.
 * `r0d-php.bat` — (legacy) batch file for quick execution of PHP script in Windows environment. ATTN! You need to update path to "r0d.php" before using it!!
 * `r0d.pas` — (not used) legacy code for Borland Pascal from 1996. It’s console application (originally written for 16-bit DOS, not even for Delphi & Windows) which strips \r (#$0d) characters from files. It’s should NOT be used anymore and stored here just because of little nostalgy.

# CHANGES LOG:
  * 10.09.2024: Rewritten in Python script.
  * 20.04.2019: Added conversion of the character set. Eg cp1251 to UTF8 or vise versa.
  * 30.03.2019: Added wildcarded bulk optimization, including recursive directory optimization.
  * 17.03.2019: Now it removes all spaces and tabs before the end of each line.
  * 13.03.2019: Originally it strips \r characters and UTF-8 BOM prefix (&#65279 character) from the UTF-8 encoded files.
