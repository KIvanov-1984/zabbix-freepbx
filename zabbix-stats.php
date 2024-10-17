#!/usr/bin/env php
<?php

$bootstrap_settings['include_compress'] = false;
require_once '/etc/freepbx.conf';

$freepbx = \FreePBX::create();
$Ami = $freepbx->astman;

$asterisk = [
    "version" => '',
    "freepbx_version" => '',
    "mail_queued" => 0,
    "uptime"  => 0,
    "uptime_reload" => 0,
    "active_channels" => 0,
    "calls_processed" => 0,
    "sip" => [
        "trunks" => [],
        "monitored_online" => 0,
        "monitored_offline" => 0,
        "unmonitored_online" => 0,
        "unmonitored_offline" => 0,
        "active_channels" => 0,
        "total" => 0
    ], 
    "iax" => [
        "trunks" => [],
        "online" => 0,
        "offline" => 0,
        "unmonitored" => 0,
        "active_channels" => 0,
        "total" => 0
    ],
    "pjsip" => [
        "trunks" => [],
        "endpoints" => [],
        "available" => 0,
        "unavailable" => 0,
        "active_channels" => 0,
        "total" => 0
    ],
    "queue" => [
        "queues" => [],
        "total" => 0
    ],
    "license_file" => ''
];

function text2Object($str) {
        $lines = explode("\n", trim($str));
        $arr = [];
        
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $arr[$key] = $value;
            }
        }
        
        return $arr;
}

function getSipPeers() {
    global $Ami, $asterisk, $channels;
    $Ami->response_catch = [];
    
    $sipPeers = $Ami->send_request('SIPpeers');
    $Ami->add_event_handler('peerentry', 'SipPeers_catch');
    $Ami->add_event_handler('peerlistcomplete', 'SipPeers_catch');

    if ($sipPeers['Response'] == 'Success') {
        $Ami->wait_response(true);
        stream_set_timeout($Ami->socket, 30);
    }
    unset($Ami->event_handlers['peerentry']);
    unset($Ami->event_handlers['peerlistcomplete']);

    $peers = $Ami->response_catch;
    if (empty($peers)) return false;

    $asterisk['sip']['total'] = count($peers);
    $asterisk['sip']['trunks'] = array_values(array_filter($peers, function($peer) {
        return strpos($peer['ObjectName'], 'trunk') !== false;
    }));
    
    foreach ($peers as $peer) {
        if ($peer['IPaddress'] === '-none-') {
            switch ($peer['Status']) {
                case 'Unmonitored':
                    $asterisk['sip']['unmonitored_offline']++;
                    break;
                case 'UNKNOWN':
                    $asterisk['sip']['monitored_offline']++;
                    break;
            }
        } else {
            if ($peer['Status'] === 'Unmonitored') {
                $asterisk['sip']['unmonitored_online']++;
            } else {
                $asterisk['sip']['monitored_online']++;
                if (strpos($peer['Status'], 'OK') === 0) {
                    $peer['Status'] = explode(' ', $peer['Status'])[0];
                }
            }
        }
    }
    
    foreach ($asterisk['sip']['trunks'] as &$trunk) {
        preg_match_all('/[^!J]SIP\/' . preg_quote($trunk['ObjectName'], '/') . '/', $channels['data'], $matches);
        $trunk['active_channels'] = count($matches[0]);
        $asterisk['sip']['active_channels'] += $trunk['active_channels'];
    }
}

function SipPeers_catch($event, $data, $server, $port) {
    global $Ami;
    switch($event) {
        case 'peerlistcomplete':
            stream_set_timeout($Ami->socket, 0, 1);
            break;
        default:
            $Ami->response_catch[] = $data;
    }
}

function getIaxPeerList() {
    global $Ami, $asterisk, $channels;
    $Ami->response_catch = [];
    
    $iaxPeerList = $Ami->send_request('IAXpeerlist');
    $Ami->add_event_handler('peerentry', 'IaxPeerList_catch');
    $Ami->add_event_handler('peerlistcomplete', 'IaxPeerList_catch');

    if ($iaxPeerList['Response'] == 'Success') {
        $Ami->wait_response(true);
        stream_set_timeout($Ami->socket, 30);
    }
    unset($Ami->event_handlers['peerentry']);
    unset($Ami->event_handlers['peerlistcomplete']);
 
    $peers = $Ami->response_catch;

    if (empty($peers)) return false;

    $asterisk['iax']['total'] = count($peers);
    $asterisk['iax']['trunks'] = array_values(array_filter($peers, function($peer) {
        return strpos($peer['ObjectName'], 'trunk') !== false;
    }));
    
    foreach ($peers as $peer) {
        if (strpos($peer['Status'], 'OK') === 0) {
            $peer['Status'] = explode(' ', $peer['Status'])[0];
        }
        switch ($peer['Status']) {
            case 'Unmonitored':
                $asterisk['iax']['unmonitored']++;
                break;
            case 'UNKNOWN':
                $asterisk['iax']['offline']++;
                break;
        }
    }
    
    $asterisk['iax']['online'] = $asterisk['iax']['total'] - $asterisk['iax']['offline'];
    
    foreach ($asterisk['iax']['trunks'] as &$trunk) {
        $pattern = '/[^!](IAX2\/' . preg_quote($trunk['ObjectName'], '/') . '|IAX2\/' . preg_quote($trunk['ObjectUsername'], '/') . ')/';
        preg_match_all($pattern, $channels['data'], $matches);
        $trunk['active_channels'] = count($matches[0]);
        $asterisk['iax']['active_channels'] += $trunk['active_channels'];
    }
}

function IaxPeerList_catch($event, $data, $server, $port) {
    global $Ami;
    switch($event) {
        case 'peerlistcomplete':
            stream_set_timeout($Ami->socket, 0, 1);
            break;
        default:
            $Ami->response_catch[] = $data;
    }
}

function getPjsipShowEndpoints() {
    global $Ami, $asterisk, $channels;
    
    $Ami->response_catch = [];
    $endpoints = $Ami->PJSIPShowEndpoints();
    if (empty($endpoints)) return false;
    $endpoints = array_values(array_filter($endpoints, function($endpoint) {
        return $endpoint['ObjectName'] !== 'dpma_endpoint';
    }));
    $asterisk['pjsip']['total'] = count($endpoints);
    $asterisk['pjsip']['trunks'] = array_values(array_filter($endpoints, function($endpoint) {
        return strpos($endpoint['Auths'], $endpoint['ObjectName']) === false;
    }));
    $asterisk['pjsip']['endpoints'] = array_values(array_filter($endpoints, function($endpoint) {
        return strpos($endpoint['Auths'], $endpoint['ObjectName']) !== false;
    }));
    
    foreach ($endpoints as $endpoint) {
        if ($endpoint['DeviceState'] === 'Unavailable') {
            $asterisk['pjsip']['unavailable']++;
        }
    }
    
    $asterisk['pjsip']['available'] = $asterisk['pjsip']['total'] - $asterisk['pjsip']['unavailable'];
    
    foreach ($asterisk['pjsip']['trunks'] as &$trunk) {
        preg_match_all('/[^!]PJSIP\/' . preg_quote($trunk['ObjectName'], '/') . '/', $channels['data'], $matches);
        $trunk['active_channels'] = count($matches[0]);
        $asterisk['pjsip']['active_channels'] += $trunk['active_channels'];
    }
}

function getQueueSummary() {
    global $Ami, $asterisk;
    
    $Ami->response_catch = [];
    $queueSummary = $Ami->send_request('QueueSummary');
    $Ami->add_event_handler('queuesummary', 'Queuesummary_catch');
    $Ami->add_event_handler('queuesummarycomplete', 'Queuesummary_catch');
    if ($queueSummary['Response'] == 'Success') {
        $Ami->wait_response(true);
        stream_set_timeout($Ami->socket, 30);
    } 
    unset($Ami->event_handlers['queuesummary']);
    unset($Ami->event_handlers['queuesummarycomplete']);

    $queues = $Ami->response_catch;
    if (empty($queues)) return false;
    $queues = array_values(array_filter($queues, function($queue) {
        return $queue['Queue'] !== 'default';
    }));
    $asterisk['queue']['queues'] = $queues;
    $asterisk['queue']['total'] = count($asterisk['queue']['queues']);
}

function Queuesummary_catch($event, $data, $server, $port) {
    global $Ami;
    switch($event) {
        case 'queuesummarycomplete':
            stream_set_timeout($Ami->socket, 0, 1);
            break;
        default:
            $Ami->response_catch[] = $data;
    }
}

function getLicenseInfoFromFile($filename) {
    $matchingLines = [];
    $pattern = '/^.* = .*$/';

    if (!file_exists($filename)) {
        throw new Exception("File not found: $filename");
    }

    $file = fopen($filename, 'r');
    if (!$file) {
        throw new Exception("Unable to open file: $filename");
    }

    while (($line = fgets($file)) !== false) {
        if (preg_match($pattern, $line)) {
            $matchingLines[] = trim($line);
        }
    }

    // Close the file
    fclose($file);

    return $matchingLines;
}

/* Main script */

$coreSettings = $Ami->send_request('CoreSettings');
if (isset($coreSettings['AsteriskVersion'])) {
    $asterisk['version'] = $coreSettings['AsteriskVersion'];
}

$asterisk['freepbx_version'] = getversion() ?? '';

$uptime_text = $Ami->send_request('Command', array('Command'=>'core show uptime seconds'));
$uptime = text2Object($uptime_text['data']);
if (isset($uptime['System uptime'])) {
    $asterisk['uptime'] = intval($uptime['System uptime']);
}
if (isset($uptime['Last reload'])) {
    $asterisk['uptime_reload'] = intval($uptime['Last reload']);
}

$channels_count = $Ami->send_request('Command', array('Command'=>'core show channels count'));

$fields = [
    'active_channels' => 'active channels?',
    'active_calls' => 'active calls?',
    'calls_processed' => 'calls? processed'
];

foreach ($fields as $field => $pattern) {
    if (preg_match('/(\d+) ' . $pattern . '/', $channels_count['data'], $match)) {
        $asterisk[$field] = intval($match[1]);
    }
}

if (file_exists('/usr/sbin/postqueue')) {
    $postqueue = exec('/usr/sbin/postqueue -p');
    if (!empty($postqueue)) {
        if (preg_match('/(\d+) Request/', $postqueue, $matches)) {
            $asterisk['mail_queued'] = intval($matches[1]);
        }
    }
}

if (file_exists('/etc/sangoma/license.txt')) {
    $asterisk['license_file'] = getLicenseInfoFromFile('/etc/sangoma/license.txt');
} elseif (file_exists('/etc/schmooze/schmooze.zl')) {
    $asterisk['license_file'] = getLicenseInfoFromFile('/etc/schmooze/schmooze.zl');
}

$channels = $Ami->send_request('Command', array('Command'=>'core show channels concise'));

$listCommands = $Ami->send_request('ListCommands');

if (array_key_exists('SIPpeers', $listCommands)) {
    getSipPeers();
}

if (array_key_exists('IAXpeerlist', $listCommands)) {
    getIaxPeerList();
}

if (array_key_exists('PJSIPShowEndpoints', $listCommands)) {
    getPjsipShowEndpoints();
}

if (array_key_exists('QueueSummary', $listCommands)) {
    getQueueSummary();
}

echo json_encode($asterisk);
