<?php
// +--------------------------------------------------------------------------------+
// | LinkPopCheck:      Link Popularity Check                                       |
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
 * linkPopCheck is a link popularity checher via search engines
 *
 * function list:
 *     getMsn ( $url )              //get score from msn
 *     getFast( $url )              //get score from fast(alltheweb)
 *     getNl  ( $url )              //get score from northen light
 */

//DEBUG $url = "http://news.sina.com.cn";

//Show source of file
if ( $show == "source" ) {
    show_source ($SCRIPT_FILENAME);
    exit;
}

if ( $url ) {
	//if url not start with 'http://'
	if ( !preg_match("/^http:\/\//", $url) ) {
		$url = "http://" . $url;
	}

	$url_encoded = urlencode( $url );
	$url_no_http = preg_replace("/^http:\/\//", "", $url); //removed "http://"
	$url_no_http = urlencode($url_no_http);

	$score_nl = getNl( $url_encoded );
	$score_fast = getFast( $url_no_http );
	$score_msn = getMsn( $url_encoded );
	//DEBUG echo "get score: $score_nl \t $score_msn \t $score_fast\n ";

	$msn_orig = "";
	$nl_orig = "";
	if ($score_msn > 500) {
		//msn's error report, example: http://www.cs.ualberta.ca/~xun/sanmao/SM-2-1.html
		if ( ($score_fast + $score_nl) < ( $score_msn / 50) ) {
			$msn_orig = $score_msn;
			if ($score_fast < $score_nl) {
				$score_msn = $score_fast;
			}
			else{
				$score_msn = $score_nl;
			}
		}
	}

	if ($score_nl > 5000000) {
		//nl's error report, example: http://netsquare.com/netbook/侦探小说/阿加莎·克里斯蒂作品/djzf.htm
		if ( ($score_fast + $score_msn) < ( $score_nl / 50) ) {
			$nl_orig = $score_nl;
			if ($score_fast < $score_msn) {
				$score_nl = $score_fast;
			}
			else{
				$score_nl = $score_msn;
			}
		}
	}


	$score = $score_nl  + $score_fast  + $score_msn;
	$avg = intval( $score / 3 ) ;

}
else {
	$url = "http://";
}

echo <<<END
<?xml version="1.0" encoding="ISO-8859-1"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1"/>
<title>Link Popularity Checker</title>
</head>

<body>

<form method="get" action="">
<p>
<input type="text" name="url" size="50" value="$url"/>
<input type="submit"/>
<a href="http://searchenginewatch.com/webmasters/popularity.html">What's link
popularity?</a>
</p>
</form>

<table width="100%" border="1">
<tr>
<td>Search engine</td>
<td>Score</td>		
</tr>
<tr>
<td><a href="http://www.northernlight.com/">Northern Light</a></td>
<td>
<a href="http://www.northernlight.com/nlquery.fcg?qr=link%3A$url">$score_nl $nl_orig</a>
</td>
</tr>
<tr>
<td><a href="http://search.msn.com">MSN</a>(powered by <a href="http://www.inktomi.com">Inktomi</a>)</td>
<td>
<a href="http://search.msn.com/results.asp?q=$url&amp;FORM=SMCA&amp;cfg=SMCINK&amp;v=1&amp;ba=0&amp;f=lnk&amp;sort=&amp;rgn=&amp;lng=&amp;dom=&amp;depth=&amp;d0=&amp;d1=&amp;cf=">$score_msn $msn_orig</a>
</td>
</tr>
<tr>
<td><a href="http://alltheweb.com/">Alltheweb</a>(powered by <a href="http://www.fastsearch.com/">Fast</a>)</td>		
<td>
<a href="http://alltheweb.com/search?cat=web&amp;lang=any&amp;query=link.all%3A$url_h">$score_fast </a>
</td>
</tr>
</table>

<p><b>popularity=$score</b></p>
<a href="?show=source">\$Id$</a>

</body>
</html>
END;

/*
 * get score from msn
 * example : http://search.msn.com/results.asp?q=http%3A%2F%2Fnews.163.com/viewpoint.html&FORM=SMCA&cfg=SMCINK&v=1&ba=0&f=lnk&sort=&rgn=&lng=&dom=&depth=&d0=&d1=&cf=
 */
function getMsn ( $url ) {
	$score = 0; //links score
	$line = ""; //fetch content
    $fp = fsockopen ("search.msn.com", 80, $errno, $errstr, 30);

    if (!$fp) {
        echo "$errstr ($errno)<br>\n";
        return -1;
    }
    else {
        fputs ($fp, "GET /results.asp?q=$url&FORM=SMCA&cfg=SMCINK&v=1&ba=0&f=lnk&sort=&rgn=&lng=&dom=&depth=&d0=&d1=&cf= HTTP/1.1\nAccept: */*\nHost: search.msn.com\nUser-Agent: Mozilla/4.0 (compatible; MSIE 5.0; Windows 98; DigExt)\n\n");
        while (!feof($fp)) {
        	$line = fgets ($fp,4096);
        	//DEBUG echo $line;
        	if ( preg_match("/ of about (\d+) containing/",$line,$matches) ) {
        		$score = $matches[1];
        		//DEBUG echo "msn=$score\n";
        		return $score;
            }
            else if ( preg_match("/Sorry, no results were found containing/", $line) ) {
            	return 0;
            }
        }
        fclose ($fp);
        return $score;
    }
}


/*
 * get score from alltheweb
 * example : http://alltheweb.com/search?cat=web&lang=any&query=link.all%3Achedong.yeah.net
 */
function getFast ( $url ) {
	$score = 0; //links score
	$line = ""; //fetch content
    $fp = fsockopen ("www.alltheweb.com", 80, $errno, $errstr, 30);

    if (!$fp) {
        echo "$errstr ($errno)<br>\n";
        return -1;
    }
    else {
        fputs ($fp, "GET /search?cat=web&lang=any&query=link.all%3A$url HTTP/1.1\nAccept: */*\nHost: www.alltheweb.com\nUser-Agent: Mozilla/4.0 (compatible; MSIE 5.0; Windows 98; DigExt)\n\n");
        while (!feof($fp)) {
        	$line = fgets ($fp,4096);
        	//DEBUG echo $line;
        	if ( preg_match("/<b>([\d,]+)<\/b> web pages found/",$line,$matches) ) {
        		$score = intval( str_replace(",", "", $matches[1]) );
        		//DEBUG echo "fast=$score\n";
        		return $score;
            }
            else if ( preg_match("/No Web pages found/", $line) ) {
            	return 0;
            }
        }
        fclose ($fp);
        return $score;
    }
}


/*
 * get score from northen light
 * example : http://www.northernlight.com/nlquery.fcg?qr=link%3Ahttp%3A%2F%2Fwww.yeah.net%2F
 */
function getNl ( $url ) {
	$score = 0; //links score
	$line = ""; //fetch content
    $fp = fsockopen ("www.northernlight.com", 80, $errno, $errstr, 30);

    if (!$fp) {
        echo "$errstr ($errno)<br>\n";
        return -1;
    }
    else {
        fputs ($fp, "GET /nlquery.fcg?qr=link%3A$url HTTP/1.1\nAccept: */*\nHost: www.northernlight.com\nUser-Agent: Mozilla/4.0 (compatible; MSIE 5.0; Windows 98; DigExt)\n\n");
        while (!feof($fp)) {
        	$line = fgets ($fp,4096);
        	//DEBUG echo $line;
        	if ( preg_match("/<b>([\d,]+) items<\/b>/", $line, $matches) ) {
        		$score = intval( str_replace(",", "", $matches[1]) );
        		//225 items in 5 sources for expamle:
        		//http://www.cs.ualberta.ca/~xun/sanmao/SM-2-1.html
        		if ( preg_match("/<b>([\d,]+) sources<\/b>/", $line, $match) ) {
        			$score = intval( str_replace(",", "", $match[1]) );
        		}
        		//DEBUG echo "nl=$score\n";
        		return $score;
            }
            else if ( preg_match("/Your query did not find any documents/", $line) ) {
            	return 0;
            }
        }
        fclose ($fp);
        return $score;
    }
}
?>
