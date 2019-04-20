# r0d
r0d is tiny command-line tool that strips odd characters from code or text files.

<p>FILES:</p>
<ul>
  <li><b>r0d.php</b> -- main script which makes required actions done.</li>
  <li><b>r0d.bat</b> -- batch file to easily executed it in Windows environment. WARNING! You need to edit update path to "r0d.php" before usage!!</li>
  <li><b>r0d.pas</b> -- (not used) legacy Pascal code from 1996. It's console application (originally written for 16-bit DOS) which strips \r (#$0d) characters from files. It's NOT used anymore and stored here just because of little nostalgy.</li>
</ul>

<p>CHANGES LOG:</p>
<ul>
  <li>20.04.2019: Added conversion of the character set. Eg cp1251 to UTF8 or wise versa.</li>
  <li>30.03.2019: Added wildcarded bulk optimization, including recursive directory optimization.</li>
  <li>17.03.2019: Now it removes all spaces and tabs before the end of each line.</li>
  <li>13.03.2019: Originally it strips \r characters and UTF-8 BOM prefix (&#65279 character) from the UTF-8 encoded files.</li>
<ul>
