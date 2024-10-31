<?php
// Written by Mike (MagicBeanDip) Majorowicz http://v1.magicbeandip.com
#define('RECEIVE_LINKS_Debug','defined');
if (defined('RECEIVE_LINKS_Debug')) {
	error_reporting(E_ALL);
}
if (defined('RECEIVE_LINKS_ClientVersion')) {
	return; //This return prevents the rest of the code from being included again.
}
#
define('RECEIVE_LINKS_ClientVersion','2.1.5');
define('RECEIVE_LINKS_ClientId','uniquereid');
define('RECEIVE_LINKS_Folder',''); #No leading slash, Include trailing slash, ie: 'subdir/'
define('RECEIVE_LINKS_DataDirectoryFullPath','client_folder');# This is optional.  It specifies the full path to the data directory with a trailing slash.
#If the above line is uncommented, RLclient will look for the data & log files in the directory specified and not search other directories.
define('RECEIVE_LINKS_TransactionTemplate','http://%%SERVER%%.receivelinks.com/transaction/%%TRANSACTION_ID%%.txt');

#Uncomment and configure these lines to enable access via proxy
#define('RECEIVE_LINKS_ProxyUser', '');
#define('RECEIVE_LINKS_ProxyPassword', '');
#define('RECEIVE_LINKS_ProxyHost', 'example.com'); # no http:// in front

if (Receive_Links::isCommsMode()) {
	$oRL=new Receive_Links();
	$oRL->HandleComms();
	exit;
}

function RECEIVE_LINKS_DisplayAds() {
	$SaveER=error_reporting(0);
	if (defined('RECEIVE_LINKS_Debug')) {
		error_reporting(E_ALL);
	}
	$oRL=new Receive_Links();
	echo $oRL->GetFormattedAds();
	error_reporting($SaveER);
}
function RECEIVE_LINKS_GetAds() {
	$SaveER=error_reporting(0);
	if (defined('RECEIVE_LINKS_Debug')) {
		error_reporting(E_ALL);
	}
	$oRL=new Receive_Links();
	$Ads=$oRL->GetFormattedAds();
	error_reporting($SaveER);
	return $Ads;
}
function RECEIVE_LINKS_GetFormattedAdArray() {
	$SaveER=error_reporting(0);
	if (defined('RECEIVE_LINKS_Debug')) {
		error_reporting(E_ALL);
	}
	$oRL=new Receive_Links();
	$aAds=$oRL->aGetFormattedAdArray();
	$oRL->HandleErrors();
	error_reporting($SaveER);
	return $aAds;
}

class Receive_Links {
	function Receive_Links() {
		$this->AdString='';
		$this->DataFileHandle=FALSE;
		$this->DataFilePath=FALSE;
		$this->DataFileIdentifier='RLDATA1';
		$this->SectionSeperator='<break>';
		$this->ErrorMsgs='';
		$this->FieldSeperator='|';
		$this->isDataFileEmpty=TRUE;
		$this->isDataFileLocked=FALSE;
		$this->NewPageString='';
		$this->PageString='';
		$this->PageKey=FALSE;
		$this->aPersistantVars=array(
			'AdSpacer'=>' - ',
			'LinkClass'=>'',
			'MaxPages'=>10000,
			'Slots'=>5,
			'TestMode'=>1,
			);
	
		// allow for pre4.1.0 versions of php
		if(isset($_SERVER)) {
			$this->aServer=$_SERVER;
		} else {
			global $HTTP_SERVER_VARS;
			$this->aServer=$HTTP_SERVER_VARS;
		}
		if(isset($_POST)) {
			$this->aPost=&$_POST;
		} else {
			global $HTTP_POST_VARS;
			$this->aPost=&$HTTP_POST_VARS;
		}
		if(isset($_GET)) {
			$this->aGet=$_GET;
		} else {
			global $HTTP_GET_VARS;
			$this->aGet=$HTTP_GET_VARS;
		}
		if(get_magic_quotes_gpc()) {
			$aGetKeys=array_keys($this->aGet);
			foreach ($aGetKeys as $Key) {
				$this->aGet[$Key]=stripslashes($this->aGet[$Key]);
			}
			$aPostKeys=array_keys($this->aPost);
			foreach ($aPostKeys as $Key) {
				$this->aPost[$Key]=stripslashes($this->aPost[$Key]);
			}
		}
	}
	function GetPersistant($Key) {
		return @$this->aPersistantVars[$Key];
	}
	function GetPost($Key) {
		return @$this->aPost[$Key];
	}
	function SetPost($Key,$Value) {
		$this->aPost[$Key]=$Value;
	}
	
	## Error Handling
	function ErrMsg($String) {
		$this->ErrorMsgs.=$String."\n";
	}
	function SaveErrors() {
		$LogPath=$this->FileFind('rl'.RECEIVE_LINKS_ClientId.'log.txt');
		if ($LogPath===FALSE) {
			$this->ErrMsg('SaveErrors() :Warning - Unable to find log file.');
		} else {
			$Header=date('r').' PageKey=:'.$this->PageKey.":\n";
			$fhLog=fopen($LogPath,'r+b');
			flock($fhLog,LOCK_EX);
			fseek($fhLog,0,SEEK_END);
			fwrite($fhLog,$Header);
			fwrite($fhLog,$this->ErrorMsgs);
			$LogMax=51200;
			if (filesize($LogPath)>$LogMax) {
				fseek($fhLog,(-($LogMax/2)),SEEK_END);
				$sLog=fread($fhLog,$LogMax/2);
				rewind($fhLog);
				fwrite($fhLog,$sLog);
				ftruncate($fhLog,ftell($fhLog));
			}
			flock($fhLog,LOCK_UN);
			fclose($fhLog);
		}
	}
	function HandleErrors() {
		if ($this->ErrorMsgs) {
			$this->SaveErrors();
		}
		return $this->ErrorMsgs;
	}
	## ^ Error Handling
	
	## fsockopen() routines
	function HttpGet($Url) {
		$fhUrl=$this->HttpOpen($Url);
		if (!$fhUrl) {
			$this->ErrMsg('HttpGet() :Unable to open Url.');
			return FALSE;
		}
		$Result='';
		while(!feof($fhUrl)) {
			$Result.=fread($fhUrl, 524288);
		}
		fclose($fhUrl);
		return $Result;
	}
	function HttpOpen($Url) {
		//This is the normal way to do it.
		//return @fopen($url,'r');

		//This is the way to do it if fopen isn't configured to work with urls.
		$TimeOut = 10;
		$Port = 80;
		$ErrNum=0;
		$ErrText='';
	
		$aUrlParts=array();
		$Request='';
		if (defined('RECEIVE_LINKS_ProxyHost')) {
			$aUrlParts['host']=RECEIVE_LINKS_ProxyHost;
			$Request = 'GET '.$Url." HTTP/1.0\r\n";
			$Request .= 'User-Agent: AUTH/1.0 CUST '.phpversion()."\r\n";
			$Request .= "Pragma: no-cache\r\n";
			if (defined('RECEIVE_LINKS_ProxyUser')) {
				$Realm = base64_encode(RECEIVE_LINKS_ProxyUser.":".RECEIVE_LINKS_ProxyPassword);
				$Request .= 'Proxy-authorization: Basic '.$Realm."\r\n";
			}
		} else {
			$aUrlParts = parse_url($Url);
			$RequestUri = $aUrlParts['path'];
			if (isset($aUrlParts['query'])) {
				if ($aUrlParts['query']!='') {
				 $RequestUri .= '?'.$aUrlParts['query'];
				}
			}
			$Request = 'GET '.$RequestUri." HTTP/1.0\r\n";
		}
		$fh = @fsockopen($aUrlParts['host'], $Port, $ErrNum, $ErrText, $TimeOut);
		if (!$fh) {
			$this->ErrMsg('HttpOpen() :Unable to establish connection.');
			return FALSE;
		}
	
		$Request .= 'Host: '.$aUrlParts['host']."\r\n";
		$Request .= "\r\n";
	
		fwrite($fh, $Request);
	
		$StatusLine = fgets($fh, 1024);
		$aStatusLine=explode(' ',$StatusLine,3);
		if (!isset($aStatusLine[1])) {
			$this->ErrMsg('HttpOpen() :Invalid HTTP status line.');
			return FALSE;
		}
		$aStatusLine[1]=intval($aStatusLine[1]);
		if (($aStatusLine[1]<200) or ($aStatusLine[1]>299)) {
			$this->ErrMsg('HttpOpen() :Invalid HTTP response code - '.$aStatusLine[1].".");
			return FALSE;
		}
		while(!feof($fh)) {
			$Line = fgets($fh, 10240);
			if ($Line == "\r\n") {
				return $fh;
			}
		}
		$this->ErrMsg('HttpOpen() :Termination of HTTP header not found.');
		return FALSE;
	}
	## ^ fsockopen() routines
	
	## Low level Data File Handling
	function DataFileClose() {
		if (!$this->DataFileHandle) {
			return NULL;
		}
		$this->DataFileUnlock();
		fclose($this->DataFileHandle);
		$this->DataFileHandle=FALSE;
		return TRUE;
	}
	//If data file is found, save location in $this->DataFilePath and return TRUE.
	function DataFileFind() {
		$this->DataFilePath=$this->FileFind('rl'.RECEIVE_LINKS_ClientId.'data.txt');
		if ($this->DataFilePath===FALSE) {
			$this->ErrMsg('DataFileFind() :DataFile not found.');
			return FALSE;
		}
		return TRUE;
	}
	function DataFileLock() {
		$this->isDataFileLocked=FALSE;
		$Stop=time()+2;
		while (!($this->isDataFileLocked) and (time()<$Stop)) {
			$this->isDataFileLocked=flock($this->DataFileHandle,LOCK_EX+LOCK_NB);
		}
		return $this->isDataFileLocked;
	}
	//Save file handle in $this->DataFileHandle and return TRUE if successful, else return FALSE.
	function DataFileOpen() {
		if (is_resource($this->DataFileHandle)) {
			$this->ErrMsg('DataFileOpen():WARNING- DataFile is already open.');
			rewind($this->DataFileHandle);
			return TRUE;
		}
		if (!$this->DataFilePath) {
			$this->DataFileFind();
			if(!$this->DataFilePath) {
				$this->ErrMsg('DataFileOpen():DataFileFind failed.');
				$this->DataFilePath=FALSE;
				$this->DataFileHandle=FALSE;
				return FALSE;
			}
		}
		$SaveTrackErrors=ini_set('track_errors','1');
		$this->DataFileHandle=@fopen($this->DataFilePath,'r+b');
		$FopenErr=@$php_errormsg;
		ini_set('track_errors',$SaveTrackErrors);
		if (!$this->DataFileHandle) {
			$this->ErrMsg('DataFileOpen():Found data file but unable to open it - Check that data file is readable by the web server - '.$FopenErr.".");
			return FALSE;
		}
		$isLocked=$this->DataFileLock();
		if (!$isLocked) {
			$this->ErrMsg('DataFileOpen():Unable to lock data file.');
			return FALSE;
		}
		return TRUE;
	}
	function DataFileRead($Length) {
		if (!$this->isDataFileLocked) {
			$this->ErrMsg('DataFileRead():DataFile is not locked.');
			return FALSE;
		}
		return fread($this->DataFileHandle,$Length);
	}
	function DataFileTestId() {
		if (!$this->DataFileHandle) {
			$this->ErrMsg('DataFileTestId() :called without valid file handle.');
			return FALSE;
		}
		rewind($this->DataFileHandle);
		$HeaderLength=strlen($this->DataFileIdentifier)+strlen($this->SectionSeperator);
		$sHeader=$this->DataFileRead($HeaderLength);
		if ($sHeader==$this->DataFileIdentifier.$this->SectionSeperator) {
			return TRUE;
		}
		$this->ErrMsg('DataFileTestId() :The data file is not a valid '.$this->DataFileIdentifier.' file.');
		return FALSE;
	}
	function DataFileUnlock() {  //Returns status of operation ,not state of file lock
		$this->isDataFileLocked=FALSE;
		return flock($this->DataFileHandle,LOCK_UN);
	}
	function DataFileWrite($Data) {
		if (!$this->isDataFileLocked) {
			$this->ErrMsg('DataFileWrite():DataFile is not locked.');
			return FALSE;
		}
		$Status=@fwrite($this->DataFileHandle,$Data);
		if (FALSE===$Status) {
			$this->ErrMsg('DataFileWrite():fwrite failed.');
			return FALSE;
		}
		return $Status;
	}
	function FileFind($FileName) {
		// Do not search other directories if Full Path is specified.
		if (defined('RECEIVE_LINKS_DataDirectoryFullPath')) {
			if (file_exists(RECEIVE_LINKS_DataDirectoryFullPath.$FileName)) {
				return RECEIVE_LINKS_DataDirectoryFullPath.$FileName;
			}
			$this->ErrMsg('FileFind() :RECEIVE_LINKS_DataDirectoryFullPath does not point to '.$FileName.'  Check settings at top of RL client software.');
			return FALSE;
		}
		$DirLevel='';
		for ($i = 0; $i <= 11; $i++) {
			if (file_exists($DirLevel.RECEIVE_LINKS_Folder.$FileName)) {
				return $DirLevel.RECEIVE_LINKS_Folder.$FileName;
			}
			$DirLevel .= '../';
		}
		$this->ErrMsg("FileFind() :$FileName not found. Make sure the the file exists.");
		return FALSE;
	}
	## ^ Low level Data File Handling
	
	## High level Data File Handling
	function DataFileAppend($Data) {
		$SaveMQR=get_magic_quotes_runtime();
		set_magic_quotes_runtime(0);
		$SaveIUAbort=ignore_user_abort(1);
		$Result=$this->_DataFileAppend($Data);
		ignore_user_abort($SaveIUAbort);
		set_magic_quotes_runtime($SaveMQR);
		if ($Result===FALSE) {
			$this->DataFileClose();
		}
		return $Result;
	}
	function _DataFileAppend($Data) {
		if (!$this->DataFileHandle) {
			$this->ErrMsg('_DataFileAppend() :Missing DataFileHandle.');
			return FALSE;
		}
		
		fseek($this->DataFileHandle,0,SEEK_END);
		$this->DataFileWrite($Data);
		return TRUE;
	}
	function DataFileLoad() {
		$SaveMQR=get_magic_quotes_runtime();
		set_magic_quotes_runtime(0);
		$Result=$this->_DataFileLoad();
		set_magic_quotes_runtime($SaveMQR);
		if ($Result===FALSE) {
			$this->DataFileClose();
		}
		return $Result;
	}
	function _DataFileLoad() {
		if ($this->DataFileHandle) {
			rewind($this->DataFileHandle);
		} else {
			if (!$this->DataFileOpen()) {
				$this->ErrMsg('_DataFileLoad() : Unable to open Data File.');
				return FALSE;
			}
		}
		if (filesize($this->DataFilePath)==0) {
			return $this->DataFileSave();
		}
		if (!$this->DataFileTestId()) {
			$this->ErrMsg('_DataFileLoad():DataFile Id Test failed.');
			return FALSE;
		}
		rewind($this->DataFileHandle);
		$sDataFile='';
		while (!feof($this->DataFileHandle)) {
			$sDataFile.=$this->DataFileRead(1048575);
		}
		$aDataSections=explode($this->SectionSeperator,$sDataFile);

		if (count($aDataSections)!=5) {
			$this->ErrMsg('_DataFileLoad():Incorrect number of sections in DataFile:'.count($aDataSections).':.');
			return FALSE;
		}
		$this->aPersistantVars=unserialize($aDataSections[1]);
		if (!is_array($this->aPersistantVars)) {
			$this->ErrMsg('_DataFileLoad():Unable to load persistant vars from DataFile.');
			return FALSE;
		}
		$this->PageString=$aDataSections[2];
		$this->AdString=$aDataSections[3];
		$this->NewPageString=$aDataSections[4];
		
		return TRUE;
	}
	function DataFileSave() {
		$SaveMQR=get_magic_quotes_runtime();
		set_magic_quotes_runtime(0);
		$SaveIUAbort=ignore_user_abort(1);
		$Result=$this->_DataFileSave();
		ignore_user_abort($SaveIUAbort);
		set_magic_quotes_runtime($SaveMQR);
		if ($Result===FALSE) {

			$this->DataFileClose();
		}
		return $Result;
	}
	function _DataFileSave() {
		
		if (!$this->DataFileHandle) {
			$this->ErrMsg('_DataFileSave() :Missing DataFileHandle.');
			return FALSE;
		}
		
		rewind($this->DataFileHandle);
		$this->DataFileWrite($this->DataFileIdentifier .$this->SectionSeperator);
		
		$this->DataFileWrite(serialize($this->aPersistantVars).$this->SectionSeperator);
		$this->DataFileWrite($this->PageString);
		$this->DataFileWrite($this->SectionSeperator);
		$this->DataFileWrite($this->AdString);
		$this->DataFileWrite($this->SectionSeperator);
		$this->DataFileWrite($this->NewPageString);
		
		ftruncate ($this->DataFileHandle, ftell($this->DataFileHandle));
		return TRUE;
	}
	## High level Data File Handling
	
	## Dispatch Communications with RL server
	function HandleComms() {
		ignore_user_abort(TRUE);
		if (ini_get('max_execution_time')<900) {
			ini_set('max_execution_time',900);
		}
		$Result='';
		switch (@$this->aGet['action']) {
			case 'replacepages':
				$Result=$this->ActionReplacePages();
				break;
			case 'insertpages':
				$Result=$this->ActionInsertPages();
				break;
			case 'replaceads':
				$Result=$this->ActionReplaceAds();
				break;
			case 'insertads':
				$Result=$this->ActionInsertAds();
				break;
			case 'replacevars':
				$Result=$this->ActionReplaceVars();
				break;
			case 'getchecksums':
				$Result=$this->ActionGetChecksums();
				break;
			case 'getnewpages':
				$Result=$this->ActionGetNewPages();
				break;
			case 'erasenewpages':
				$Result=$this->ActionEraseNewPages();
				break;
			case 'getversion':
				$Result=RECEIVE_LINKS_ClientVersion;
				break;
			case 'commscheck':
				$Result=$this->ActionCommsCheck();
				break;
			default:
				$Result='error';
				$this->ErrMsg('HandleComms() :Not a valid action :'.htmlentities(@$this->aGet['action']).':.');
		}
		$this->DataFileClose();
		print $Result;
		$Errors=$this->HandleErrors();
		if ($Errors) {
			print "\n".$Errors;
		}
	}
	function isCommsMode() {
		#function must be callable as a static function.
		if (isset($_SERVER)) {
			$ScriptName=$_SERVER['SCRIPT_NAME'];
		} else {
			global $HTTP_SERVER_VARS;
			$ScriptName=$HTTP_SERVER_VARS['SCRIPT_NAME'];
		}
		if (substr($ScriptName,-28) == 'rl'.RECEIVE_LINKS_ClientId.'client.php') {
			return TRUE;
		}
		return FALSE;
	}
	## ^ Dispatch Communications with RL server
	
	## Actions initiated by RL server
	function ActionCommsCheck() {
		$isConfirmed=$this->isConfirmedByRL();
		if (!$isConfirmed) {
			$this->ErrMsg('ActionCommsCheck() :Confirmation of Transaction failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionEraseNewPages() {
		if (!$this->DataFileLoad()) {
			$this->ErrMsg('ActionEraseNewPages() :DataFileLoad failed.');
			return 'error';
		}
		
		$this->NewPageString='';
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionEraseNewPages() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionGetNewPages() {
		if (!$this->DataFileLoad()) {
			$this->ErrMsg('ActionGetNewPages() :DataFileLoad failed.');
			return 'error';
		}
		return $this->NewPageString.$this->SectionSeperator.md5($this->NewPageString);
	}
	function ActionGetChecksums() {
		if (!$this->DataFileLoad()) {
			$this->ErrMsg('ActionGetChecksums() :DataFileLoad failed.');
			return 'error';
		}
		$AdsChecksum=md5($this->AdString);
		$PagesChecksum=md5($this->PageString);
		return $AdsChecksum.$this->SectionSeperator.$PagesChecksum;
	}
	function ActionInsertAds() {
		if (!$this->SetupForActions()) {
			$this->ErrMsg('ActionReplaceAds() :SetupForActions failed.');
			return 'error';
		}
		$this->AdString=$this->GetPost('transactiondata').$this->AdString;
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionReplaceAds() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionInsertPages() {
		if (!$this->SetupForActions()) {
			$this->ErrMsg('ActionReplacePages() :SetupForActions failed.');
			return 'error';
		}
		$this->PageString=$this->GetPost('transactiondata').$this->PageString;
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionReplacePages() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionReplaceAds() {
		if (!$this->SetupForActions()) {
			$this->ErrMsg('ActionReplaceAds() :SetupForActions failed.');
			return 'error';
		}
		$this->AdString=$this->GetPost('transactiondata');
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionReplaceAds() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionReplacePages() {
		if (!$this->SetupForActions()) {
			$this->ErrMsg('ActionReplacePages() :SetupForActions failed.');
			return 'error';
		}
		$this->PageString=$this->GetPost('transactiondata');
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionReplacePages() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function ActionReplaceVars() {
		$isConfirmed=$this->isConfirmedByRL();
		if (!$isConfirmed) {
			$this->ErrMsg('ActionReplaceVars() :Confirmation of TransactionData failed.');
			return FALSE;
		}
		if (1==@$this->aGet['resetfile']) {
			if (!$this->DataFileOpen()) {
				$this->ErrMsg('ActionReplaceVars() :DataFileOpen failed.');
				return FALSE;
			}
		} else {
			if (!$this->DataFileLoad()) {
				$this->ErrMsg('ActionReplaceVars() :DataFileLoad failed.');
				return FALSE;
			}
		}
		
		$this->aPersistantVars=unserialize($this->GetPost('transactiondata'));
		if (FALSE===$this->aPersistantVars) {
			$this->ErrMsg('ActionReplaceVars() :Failed to unserialize TransactionData.');
			return 'error';
		}
		if (!$this->DataFileSave()) {
			$this->ErrMsg('ActionReplaceVars() :DataFileSave failed.');
			return 'error';
		}
		return 'success';
	}
	function isConfirmedByRL() {
		if (empty($this->aGet['transactionid'])) {
			$this->ErrMsg('isConfirmedByRL() :TransactionId not found in GET array.');
			return FALSE;
		}
		$TransactionId=rawurlencode($this->aGet['transactionid']);
		if (!isset($this->aGet['server'])) {
			$this->ErrMsg('isConfirmedByRL() :Server number not found in GET array.');
			return FALSE;
		}
		$Server=$this->ValidServer($this->aGet['server']);
		if (FALSE===$Server) {
			$this->ErrMsg('isConfirmedByRL() :Server number not valid.');
			return FALSE;
		}
		# Must allow transactiondata to be an empty string ''
		if (!empty($this->aGet['datamode'])) {
			if ('pull'==$this->aGet['datamode']) {
				$this->PulledDataToPostArray($TransactionId,$Server);
			}
		}
		if (is_null($this->GetPost('transactiondata'))) {
			$this->ErrMsg('isConfirmedByRL() :TransactionData not found in POST array.');
			return FALSE;
		}

		$Url=$this->MakeTransactionUrl($TransactionId,$Server);
		$RlTransactionKey=$this->HttpGet($Url);
		if (md5($this->GetPost('transactiondata'))!=$RlTransactionKey) {
			$this->ErrMsg('isConfirmedByRL() :Client TransactionKey does not match RL Transaction Key.');
			return FALSE;
		}
		return TRUE;
	}
	function MakeTransactionUrl($TransactionId,$Server) {
		$Url=RECEIVE_LINKS_TransactionTemplate;
		$Url=str_replace('%%SERVER%%',$Server,$Url);
		$Url=str_replace('%%TRANSACTION_ID%%',$TransactionId,$Url);
		return $Url;
	}
	function PulledDataToPostArray($TransactionId,$Server) {
		$Url=$this->MakeTransactionUrl($TransactionId.'_data',$Server);
		$PulledData=$this->HttpGet($Url);
		if (FALSE===$PulledData) {
			$this->ErrMsg('PulledDataToPostArray() :HttpGet failed to retrieve transaction data.');
			return FALSE;
		}
		$this->SetPost('transactiondata',$PulledData);
	}
	function SetupForActions() {
		$isConfirmed=$this->isConfirmedByRL();
		if (!$isConfirmed) {
			$this->ErrMsg('SetupForActions() :Confirmation of TransactionData failed.');
			return FALSE;
		}
		if (!$this->DataFileLoad()) {
			$this->ErrMsg('SetupForActions() :DataFileLoad failed.');
			return FALSE;
		}
		return TRUE;
	}
	function ValidServer($Server) {
		$Server=intval($Server);
		if (($Server>=10) and ($Server<=20)) {
			return $Server;
		}
		$this->ErrMsg('ValidServer() :Server number is not valid. :'.$Server.':.');
		return FALSE;
	}
	##  ^ Actions initiated by RL server
	
	
	##  DisplayAds related
	# Return ads in array format
	function aGetAds() {
		$PageKey=$this->FindPageKey();
		if (!$this->DataFileLoad()) {
			$this->ErrMsg('aGetAds() :DataFileLoad failed.');
			return array();
		}
		$aAds=array();
		if ($this->GetPersistant('TestMode')) {
			for ($x=0;$x<$this->GetPersistant('Slots');$x++) {
				$aAds[$x]=array('Href'=>'#','AnchorText'=>'Test Link '.($x+1));
			}
		}
		if (!$PageKey) {
			$this->ErrMsg('aGetAds() :FindPageKey failed.');
			return $aAds;
		}
		$PageRec=$this->GetRecord($PageKey,$this->PageString);
		if (FALSE===$PageRec) {
			$isNewPage=(FALSE===$this->GetRecord($PageKey,$this->NewPageString));
			if ($isNewPage) {
				if (!$this->isMaxPagesReached()) {
					$this->DataFileAppend("<rec $PageKey></rec>");
				}
			}
			$this->DataFileClose();
			return $aAds;
		}
		$aAdKeys=explode($this->FieldSeperator,$PageRec);
		$x=0;
		foreach ($aAdKeys as $AdKey) {
			$AdRec=$this->GetRecord($AdKey, $this->AdString);
			if (FALSE===$AdRec) {
				$this->ErrMsg('aGetAds() :Ad not found :'.$AdKey.':.');
				continue;
			}
			$aAdRec=explode($this->FieldSeperator,$AdRec);
			if (2!=count($aAdRec)) {
				$this->ErrMsg('aGetAds() :AdRec not valid :'.$AdKey.':.');
				continue;
			}
			$aAds[$x]=array('Href'=>$aAdRec[0],'AnchorText'=>$aAdRec[1]);
			++$x;
		}
		return $aAds;
	}
	function aGetFormattedAdArray() {
		$aAds=$this->aGetAds();
		$aFormattedAds=array();
		foreach ($aAds as $aAd) {
			$AdHtml='<a href="'.$aAd['Href'].'"';
			if ($this->GetPersistant('LinkClass')) {
				$AdHtml.=' class="'.$this->GetPersistant('LinkClass').'"';
			}
			$AdHtml.='>'.$aAd['AnchorText'].'</a>';
			$aFormattedAds[]=$AdHtml;
		}
		return $aFormattedAds;
	}
	function FindPageKey() {
		$this->PageKey=FALSE;
		$PageKey='';
		$QueryString='';
		if (!empty($this->aServer['QUERY_STRING'])) {
			$QueryString='?'.$this->aServer['QUERY_STRING'];
		}
#		if (!empty($this->aServer['SCRIPT_NAME'])) {
#			$PageKey=$this->aServer['SCRIPT_NAME'].$QueryString;
#		}
		if (!empty($this->aServer['PATH_INFO'])) {
			$PageKey=$this->aServer['PATH_INFO'].$QueryString;
		}
		if (!empty($this->aServer['REQUEST_URI'])) {
			$PageKey=$this->aServer['REQUEST_URI'];
		}
		if (''==$PageKey) {
			$this->ErrMsg('FindPageKey() :Data to build PageKey is not availablie in $_SERVER array.');
			return FALSE;
		}
		//A ':' in the url will break parse_url() without the 'http://fakedomain.com'
		$aPageKey=parse_url('http://fakedomain.com'.$PageKey);
		$aQuery=array();
		if (isset($aPageKey['query'])) {
			$aQuery=$this->_parse_str($aPageKey['query']);
		}
		if (isset($aQuery[session_name()])) {
			unset($aQuery[session_name()]);
		} else {
			foreach ($aQuery as $Key=>$Value) {
				if (strlen($Value)==32) {
					unset($aQuery[$Key]);
				}
			}
		}
		$NewQuery='';
		$Seperator='';
		foreach ($aQuery as $Key=>$Value) {
			$NewQuery.=$Seperator.$Key;
			if ($aQuery[$Key]!==NULL) {
				$NewQuery.='='.$Value;
			}
			$Seperator='&';
		}
		$this->PageKey=$aPageKey['path'];
		if (''!=$NewQuery) {

			$this->PageKey.='?'.$NewQuery;
		}
		foreach (array('//','/../','/./','#') as $NotAnywhere) {
			if (strpos($this->PageKey,$NotAnywhere) !== FALSE) {
				$this->ErrMsg('FindPageKey() :"'.$NotAnywhere.'" is not allowed in the url.:'.$this->PageKey.".");
				$this->PageKey=FALSE;
				return FALSE;
			}
		}
		if (substr($this->PageKey,0,1)!='/') {
				$this->ErrMsg('FindPageKey() :Url must begin with "/":'.$this->PageKey.".");
				$this->PageKey=FALSE;
				return FALSE;
		}
		foreach (array('?','/.') as $NotEndWith) {
			if (substr($this->PageKey,-(strlen($NotEndWith)))==$NotEndWith) {
				$this->ErrMsg('FindPageKey() :Url must not end with "'.$NotEndWith.'":'.$this->PageKey.".");
				$this->PageKey=FALSE;
				return FALSE;
			}
		}
		return $this->PageKey;
	}
	function GetFormattedAds() {
		$aFormattedAds=$this->aGetFormattedAdArray();
		$AllAdHtml='';
		if ($this->GetPersistant('TestMode')) {
			$AllAdHtml.='Page: '.$this->PageKey.' : ';
		}
		$AdSpacer='';
		foreach ($aFormattedAds as $AdHtml) {
			$AllAdHtml.=$AdSpacer.$AdHtml;
			$AdSpacer=$this->GetPersistant('AdSpacer');
		}
		$this->HandleErrors();
		return $AllAdHtml;
	}
	function GetRecord($Key,$String) {
	# Returns '' if record empty.  Use $Result===FALSE to detect failure.
		$RecStart=strpos($String,'<rec '.$Key.'>');
		if ($RecStart===FALSE) {
			return FALSE;
		}
		$DataStart=$RecStart+strlen($Key)+6;
		$DataEnd=strpos($String,'</rec>',$DataStart);
		if ($DataEnd===FALSE) {
			$this->ErrMsg('GetRecord() :End of record not found, Data file may be corrupt.');
			return FALSE;
		}
		$Data=substr($String,$DataStart,$DataEnd-$DataStart);
		return $Data;
	}
	function isMaxPagesReached() {
		$PageCount=substr_count($this->PageString, '</rec>');
		$NewPageCount=substr_count($this->NewPageString, '</rec>');
		return ($PageCount+$NewPageCount)>=$this->GetPersistant('MaxPages');
	}
	//I wrote my own parse_str because PHP's parse_str() urldecodes the data
	function _parse_str($Str) {
		$aQuery=array();
		$aStr=explode('&',$Str);
		foreach($aStr as $Item) {
			$aItem=explode('=',$Item,2);
			//This was added to differentiate between empty vars with and w/o an '=' ie: ?var1&var2=
			if (strpos($Item,'=')===FALSE) {
				$aQuery[$aItem[0]]=NULL;
			} else {
				$aQuery[$aItem[0]]='';
			}
			if (isset($aItem[1])) {
				$aQuery[$aItem[0]]=$aItem[1];
			}
		}
		return $aQuery;
	}
	##  ^ DisplayAds related
}
?>
