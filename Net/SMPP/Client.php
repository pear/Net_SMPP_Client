<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Net_SMPP_Client class
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
 * @since      Release: 0.0.1dev1
 * @link       http://pear.php.net/package/Net_SMPP
 */

// Place includes, constant defines and $_GLOBAL settings here.
require_once 'PEAR/ErrorStack.php';
require_once 'Net/SMPP.php';
require_once 'Net/SMPP/Vendor.php';
require_once 'Net/Socket.php';

define('NET_SMPP_CLIENT_STATE_CLOSED',    0);
define('NET_SMPP_CLIENT_STATE_OPEN',      1);
define('NET_SMPP_CLIENT_STATE_BOUND_TX',  2);
define('NET_SMPP_CLIENT_STATE_BOUND_RX',  3);
define('NET_SMPP_CLIENT_STATE_BOUND_TRX', 4);


/**
 * Net_SMPP_Client class
 *
 * This is a Net_Socket-based SMPP client (ESME).
 *
 * @category   Networking
 * @package    Net_SMPP
 * @author     Ian Eure <ieure@php.net>
 * @copyright  2005 WebSprockets, LLC.
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    Release: @package_version@
 * @version    CVS:     $Revision$
 * @since      Release: 0.0.1dev1
 * @link       http://pear.php.net/package/Net_SMPP
 */
class Net_SMPP_Client
{
    /**
     * Current state of the connection
     *
     * Certain commands alter the state of the connection, e.g. a non-error
     * bind_transmitter_resp PDU changes the state to BOUND_TX. We keep track of
     * the connection state here.
     *
     * @var  int
     * @see  NET_SMPP_CLIENT_STATE_* constants
     * @see  readPDU()
     * @see  _stateSetters()
     * @see  _commandStates()
     */
    var $state = NET_SMPP_CLIENT_STATE_CLOSED;

    /**
     * Host to connect to
     *
     * @var  string
     */
    var $host;

    /**
     * Port to connect to
     *
     * @var  int
     */
    var $port;

    /**
     * SMPP Vendor extension to use
     *
     * @var  string
     */
     var $vendor;

    /**
     * Net_Socket instance
     *
     * @var     object
     * @access  private
     */
    var $_socket;

    /**
     * PDU stack
     *
     * @var     array
     * @access  private
     * @see     _pushPDU()
     */
    var $_stack = array();

    /**
     * PEAR_ErrorStack instance
     *
     * @var  object
     */
    var $es;

    /**
     * Enable debugging?
     *
     * @var     boolean
     * @access  private
     */
    var $_debug = false;


    /**
     * 4.x/thunk constructor
     *
     * @param   string  $host  SMPP host to connect to
     * @param   int     $port  TCP port on $host to connect to
     * @return  void
     * @see  __construct()
     */
    function Net_SMPP_Client($host, $port)
    {
        $this->__construct($host, $port);
    }

    /**
     * 5.x/real constructor
     *
     * @param   string  $host  SMPP host to connect to
     * @param   int     $port  TCP port on $host to connect to
     * @return  void
     */
    function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = intval($port);
        $this->_socket = new Net_Socket;
        $this->es =& new PEAR_ErrorStack('Net_SMPP_Client');
    }

    /**
     * Make TCP connection
     *
     * @return  mixed  Net_Socket::connect()'s return value
     * @see     Net_Socket::connect()
     */
    function &connect()
    {
        $this->log('Connecting to ' . $this->host . ':' . $this->port);
        $res =& $this->_socket->connect($this->host, $this->port);
        if (!PEAR::isError($res)) {
            $this->state = NET_SMPP_CLIENT_STATE_OPEN;
            return true;
        }
        $this->_errThunk($res);
        return false;
    }

    /**
     * Disconnect TCP connection
     *
     * @return  mixed  Net_Socket::disconnect()'s return value
     * @see     Net_Socket::disconnect()
     */
    function &disconnect()
    {
        $this->log('Disconnecting');
        $res =& $this->_socket->disconnect();
        if (!PEAR::isError($res)) {
            $this->state = NET_SMPP_CLIENT_STATE_CLOSED;
            return true;
        }
        $this->_errThunk($res);
        return false;
    }

    /**
     * Bind to SMSC
     *
     * This sends the bind_transmitter SMPP command to the SMSC, and reads back
     * the bind_transmitter_resp PDU.
     *
     * @see     Net_SMPP_Command_bind_transmitter
     * @return  mixed
     */
    function &bind($args = array())
    {
        if (isset($this->vendor) &&
            Net_SMPP_Vendor::PDUexists($this->vendor, 'bind_transmitter')) {
            $pdu =& Net_SMPP_Vendor::PDU($this->vendor, 'bind_transmitter', $args);
        } else {
            $pdu =& Net_SMPP::PDU('bind_transmitter', $args);
        }

        $res =& $this->sendPDU($pdu);
        if ($res === false) {
            return $res;
        }
        return $this->readPDU();
    }

    /**
     * Unbind from SMSC
     *
     * This sends the unbind SMPP command to the SMSC
     *
     * @see     Net_SMPP_Command_unbind
     * @return  mixed
     */
    function &unbind()
    {
        if (isset($this->vendor) &&
            Net_SMPP_Vendor::PDUexists($this->vendor, 'unbind')) {
            $pdu =& Net_SMPP_Vendor::PDU($this->vendor, 'unbind');
        } else {
            $pdu =& Net_SMPP::PDU('unbind');
        }

        $res =& $this->sendPDU($pdu);
        if ($res === false) {
            return $res;
        }
        return $this->readPDU();
    }

    /**
     * Send a PDU to the SMSC
     *
     * @param   object  $pdu  PDU to send
     * @return  mixed   Net_Socket::write()'s return value
     * @see     Net_Socket::write()
     */
    function &sendPDU(&$pdu)
    {
        $map =& Net_SMPP_Client::_commandStates();

        // Check state
        if (!in_array($this->state, $map[$pdu->command])) {
            $this->es->push(NET_SMPP_ESME_RINVBNDSTS, 'error',
                array('command' => $pdu->command),
                Net_SMPP_PDU::statusDesc(NET_SMPP_ESME_RINVBNDSTS));
            return false;
        }

        $this->_pushPDU($pdu);
        $this->log('Sending ' . $pdu->command . ' PDU');
        if ($this->_debug) {
            $this->_dumpPDU($pdu);
        }
        $res =& $this->_socket->write($pdu->generate());
        if (PEAR::isError($res)) {
            $this->_errThunk($res);
            return false;
        }
        return true;
    }

    /**
     * Read a PDU from the SMSC
     *
     * @return  mixed  Object containing response PDU or PEAR_Error
     * @since   0.0.1dev2
     */
    function &readPDU()
    {
        static $responses = 0;

        $map =& Net_SMPP_Client::_stateSetters();

        $this->log('Have read ' . $responses . ' response PDUs');

        // Get the PDU length
        $rawlen = $this->_socket->read(4);

        if (PEAR::isError($rawlen)) {
            $this->log('Error reading PDU length');
            $this->_errThunk($rawlen);
            return false;
        } else if (strlen($rawlen) === 0) {
            // No data to read
            $this->log('No data to read');
            return false;
        }

        $len = array_values(unpack('N', $rawlen));
        $len = $len[0];
        $this->log('PDU length is ' . $len . ' octets');

        // Get the rest of the PDU
        $rawpdu = $this->_socket->read($len - 4);
        if (PEAR::isError($rawpdu)) {
            $this->log('Error reading PDU data');
            return $rawpdu;
        }
        $rawpdu = $rawlen . $rawpdu;

        if ($this->_debug) {
            $file = ++$responses . '.pdu';
            $this->log('Dumping PDU data to ' . $file);
            $fp = fopen($file, 'w');
            fwrite($fp, $rawpdu);
            fclose($fp);
        }

        $cmd = Net_SMPP_PDU::extractCommand($rawpdu);
        $this->log('Read ' . $cmd . ' PDU');
        if (isset($this->vendor)) {
            $pdu =& Net_SMPP_Vendor::parsePDU($this->vendor, $rawpdu);
        } else {
            $pdu =& Net_SMPP::parsePDU($rawpdu);
        }
        $this->_pushPDU($pdu);
        $this->log('Parsed PDU data');

        if ($this->_debug) {
            $this->_dumpPDU($pdu);
        }

        if ($pdu->isError()) {
            $this->log('Response PDU was an error: ' . $pdu->statusDesc());
            $this->es->push($pdu->status, 'error', array(), $pdu->statusDesc());
        } else if (array_key_exists($pdu->command,
                                    $map)) {
            $this->state = $map[$pdu->command];
            $this->log('Command ' . $pdu->command . ' changes state to ' .  $map[$pdu->command]);
        }
        return $pdu;
    }

    /**
     * Accept an object
     *
     * @param   object  $object
     * @return  boolean  true if accepted, false otherwise
     */
    function accept(&$object)
    {
        if (is_a($object, 'log')) {
            $this->log =& $object;
            return true;
        }
        return false;
    }

    /**
     * Log a message
     *
     * @param   string  $msg    Log message
     * @param   int     $level  Log level
     * @access  private
     * @return  void
     */
    function log($msg, $level = PEAR_LOG_DEBUG)
    {
        if (isset($this->log) && is_object($this->log)) {
            $this->log->log($msg, $level);
        }
    }

    /**
     * Push a PDU onto the stack
     *
     * @param   object  $pdu  PDU to push
     * @return  void
     * @access  private
     */
    function _pushPDU(&$pdu)
    {
        if ($pdu->isRequest()) {
            $k = 'request';
        } else {
            $k = 'response';
        }
        $this->log("Pushing " . $pdu->command . " PDU onto the stack as a $k");
        $this->_stack[$pdu->sequence][$k] =& $pdu;
    }

    /**
     * Repackage a PEAR_Error as an ErrorStack error
     *
     * @param   object  $err  PEAR_Error
     * @return  void
     * @access  private
     */
    function _errThunk(&$err)
    {
        $this->es->push($err->code, 'error', array('userinfo' => $err->userinfo),
            $err->message);
    }

    /**
     * Dump PDU data
     *
     * @param   object   $pdu  PDU instance
     * @return  void
     * @access  private
     */
    function _dumpPDU(&$pdu)
    {
        $fp = fopen($pdu->sequence . '-' . $pdu->command, 'w');
        fwrite($fp, $pdu->generate());
        fclose($fp);
    }

    /**
     * States required to issue a command
     *
     * Most SMPP commands may only be issued if the connection is in a certain
     * state. This contains the mapping of which states are valid for which
     * SMPP commands.
     *
     * @return  array  Command state map
     * @access  private
     * @static
     */
    function &_commandStates()
    {
        static $states = array(
            'bind_transmitter'      => array(NET_SMPP_CLIENT_STATE_OPEN),
            'bind_transmitter_resp' => array(NET_SMPP_CLIENT_STATE_OPEN),
            'bind_receiver'         => array(NET_SMPP_CLIENT_STATE_OPEN),
            'bind_receiver_resp'    => array(NET_SMPP_CLIENT_STATE_OPEN),
            'bind_transceiver'      => array(NET_SMPP_CLIENT_STATE_OPEN),
            'bind_transceiver_resp' => array(NET_SMPP_CLIENT_STATE_OPEN),
            'outbind'               => array(NET_SMPP_CLIENT_STATE_OPEN),
            'unbind'                => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'unbind_resp'           => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'submit_sm'             => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'submit_sm_resp'        => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'submit_sm_multi'       => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'submit_sm_multi_resp'  => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'data_sm'               => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'data_sm_resp'          => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'deliver_sm'            => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'deliver_sm_resp'       => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'query_sm'              => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'query_sm_resp'         => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'cancel_sm'             => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'cancel_sm_resp'        => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'replace_sm'            => array(NET_SMPP_CLIENT_STATE_BOUND_TX),
            'replace_sm_resp'       => array(NET_SMPP_CLIENT_STATE_BOUND_TX),
            'enquire_link'          => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'enquire_link_resp'     => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'alert_notification'    => array(NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX),
            'generic_nack'          => array(NET_SMPP_CLIENT_STATE_BOUND_TX,
                                             NET_SMPP_CLIENT_STATE_BOUND_RX,
                                             NET_SMPP_CLIENT_STATE_BOUND_TRX)
        );

        return $states;
    }

    /**
     * Commands which change the state
     *
     * If a command is sent (and a successful response is received), the state
     * needs to be updated. This contains the response PDUs and the state they
     * change to connection to.
     *
     * @return  array  State map
     * @access  private
     * @static
     */
    function &_stateSetters()
    {
        static $setters = array(
            'bind_transmitter_resp' => NET_SMPP_CLIENT_STATE_BOUND_TX,
            'bind_receiver_resp'    => NET_SMPP_CLIENT_STATE_BOUND_RX,
            'bind_transceiver_resp' => NET_SMPP_CLIENT_STATE_BOUND_TRX,
            'unbind_resp'           => NET_SMPP_CLIENT_STATE_OPEN
        );

        return $setters;
    }
}
?>