<?php
/**
phpMan is a web interface of Unix command 'man' and 'perldoc'.
This script makes it easier to read man pages which is lengthy
and require you to use 'more' or 'pg' filters.
Just try it if you feel hard to remember the command for page back
or need to dump man page into text/html format.

$Id$
Author: Che, Dong
chedong@bigfoot.com
*/

//header
echo "<html>
<head>
<title>Unix Manual / Perldoc</title>
</head>
<body>";

//add promter and recursive call
echo "<b>Unix Manual and Perldoc</b>
<form action=\"$PHP_SELF\">
Command:
<input type=\"text\" size=20 name=\"parm\" value=\"$parm\">
<input type=\"radio\" value=\"man\" checked name=\"docType\">man
<input type=\"radio\" name=\"docType\" value=\"perldoc\">perldoc
<input type=submit>
</form>";

echo "<hr /><br />";
echo "<pre>";

//remove unsecure commands
$semi = strpos($parm,";");
if ($semi > 1) 
	$parm = substr($parm, 0, $semi);

if ( $docType == "perldoc" )
	exec("perldoc $parm",$lines,$rc);
else
	exec("man $parm",$lines,$rc);


$count = count($lines);
for ( $i = 1; $i <= $count; $i ++ ) {
	//highlighting attribute characters
	$lines[$i] = htmlspecialchars($lines[$i]);
	$patterns = array(
					"/_".chr(8)."(.)/",  //_^H?  => <u>?</u>
					"/.".chr(8)."(.)/",  //^H?    => <b>?</b>
					"/<.>&<\/.>/",
					"/<b>.<\/b>".chr(8)."(<b>.<\/b>)/",
					"/".chr(173)."/"     //end line mark
					);
	$replace = array(
					"<u>\\1</u>",
					"<b>\\1</b>",
					"&",
					"\\1",
					""
					);

	$lines[$i] = preg_replace($patterns, $replace, $lines[$i]);
	echo "$lines[$i] <br />";
}

//footer
echo "</pre>
<hr />
<br />
<a href=\"http://www.phpbuilder.com/snippet/detail.php?type=snippet&id=597\">\$Id$</a>
</body>
</html>";
?>
