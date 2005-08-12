<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Net_SMPP_Client example
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Networking
 * @package    Net_SMPP_Client
 * @author     Ian Eure <ieure@php.net>
 * @copyright  2005 WebSprockets, LLC.
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    Release: @package_version@
 * @version    CVS:     $Revision$
 * @since      Release: 0.2.2
 * @link       http://pear.php.net/package/Net_SMPP
 */

// Place includes, constant defines and $_GLOBAL settings here.
require_once 'Net/SMPP/Client.php';

$smsc =& new Net_SMPP_Client('smpp.example.com', 3204);

// Make the TCP connection first
$smsc->connect();

// Now bind to the SMSC. bind() and unbind() return the response PDU.
$resp =& $smsc->bind(array(
    'system_id'   => 'Net_SMPP_Client',
    'password'    => 'asdfghjk',
    'system_type' => 'WWW'
));

if ($resp->isError()) {
    die('Couldn\'t bind: ' . $resp->statusDesc());
}

// Prepare the submit_sm PDU
$ssm =& Net_SMPP::PDU('submit_sm', array(
    'source_addr_ton'   => NET_SMPP_TON_INTL,
    'dest_addr_ton'     => NET_SMPP_TON_INTL,
    'source_addr'       => '15555551212',
    'destionation_addr' => '15555553434',
    'short_message'     => 'This is a test SMS'
));

// Send it.
$smsc->sendPDU($ssm);

// sendPDU() doesn't return a response PDU, so we need to explicitly read it
$resp =& $smsc->readPDU();

if ($resp->isError()) {
    echo "Error sending message: " . $resp->statusDesc() . "\n";
}

// Unbind.
$smsc->sendPDU(Net_SMPP::PDU('unbind'));
$ubr =& $smsc->readPDU();

// Disconnect.
$smsc->disconnect();

?>