<?php
/**
 * $Id$
 *
 * phpMan is a web interface of Unix command 'man' and 'perldoc'.
 * This script makes it easier to read man pages which is lengthy
 * and require you to use 'more' or 'pg' filters.
 * Just try it if you feel hard to remember the command for page back
 * or need to dump man page into text/html format.
 * Tested on Linux and FreeBSD.
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
$PHP_MAN_TITLE = "phpMan: Unix Manual / Perldoc / Info Web Interface";

//remove arbitrary commands
if ( isset($parm) ) {
	$parm = escapeshellcmd($parm);
}
else {
	$parm = "";
}

//default page type
if ( $docType != "perldoc" && $docType != "man" && $docType != "info" ) {
	$docType = "man";
}

//Get screen size and set man page column size (It's only work under man above 1.5)
$width = 132;  //default for 1024 * 768
if (isset($screen) && $screen < 1024) {
	$width = $screen / 8;
}

//option checker and get manual page content, if no parameter: get index tree
if ($docType == "perldoc") {
	$check_man = "";
	$check_perldoc = " checked=\"checked\"";
	$check_info = "";
	if ( $parm != "" ){
		exec("MANWIDTH=$width perldoc $parm", $lines);
	}
	else {
		$perl_mod_list = "'use ExtUtils::Installed;
			my (\$inst) = ExtUtils::Installed->new();
			my (@modules) = \$inst->modules();
			print join(\"\\n\",@modules),\"\\n\"'";
		//debug echo $perl_mod_list;
		exec("perl -e $perl_mod_list",$lines);
	}
}
else if ($docType == "info") {
	$check_man = "";
	$check_perldoc = "";
	$check_info = " checked=\"checked\"";
	if ( $parm != "" ){
		exec("MANWIDTH=$width info $parm", $lines);
	}
	else {
		exec("info", $lines);
	}
}
else if ($docType == "man"){
	$check_man = " checked=\"checked\"";
	$check_perldoc = "";
	$check_info = "";
	if ( $parm != "" ){
		exec("MANWIDTH=$width man $parm", $lines);
	}
	else {
		exec("info", $lines);
	}
}

$count = count($lines);

//regular replace patterns for specified command(module) name
if ( $parm != "" ) {
	$patterns = array(
		"/&/",  //html special char: '&' => chr(5) => '&gt;';
		"/</",  //html special char: '>' => chr(6) => '&lt;';
		"/>/",  //html special char: '<' => chr(7) => '&gt;';
		//man page special chars
		"/.".chr(8).".".chr(8)."(.)".chr(8)."./",	// ?^H?^H?^H? => <b>?</b>
		"/_".chr(8)."(.)".chr(8)."./",	// _^H?^H? => <b>?</b>
		"/_".chr(8)."(.)/",  //_^H? => <u>?</u>
		"/.".chr(8)."(.)/",  //?^H? => <b>?</b>
		//reverse html special chars
		"/".chr(5)."/",  //reverse '&'
		"/".chr(6)."/",  //reverse '<'
		"/".chr(7)."/",  //reverse '>'
		//removed duplicated html tag
		"/<\/u><u>/",       // '<\/u><u>' => ''
		"/<u>_<\/u><b>/",   // '<u>_<\/u><b>' => '<b>_'
		"/<\/b><b>/",       // '<\/b><b>' => ''
		//transfer related command to hyperlinks, but $b->func(#) will not be translate.
		//'<b>command</b>(<b>#</b>),</b>' => ' command(#)' => link to command
		//Man Page Howto: http://www.schweikhardt.net/man_page_howto.html
		"/((<.>){1}|([\s,]){1})([a-z0-9_\-\.\+]+)(<\/.>)?\((<.>)?([\dnol][MXS]?)(<\/.>)?\)(,)?(<\/.>)?/",
		"/([\s,])([a-z0-9_\-\.\+]+)\(([\dnol][MXS]?)\)/",
		//translate link to related perl modules, but $obj->Module::Name-> will not be translate
		//'<u>Module::Name</u>' => ' Module::Name'
		"/((<.>){1}|([\s,]))([a-zA-Z]+(::[a-zA-Z]+)+)(<\/.>)?/", 
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
		"<b>_",
		"",
		"\\3\\4(\\7)\\9",
		"\\1<a href=\"?docType=man&amp;screen=$screen&amp;parm=\\3 \\2\">\\2(\\3)</a>",
		"\\3<a href=\"?docType=$docType&amp;screen=$screen&amp;parm=\\4\">\\4</a>"
		);
}
//not specify command(module) name: try to show index
else {
	if ( $docType == "man" ) {
		$patterns = array(
			"/&/",  //html special char: '&' => '&gt;';
			"/</",  //html special char: '>' => '&lt;';
			"/>/",  //html special char: '<' => '&gt;';
			"/\(([a-z0-9_\-]+)\)([a-z0-9\+]+)/", //'(group)command' => man page of command;
			"/\(([a-z0-9_\-]+)\)/"     //'(command)' => man page of command;			
			);

		$replace = array(
			"&amp;",
			"&lt;",
			"&gt;",
			"( \\1 )<a href=\"?docType=$docType&amp;parm=\\2\">\\2</a>",
			"(<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>)"			
			);
	}
	else if ( $docType == "info" ) {
		$patterns = array(
			"/&/",  //html special char: '&' => '&gt;';
			"/</",  //html special char: '>' => '&lt;';
			"/>/",  //html special char: '<' => '&gt;';
			"/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/", //'(group)command' => info page of command;
			"/\(([a-z0-9_\-]+)\)/"     //'(command)' => info page of command;
			);

		$replace = array(
			"&amp;",
			"&lt;",
			"&gt;",
			"(<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>)<a href=\"?docType=$docType&amp;parm=\\2\">\\2</a>",
			"(<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>)"
			);
	}
	else if ( $docType == "perldoc" ) {
		//'Module::Name' => perldoc page of module;
		$patterns = array("/([\w:]+)/");

		$replace = array("<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>");
	}
}


//show header
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

//promter and recursive call
echo "<b>$PHP_MAN_TITLE</b>
	<form action=\"$PHP_SELF\" method=\"get\">
	<p>Command:
	<input type=\"text\" size=\"20\" name=\"parm\" value=\"$parm\"/>
	<input type=\"radio\" name=\"docType\" value=\"man\"$check_man/><a href=\"?docType=man\">man</a>
	<input type=\"radio\" name=\"docType\" value=\"perldoc\"$check_perldoc/><a href=\"?docType=perldoc\">perldoc</a>
	<input type=\"radio\" name=\"docType\" value=\"info\"$check_info/><a href=\"?docType=info\">info</a>
	<script language=\"JavaScript\" type=\"text/javascript\">
	<!--
	this.document.write('<input type=\"hidden\" name=\"screen\" value=\"' + screen.width + '\"/>');
	-->
	</script>
	<input type=\"submit\"/></p>
	</form>
	<hr />
	<pre>";

//highlighting attribute characters
for ( $i = 0; $i < $count; $i ++ ) {
	$lines[$i] = preg_replace($patterns, $replace, $lines[$i]);
	echo "$lines[$i] <br />";
}

//show footer
echo "</pre>
	<hr />
	<!--
	<a href=\"http://validator.w3.org/check/referer\">
	<img style=\"border:0;width:88px;height:31px\"
	src=\"http://www.w3.org/Icons/valid-xhtml10\"
	alt=\"Valid XHTML 1.0!\" /></a>
	<a href=\"http://jigsaw.w3.org/css-validator/\">
	<img style=\"border:0;width:88px;height:31px\"
	src=\"http://jigsaw.w3.org/css-validator/images/vcss\"
	alt=\"Valid CSS!\" /></a>
	-->
	<a href=\"http://sourceforge.net/projects/phpunixman/\">
	\$Id$
	</a>
	</body>
	</html>";
?>