<?php
require_once "Net/NNTP/Client.php";

class SpotNntp {
		private $_server;
		private $_user;
		private $_pass;
		private $_serverenc;
		private $_serverport;
		
		private $_error;
		private $_nntp;
		
		function __construct($server, $serverenc, $serverport, $user, $pass) {
			$error = '';
			
			$this->_server = $server;
			$this->_serverenc = $serverenc;
			$this->_serverport = $serverport;
			$this->_user = $user;
			$this->_pass = $pass;
			
			# Set pear error handling to be used by exceptions
			PEAR::setErrorHandling(PEAR_ERROR_EXCEPTION);			
			$this->_nntp = new Net_NNTP_Client();
		} # ctor
		
		function selectGroup($group) {
			return $this->_nntp->selectGroup($group);
		} # selectGroup()
		
		function getOverview($first, $last) {
			$hdrList = $this->_nntp->getOverview($first . '-' . $last);
			$hdrList = array_reverse($hdrList);
			
			return $hdrList;
		} # getOverview()
		
		function quit() {
			try {
				$this->_nntp->quit();
			} 
			catch(Exception $x) {
				// dummy, we dont care about exceptions during quitting time
			} # catch
		} # quit()
		
		function getHeader($msgid) {
			return $this->_nntp->getHeader($msgid);
		} # getHeader()

		function getBody($msgid) {
			return $this->_nntp->getBody($msgid);
		} # getBody	()
		
		function connect() {
			$ret = $this->_nntp->connect($this->_server, $this->_serverenc, $this->_serverport);
			if (!empty($this->_user)) {
				$authed = $this->_nntp->authenticate($this->_user, $this->_pass);
			} # if
		} # connect()
		
		function getArticle($msgId) {
			$result = array('header' => array(), 'body' => array());
			
			# Fetch het artikel
			$art = $this->_nntp->getArticle($msgId);
			
			# vervolgens splitsen we het op in een header array en een body array
			$i = 0;
			while( (count($art) >= $i) && ($art[$i] != '')) {
				$result['header'][] = $art[$i];
				$i++;
			} # while
			$i++;

			while( (count($art) >= $i) && ($art[$i] != '')) {
				$result['body'][] = $art[$i];
				$i++;
			} # while
			
			return $result;
		} # getArticle
		
		
		function getComments($commentList) {
			$comments = array();
			
			foreach($commentList as $comment) {
				$tmpAr = $this->getArticle('<' . $comment['messageid'] . '>');
				
				# extract de velden we die we willen hebben
				foreach($tmpAr['header'] as $hdr) {
					$keys = explode(':', $hdr);
					
					switch($keys[0]) {
						case 'From'	: $tmpAr['from'] = trim(substr($hdr, strlen('From: '), strpos($hdr, '<') - 1 - strlen('From: '))); break;
						case 'Date'	: $tmpAr['date'] = substr($hdr, strlen('Date: ')); break;
					} # switch
				} # foreach
				
				$comments[] = $tmpAr; 
			} # foreach

			return $comments;
		} # getComments
		
		function getFullSpot($msgId) {
			# initialize some variables
			$spotParser = new SpotParser();
			
			$spot = array('xml' => '',
						  'user-signature' => '',
						  'user-key' => '',
						  'verified' => false,
						  'info' => array(),
						  'xml-signature' => '');
			# Vraag de volledige article header van de spot op
			$header = $this->getHeader('<' . $msgId . '>');
			
			# Parse de header			  
			foreach($header as $str) {
				$keys = explode(':', $str);
				
				switch($keys[0]) {
					case 'X-XML' 			: $spot['xml'] .= substr($str, 7); break;
					case 'X-User-Signature'	: $spot['user-signature'] = base64_decode($spotParser->UnspecialString(substr($str, 18))); break;
					case 'X-XML-Signature'	: $spot['xml-signature'] = substr($str, 17); break;
					case 'X-User-Key'		: {
							$xml = simplexml_load_string(substr($str, 12)); 
							$spot['user-key']['exponent'] = (string) $xml->Exponent;
							$spot['user-key']['modulo'] = (string) $xml->Modulus;
							break;
					} # x-user-key
				} # switch
			} # foreach
			
			# Valideer de signature van de XML, deze is gesigned door de user zelf
			$spot['verified'] = $spotParser->checkRsaSignature($spot['xml-signature'], $spot['user-signature'], $spot['user-key']);

			# Parse nu de XML file
			$spot['info'] = $spotParser->parseFull($spot['xml']);
			
			return $spot;
		} # getSpot 
		
} # class SpotNntp