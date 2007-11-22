<?php

/**
 * Entry point
 */
function wfSpecialResources ($par) {
	global $wgOut;
	$page = new Resources();
	$page->execute($par);
}

/**
 * The class implementing Special:Resources (a.k.a. Spezial:Materialien).
 */
class Resources extends SpecialPage {

	// global variables
	var $resourcesList;
	var $title;

	/**
	 * ctor, only calls i18n-routine and creates special page
	 */
	function Resources() {
		self::loadMessages();
		SpecialPage::SpecialPage( wfMsg('resources') ); // this is where the link points to
	}
	
	/**
	 * main worker-function...
	 * @param par the part after the '/' from the HTTP-Request
	 */
	function execute ( $par ) {
		global $wgOut, $wgRequest;
		global $wgResourcesShowPages, $wgResourcesShowSubpages, $wgResourcesShowLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$this->setHeaders();
		$backlinkTitle;

		/* make a Title object from $par */
		if ( $par ) {
			$this->title = Title::newFromText( $par );
		} else {
			$wgOut->addWikiText( wfMsg('no_page_specified') );
			return;
		}

		$backlinkTitle = $this->title;
		$backlinkTalkTitle = $backlinkTitle->getTalkPage();
		$backlinkTalk = $backlinkTalkTitle->getPrefixedText();
		if ( $backlinkTitle->getNsText() == "" ) {
			$nsTabText = wfMsg('nstab-main');
		} else {
			$nsTabText = $backlinkTitle->getNsText();
		}

		/* make backlink variable for script in MediaWiki:Common.js */
		$script = "<script type=\"text/javascript\">/*<![CDATA[*/
var downloadPage = \"" . $backlinkTitle->getPrefixedText() . "\";
var downloadTalkPage = \"" . $backlinkTalk . "\";
var wgArticleTabText = \"" . str_replace("_", " ", $nsTabText) . "\";
var wgDiscussionTabText = \"" . wfMsg('talk') . "\";
/*]]>*/</script>\n";
		$wgOut->addScript( $script );

		$this->resourceList = $this->getResourceList( $this->title );

		$wgOut->addWikiText( $this->printHeader() );
		$wgOut->addHTML( $this->makeList() );
	}

	/** 
	 * generate a list of resources for a given title
	 * @param title The title we want to build the list for
	 * @return a list containing the pages
	 */
	function getResourceList( $title ) {
		global $wgResourcesShowPages, $wgResourcesShowSubpages, $wgResourcesShowLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$resourceList = array();

		/* add the list of pages linking here, if desired */
		if ( $wgResourcesShowPages or $wgResourcesShowPages == NULL ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $wgResourcesShowSubpages or $wgResourcesShowSubpages == NULL )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links (requires ExternalRedirects extension) */
		if ( $wgEnableExternalRedirects and
			( $wgResourcesShowLinks or $wgResourcesShowLinks == NULL ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );
		return $resourceList;
	}

	/**
	 * get a list of pages linking to $title. The algorithm used here
	 * is a modified version of the algorithm used in Special:Whatlinkshere
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($link, $second_line) )
	 */
	function getFiles( $title ) {
		$dbr =& wfGetDB( DB_READ );
		$prefix = $title->getPrefixedText() . ' - ';
		/* copied from SpecialUpload::processUpload(): */
                $prefix = preg_replace ( "/[^" . Title::legalChars() . "]|:/", '-', $prefix );
		$result = array ();

		// Make the query
		$plConds = array(
				'page_id=pl_from',
				'pl_namespace' => $title->getNamespace(),
				'pl_title' => $title->getDBkey(),
				'page_latest=rev_id',
				);

		$tlConds = array(
				'page_id=tl_from',
				'tl_namespace' => $title->getNamespace(),
				'tl_title' => $title->getDBkey(),
				'page_latest=rev_id',
				);

		// Read an extra row as an at-end check
		$queryLimit = $limit + 1;
		$options['LIMIT'] = $queryLimit;
		if ( $offsetCond ) {
			$tlConds[] = $offsetCond;
			$plConds[] = $offsetCond;
		}
		$fields = array( 'page_id', 'page_namespace', 'page_title', 'page_is_redirect', 'page_len', 'rev_timestamp' );
		$plRes = $dbr->select( array( 'pagelinks', 'page', 'revision' ), $fields,
				$plConds, $fname );
		$tlRes = $dbr->select( array( 'templatelinks', 'page', 'revision' ), $fields,
				$tlConds, $fname );
		if ( !$dbr->numRows( $plRes ) && !$dbr->numRows( $tlRes ) ) {
			return array();
		}

		// Read the rows into an array and remove duplicates
		// templatelinks comes second so that the templatelinks row overwrites the
		// pagelinks row, so we get (inclusion) rather than nothing
		while ( $row = $dbr->fetchObject( $plRes ) ) {
			$row->is_template = 0;
			$rows[$row->page_id] = $row;
		}
		$dbr->freeResult( $plRes );
		while ( $row = $dbr->fetchObject( $tlRes ) ) {
			$row->is_template = 1;
			$rows[$row->page_id] = $row;
		}
		$dbr->freeResult( $tlRes );

		// change the keys to 0-based indices
		$rows = array_values( $rows );
		$numRows = count( $rows );

		global $wgSkin, $wgContLang, $wgUser;
		global $wgResourcesNamespaces, $wgResourcesDirectFileLinks;
		$skin = $wgUser->getSkin();
		foreach ( $rows as $row ) {
			if ( $row->page_namespace != 6 ) 
				continue; /* TODO! */

			$targetTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			$pageLength = $row->page_len;

			// the sortkey is suffixed with the NS in case we have articles with same name
			$sortkey = $targetTitle->getText() . ":" . $row->page_namespace;
	
			/* create link and comment text */
			if ( $row->page_namespace == NS_IMAGE && $wgResourcesDirectFileLinks ) {
				// this code is also used below in the else-statement
				$fileArticle = new Image( $targetTitle );
				$link = '<span class="plainlinks">' .
					$skin->makeExternalLink( $fileArticle->getURL(),
					$targetTitle->getText() ) . '</span>';
				$size = $this->size_readable( $fileArticle->getSize(), 'GB', '%01.0f %s' );
				$detailLink = $skin->makeSizeLinkObj(
					$pageLength, $targetTitle, wfMsg( 'details' ) );
				$comment = wfMsg ( 'fileCommentWithDetails', $size, $fileArticle->getMimeType(), $detailLink );
			} else {
				$link = $skin->makeSizeLinkObj(
					$pageLength, $targetTitle, $targetTitle->getText() );

				/* FileArticles still get a special treatment to print the size etc. */
				if ( $row->page_namespace == NS_IMAGE ) {
					// this code is also used above!
					$fileArticle = new Image( $targetTitle );
					$size = $this->size_readable( $fileArticle->getSize(), 'GB', '%01.0f %s' );
					$comment = wfMsg( 'fileComment', $size, $fileArticle->getMimeType() );
				} else {
					$comment = $this->createPageComment( $targetTitle->getNsText(),
							$row->page_len, $row->rev_timestamp );
				}
			}

			$result[$sortkey] = array ( $link, $comment );

		}
		return $result;
	}

	/**
	 * get a list of Subpages for $title. This function is a modfied
	 * version of the algorithm of Special:Prefixindex.
	 * @param title The title of the page we want the subpages of
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ( $link, $linkInfo ) )
	 */
	function getSubpages( $title ) {
		global $wgUser;
		global $wgResourcesSubpagesIncludeRedirects;
		$skin = $wgUser->getSkin();
		$result = array ();
		$prefix = $title->getPrefixedDBkey() . '/';
		$fname = 'Resources::getSubpages';

		/* make a query */
		$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$dbr = wfGetDB( DB_SLAVE );
		$db_conditions = array(
				'page_namespace' => $namespace,
				'page_title LIKE \'' . $dbr->escapeLike( $prefixKey ) .'%\'',
				'page_title >= ' . $dbr->addQuotes( $prefixKey ),
				'page_latest=rev_id',
				);
		if ( $wgResourcesSubpagesIncludeRedirects == false )
			$db_conditions = array_merge( $db_conditions, array('page_is_redirect=0'));

		$res = $dbr->select( array('page', 'revision'),
				array( 'page_namespace', 'page_title', 'page_len', 'rev_timestamp' ),
				$db_conditions,
				$fname
				);

		/* use the results of the query */
		while ( $row = $dbr->fetchObject( $res ) ) {
			$targetTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );

			$link = $skin->makeSizeLinkObj(
				$pageLength, $targetTitle, $targetTitle->getSubpageText() );
			$comment = $this->createPageComment( wfMsg('subpage'),
				$row->page_len, $row->rev_timestamp );
			$sortkey = $targetTitle->getSubpageText() . '/' .
				$targetTitle->getBaseText() . ':' . $targetTitle->getNsText();
			
			$result[$sortkey] = array( $link, $comment );
		}
		return $result;
	}

	/**
	 * get a list of ExternalRedirects for $title. The algorithm we use here is
	 * a modified version of the algorithm used in Special:Prefixindex. We
	 * @param title the title we get the external redirects for
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ( $link, $linkInfo )
	 */
	function getLinks( $title ) {
		global $IP, $wgUser;
		$skin = $wgUser->getSkin();
		$result = array();
		require_once("$IP/extensions/ExternalRedirects/ExternalRedirects.php");

		/* make the query */
		$prefix = $title->getPrefixedDBkey() . "/";
		$fname = 'Resources::getLinks';
		$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page',
				array( 'page_namespace', 'page_title' ),
				array( 'page_namespace' => $namespace,
					'page_title LIKE \'' . $dbr->escapeLike( $prefixKey ) .'%\'',
					'page_title >= ' . $dbr->addQuotes( $prefixKey ),
					'page_is_redirect=1'
				     ),
				$fname,
				array(
					'ORDER BY'  => 'page_title',
					'USE INDEX' => 'name_title',
				     )
				);

		/* use the results */
		while ( $row = $dbr->fetchObject( $res ) ) {
			$targetTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );

			/* fetch content of found article */
			$targetArticle = new Article( $targetTitle ); /* create article obj */
			$data = $targetArticle->pageDataFromTitle( $dbr, $targetTitle ); /* get some data */
			$targetArticle->loadPageData( $data ); /* save that data (i.e. mLatest from next line */
			$revision = Revision::newFromId( $targetArticle->mLatest ); /* create a revision */
			/* finally get some text from that revision */
			$targetArticle->mContent = $revision->userCan( Revision::DELETED_TEXT ) ? $revision->getRawText() : "";
			
			/* parse content of found article (see ExternalRedirect.php) */
			list($num, $target, $targetInfo) = getTargetInfo( $targetArticle );
			if ($num == 0) 
				continue; // not an external redirect

			$link = $skin->makeExternalLink(
					$target, $targetTitle->getSubpageText() );
			$linkInfo = $targetInfo . ' (' . $skin->makeKnownLink( $targetTitle->getPrefixedText(),
					wfMsg('redirect_link_view'), 'redirect=no') . ')';
			$sortkey = ucfirst( $targetTitle->getSubpageText() ) . '/' .
				$targetTitle->getBaseText() . ':' .
				$targetTitle->getNsText();

			$result[$sortkey] = array( $link, $linkInfo );
		}
		return $result;
	}

	/**
	 * constructs the header printed above the actual list of found 
	 * resources. This includes the <h1> as well as the "There are
	 * currently..."
	 * @return string - apart from the div-tags (which are not interpreted
	 * 		by the parser, this is Wiki-Syntax!
	 */
	function printHeader() {
		global $wgUser;
		$count = count( $this->resourceList );
		$skin = $wgUser->getSkin();
		$titleText = $this->title->getFullText();
		$r = "<div id=\"mw-pages\">\n";
		$addResourceText = SpecialPage::getTitleFor( 'AddResource' );
		$r .= wfMsg( 'header', $titleText ) . "\n";
		if ( $count > 1 ) {
			$r .= wfMsg( 'header_text', $count, $addResourceText, $titleText );
		} elseif ( $count == 1 ) {
			$r .= wfMsg( 'header_text_one', $count, $addResourceText, $titleText );
		} else {
			$r .= wfMsg( 'header_text_none', $titleText, $addResourceText );
		}
		$r .= "</div>";
		return $r;	
	}

	/**
	 * Creates a category-style list of all the resources we found.
	 * This function emulates various functions in CategoryViewer.php.
	 * @return string - a HTML-representation of the array.
	 */
	function makeList() {
		global $wgTitle, $wgContLang, $wgCanonicalNamespaceNames;
		global $wgResourcesAddInfos;
		$catPage = new CategoryViewer( $wgTitle );
		$skin = $catPage->getSkin();
		ksort( $this->resourceList );
		
		// this emulates CategoryViewer::getHTML(): 
		$catPage->clearCategoryState();
		// populate:
		foreach ( $this->resourceList as $sortkey=>$value) {
			$catPage->articles_start_char[] = $wgContLang->convert( $wgContLang->firstChar( $sortkey ) );
			if ( $wgResourcesAddInfos ) {
				$catPage->articles[] = $value[0] . '<br />' . $value[1];
			} else {
				$catPage->articles[] = $value[0];
			}
		}
		
		if( count( $catPage->articles ) > 0 )
			$result = $catPage->formatList( $catPage->articles, $catPage->articles_start_char );

		return $result;
	}

	/**
	 * internationalization stuff
	 */
	function loadMessages() {
		static $messagesLoaded = false;
		global $wgMessageCache;
		if ( $messagesLoaded )
			return true;
		$messagesLoaded = true;

		require( dirname( __FILE__ ) . '/Resources.i18n.php' );
		foreach ( $allMessages as $lang => $langMessages ) {
			$wgMessageCache->addMessages( $langMessages, $lang );
		}
		return true;
	}

	/**
	 * function used by SimilarNamedArticles to print the number of
	 * resources for the given title.
	 * @param title Article for that we want the number of resources
	 * @return int the number of resources for the article.
	 */
	public function getResourceListCount( $title ) {
		global $wgResourcesShowPages, $wgResourcesShowSubpages, $wgResourcesShowLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$resourceList = array();

		/* add the list of pages linking here, if desired */
		if ( $wgResourcesShowPages or $wgResourcesShowPages == NULL ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $wgResourcesShowSubpages or $wgResourcesShowSubpages == NULL )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links (requires ExternalRedirects extension) */
		if ( $wgEnableExternalRedirects and
			( $wgResourcesShowLinks or $wgResourcesShowLinks == NULL ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );
		return count($resourceList);
	}

	/**
	 * create a comment to the given (sub)page. This is mainly used to
	 * parse the timestamp.
	 * @param info the namespace for pages, wfMsg('subpage') for subpages
	 * @param length the length of the page
	 * @param timestamp the timestamp as found in the MW-database
	 * 		('YYYYmmddHHMMSS')
	 * @return string the comment that is later printed
	 */
	private function createPageComment( $info, $length, $timestamp ) {
		/* parse timestamp */
		$time = strptime( $timestamp, '%Y%m%d%H%M%S' );
		$timestamp = mktime( $time[tm_hour], 
			$time[tm_min],
			$time[tm_sec],
			$time[tm_mon],
			$time[tm_day], 
			$time[tm_year] );
		$lastChange = strftime( '%Y-%m-%d %H:%M', $timestamp );

		return wfMsg( 'pageComment', $info, $length, $lastChange) ;
	}

	/**
	 * Return human readable sizes 
	 *
	 * @author	  Aidan Lister <aidan@php.net>
	 * @version     1.1.0
	 * @link        http://aidanlister.com/repos/v/function.size_readable.php
	 * @param       int    $size        Size
	 * @param       int    $unit        The maximum unit
	 * @param       int    $retstring   The return string format
	 * @param	   int    $si          Whether to use SI prefixes
	 */
	function size_readable($size, $unit = null, $retstring = null, $si = true) {
		// Units
		if ($si === true) {
			$sizes = array('B', 'kB', 'MB', 'GB', 'TB', 'PB');
			$mod   = 1000;
		} else {
			$sizes = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
			$mod   = 1024;
		}
		$ii = count($sizes) - 1;
 
		// Max unit
		$unit = array_search((string) $unit, $sizes);
		if ($unit === null || $unit === false) {
			$unit = $ii;
		}
 
		// Return string
		if ($retstring === null) {
			$retstring = '%01.2f %s';
		}
 
		// Loop
		$i = 0;
		while ($unit != $i && $size >= 1024 && $i < $ii) {
			$size /= $mod;
			$i++;
		}
 
		return sprintf($retstring, $size, $sizes[$i]);
	}

}

?>
