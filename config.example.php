<?php
ini_set('memory_limit', '-1');
/*
 * Author: Michael aka SossenSystems
 * Credits: Alex aka xLikeAlex and Bluscream
 * Info: for questions, issues and pull requests visit https://github.com/SossenSystems/myTeamSpeakID-Checker
 */

$enforce_myteamspeak_auth = false;
$admin_group_ids = array(2,6);
$command_prefix = "!myts ";

/* Server Query Data */
$teamspeak['host'] = "127.0.0.1";
$teamspeak['queryport'] = 10011;
$teamspeak['serverport'] = 9987;
$teamspeak['loginname'] = 'serveradmin';
$teamspeak['loginpass'] = '';
$teamspeak['nickname'] = 'myTeamspeak';

/* mySQL Data */
$mysql['host'] = "127.0.0.1";
$mysql['username'] = "myteamspeak";
$mysql['password'] = "myteamspeak";
$mysql['database'] = "myteamspeak";
$mysql['table'] = "bans";