<?php
/**
 * phpMan is a web interface of Unix command 'man' and 'perldoc'.
 * This script makes it easier to read man pages which is lengthy
 * and require you to use 'more' or 'pg' filters.
 * Just try it if you feel hard to remember the command for page back
 * or need to dump man page into text/html format.
 *
 * $Id$
 * Author: Che, Dong
 * chedong@bigfoot.com
 *
 * Tested on Linux and FreeBSD
 */

//global title
$PHP_MAN_TITLE = "phpMan: Unix Manual / Perldoc Web Interface";

//header
echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"DTD/xhtml1-transitional.dtd\">
<html>
<head>
<title>$PHP_MAN_TITLE</title>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=ISO-8859-1\"/>
<style type=\"text/css\">
<!--
b {color:brown}
u {color:green}
//-->
</style>
</head>
<body bgcolor=\"#EEEEEE\">";

//option checker
if ($docType == "perldoc") {
	$check_man = "";
	$check_perldoc = " checked=\"checked\"";
}
else {
	$check_man = " checked=\"checked\"";
	$check_perldoc = "";
}

//promter and recursive call
echo "<b>$PHP_MAN_TITLE</b>
<form action=\"$PHP_SELF\">
Command:
<input type=\"text\" size=\"20\" name=\"parm\" value=\"$parm\"/>
<input type=\"radio\" name=\"docType\" value=\"man\"$check_man/>man
<input type=\"radio\" name=\"docType\" value=\"perldoc\"$check_perldoc/>perldoc
<input type=\"submit\"/>
</form>";

echo "<hr /><br />";
echo "<pre>";

//remove arbitrary commands
$semi = strpos($parm,";");
if ($semi > 1)
	$parm = substr($parm, 0, $semi);
//get
if ( $docType == "perldoc" )
	exec("perldoc $parm",$lines,$rc);
else
	exec("man $parm",$lines,$rc);

$count = count($lines);
for ( $i = 1; $i <= $count; $i ++ ) {
	//highlighting attribute characters
	$patterns = array(
					"/&/",  //html special char: '&' => chr(5) => '&gt;';
					"/</",  //html special char: '>' => chr(6) => '&lt;';
					"/>/",  //html special char: '<' => chr(7) => '&gt;';
					"/_".chr(8)."(.)".chr(8)."./",	// _^H?^H? => <b><u>?</u></b>
					"/_".chr(8)."(.)/",  //_^H? => <u>?</u>
					"/.".chr(8)."(.)/",  //?^H? => <b>?</b>
					"/<b>.<\/b>".chr(8)."(<b>.<\/b>)/", //duplicated: <b>?</b>^H<b>?</b> => <b>?</b>
					"/".chr(5)."/",  //reverse '&'
					"/".chr(6)."/",  //reverse '<'
					"/".chr(7)."/",  //reverse '>'
					//ifconfig(8) => <a href="8 ifconfig">ifconfig(8)</f>
					//IO::Handle(3) => IO::Handle
					"/([\w:\.]+)\((\d)\)/"
					);
	$replace = array(
					chr(5),
					chr(6),
					chr(7),
					"<b><u>\\1</u></b>",
					"<u>\\1</u>",
					"<b>\\1</b>",
					"\\1",
					"&amp;",
					"&lt;",
					"&gt;",
					"<a href=\"?docType=$docType&amp;parm=\\2 \\1\">\\1(\\2)</a>"
					);
	$lines[$i] = preg_replace($patterns, $replace, $lines[$i]);
	echo "$lines[$i] <br />";
}

//footer
echo "</pre>
<hr />
<br />
<p>
<a href=\"http://validator.w3.org/check/referer\">
<img src=\"http://www.w3.org/Icons/valid-xhtml10\" alt=\"Valid XHTML 1.0!\" height=\"31\" width=\"88\" border=\"0\" />
</a>
<a href=\"http://sourceforge.net/projects/phpunixman/\">\$Id$</a>
</p>
</body>
</html>";
?>
