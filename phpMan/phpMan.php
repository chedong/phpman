<?php
// +--------------------------------------------------------------------------------+
// | phpMan:      Unix Manual / Perldoc / Info Web Interface                        |
// +--------------------------------------------------------------------------------+
// | Copyright (C) 2002 Che, Dong chedong@bigfoot.com                               |
// +--------------------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or                  |
// | modify it under the terms of the GNU General Public License                    |
// | as published by the Free Software Foundation; either version 2                 |
// | of the License, or (at your option) any later version.                         |
// |                                                                                |
// | This program is distributed in the hope that it will be useful,                |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of                 |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                  |
// | GNU General Public License for more details.                                   |
// |                                                                                |
// | You should have received a copy of the GNU General Public License              |
// | along with this program; if not, write to the Free Software                    |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.    |
// +--------------------------------------------------------------------------------+
// $Id$

/**
 * phpMan is a web interface of Unix command 'man', 'perldoc', 'info' and 'apropos'.
 * This script makes it easier to read man pages which is lengthy and require you
 * to use 'more' or 'pg' filters. Just try it if you feel hard to remember the command
 * for page back or need to dump man page into text/html format.
 * Tested on Linux and FreeBSD under php 4.x above.
 *
 * function list:
 *    showForm ($parm, $check)              //show input form and recursive call
 *    showHeader ( $title )                 //show html header css style
 *    showFooter ( $validate )              //show html footer
 *    getManPage ($parm, $man_width = 128)  //get html format man page
 *    getInfoPage ($parm)                   //get html format info page
 *    getPerldocPage ($parm)                //get html format perldoc page
 *    getSearchPage ($parm)                 //get html format apropos page
 *    getManIndex ()                        //get man page index
 *    getPerldocIndex ()                    //get perldoc page index
 *    getInfoIndex ()                       //get info page index
 *    formatManPerldoc ($lines)             //formate man, perldoc and info output
 */
 
// +--------------------------------------------------------------------------------+
// | parameter checking and format page output                                      |
// +--------------------------------------------------------------------------------+ 

//global title
$PHP_MAN_TITLE = "phpMan: Unix Manual / Perldoc / Info Web Interface";

//default options
$check[man] = "";
$check[perldoc] = "";
$check[info] = "";
$check[search] = "";

//page content
$content = "";

//set default doc type to man page
if ( !isset($docType) || $docType == "" ) {
	$docType = "man";
}

//remove arbitrary commands
if ( isset($parm) ) {
	$parm = escapeshellcmd($parm);
}
else {
	$parm = "";
}

//Get screen size and set man page column size (It's only work under man1.5+)
if (isset($screen) && $screen != "") {
	$man_width = intval($screen) / 8;
}

/*
 * option checker and get manual page content, if no parameter: get index tree
 * phpMan -- man     -- man page index: section list
 *        |          \- man page by section: command list(by search)
 *        |           \ man page: specified command
 *        \- perldoc -- command list: (by search)
 *        |          \- perldoc page: specified module
 *        \- info    -- info page index: list
 *        |          \- info page:
 *        \- search  -- apropos search results: man page entrance list
 */
switch ( $docType ) {
	case "man":
		$check[man] = " checked=\"checked\"";
		//show man pages
		if ( $parm != "" ){
			$content = getManPage($parm, $man_width);
		}
		//redirect to search sections
		else {
			$content = getManIndex();
		}
		break;
	case "perldoc":
		$check[perldoc] = " checked=\"checked\"";
		if ( $parm != "" ) {
			//exec("perldoc $parm", $lines);
	 		$content = getPerldocPage($parm);
		}
		else {
			//show all possable perl entrance by search keywords: 'pm' 'perl'
			$content = getPerldocIndex();
		}
		break;
	case "info":
		$check[info] = " checked=\"checked\"";
		if ( $parm != "" ){
			$content = getInfoPage($parm);
		}
		else {
			$content = getInfoIndex();
		}
		break;
	case "search":
		$check[search] = " checked=\"checked\"";
		if ( $parm != "" ){
			$content = getSearchPage($parm);
		}
		break;
}

// +--------------------------------------------------------------------------------+
// | show output                                                                    |
// +--------------------------------------------------------------------------------+
showHeader($PHP_MAN_TITLE);
showForm($parm, $check);
echo "<hr /><pre>".$content."</pre><hr />";
showFooter(0);

// +--------------------------------------------------------------------------------+
// | sub functions                                                                  |
// +--------------------------------------------------------------------------------+

//show html header
function showHeader ($title) {
	echo "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>".
		"<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"".
		" \"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">".
		"<html xmlns=\"http://www.w3.org/1999/xhtml\">".
		"<head>".
		"<title>$title</title>".
		"<meta http-equiv=\"Content-Type\" content=\"text/html; charset=ISO-8859-1\"/>".
		"<style type=\"text/css\">".
		"<!--".
		"body {color:#000000;background-color:#EEEEEE} ".
		"b {color:#996600;background-color:#EEEEEE} ".
		"u {color:#008000;background-color:#EEEEEE} ".
		"//-->".
		"</style>".
		"</head>".
		"<body><b>$title</b>";
}


//promter and recursive call
function showForm ($parm, $check) {
	echo "<form action=\"$PHP_SELF\" method=\"get\">".
	"<p>Command:".
	"<input type=\"text\" size=\"20\" name=\"parm\" value=\"".stripslashes($parm)."\"/>".
	"<input type=\"radio\" name=\"docType\" value=\"man\"$check[man]/>".
	"<a href=\"?docType=man\">man</a>".
	"<input type=\"radio\" name=\"docType\" value=\"perldoc\"$checked[perldoc]/>".
	"<a href=\"?docType=search&amp;parm=pm%20perl\">perldoc</a>".
	"<input type=\"radio\" name=\"docType\" value=\"info\"$check[info]/>".
	"<a href=\"?docType=info\">info</a>".
	"<input type=\"radio\" name=\"docType\" value=\"search\"$check[search]/>".
	"<a href=\"?docType=man&amp;parm=apropos\">search(apropos)</a>".
	"<script language=\"JavaScript\" type=\"text/javascript\">".
	"<!--".
	"this.document.write('<input type=\"hidden\" name=\"screen\" value=\"' + screen.width + '\"/>');".
	"-->".
	"</script>".
	"&nbsp;<input type=\"submit\"/></p>".
	"</form>";
}

//show footer
function showFooter ($show_validate = 0) {
	if ( $show_validate ) {
		echo "<a href=\"http://validator.w3.org/check/referer\">".
		"<img style=\"border:0;width:88px;height:31px\" ".
		"src=\"http://www.w3.org/Icons/valid-xhtml10\" ".
		"alt=\"Valid XHTML 1.0!\" /></a>".
		"<a href=\"http://jigsaw.w3.org/css-validator/\">".
		"<img style=\"border:0;width:88px;height:31px\"".
		"src=\"http://jigsaw.w3.org/css-validator/images/vcss\"".
		"alt=\"Valid CSS!\" /></a>";
	}
	echo "<a href=\"http://sourceforge.net/projects/phpunixman/\">".
	"\$Id$".
	"</a></body></html>";
}

//get specified command's man page and convert to html format
function getManPage ($parm, $man_width = 128) {
	exec("MANWIDTH=$man_width man $parm", $lines);
	$output = formatManPerldoc($lines);
	return $output;
}

//get specified perl module's man page and convert to html format
function getPerldocPage ($parm) {
	exec("perldoc $parm", $lines);
	$output = formatManPerlDoc($lines);
	return $output;
}

//get specified command's info page
function getInfoPage ($parm) {
	exec("info $parm", $lines);
	$output = formatManPerlDoc($lines);
	return $output;
}

//search specified keyword by apropos and convert output link to man pages
function getSearchPage ($parm) {
	$patterns = array(
		"/&/",  //html special char: '&' => '&gt;';
		"/</",  //html special char: '>' => '&lt;';
		"/>/",  //html special char: '<' => '&gt;';
		//for linux format of search output
		"/(.*\/)?([\w\-\.\+:]+)((\s+\[)([\w\-\.:]+)(\]\s+))\(([\dnol]\w*)\)/",
		//'(command)' => man page of command;
		"/([\w+\.\-:]+)(\s+)?(\((\d\w*)\))/"
		);
	$replace = array(
		"&amp;",
		"&lt;",
		"&gt;",
		"\\1\\2\\4<a href=\"?docType=man&amp;parm=\\7 \\5\">\\5</a>\\6(\\7)",
		"<a href=\"?docType=man&amp;parm=\\4 \\1\">\\1</a>\\2\\3"
		);
	$cmd = "apropos ".$parm;
	//echo $cmd;
	exec($cmd, $lines);
	$output = "";
	$count = count($lines);
	for ( $i = 0; $i < $count; $i ++ ) {
		$output .= preg_replace($patterns, $replace, $lines[$i]);
		$output .= " <br />";
	}
	return $output;

}

//link to man page list by searching section tag
function getManIndex () {
	$output .= "<a href=\"?docType=search&amp;parm=(1)\">1 - General Commands</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(2)\">2 - System Calls</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(3)\">3 - Subroutines</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(4)\">4 - Special Files</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(5)\">5 - File Formats</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(6)\">6 - Games</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(7)\">7 - Macros and Conventions</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(8)\">8 - Maintenance Commands</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(9)\">9 - Kernel Interface</a><br />";
	$output .= "<a href=\"?docType=search&amp;parm=(n)\">n - New Commands</a><br />";
	return $output;
}

//get perldoc list by searching perl related keywords
function getPerldocIndex () {
	return getSearchPage("pm perl");
}

//get info page index page
function getInfoIndex () {
	exec("info", $lines);
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
		"(<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>)".
		"<a href=\"?docType=$docType&amp;parm=\\2\">\\2</a>",
		"(<a href=\"?docType=$docType&amp;parm=\\1\">\\1</a>)"
		);
	$output = "";
	$count = count($lines);
	for ( $i = 0; $i < $count; $i ++ ) {
		$output .= preg_replace($patterns, $replace, $lines[$i]);
		$output .= " <br />";
	}
	return $output;
}

//convert man perldoc output to html
function formatManPerldoc ($lines) {
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
		"/((<.>)|([\s,]))([\w\-\.\+]+)(<\/.>)?\((<.>)?([\dnol]\w*)(<\/.>)?\)(,)?(<\/.>)?/",
		"/([\s,])([\w\-\.\+]+)\(([\dnol]\w*)\)/",
		//translate link to related perl modules, but $obj->Module::Name-> will not be translate
		//'<u>Module::Name</u>' => ' Module::Name'
		"/((<.>)|([\s,]))(\w+(::\w+)+)(<\/.>)?/",
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
	$output = "";
	$count = count($lines);
	for ( $i = 0; $i < $count; $i ++ ) {
		$output .= preg_replace($patterns, $replace, $lines[$i]);
		$output .= " <br />";
	}
	return $output;
}
?>
