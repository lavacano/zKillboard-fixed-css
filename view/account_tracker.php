<?php

global $redis;

$id = (int) $id;

$userID = User::getUserID();
$message = null;
if ($userID > 0 && $id > 0) {
    $redisKey = 'user:'.$userID;
    $mapKey = 'tracker_'.$type;
    $tracked = UserConfig::get($mapKey, []);
    if ($action == 'add') {
        $tracked[] = $id;
        $name = Info::getInfoField($type.'ID', $id, 'name');
        User::sendMessage("Added $name to your Tracker in the menu bar.");
        Log::log("$userID adding tracker $type $id");
    } elseif ($action == 'remove') {
        unset($tracked[array_search($id, $tracked)]);
        $name = Info::getInfoField($type.'ID', $id, 'name');
        User::sendMessage("Removed $name from your Tracker in the menu bar. Please note, your logged in character and their corporation and alliance will always show in the tracker.");
        Log::log("$userID removing tracker $type $id");
    }
    UserConfig::set($mapKey, $tracked);
}

$app->redirect("/$type/$id/");
