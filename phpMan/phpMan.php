<?php
/**
 * $Id$
 *
 * phpMan is a web interface of Unix command 'man' and 'perldoc'.
 * This script makes it easier to read man pages which is lengthy
 * and require you to use 'more' or 'pg' filters.
 * Just try it if you feel hard to remember the command for page back
 * or need to dump man page into text/html format.
 * Tested on Linux, FreeBSD and Solaris.
 *
 * Copyright (C) 2002 Che, Dong chedong@bigfoot.com
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

//global title
$PHP_MAN_TITLE = "phpMan: Unix Manual / Perldoc Web Interface";

//header
echo "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>
<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"
    \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">
<html xmlns=\"http://www.w3.org/1999/xhtml\">
<head>
<title>$PHP_MAN_TITLE</title>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=ISO-8859-1\"/>
<style type=\"text/css\">
<!--
body {color:#000000;background-color:#EEEEEE}
b {color:#996600;background-color:#EEEEEE}
u {color:#008000;background-color:#EEEEEE}
//-->
</style>
</head>
<body>";

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
<p>Command:
<input type=\"text\" size=\"20\" name=\"parm\" value=\"$parm\"/>
<input type=\"radio\" name=\"docType\" value=\"man\"$check_man/>man
<input type=\"radio\" name=\"docType\" value=\"perldoc\"$check_perldoc/>perldoc
<input type=\"submit\"/></p>
</form>";

echo "<hr /><br />";
echo "<pre>";

//remove arbitrary commands
$parm = escapeshellcmd($parm);

//get
if ( $docType == "perldoc" )
	exec("perldoc $parm", $lines, $rc);
else
	exec("man $parm", $lines, $rc);

$count = count($lines);
for ( $i = 1; $i <= $count; $i ++ ) {
	//highlighting attribute characters
	$patterns = array(
		"/&/",  //html special char: '&' => chr(5) => '&gt;';
		"/</",  //html special char: '>' => chr(6) => '&lt;';
		"/>/",  //html special char: '<' => chr(7) => '&gt;';
		"/.".chr(8).".".chr(8)."(.)".chr(8)."./",	// ?^H?^H?^H? => <b>?</b>
		"/_".chr(8)."(.)".chr(8)."./",	// _^H?^H? => <b>?</b>
		"/_".chr(8)."(.)/",  //_^H? => <u>?</u>
		"/.".chr(8)."(.)/",  //?^H? => <b>?</b>
		"/".chr(5)."/",  //reverse '&'
		"/".chr(6)."/",  //reverse '<'
		"/".chr(7)."/",   //reverse '>'
		"/<\/u><u>/", //removed duplicated html tag
		"/<\/b><b>/", //removed duplicated html tag
		"/\s([a-z_\-\.]+)\((\d)\)/" //transfer related command to hyperlinks
		);
	$replace = array(
		chr(5),
		chr(6),
		chr(7),
		"<b>\\1</b>",
		"<b>\\1</b>",
		"<u>\\1</u>",
		"<b>\\1</b>",
		"&amp;",
		"&lt;",
		"&gt;",
		"",
		"",
		" <a href=\"?docType=$docType&amp;parm=\\2 \\1\">\\1(\\2)</a>"
		);

	$lines[$i] = preg_replace($patterns, $replace, $lines[$i]);
	echo "$lines[$i] <br />";
}

//footer
echo "</pre>
<hr />
<br />
<a href=\"http://sourceforge.net/projects/phpunixman/\">
\$Id$
</a>
</body>
</html>";
?>