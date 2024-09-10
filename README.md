# r0d
r0d is tiny command-line tool that strips odd characters from code or text files.

<p>FILES:</p>
<ul>
  <li><b>r0d.py</b> -- main script which makes required actions done.</li>
  <li><b>r0d.php</b> -- (legacy) the main script originally written in PHP. But most likely we’ll support Python script in case if future versions will be ever released.</li>
  <li><b>r0d-php.bat</b> -- (legacy) batch file for quick execution of PHP script in Windows environment. ATTN! You need to update path to "r0d.php" before using it!!</li>
  <li><b>r0d.pas</b> -- (not used) legacy code for Borland Pascal from 1996. It’s console application (originally written for 16-bit DOS, not even for Delphi & Windows) which strips \r (#$0d) characters from files. It’s should NOT be used anymore and stored here just because of little nostalgy.</li>
</ul>

<p>CHANGES LOG:</p>
<ul>
  <li>10.09.2024: Rewritten to Python.</li>
  <li>20.04.2019: Added conversion of the character set. Eg cp1251 to UTF8 or vise versa.</li>
  <li>30.03.2019: Added wildcarded bulk optimization, including recursive directory optimization.</li>
  <li>17.03.2019: Now it removes all spaces and tabs before the end of each line.</li>
  <li>13.03.2019: Originally it strips \r characters and UTF-8 BOM prefix (&#65279 character) from the UTF-8 encoded files.</li>
<ul>
