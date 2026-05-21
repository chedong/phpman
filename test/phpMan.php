<?php
declare(strict_types=1);
// +--------------------------------------------------------------------------------+
// | phpMan:      Unix Man page / Perldoc / Info page Web Interface                 |
// +--------------------------------------------------------------------------------+
// | Copyright (C) 2002 - 2026 Che, Dong chedong AT chedong.com                     |
// +--------------------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or                  |
// | modify it under the terms of the GNU General Public License                    |
// | the Free Software Foundation; either version 2 of the License, or              |
// | (at your option) any later version.                                            |
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
// $Id: phpMan.php,v 4.54 2007/08/21 09:05:22 chedong Exp $

/**
 * phpMan is a web interface of Unix command 'man', 'perldoc', 'info' and 'apropos'.
 * This script makes it easier to read man pages which is lengthy and require you
 * to use 'more' or 'pg' filters. Just try it if you feel hard to remember the command
 * for page back or need to dump man page into text/html format.
 * Compatible with GNU/Linux and FreeBSD under PHP 8.x.
 *
 * !!! Note: on Apache 2.0.x need configure: AcceptPathInfo On !!!
 *
 * You can also find other web interface:
 *   shell-sed-awk based script at:
 *     http://www.softlab.ntua.gr/~christia/man-cgi.html
 *   perl based script at:
 *     http://www.freebsd.org/cgi/man.cgi
 *     http://www.freebsd.org/cgi/man.cgi/source
 *   dwww of debian:
 *     http://packages.debian.org/stable/doc/dwww
 *
 * Sub function list:
 *     showHeader ( $css_style )             //show html header with css style
 *     showForm ($parameter, $check)         //show input form and recursive call
 *     showFooter ( $validate )              //show html footer
 *     getManPage ($parameter, $mode)        //get html format man page
 *     getInfoPage ($parameter)              //get html format info page
 *     getPerldocPage ($parameter)           //get html format perldoc page
 *     getSearchPage ($parameter)            //get html format apropos page
 *     getManIndex ()                        //get man page index
 *     getPerldocIndex ()                    //get perldoc page index
 *     getInfoIndex ()                       //get info page index
 *     formatManPerlDoc ($lines)             //formate man, perldoc and info output
 *
 */

// +--------------------------------------------------------------------------------+
// | global configures: output html style and whether show xhtml validators         |
// +--------------------------------------------------------------------------------+

//app title
$PHP_MAN_TITLE = "phpMan: Unix Man page/ Perldoc / Info page Web Interface";

//set MANWIDTH for man 1.5+, default for 1024 * 768
$MAN_WIDTH = 100;

//use colored man page
$CSS_STYLE = "<style type=\"text/css\">\n".
    "body {color:#000000;background-color:#EEEEEE;}\n".
    "b {color:#996600;background-color:#EEEEEE;}\n".
    "u {color:#008000;background-color:#EEEEEE;}\n".
    "</style>\n";

$VALIDATOR = "";

//unmask comments to show xhtml 1.0 and css validator
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . serverValue("HTTP_HOST", "localhost") . serverValue("REQUEST_URI", scriptName());
$VALIDATOR = "<a href=\"https://validator.w3.org/check?uri=" . urlencode($currentUrl) . "\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"http://www.w3.org/Icons/valid-xhtml10\"".
" alt=\"Valid XHTML 1.0!\" /></a>".
"<a href=\"https://jigsaw.w3.org/css-validator/validator?uri=" . urlencode($currentUrl) . "&amp;profile=css3svg&amp;usermedium=all&amp;warning=1&amp;vextwarning=&amp;lang=zh-cn\">".
"<img style=\"border:0;width:88px;height:31px\"".
" src=\"https://jigsaw.w3.org/css-validator/images/vcss\"".
" alt=\"Valid CSS!\" /></a>";

ini_set("default_charset", "UTF-8");

function h (mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function serverValue (string $key, string $default = ""): string {
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : $default;
}

function scriptName (): string {
    return serverValue("SCRIPT_NAME", "phpMan.php");
}

function requestValue (array $source, string $key): string {
    if (!isset($source[$key]) || is_array($source[$key])) {
        return "";
    }

    return trim((string)$source[$key]);
}

function normalizeMode (mixed $mode): string {
    $mode = strtolower(trim((string)$mode));
    $allowed_modes = array(
        "man" => true,
        "perldoc" => true,
        "info" => true,
        "search" => true,
        "source" => true,
        "phpinfo" => true,
        "copyright" => true,
    );

    return isset($allowed_modes[$mode]) ? $mode : "man";
}

function normalizeParameter (mixed $parameter): string {
    $parameter = trim((string)$parameter);
    $parameter = str_replace(array("/", "\0"), array(" ", ""), $parameter);
    $parameter = preg_replace("/[\x00-\x1F\x7F]+/", " ", $parameter);

    return trim((string)$parameter);
}

function normalizeSection (mixed $section): string {
    $section = trim((string)$section);
    if (preg_match("/^[A-Za-z0-9_]+$/", $section) !== 1) {
        return "";
    }

    return $section;
}

// +--------------------------------------------------------------------------------+
// | parameter checking and format page output                                      |
// +--------------------------------------------------------------------------------+

//default options

//page content
$content = "";
//output mode
$mode = "";
$parameter = "";
$section = "";

$check['man'] = "";
$check['perldoc'] = "";
$check['info'] = "";
$check['search'] = "";

// Detect format preference (markdown or html)
$format = "html";
if (requestValue($_GET, "format") === "markdown") {
    $format = "markdown";
} else {
    $accept = serverValue("HTTP_ACCEPT");
    if (str_contains(strtolower($accept), "text/markdown") || str_contains(strtolower($accept), "text/x-markdown")) {
        $format = "markdown";
    }
}

/**
 * trans$_SERVER["ORIG_PATH_INFO"] to $_SERVER["PATH_INFO"]
 * for cgi/fcgi mode of php
 */
if ( serverValue("ORIG_PATH_INFO") !== "" ){
    $_SERVER["PATH_INFO"] = serverValue("ORIG_PATH_INFO");
}
/**
 * parse parameters from $_SERVER["PATH_INFO"]: phpMan.php/MODE/COMMAND/SECTION/FORMAT
 * or parse parameters from HTTP/GET
 */
if ( serverValue("PATH_INFO") !== "" && trim(serverValue("PATH_INFO")) != "") {
    $array_param = explode('/', serverValue("PATH_INFO"));
    $segments = [];
    foreach ($array_param as $p) {
        $p_trimmed = trim($p);
        if ($p_trimmed !== "") {
            $segments[] = $p_trimmed;
        }
    }
    
    $allowed_modes = array("man", "perldoc", "info", "search", "source", "phpinfo", "copyright");
    $seg_count = count($segments);
    
    if ($seg_count >= 1) {
        $first_seg = strtolower($segments[0]);
        if (in_array($first_seg, $allowed_modes)) {
            $mode = $first_seg;
            
            if ($seg_count >= 2) {
                $parameter = urldecode($segments[1]);
            }
            if ($seg_count >= 3) {
                $third_seg_lower = strtolower($segments[2]);
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown") {
                    $format = $third_seg_lower;
                } else {
                    $section = $segments[2];
                }
            }
            if ($seg_count >= 4) {
                $fourth_seg_lower = strtolower($segments[3]);
                if ($fourth_seg_lower === "html" || $fourth_seg_lower === "markdown") {
                    $format = $fourth_seg_lower;
                } else {
                    $section = $segments[3];
                }
            }
        } else {
            // Mode is NOT explicitly provided, default to 'man'
            $mode = "man";
            $parameter = urldecode($segments[0]);
            
            if ($seg_count >= 2) {
                $second_seg_lower = strtolower($segments[1]);
                if ($second_seg_lower === "html" || $second_seg_lower === "markdown") {
                    $format = $second_seg_lower;
                } else {
                    $section = $segments[1];
                }
            }
            if ($seg_count >= 3) {
                $third_seg_lower = strtolower($segments[2]);
                if ($third_seg_lower === "html" || $third_seg_lower === "markdown") {
                    $format = $third_seg_lower;
                } else {
                    $section = $segments[2];
                }
            }
        }
    }
}
else {
    if ( requestValue($_GET, "mode") != "" ) {
        $mode = requestValue($_GET, "mode");
    }

    if ( requestValue($_GET, "parameter") != "" ) {
        $parameter = requestValue($_GET, "parameter");
    }

    if ( requestValue($_GET, "section") != "") {
        $section = requestValue($_GET, "section");
    }
}

// GET parameter always overrides
if ( requestValue($_GET, "format") != "" ) {
    $format = strtolower(trim(requestValue($_GET, "format")));
}

// set default mode
$mode = normalizeMode($mode);
$parameter = normalizeParameter($parameter);
$section = normalizeSection($section);

if ($parameter !== "" && $section === "" && $mode === "man") {
    $section = "1";
}

if ( $parameter != "" ) {
    if ( $section == "" ) {
        $PHP_MAN_TITLE = $parameter . " - phpMan";
    }
    else {
        $PHP_MAN_TITLE = $parameter . "(" . $section . ") - phpMan";
    }
}

//show source of file
if ( $mode == "source" ) {
    showHeader($PHP_MAN_TITLE, $CSS_STYLE);
    highlight_file(serverValue("SCRIPT_FILENAME", __FILE__));
    echo "</body></html>";
    exit;
}
//show php info
else if ( $mode == "phpinfo" ) {
    phpinfo();
    exit;
}
//show GPL
else if ( $mode == "copyright" ) {
    showHeader($PHP_MAN_TITLE, $CSS_STYLE);
    showCopyright();
    echo "</body></html>";
    exit;
}
/**
 * option checker and get manual page content, if no parameter: get index tree
 * phpMan -- man     -- man page index: section list
 *        |          \- man page by section: command list(via search)
 *        |          \- man page: specified command
 *        \- perldoc -- command list: (by search)
 *        |          \- perldoc page: specified module
 *        \- info    -- info page index: list
 *        |          \- info page:
 *        \- search  -- apropos search results: man page entrance list
 */
switch ( $mode ) {
    case "man":
        $check['man'] = " checked=\"checked\"";
        //show man pages
        if ( $parameter != "" ) {
            $content = getManPage($parameter, $section, $format);

            // retry lower case if content is empty
            if ( preg_match("/^[A-Z\._]+$/",$parameter) && trim($content) == ""){
                $content = getManPage(strtolower($parameter), $section, $format);
            }

            //not find command then redirect to search sections
            if (trim($content) == "") {
                $content = getSearchPage($parameter, $format);
            }
        }
        //redirect to search sections
        else {
            $content = getManIndex($format);
        }
        break;
    case "perldoc":
        $check['perldoc'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = getPerldocPage($parameter, $format);
        }
        else {
            //show all possable perl entrance by search keywords: 'perl'
            $content = getPerldocIndex($format);
        }
        break;
    case "info":
        $check['info'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = getInfoPage($parameter, $format);
        }
        else {
            $content = getInfoIndex($format);
        }
        break;
    case "search":
        $check['search'] = " checked=\"checked\"";
        if ( $parameter != "" ) {
            $content = getSearchPage($parameter, $format);
        }
        break;
}

// Show Markdown or HTML output
if ($format === "markdown") {
    header("Content-Type: text/markdown; charset=UTF-8");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 3600 * 24 * 30) . " GMT");
    echo "# " . $PHP_MAN_TITLE . "\n\n" . $content;
    exit;
}

// +--------------------------------------------------------------------------------+
// | show output                                                                    |
// +--------------------------------------------------------------------------------+
showHeader($PHP_MAN_TITLE, $CSS_STYLE);
echo "<h1><a href=\"".$_SERVER['PHP_SELF']."\">".h($PHP_MAN_TITLE)."</a></h1>\n";
showForm($parameter, $check);
echo "<hr /><pre>".$content."</pre><hr />";
showFooter($VALIDATOR);


// +--------------------------------------------------------------------------------+
// | sub functions                                                                  |
// +--------------------------------------------------------------------------------+

//show html header
function showHeader (string $title = "", string $css_style = ""): void {
    header("Content-Type: text/html; charset=UTF-8");
    // always modified now
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    // Expires one month later
    header("Expires: " .gmdate ("D, d M Y H:i:s", time() + 3600 * 24 * 30). " GMT");

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
        "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" ".
        "\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">".
        "<html xmlns=\"http://www.w3.org/1999/xhtml\">\n".
        "<head>\n".
        "<title>".h($title)."</title>\n".
        "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n";

    echo $css_style;

    echo "</head>\n<body>\n";
}

//promter and recursive call
function showForm (string $parameter, array $check): void {
    $script_name = h(scriptName());
    $parameter_value = h(stripslashes((string)$parameter));

    echo "<form action=\"".$script_name."\" method=\"get\">\n".
        "<p>Command: ".
        "<input type=\"text\" size=\"20\" name=\"parameter\" value=\"".$parameter_value."\"/>\n".
        "<input type=\"radio\" name=\"mode\" value=\"man\"".$check['man']."/>".
        "<a href=\"".$script_name."/man\">man</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"perldoc\"".$check['perldoc']."/>".
        "<a href=\"".$script_name."/search/perl\">perldoc</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"info\"".$check['info']."/>".
        "<a href=\"".$script_name."/info\">info</a>\n".
        "<input type=\"radio\" name=\"mode\" value=\"search\"".$check['search']."/>".
        "<a href=\"".$script_name."/man/apropos\">search(apropos)</a>\n".
        "&nbsp;<input type=\"submit\"/></p>".
        "</form>\n";
}

//show footer
function showFooter (string $validator = ""): void {
    $script_name = h(scriptName());
    $server_software = h(serverValue("SERVER_SOFTWARE", "unknown server"));
    $home_url = h("http://" . serverValue("HTTP_HOST", "localhost"));
    $remote_addr = h(serverValue("REMOTE_ADDR", "unknown"));
    $user_agent = h(serverValue("HTTP_USER_AGENT", "unknown"));

    echo "<p>Generated by <a href=\"".$script_name."/source\">" .
        "\$Id: phpMan.php,v 4.54 2007/08/21 09:05:22 chedong Exp $" .
        "</a> Author: <a href=\"http://www.chedong.com/\">Che Dong</a><br />" .
        "On <a href=\"".$script_name."/phpinfo\">" . $server_software .
        "</a><br />Under <a href=\"".$script_name."/copyright\">GNU General Public License</a><br />" .
        "<a href=\"" . $home_url . "\">" . date("Y-m-d H:i") . " @". $remote_addr .
        " CrawledBy " . $user_agent . "</a>" .
        "<br />" . $validator . "</p>" .
        "</body></html>";
}

//get specified command's man page and convert to html format
function getManPage (string $parameter, string $section = "1", string $format = "html"): string {
    global $MAN_WIDTH;
    $lines = array();
    $command = "MANWIDTH=".(int)$MAN_WIDTH." man ";
    if ($section !== "") {
        $command .= escapeshellarg($section)." ";
    }
    $command .= escapeshellarg($parameter);

    exec($command, $lines);
    if ($format === "markdown") {
        return formatManPerlDocToMarkdown($lines, "man");
    }
    return formatManPerlDoc($lines, "man");
}

//get specified perl module's man page and convert to html format
function getPerldocPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("perldoc ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines, "perldoc") : formatManPerlDoc($lines, "perldoc");
    }

    // try build in function
    $lines = array();
    exec("perldoc -f ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines, "perldoc") : formatManPerlDoc($lines, "perldoc");
    }

    // try perldoc search
    $lines = array();
    exec("perldoc -q ".escapeshellarg($parameter), $lines, $return_code);
    if ($return_code === 0) {
        return $format === "markdown" ? formatManPerlDocToMarkdown($lines, "perldoc") : formatManPerlDoc($lines, "perldoc");
    }

    return "";
}

//get specified command's info page
function getInfoPage (string $parameter, string $format = "html"): string {
    $lines = array();
    exec("info ".escapeshellarg($parameter), $lines);
    return $format === "markdown" ? formatManPerlDocToMarkdown($lines, "info") : formatManPerlDoc($lines, "info");
}

/**
 * search specified keyword by apropos and convert output link to man pages
 * Note: on linux, rebuild whatis database under root with:
 * /usr/sbin/makewhatis -w
 */
function getSearchPage (string $parameter, string $format = "html"): string {
    $script_name = scriptName();
    
    // get last parameter of search string
    // example: "1 GCC" ==> "GCC"
    $parameter_parts = preg_split("/\s+/", trim((string)$parameter));
    $parameter = $parameter_parts === false ? "" : (string)array_pop($parameter_parts);

    if ($parameter === "") {
        return "";
    }

    $cmd = "apropos ".escapeshellarg($parameter);
    $lines = array();
    exec($cmd, $lines);

    if ($format === "markdown") {
        $patterns = array(
            "/(.*\/)?([\w\-\.\+:]+)((\s+\[)([\w\-\.:]+)(\]\s+))\(([\dnol]\w*)\)/",
            "/([\w+\.\-:]+)(\s+)?(\(([\dnol]\w*)\))/"
        );
        $replace = array(
            '$1$2$4[$5($7)]('.$script_name.'/man/$5/$7/markdown)$6($7)',
            '[$1($3)]('.$script_name.'/man/$1/$3/markdown)'
        );
        $output = "";
        $count = count($lines);
        for ( $i = 0; $i < $count; $i ++ ) {
            $output .= preg_replace($patterns, $replace, $lines[$i]) . "\n";
        }
        return $output;
    }

    $patterns = array(
                    "/&/",  //html special char: '&' => '&gt;';
                    "/</",  //html special char: '>' => '&lt;';
                    "/>/",  //html special char: '<' => '&gt;';
                    //for linux format of search output
                    "/(.*\/)?([\w\-\.\+:]+)((\s+\[)([\w\-\.:]+)(\]\s+))\(([\dnol]\w*)\)/",
                    //'(command)' => man page of command;
                    "/([\w+\.\-:]+)(\s+)?(\(([\dnol]\w*)\))/"
                );
    $replace = array(
                   "&amp;",
                   "&lt;",
                   "&gt;",
                   '$1$2$4<a href="'.$script_name.'/man/$5/$7">$5</a>$6($7)',
                   '<a href="'.$script_name.'/man/$1/$4">$1</a>$2$3'
               );
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $output .= preg_replace($patterns, $replace, $lines[$i]);
        $output .= "\n";
    }
    return $output;
}

//link to man page list by searching section tag
function getManIndex (string $format = "html"): string {
    $script_name = scriptName();
    if ($format === "markdown") {
        return "[1 - General Commands](".$script_name."/search/(1)/markdown) [intro(1)](".$script_name."/man/intro/1/markdown)\n" .
               "[2 - System Calls](".$script_name."/search/(2)/markdown) [intro(2)](".$script_name."/man/intro/2/markdown)\n" .
               "[3 - Subroutines](".$script_name."/search/(3)/markdown) [intro(3)](".$script_name."/man/intro/3/markdown)\n" .
               "[4 - Special Files](".$script_name."/search/(4)/markdown) [intro(4)](".$script_name."/man/intro/4/markdown)\n" .
               "[5 - File Formats](".$script_name."/search/(5)/markdown) [intro(5)](".$script_name."/man/intro/5/markdown)\n" .
               "[6 - Games](".$script_name."/search/(6)/markdown) [intro(6)](".$script_name."/man/intro/6/markdown)\n" .
               "[7 - Macros and Conventions](".$script_name."/search/(7)/markdown) [intro(7)](".$script_name."/man/intro/7/markdown)\n" .
               "[8 - Maintenance Commands](".$script_name."/search/(8)/markdown) [intro(8)](".$script_name."/man/intro/8/markdown)\n" .
               "[9 - Kernel Interface](".$script_name."/search/(9)/markdown) [intro(9)](".$script_name."/man/intro/9/markdown)\n" .
               "[n - New Commands](".$script_name."/search/(n)/markdown)\n";
    }

    $script_name_html = h($script_name);
    $output = "<a href=\"".$script_name_html."/search/(1)\">1 - General Commands</a> ".
               "<a href=\"".$script_name_html."/man/intro/1\">intro(1)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(2)\">2 - System Calls</a> ".
               "<a href=\"".$script_name_html."/man/intro/2\">intro(2)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(3)\">3 - Subroutines</a> ".
               "<a href=\"".$script_name_html."/man/intro/3\">intro(3)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(4)\">4 - Special Files</a> ".
               "<a href=\"".$script_name_html."/man/intro/4\">intro(4)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(5)\">5 - File Formats</a> ".
               "<a href=\"".$script_name_html."/man/intro/5\">intro(5)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(6)\">6 - Games</a> ".
               "<a href=\"".$script_name_html."/man/intro/6\">intro(6)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(7)\">7 - Macros and Conventions</a> ".
               "<a href=\"".$script_name_html."/man/intro/7\">intro(7)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(8)\">8 - Maintenance Commands</a> ".
               "<a href=\"".$script_name_html."/man/intro/8\">intro(8)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(9)\">9 - Kernel Interface</a> ".
               "<a href=\"".$script_name_html."/man/intro/9\">intro(9)</a>\n";
    $output .= "<a href=\"".$script_name_html."/search/(n)\">n - New Commands</a>\n";

    return $output;
}

//get perldoc list by searching perl related keywords
function getPerldocIndex (string $format = "html"): string {
    return getSearchPage("perl", $format);
}

//get info page index page
function getInfoIndex (string $format = "html"): string {
    $lines = array();
    exec("info", $lines);
    $script_name = scriptName();

    if ($format === "markdown") {
        $patterns = array(
            "/\(([a-z0-9_\-]+)\)([a-z0-9_\+]+)/",
            "/\(([a-z0-9_\-]+)\)/"
        );
        $replace = array(
            '([$1]('.$script_name.'/info/$1/markdown))[$2]('.$script_name.'/info/$2/markdown)',
            '([$1]('.$script_name.'/info/$1/markdown))'
        );
        $output = "";
        $count = count($lines);
        for ( $i = 0; $i < $count; $i ++ ) {
            $output .= preg_replace($patterns, $replace, $lines[$i]) . "\n";
        }
        return $output;
    }

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
                   '(<a href="'.$script_name.'/info/$1">$1</a>)'.
                   '<a href="'.$script_name.'/info/$2">$2</a>',
                   '(<a href="'.$script_name.'/info/$1">$1</a>)'
               );
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $output .= preg_replace($patterns, $replace, $lines[$i]);
        $output .= " \n";
    }
    return $output;
}

//convert man perldoc output to html
function formatManPerlDoc (array $lines, string $mode = "man"): string {
    $script_name = h(scriptName());
    $mode = h($mode);
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
                    "/".chr(27)."\[1m(.*?)".chr(27)."\[0m/",  //for perldoc on RedHat 8 only
                    "/".chr(27)."\[4m(.*?)".chr(27)."\[24m/", //for perldoc on RedHat 8 only
                    "/(([\w\-\.]+)@([\w\-]+)(\.[\w\-]+)+)/",  //link to email
                    "/([\w]+:\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i", //link to url
                    "/".chr(7)."/",  //reverse '>'
                );

    $replace = array(
                   chr(5),
                   chr(6),
                   chr(7),
                   '<b>$1</b>',
                   '<b>$1</b>',
                   '<u>$1</u>',
                   '<b>$1</b>',
                   "&amp;",
                   "&lt;",
                   "",
                   "<b>_",
                   "",
                   '$3$4($7)$9',
                   '$1<a href="'.$script_name.'/man/$2/$3">$2($3)</a>',
                   '$3<a href="'.$script_name.'/'.$mode.'/$4">$4</a>',
                   '<b>$1</b>',
                   '<u>$1</u>',
                   '<a href="mailto:$2 AT $3$4">$2<u> AT </u>$3$4</a>',
                   '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
                   "&gt;",
               );
    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $output .= preg_replace($patterns, $replace, $lines[$i]);
        $output .= "\n";
    }
    return $output;
}

//convert man perldoc output to markdown
function formatManPerlDocToMarkdown (array $lines, string $mode = "man"): string {
    $script_name = scriptName();
    $mode = h($mode);

    $patterns = array(
        "/.".chr(8).".".chr(8)."(.)".chr(8)."./",  // ?^H?^H?^H? => bold
        "/_".chr(8)."(.)".chr(8)."./",  // _^H?^H? => bold
        "/_".chr(8)."(.)/",  // _^H? => underline
        "/.".chr(8)."(.)/",  // ?^H? => bold
        "/".chr(27)."\[1m(.*?)".chr(27)."\[0m/",  // perldoc ANSI bold
        "/".chr(27)."\[4m(.*?)".chr(27)."\[24m/", // perldoc ANSI underline
    );
    $replace = array(
        "\x01$1\x02",
        "\x01$1\x02",
        "\x03$1\x04",
        "\x01$1\x02",
        "\x01$1\x02",
        "\x03$1\x04",
    );

    $output = "";
    $count = count($lines);
    for ( $i = 0; $i < $count; $i ++ ) {
        $line = $lines[$i];
        
        // Format backspaces and ANSI escapes
        $line = preg_replace($patterns, $replace, $line);
        
        // Merge consecutive bold/underline
        $line = str_replace(array("\x02\x01", "\x04\x03"), "", $line);
        $line = str_replace(array("\x01", "\x02", "\x03", "\x04"), array("**", "**", "_", "_"), $line);
        
        // Section Headers: e.g. NAME, SYNOPSIS
        if (preg_match('/^[A-Z][A-Z\s\-]{1,29}$/', $line)) {
            $line = '## ' . $line;
        }

        // Email
        $line = preg_replace('/([\w\-\.]+)@([\w\-]+(?:\.[\w\-]+)+)/', '[$0](mailto:$0)', $line);
        // URL
        $line = preg_replace('/(https?:\/\/[\w%\-\?&;#~=\.\/\@]+[\w\/])/i', '[$0]($0)', $line);

        // Command references
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?([\w\-\.\+]+)(?:\*\*|_)?\((?:\*\*|_)?([\dnol]\w*)(?:\*\*|_)?\)/',
            function ($matches) use ($script_name) {
                return '[' . $matches[0] . '](' . $script_name . '/man/' . urlencode($matches[1]) . '/' . urlencode($matches[2]) . '/markdown)';
            },
            $line
        );
        
        // Perl modules: Module::Name
        $line = preg_replace_callback(
            '/(?<![\w])(?:\*\*|_)?(\w+(?:::\w+)+)(?:\*\*|_)?/',
            function ($matches) use ($script_name, $mode) {
                return '[' . $matches[0] . '](' . $script_name . '/' . $mode . '/' . urlencode($matches[1]) . '/markdown)';
            },
            $line
        );

        $output .= $line . "\n";
    }
    return $output;
}

// +--------------------------------------------------------------------------------+
// | GNU GENERAL PUBLIC LICENSE   Version 2                                         |
// |        http://www.gnu.org/licenses/gpl.txt                                     |
// +--------------------------------------------------------------------------------+
function showCopyright (): void {
echo <<<END_OF_COPYRIGHT
<pre>
		    GNU GENERAL PUBLIC LICENSE
		       Version 2, June 1991

 Copyright (C) 1989, 1991 Free Software Foundation, Inc.
                       59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 Everyone is permitted to copy and distribute verbatim copies
 of this license document, but changing it is not allowed.

			    Preamble

  The licenses for most software are designed to take away your
freedom to share and change it.  By contrast, the GNU General Public
License is intended to guarantee your freedom to share and change free
software--to make sure the software is free for all its users.  This
General Public License applies to most of the Free Software
Foundation's software and to any other program whose authors commit to
using it.  (Some other Free Software Foundation software is covered by
the GNU Library General Public License instead.)  You can apply it to
your programs, too.

  When we speak of free software, we are referring to freedom, not
price.  Our General Public Licenses are designed to make sure that you
have the freedom to distribute copies of free software (and charge for
this service if you wish), that you receive source code or can get it
if you want it, that you can change the software or use pieces of it
in new free programs; and that you know you can do these things.

  To protect your rights, we need to make restrictions that forbid
anyone to deny you these rights or to ask you to surrender the rights.
These restrictions translate to certain responsibilities for you if you
distribute copies of the software, or if you modify it.

  For example, if you distribute copies of such a program, whether
gratis or for a fee, you must give the recipients all the rights that
you have.  You must make sure that they, too, receive or can get the
source code.  And you must show them these terms so they know their
rights.

  We protect your rights with two steps: (1) copyright the software, and
(2) offer you this license which gives you legal permission to copy,
distribute and/or modify the software.

  Also, for each author's protection and ours, we want to make certain
that everyone understands that there is no warranty for this free
software.  If the software is modified by someone else and passed on, we
want its recipients to know that what they have is not the original, so
that any problems introduced by others will not reflect on the original
authors' reputations.

  Finally, any free program is threatened constantly by software
patents.  We wish to avoid the danger that redistributors of a free
program will individually obtain patent licenses, in effect making the
program proprietary.  To prevent this, we have made it clear that any
patent must be licensed for everyone's free use or not licensed at all.

  The precise terms and conditions for copying, distribution and
modification follow.

		    GNU GENERAL PUBLIC LICENSE
   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION

  0. This License applies to any program or other work which contains
a notice placed by the copyright holder saying it may be distributed
under the terms of this General Public License.  The "Program", below,
refers to any such program or work, and a "work based on the Program"
means either the Program or any derivative work under copyright law:
that is to say, a work containing the Program or a portion of it,
either verbatim or with modifications and/or translated into another
language.  (Hereinafter, translation is included without limitation in
the term "modification".)  Each licensee is addressed as "you".

Activities other than copying, distribution and modification are not
covered by this License; they are outside its scope.  The act of
running the Program is not restricted, and the output from the Program
is covered only if its contents constitute a work based on the
Program (independent of having been made by running the Program).
Whether that is true depends on what the Program does.

  1. You may copy and distribute verbatim copies of the Program's
source code as you receive it, in any medium, provided that you
conspicuously and appropriately publish on each copy an appropriate
copyright notice and disclaimer of warranty; keep intact all the
notices that refer to this License and to the absence of any warranty;
and give any other recipients of the Program a copy of this License
along with the Program.

You may charge a fee for the physical act of transferring a copy, and
you may at your option offer warranty protection in exchange for a fee.

  2. You may modify your copy or copies of the Program or any portion
of it, thus forming a work based on the Program, and copy and
distribute such modifications or work under the terms of Section 1
above, provided that you also meet all of these conditions:

    a) You must cause the modified files to carry prominent notices
    stating that you changed the files and the date of any change.

    b) You must cause any work that you distribute or publish, that in
    whole or in part contains or is derived from the Program or any
    part thereof, to be licensed as a whole at no charge to all third
    parties under the terms of this License.

    c) If the modified program normally reads commands interactively
    when run, you must cause it, when started running for such
    interactive use in the most ordinary way, to print or display an
    announcement including an appropriate copyright notice and a
    notice that there is no warranty (or else, saying that you provide
    a warranty) and that users may redistribute the program under
    these conditions, and telling the user how to view a copy of this
    License.  (Exception: if the Program itself is interactive but
    does not normally print such an announcement, your work based on
    the Program is not required to print an announcement.)

These requirements apply to the modified work as a whole.  If
identifiable sections of that work are not derived from the Program,
and can be reasonably considered independent and separate works in
themselves, then this License, and its terms, do not apply to those
sections when you distribute them as separate works.  But when you
distribute the same sections as part of a whole which is a work based
on the Program, the distribution of the whole must be on the terms of
this License, whose permissions for other licensees extend to the
entire whole, and thus to each and every part regardless of who wrote it.

Thus, it is not the intent of this section to claim rights or contest
your rights to work written entirely by you; rather, the intent is to
exercise the right to control the distribution of derivative or
collective works based on the Program.

In addition, mere aggregation of another work not based on the Program
with the Program (or with a work based on the Program) on a volume of
a storage or distribution medium does not bring the other work under
the scope of this License.

  3. You may copy and distribute the Program (or a work based on it,
under Section 2) in object code or executable form under the terms of
Sections 1 and 2 above provided that you also do one of the following:

    a) Accompany it with the complete corresponding machine-readable
    source code, which must be distributed under the terms of Sections
    1 and 2 above on a medium customarily used for software interchange; or,

    b) Accompany it with a written offer, valid for at least three
    years, to give any third party, for a charge no more than your
    cost of physically performing source distribution, a complete
    machine-readable copy of the corresponding source code, to be
    distributed under the terms of Sections 1 and 2 above on a medium
    customarily used for software interchange; or,

    c) Accompany it with the information you received as to the offer
    to distribute corresponding source code.  (This alternative is
    allowed only for noncommercial distribution and only if you
    received the program in object code or executable form with such
    an offer, in accord with Subsection b above.)

The source code for a work means the preferred form of the work for
making modifications to it.  For an executable work, complete source
code means all the source code for all modules it contains, plus any
associated interface definition files, plus the scripts used to
control compilation and installation of the executable.  However, as a
special exception, the source code distributed need not include
anything that is normally distributed (in either source or binary
form) with the major components (compiler, kernel, and so on) of the
operating system on which the executable runs, unless that component
itself accompanies the executable.

If distribution of executable or object code is made by offering
access to copy from a designated place, then offering equivalent
access to copy the source code from the same place counts as
distribution of the source code, even though third parties are not
compelled to copy the source along with the object code.

  4. You may not copy, modify, sublicense, or distribute the Program
except as expressly provided under this License.  Any attempt
otherwise to copy, modify, sublicense or distribute the Program is
void, and will automatically terminate your rights under this License.
However, parties who have received copies, or rights, from you under
this License will not have their licenses terminated so long as such
parties remain in full compliance.

  5. You are not required to accept this License, since you have not
signed it.  However, nothing else grants you permission to modify or
distribute the Program or its derivative works.  These actions are
prohibited by law if you do not accept this License.  Therefore, by
modifying or distributing the Program (or any work based on the
Program), you indicate your acceptance of this License to do so, and
all its terms and conditions for copying, distributing or modifying
the Program or works based on it.

  6. Each time you redistribute the Program (or any work based on the
Program), the recipient automatically receives a license from the
original licensor to copy, distribute or modify the Program subject to
these terms and conditions.  You may not impose any further
restrictions on the recipients' exercise of the rights granted herein.
You are not responsible for enforcing compliance by third parties to
this License.

  7. If, as a consequence of a court judgment or allegation of patent
infringement or for any other reason (not limited to patent issues),
conditions are imposed on you (whether by court order, agreement or
otherwise) that contradict the conditions of this License, they do not
excuse you from the conditions of this License.  If you cannot
distribute so as to satisfy simultaneously your obligations under this
License and any other pertinent obligations, then as a consequence you
may not distribute the Program at all.  For example, if a patent
license would not permit royalty-free redistribution of the Program by
all those who receive copies directly or indirectly through you, then
the only way you could satisfy both it and this License would be to
refrain entirely from distribution of the Program.

If any portion of this section is held invalid or unenforceable under
any particular circumstance, the balance of the section is intended to
apply and the section as a whole is intended to apply in other
circumstances.

It is not the purpose of this section to induce you to infringe any
patents or other property right claims or to contest validity of any
such claims; this section has the sole purpose of protecting the
integrity of the free software distribution system, which is
implemented by public license practices.  Many people have made
generous contributions to the wide range of software distributed
through that system in reliance on consistent application of that
system; it is up to the author/donor to decide if he or she is willing
to distribute software through any other system and a licensee cannot
impose that choice.

This section is intended to make thoroughly clear what is believed to
be a consequence of the rest of this License.

  8. If the distribution and/or use of the Program is restricted in
certain countries either by patents or by copyrighted interfaces, the
original copyright holder who places the Program under this License
may add an explicit geographical distribution limitation excluding
those countries, so that distribution is permitted only in or among
countries not thus excluded.  In such case, this License incorporates
the limitation as if written in the body of this License.

  9. The Free Software Foundation may publish revised and/or new versions
of the General Public License from time to time.  Such new versions will
be similar in spirit to the present version, but may differ in detail to
address new problems or concerns.

Each version is given a distinguishing version number.  If the Program
specifies a version number of this License which applies to it and "any
later version", you have the option of following the terms and conditions
either of that version or of any later version published by the Free
Software Foundation.  If the Program does not specify a version number of
this License, you may choose any version ever published by the Free Software
Foundation.

  10. If you wish to incorporate parts of the Program into other free
programs whose distribution conditions are different, write to the author
to ask for permission.  For software which is copyrighted by the Free
Software Foundation, write to the Free Software Foundation; we sometimes
make exceptions for this.  Our decision will be guided by the two goals
of preserving the free status of all derivatives of our free software and
of promoting the sharing and reuse of software generally.

			    NO WARRANTY

  11. BECAUSE THE PROGRAM IS LICENSED FREE OF CHARGE, THERE IS NO WARRANTY
FOR THE PROGRAM, TO THE EXTENT PERMITTED BY APPLICABLE LAW.  EXCEPT WHEN
OTHERWISE STATED IN WRITING THE COPYRIGHT HOLDERS AND/OR OTHER PARTIES
PROVIDE THE PROGRAM "AS IS" WITHOUT WARRANTY OF ANY KIND, EITHER EXPRESSED
OR IMPLIED, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.  THE ENTIRE RISK AS
TO THE QUALITY AND PERFORMANCE OF THE PROGRAM IS WITH YOU.  SHOULD THE
PROGRAM PROVE DEFECTIVE, YOU ASSUME THE COST OF ALL NECESSARY SERVICING,
REPAIR OR CORRECTION.

  12. IN NO EVENT UNLESS REQUIRED BY APPLICABLE LAW OR AGREED TO IN WRITING
WILL ANY COPYRIGHT HOLDER, OR ANY OTHER PARTY WHO MAY MODIFY AND/OR
REDISTRIBUTE THE PROGRAM AS PERMITTED ABOVE, BE LIABLE TO YOU FOR DAMAGES,
INCLUDING ANY GENERAL, SPECIAL, INCIDENTAL OR CONSEQUENTIAL DAMAGES ARISING
OUT OF THE USE OR INABILITY TO USE THE PROGRAM (INCLUDING BUT NOT LIMITED
TO LOSS OF DATA OR DATA BEING RENDERED INACCURATE OR LOSSES SUSTAINED BY
YOU OR THIRD PARTIES OR A FAILURE OF THE PROGRAM TO OPERATE WITH ANY OTHER
PROGRAMS), EVEN IF SUCH HOLDER OR OTHER PARTY HAS BEEN ADVISED OF THE
POSSIBILITY OF SUCH DAMAGES.

		     END OF TERMS AND CONDITIONS

	    How to Apply These Terms to Your New Programs

  If you develop a new program, and you want it to be of the greatest
possible use to the public, the best way to achieve this is to make it
free software which everyone can redistribute and change under these terms.

  To do so, attach the following notices to the program.  It is safest
to attach them to the start of each source file to most effectively
convey the exclusion of warranty; and each file should have at least
the "copyright" line and a pointer to where the full notice is found.

    <one line to give the program's name and a brief idea of what it does.>
    Copyright (C) <year>  <name of author>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA


Also add information on how to contact you by electronic and paper mail.

If the program is interactive, make it output a short notice like this
when it starts in an interactive mode:

    Gnomovision version 69, Copyright (C) year name of author
    Gnomovision comes with ABSOLUTELY NO WARRANTY; for details type `show w'.
    This is free software, and you are welcome to redistribute it
    under certain conditions; type `show c' for details.

The hypothetical commands `show w' and `show c' should show the appropriate
parts of the General Public License.  Of course, the commands you use may
be called something other than `show w' and `show c'; they could even be
mouse-clicks or menu items--whatever suits your program.

You should also get your employer (if you work as a programmer) or your
school, if any, to sign a "copyright disclaimer" for the program, if
necessary.  Here is a sample; alter the names:

  Yoyodyne, Inc., hereby disclaims all copyright interest in the program
  `Gnomovision' (which makes passes at compilers) written by James Hacker.

  <signature of Ty Coon>, 1 April 1989
  Ty Coon, President of Vice

This General Public License does not permit incorporating your program into
proprietary programs.  If your program is a subroutine library, you may
consider it more useful to permit linking proprietary applications with the
library.  If this is what you want to do, use the GNU Library General
Public License instead of this License.

</pre>
END_OF_COPYRIGHT;

}
