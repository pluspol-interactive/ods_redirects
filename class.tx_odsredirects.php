<?php
class tx_odsredirects {
	function checkRedirect(&$params){
		$pObj=$params['pObj'];
//		$hash = t3lib_div::md5int($speakingURIpath);
		/*
			Priority (highest on top):
			mode 2: Path and query string match
			mode 3: Path and only given query parts match
			mode 1: Path match
			mode 0: Begins with path
			mode 4: Path and query string regex match
		*/
		$prio=array('0'=>50,'1'=>100,'2'=>200,'3'=>150,'4'=>10);

		// URL parts
		$url=$pObj->siteScript;
		$path=strtok($pObj->siteScript,'?');

		// Create query
		$where=array(
			'(mode=0 AND url=LEFT('.$GLOBALS['TYPO3_DB']->fullQuoteStr($url,'tx_odsredirects_redirects').',LENGTH(url)))', // Begins with URL
			'(mode=1 AND url='.$GLOBALS['TYPO3_DB']->fullQuoteStr($path,'tx_odsredirects_redirects').')', // Path match
			'(mode=2 AND url='.$GLOBALS['TYPO3_DB']->fullQuoteStr($url,'tx_odsredirects_redirects').')', // Path and query string match
			'(mode=4 AND '.$GLOBALS['TYPO3_DB']->fullQuoteStr($url,'tx_odsredirects_redirects').' REGEXP url)', // Path and query string regex match
		);
		if(substr($path,-1)!='/') $where[]='(mode=1 AND url='.$GLOBALS['TYPO3_DB']->fullQuoteStr($path.'/','tx_odsredirects_redirects').')'; // Path match if entered without trailing '/'

		// Fetch redirects
		$res=$GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'*',
			'tx_odsredirects_redirects',
			'('.implode(' OR ',$where).') AND hidden=0',
			'',
			'domain_id DESC'
		);
		$redirect=false;
		while($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)){
			// Check furhter requirements
/*			switch($row['mode']){
				case '3':
					// Only given query parts must match
					$parts=parse_url($row['url']);
					$parts=parse_url();
				break;
			}*/

			if($row){
				// Entries with a domain_id have priority
				if($row['domain_id']){
					if(!$domain_id){
						// Get domain record
						$res2=$GLOBALS['TYPO3_DB']->exec_SELECTquery('uid','sys_domain','domainName='.$GLOBALS['TYPO3_DB']->fullQuoteStr($_SERVER['HTTP_HOST'],'sys_domain').' AND hidden=0');
						$row2=$GLOBALS['TYPO3_DB']->sql_fetch_row($res2);
						$domain_id=$row2[0];
					}
					if($row['domain_id']==$domain_id){
						$specific=true;
						if($prio[$row['mode']]>$prio[$redirect['mode']]) $redirect=$row;
					}
				}else{
					// All Domains
					if(!$specific && $prio[$row['mode']]>$prio[$redirect['mode']]) $redirect=$row;
				}
			}
		}

		// Do redirect
		if($redirect){
			// Update statistics
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
				'tx_odsredirects_redirects',
				'uid='.$redirect['uid'],
				array(
					'counter' => 'counter+1',
					'tstamp' => time(),
					'last_referer' => t3lib_div::getIndpEnv('HTTP_REFERER')
				),
				array('counter')
			);

			// Build destination URL
			if($redirect['mode']==4) $redirect['destination']=preg_replace('/'.$redirect['url'].'/i',$redirect['destination'],$url);
			$destination=$this->getLink($redirect['destination'],$_SERVER['HTTP_HOST'].'/'.$url);

			// Append trailing url
			if($redirect['append']){
				$append=substr($url,strlen($redirect['url']));
				// Replace ? by & if query parts appended to non speaking url
				if(strpos($destination,'?') && substr($append,0,1)=='?') $append='&'.substr($append,1);
				$destination.=$append;
			}

			// Redirect
			if ($redirect['has_moved']) {
				header('HTTP/1.1 301 Moved Permanently');
			}
			header('Location: '.t3lib_div::locationHeaderUrl($destination));
			header('X-Note: Redirect by ods_redirects');
			header('Connection: close');
			exit();
		}
	}

	function getLink($destination,$source=''){
		// $destination='test@example.com'; // email
		// $destination='http://example.com/test.html'; // URL
		// $destination='example.com'; // URL without http://
		// $destination='example.com/test.html'; // URL without http://
		// $destination='/test.html'; // path
		// $destination='test/test.html'; // path
		// $destination='123'; // page id
		// $destination='test'; // alias
		if(strpos($destination,'@')){
			// email
			return 'mailto:'.$destination;
		}elseif(strpos($destination,'//')){
			// URL
			return $destination;
		}elseif((strpos($destination,'.') && strpos($destination,'/')===false) || (strpos($destination,'.')<strpos($destination,'/'))){
			// URL without http://
			return 'http://'.$destination;
		}elseif(strpos($destination,'/')!==false){
			// path
			return $destination;
		}else{
			// page id
			// language detection
			$L=$this->languageDetection($source);
			// build URL
			$url=$this->buildURL($destination,$L);
			if(!$url) $url='index.php?id='.$destination.($L===false ? '' : '&L='.$L);
			return $url;
		}
	}

	function languageDetection($source){
		$conf=unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ods_redirects']);

		// Language from L
		$L=is_numeric(t3lib_div::_GP('L')) ? intval(t3lib_div::_GP('L')) : false;

		// Language from speaking URL
		if(!$L && $conf['lang_detect'] && $source){
//			parse_str($conf['lang_detect'],$lang_detect); // not work with "."
			$lang_detect=array();
			foreach(explode(';',$conf['lang_detect']) as $pair){
				$parts=explode('=',$pair);
				$lang_detect[$parts[0]]=$parts[1];
			}
			foreach($lang_detect as $str=>$id){
				if(strpos($source,strval($str))!==false) $L=intval($id);
			}
		}
		return $L;
	}

	function buildURL($id,$L=false){
		if($id){
			$GLOBALS['TSFE']->determineId();
			$GLOBALS['TSFE']->getCompressedTCarray();
			$GLOBALS['TSFE']->initTemplate();
			$GLOBALS['TSFE']->getConfigArray();

			// Set linkVars, absRefPrefix, etc
			require_once(PATH_tslib . 'class.tslib_pagegen.php');
			TSpagegen::pagegenInit();

			$cObj = t3lib_div::makeInstance('tslib_cObj');
			$cObj->start(array());
			$url = $cObj->getTypoLink_URL($id, $L ? array('L'=>$L) : array());
			return $url;
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.tx_odsredirects.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.tx_odsredirects.php']);
}
?>