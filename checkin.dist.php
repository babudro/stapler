<?php
# vim: ts=3 sw=3:
# Receive a check-in request from a dynamic-DNS host, check if its IP address
# has changed, and if so, adjust Stapler's dynamic file, run Stapler to
# regenerate DNS files, and then reload BIND.
#
# Angelo Babudro    www.ispltd.org    www.ispltd.com
#
# First releas:  Stapler version 4.31 January 2018
#
# For this to work you will need to allow Apache (or whatever web server you
# use) to run two commands with 'sudo'.  Put a line like this in a file in
# /etc/sudoers.d/:
#     apache  ALL=NOPASSWD: /usr/sbin/rndc, /usr/local/bin/stapler
# If you use group such as 'httpd' for Apache and/or Nginx then use:
#     %httpd  ALL=NOPASSWD: /usr/sbin/rndc, /usr/local/bin/stapler
#
# Of course, adjust the paths as required.
#
# ADJUST THESE VARIABLES------------------------------

$dynamic_file = "/var/www/dns/dynamic";					#|dynamic DNS file, writable by Apache or Nginx
$mail_recipient = "tech.support@example.com";			#|Who gets e-mails when dynamic DNS changes or verbose flag is used?

#_____[ CHANGE ABOVE ]________________________________



$verbose = (!empty($_GET["verbose"]) || !empty($_GET["debug"]) || !empty($_GET["v"]));
$exists_msg = file_exists($dynamic_file) ? "[OK]" : "[Not found or no access]";
$title = "Server Check-in";
if(isset($_SERVER['X_FORWARDED_FOR'])) {
	$current_IP_address = $_SERVER['X_FORWARDED_FOR'];
} else {
	$current_IP_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : "127.0.0.1";
}
$user_node = (!empty($current_IP_address)) ? gethostbyaddr($current_IP_address) : "(unknown)";
if($verbose) {
	ini_set('display_errors', true);
	ini_set('html_errors', false);
	ini_set('track_errors', true);
	echo "This server\t".gethostname()."\nDynamic file\t$dynamic_file $exists_msg\nCheck-in from\t$current_IP_address $user_node\n";
}

$msg ="<HTML>
	<HEAD>
<STYLE>
	TH { text-align: right; }
</style>
<BODY>
<H1>$title</h1>
<TABLE border=0 cellspacing=2 cellpadding=3>

<TR>
<TH>Receiving host</th><TD>".system("hostname")."</td>
</tr>

<TR>
<TH>Check-in from</th><TD>".$_GET["host"]."</td>
</tr>

<TR>
<TH>IP address</th><TD>{$current_IP_address}</td>
</tr>

<TR>
<TH>Node alias</th><TD>{$user_node}</td>
</tr>

<TR>
<TH>Dynamic IP address file</th><TD>{$dynamic_file} $exists_msg</td>
</tr>
";
if(isset($_GET["user"])) {
	$msg .= "<TR><TH>Remote Cron run as user</th><TD>".$_GET["user"]."</td></tr>";
}
$msg .= "\n</table>\n";

if(!$tmpf = tmpfile()) {
	$msg .= "Unable to create temporary file.<BR>Changes will need to be made manually.<BR>";
}
$change = FALSE;
if(file_exists($dynamic_file) && $tmpf) {
	$lines = file($dynamic_file);													#|Read entire file into an array
	$change = check_dynamic($msg, $lines);
	if($change) {
		if($ec = rewrite_dynamic($msg, $tmpf, $lines)) {
			$msg .= "<SMALL>".__LINE__."</small> SUCCESS Writing of the new dynamic file.<BR>";

			$cmd = "sudo stapler --batch";
			$stapler_output = shell_exec($cmd);									#|Save this for last

			$msg .= "<H3>BIND</b>";
			$output = shell_exec("sudo rndc reload");
			$msg .= "<PRE>$ sudo rndc reload\n".$output."</pre><BR>";

			$output = shell_exec("sudo rndc refresh ixo.ca");
			$msg .= "<PRE>$ sudo rndc refresh ixo.ca\n".$output."</pre><BR>";

			$msg .= "<H3>Validate results</h3>";
			$output = shell_exec("host ".$_GET["host"]);
			$msg .= "<PRE>$ host ".$_GET["host"]."\n".$output."</pre><BR>";
			if(validateNewAddr($current_IP_address)) {
				$msg .= "(OK)";
			} else {
				$msg .= "<B style='color:red; font-size:36pt;'>FAILURE</b> DNS is <b><i>not</i></b> working!";
			}
			$msg .= "<BR><BR>
			<H3>Things that may need changing:</h3><BR>
			<UL>
				<LI>Firewalls</li>
				<LI>Public IP address alias
					<UL>
						<LI>Gentoo Linux: edit /etc/conf.d/net &mdash; change public IP address alias (e.g., eth0:1) so server recognizes public traffic coming in from the outside as belonging to it</li>
						<LI>Restart the affected interface, e.g., /etc/init.d/net.eth0 restart
						<LI>ip addr list (verify the proper address is shown)
						<LI>Edit /etc/hosts and change the IP address to $current_IP_address
					</ul>
				</li>
				<LI>Apache and Nginx
					<UL>
						<LI>Adjust trusted addresses for sensitive sites &mdash; these should be in a file, e.g., /etc/apache2/acl/trusted</li>
						<LI>Restart Apache (e.g., /etc/init.d/apache2 reload)</li>
						<LI>Nginx trusted addresses for sensitive sites &mdash; these should be in a file, e.g., /etc/nginx/acl/trusted</li>
						<LI>Restart Nginx (e.g., sudo systemctl restart nginx)</li>
					</ul>
				</li>
				<LI>MariaDB/MySQL GRANTs
					<UL>
						<LI>On master:  grants-change.sh $change $current_IP_address slave (script can be found on ispltd.org)</li>
						<LI>On slaves:  mysql -e 'stop slave; start slave; show slave status\G'</li>
					</ul>
				</li>
				<LI>Security settings in other applications, scripts, PHP code, etc.</li>
				<LI>Master DNS
					<UL>
						<LI>/etc/stapler/active/stapler.conf &mdash; change ournet entry for dynamic host(s)
						<LI>/etc/stapler/active/domains.primary &mdash; change any domains pointing to dynamic host(s)
						<LI>Run stapler to update master and push changes to slaves
					</ul>
				</li>
				<LI>Sendmail rules (e.g., allowed relays)</li>
				<LI>NTP servers
					<UL>
					<LI>Change $change to $current_IP_address in /etc/chrony/chrony.conf or /etc/ntp.conf</li>
					<LI>/etc/init.d/chronyd restart (or ntpd restart)</li>
					<LI>chronyc sources (to see if it is synchronised) (or use ntpq)</li>
					</ul>
				</li>
			</ul>

			<H3>Stapler output</h3>
			<PRE>$ ".$cmd."\n".$stapler_output."</pre><BR>";
		} else {
			$msg .= "<SMALL>".__LINE__."</small> FAILURE writing the new dynamic file.<BR>";
		}
	}
}

$msg .= "
</body>
</html>
";

if($tmpf) fclose($tmpf);

$msg64 = base64_encode($msg);
$headers = "Content-Type: text/html";

$chg_msg = $change ? " DNS changed" : " check-in (no change)";
if($verbose || $change) {
	mail($mail_recipient, $_GET['host'].$chg_msg, $msg, $headers);
}




/* Find the element in an array that contains a string (i.e., a substring search).
This is an enhancement of the array_search() function, which does an exact match.

Pass		$haystack   array Data array
			$needle     str   Value to search for
			$strict     bool  Case-sensitive (TRUE) or not (FALSE)
									(default: TRUE if not specified)

NB: I have kept haystack,needle order to be consistent with strpos(), although it is
			the opposite of how array_search() has them.

Returns	(fn)			str	Key to the element in the array, FALSE if not found.		*/
function array_find_element($haystack, $needle, $strict=TRUE) {
	global $msg;
	foreach($haystack as $key => $value) {
		$value = trim($value, "\n\r\0");
		$is_match = ($strict) ? strpos($value, $needle) : stripos($value, $needle);
		if($is_match !== FALSE) return $key;									#|Test for FALSE because zero is a valid result.
	}
	return FALSE;																		#|Not found
}


#__________________________________________
# Pass		$msg		str	E-mail body being built above and below
# 				$lines	arr	Lines of the 'dynamic' file
# Returns	(fn)		bool	FALSE if there was no change
#							str	Old IP address if there was a change (which will evalute as TRUE)
function check_dynamic(&$msg, &$lines) {
	global $dynamic_file, $current_IP_address;
	if(!file_exists($dynamic_file)) {
		$msg .= "<SMALL>".__LINE__."</small> File $dynamic_file does not exist.<BR>";
		return FALSE;
	}
	foreach($lines as $key => $value) {
		$value = trim($value, "\n\r\0");
		$lines[$key] = $value;
	}
	$ec = array_find_element($lines, $_GET["host"], FALSE);
	$change = FALSE;
	if($ec !== FALSE) {																#|Key of zero is valid
		$components = explode("\t", $lines[$ec]);
		if($components[0] != $current_IP_address) {
			$new = sprintf("%s\t%s", $current_IP_address, $_GET["host"]);
			$msg .= "<SMALL>".__LINE__."</small> ".$_GET["host"]." changed from <U>".$components[0]."</u> to <U>".$current_IP_address."</u><BR><BR>";
			$msg .= "Consider things that may need changing due to this:<BR><UL><LI>Firewalls</li><LI>Apache trusted address lists in /etc/apache2/vhosts.d/</li><LI>MariaDB/MySQL GRANTs (grants-change.sh ".$components[0]." ".$current_IP_address." &lt;userName&gt;)</li><LI>Security settings in other applications, scripts, PHP code, etc.</li><LI>ntp.conf or chrony.conf</li><LI>DNS named.conf</li><LI>Sendmail rules (e.g., allowed relays, access.db, sendmail.mc, sendmail.cf)</li></ul>";
			$lines[$ec] = $new;
			$change = $components[0];
		} else {
			$msg .= "No change.<BR>";
		}
	} else {
		$msg .= "No line containing ".$_GET["host"]." was found in the $dynamic_file file.<BR><PRE>lines ".print_r($lines,true)."</pre>";
	}
	return $change;
}


#__________________________________________
# Pass		$msg		str	E-mail body being built above and below
# 				$tmp		res	File handle of temporary file, or FALSE if unable to create it.
# 				$lines	arr	Lines from the 'dynamic' file
function rewrite_dynamic(&$msg, $tmpf, $lines) {
	global $dynamic_file, $current_IP_address;
	$bu = "/var/tmp/backup/".basename($dynamic_file);
	if(!copy($dynamic_file, $bu)) {
		$msg .= "<SMALL>".__LINE__."</small> Unable to create $bu.  Will try /tmp/.<BR>";
		$bu = "/tmp/".basename($dynamic_file);
		if(!copy($dynamic_file, $bu)) {
			$msg .= "<SMALL>".__LINE__."</small> Unable to create $bu.  There will be no backup.<BR>";
		}
	}
	rewind($tmpf);
	if(!$dynf = fopen($dynamic_file, "w")) {									#|Overwrite the dynamic file
		$msg .= "<SMALL>".__LINE__."</small> Unable to open $dynamic_file for writing.  Is it owned by Apache?<BR>";
		return FALSE;
	}
	$msg .= "<SMALL>".__LINE__."</small> Opened $dyamic_file for writing.<BR>";
	foreach($lines as $key => $value) {
		fwrite($dynf, $value."\n");												#|Write out a new temporary file, in case something changed
		$msg .= "<SMALL>".__LINE__."</small> Wrote $newline<BR>";
	}
	fclose($dynf);
	return TRUE;
}


?>
