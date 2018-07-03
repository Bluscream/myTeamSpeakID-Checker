<?php /** @noinspection PhpUndefinedClassInspection */
/**
 * Created by PhpStorm.
 * User: blusc
 * Date: 7/2/2018
 * Time: 6:41 PM
 */
require_once __DIR__ . '/vendor/autoload.php';
include_once 'config.php';
TeamSpeak3::init();
$db = null;
register_shutdown_function("shutdownHandler");

function startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle) {
    $length = strlen($needle);

    return $length === 0 ||
        (substr($haystack, -$length) === $needle);
}
function db_exec($sql, $fetch = false) {
    global $db;
    echo $sql."\n";
    $result = $db->query($sql);
    print_r($result);
    if (!$fetch && $result === FALSE) {
        exit("Database Error: ". $db->error ."\n");
    }
    elseif ($fetch && $result->num_rows == 1) {
        while($row = $result->fetch_assoc()) { return $row; }
    }
    return $result;
}
function shutdownHandler()
{
    $lasterror = error_get_last();
    switch ($lasterror['type'])
    {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_PARSE:
            $error = "[SHUTDOWN] lvl:" . $lasterror['type'] . " | msg:" . $lasterror['message'] . " | file:" . $lasterror['file'] . " | ln:" . $lasterror['line'];
            print_r("FATAL EXIT $error\n");
    }
    $_ = $_SERVER['_'];
    global $_;
    pcntl_exec($_);
}

function main() {
    global $teamspeak,$mysql,$db;
    $db = new mysqli($mysql['host'], $mysql['username'], $mysql['password'], $mysql['database']);
    db_exec("CREATE TABLE IF NOT EXISTS ".$mysql['table']." ( id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY UNIQUE, timestamp TIMESTAMP, myteamspeak_id VARCHAR(44) NOT NULL UNIQUE, uid VARCHAR(28))");
    TeamSpeak3_Helper_Signal::getInstance()->subscribe("serverqueryWaitTimeout", "onTimeout");
    TeamSpeak3_Helper_Signal::getInstance()->subscribe("notifyCliententerview", "onClientEnter");
    TeamSpeak3_Helper_Signal::getInstance()->subscribe("notifyClientleftview", "onClientLeft");
    TeamSpeak3_Helper_Signal::getInstance()->subscribe("notifyTextmessage", "onTextmessage");
    TeamSpeak3_Helper_Signal::getInstance()->subscribe("notifyServerselected", "onSelect");
    $uri = "serverquery://".$teamspeak["loginname"].":".$teamspeak["loginpass"]."@".$teamspeak["host"].":".$teamspeak["queryport"]."/?server_port=".$teamspeak["serverport"]."&nickname=".urlencode($teamspeak["nickname"])."&blocking=0";
    echo $uri."\n";
    $tsHandle = TeamSpeak3::factory($uri);
    while(1) $tsHandle->getAdapter()->wait();
    mysqli_close($db);
    //main();
}

try {
    main();
} catch(Exception $e) {
    print_r("[ERROR]  " . $e->getMessage() . "\n");
    // main();
}

function onTimeout($seconds, TeamSpeak3_Adapter_ServerQuery $adapter) {
    $last = $adapter->getQueryLastTimestamp();
    $time = time();
    $newtime = $time-300;
    $update = $last < $newtime;
    //$update_str = ($update) ? 'true' : 'false';
    //print_r("Timeout! seconds=$seconds last=$last time=$time newtime=$newtime update=$update_str\n");
    if($update) {
        $adapter->request("clientupdate");
    }
}

function onSelect(TeamSpeak3_Node_Host $host) {
    $host->serverGetSelected()->notifyRegister("server");
    $host->serverGetSelected()->notifyRegister("textserver");
    $host->serverGetSelected()->notifyRegister("textchannel");
    $host->serverGetSelected()->notifyRegister("textprivate");
}

function onTextmessage(TeamSpeak3_Adapter_ServerQuery_Event $event, TeamSpeak3_Node_Host $host) {
    global $admin_group_ids,$enforce_myteamspeak_auth,$command_prefix,$mysql;
    if ($event["invokerid"] == $host->whoamiGet("client_id")) return;
    $msg = strtolower($event["msg"]);
    if (!startsWith($msg, $command_prefix)) return;
    $command = explode(" ", str_replace($command_prefix,"",$msg));
    $client = $host->serverGetSelected()->clientGetById($event["invokerid"]);
    $sgids = explode(",", $client["client_servergroups"]);
    $result = array_intersect($sgids, $admin_group_ids);
    if (empty($result))
        return;
    if ($command[0] == "toggle" || $command[0] == "raid") {
        $enforce_myteamspeak_auth = !$enforce_myteamspeak_auth;
        if ($enforce_myteamspeak_auth)
            $msg = "[color=green]Now";
        else
            $msg = "[color=red]No longer";
        $client->message($msg . " enforcing authentication!");
    } elseif ($command[0] == "ban") {
        if (count($command) == 3) {
            db_exec("INSERT IGNORE INTO " . $mysql['table'] . " (myteamspeak_id,uid) VALUES ('" . $command[1] . "','" . $command[2] . "')");
            $msg = "Banned myTeamSpeak ID ".$command[1];
        } else {
            $msg = "Invalid Syntax! ".$command_prefix." ban <myteamspeak_id> <uid>";
        }
        $client->message($msg);
    } elseif ($command[0] == "unban") {
        if (count($command) == 2) {
            db_exec("DELETE FROM ".$mysql['table']." WHERE myteamspeak_id='".$command[1]."'");
            $msg = "Unbanned myTeamSpeak ID ".$command[1];
        } else {
            $msg = "Invalid Syntax! ".$command_prefix." unban <myteamspeak_id>";
        }
        $client->message($msg);
    }
}

function onClientLeft(TeamSpeak3_Adapter_ServerQuery_Event $event, TeamSpeak3_Node_Host $host) {
    if ($event["reasonid"] != 6) return;
    // $uid =
}

function onClientEnter(TeamSpeak3_Adapter_ServerQuery_Event $event, TeamSpeak3_Node_Host $host) {
    global $enforce_myteamspeak_auth,$mysql,$db;
    try {
        if ($event["client_type"] != 0) return;
        $client = $host->serverGetSelected()->clientGetById($event["clid"]);
        $clientInfo = $client->getInfo();
        $uid = $clientInfo["client_unique_identifier"];
        $mytsid = $clientInfo["client_myteamspeak_id"];
        $id_exists = isset($mytsid);
        $id_valid_length = strlen($mytsid) == 44;
        $id_matches = preg_match("/^A[\da-zA-Z\/]{43}$/", $mytsid);
        print_r("\$uid = $uid | \$mytsid = $mytsid | \$id_exists = $id_exists | \$id_valid_length = $id_valid_length | \$id_matches = $id_matches\n");
        if ($id_exists && $id_valid_length && $id_matches) {
            $sql = "SELECT * FROM ".$mysql['table']." where myteamspeak_id='".$mytsid."'";
            print_r($sql."\n");
            $result = $db->query($sql);
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    print_r("Found ban myteamspeak_id=".$row["myteamspeak_id"]." uid=".$row["uid"]."\n");
                    kickClient($client, 'banned_id');
                    return;
                }
            }
        }
        if ($enforce_myteamspeak_auth) {
            if (!$id_exists) {
                kickClient($client, 'missing_id');
                return;
            }
            if (!$id_valid_length) {
                kickClient($client, 'invalid_id');
                return;
            }
            if (!$id_matches) {
                kickClient($client, 'invalid_id');
            }
        }
    } catch(TeamSpeak3_Exception $e) {
        print_r("Teamspeak Error ".$e->getCode().": ".$e->getMessage()."\n");
        $host->serverGetSelected()->message("[color=red]Error");
        // $client = $host->serverGetSelected()->clientGetById($event["clid"]);
        //$client->kick(5, "Error while verifying myTeamSpeak ID!");// kickClient($client, 'error');
    }
}

function kickClient(TeamSpeak3_Node_Client $client, $reason) { // , $clientInfo = null
    //if (is_null($clientInfo)) $clientInfo = $client->getInfo();
    $lang = strtolower($client["client_country"]);
    if (!file_exists("lang/$lang.json")) $lang = "en";
    print_r("lang/$lang.json"."\n");
    $json = file_get_contents("lang/$lang.json");
    $json = json_decode($json, true);
    if (isset($json[$reason]["private"]) && checkMessage($json[$reason]["private"], 1024)) $client->message($json[$reason]["private"]);
    if (isset($json[$reason]["poke"]) && checkMessage($json[$reason]["poke"],100)) $client->poke($json[$reason]["poke"]);
    if (isset($json[$reason]["kick"])) $client->kick(5, $json[$reason]["kick"]); //  && strlen($json[$reason]["kick"] < 80)
    if (isset($json[$reason]["ban"])) $client->ban($json[$reason]["ban"]["duration"], $json[$reason]["ban"]["reason"]);
}

function checkMessage($msg, $max)
{
    //if (!property_exists())
    //if (!isset($msg)) return false;
    $len = strlen($msg);
    if ($len > $max) {
        print_r($msg." is ".($len-$max)." chars too long (".$len."/".$max.")!\n");
        return false;
    }
    return true;
}