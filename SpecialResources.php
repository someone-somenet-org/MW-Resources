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
	 */
	function execute ( $par ) {
		global $wgOut, $wgRequest;
		global $resources_showPages, $resources_showSubpages, $resources_showLinks;
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

		/* make backlink variable for script in MediaWiki:Common.js */
		$script = "<script type=\"text/javascript\">/*<![CDATA[*/
var downloadPage = \"" . $backlinkTitle->getPrefixedText() . "\";
var downloadTalkPage = \"" . $backlinkTalk . "\";
/*]]>*/</script>\n";
		$wgOut->addScript( $script );

		$this->resourceList = $this->getResourceList( $this->title );

		$wgOut->addWikiText( $this->printHeader() );
		$wgOut->addHTML( $this->makeList() );
	}

	/** 
	 * this populates the global $resourceList
	 */
	function getResourceList( $title ) {
		global $resources_showPages, $resources_showSubpages, $resources_showLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$resourceList = array();

		/* add the list of pages linking here, if desired */
		if ( $resources_showPages or $resources_showPages == NULL ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $resources_showSubpages or $resources_showSubpages == NULL )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links (requires ExternalRedirects extension) */
		if ( $wgEnableExternalRedirects and
			( $resources_showLinks or $resources_showLinks == NULL ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );
		return $resourceList;
	}

	/**
	 * get a list of pages linking to $title
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($type, $link, $linktext) )
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
				);

		$tlConds = array(
				'page_id=tl_from',
				'tl_namespace' => $title->getNamespace(),
				'tl_title' => $title->getDBkey(),
				);

		// Read an extra row as an at-end check
		$queryLimit = $limit + 1;
		$options['LIMIT'] = $queryLimit;
		if ( $offsetCond ) {
			$tlConds[] = $offsetCond;
			$plConds[] = $offsetCond;
		}
		$fields = array( 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' );
		$plRes = $dbr->select( array( 'pagelinks', 'page' ), $fields,
				$plConds, $fname );
		$tlRes = $dbr->select( array( 'templatelinks', 'page' ), $fields,
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

		// Sort by key and then change the keys to 0-based indices
		ksort( $rows );
		$rows = array_values( $rows );
		$numRows = count( $rows );

		global $resources_Namespaces, $wgContLang;
		foreach ( $rows as $row ) {
			if ( $row->page_namespace != 6 ) 
				continue;

			$tmp = str_replace( '_', ' ', $row->page_title );
			$displayTitle = str_replace( $prefix, '', $tmp );
			$sortkey = $displayTitle . ":" . $row->page_namespace;

			$result[$sortkey] = array ($row->page_namespace, $row->page_title, $displayTitle);
		}
		return $result;
	}

	/**
	 * get a list of Subpages of $title
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($type, $link, $linktext) )
	 */
	function getSubpages( $title ) {
		global $resources_SubpagesIncludeRedirects;
		$result = array ();
		$prefix = $title->getPrefixedDBkey() . '/';
		$fname = 'Resources::getSubpages';

		$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$dbr = wfGetDB( DB_SLAVE );
		$db_conditions = array(
				'page_namespace' => $namespace,
				'page_title LIKE \'' . $dbr->escapeLike( $prefixKey ) .'%\'',
				'page_title >= ' . $dbr->addQuotes( $prefixKey ),
				);
		if ( $resources_SubpagesIncludeRedirects == false )
			$db_conditions = array_merge( $db_conditions, array('page_is_redirect=0'));

		$res = $dbr->select( 'page',
				array( 'page_namespace', 'page_title', 'page_is_redirect' ),
				$db_conditions,
				$fname,
				array(
					'ORDER BY'  => 'page_title',
					'USE INDEX' => 'name_title',
				     )
				);

		while ( $row = $dbr->fetchObject( $res ) ) {
			$niceTitle = str_replace('_', ' ', $row->page_title);
			$tmp = str_replace( $prefix, '', $niceTitle );
			$displayTitle = ucfirst( $tmp );
			$sortkey = ucfirst( $tmp ) . ":" . $row->page_namespace;
			$result[$sortkey] = array ($row->page_namespace, $row->page_title, $displayTitle );
		}
		return $result;
	}

	/**
	 * get a list of ExternalRedirects of $title
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($type, $link, $linktext) )
	 */
	function getLinks( $title ) {
		global $IP;
		require_once("$IP/extensions/ExternalRedirects/ExternalRedirects.php");

		$prefix = $title->getPrefixedDBkey() . "/";
		$fname = 'Resources::getLinks';
		$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page',
				array( 'page_namespace', 'page_title', 'page_is_redirect' ),
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

		$result = array();

		while ( $row = $dbr->fetchObject( $res ) ) {
			/* WARNING: retrieving the content of pages we find here is somewhat
			   complicated. It has to emulate some functions of Article.php. We
			   cannot simpy do a $article->fetchContent, because this triggers some
			   hooks, especially the one we use for external redirection!
			   We don't do all that fancy error checking that Article.php does 
			   (yet). */
			   
			$linkTitle = Title::newFromText( $row->page_title, $row->page_namespace );
			$linkArticle = new Article( $linkTitle ); /* create article obj */
			$data = $linkArticle->pageDataFromTitle( $dbr, $linkTitle ); /* get some data */
			$linkArticle->loadPageData( $data ); /* save that data (i.e. mLatest from next line */
			$revision = Revision::newFromId( $linkArticle->mLatest ); /* create a revision */
			/* finally get some text from that revision */
			$linkArticle->mContent = $revision->userCan( Revision::DELETED_TEXT ) ? $revision->getRawText() : "";
			list($num, $target, $targetInfo) = getTargetInfo( $linkArticle );
			if ($num == 0)
				continue;
			$niceTitle = str_replace('_', ' ', $row->page_title);
			$tmp = str_replace( $prefix, '', $niceTitle );
			$displayTitle = ucfirst( $tmp );
			$sortkey = ucfirst( $tmp ) . ":" . $row->page_namespace;
			$result[$sortkey] = array( "http", $target, array($displayTitle, $linkTitle) );

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
	 * creates a category-style list of all the resources we found. This
	 * function includes special treatment for ExternalRedirects as well
	 * as for files, which can be directly linked by setting
	 * 		$resources_enableDirectFileLinks
	 * @return string - a HTML-representation of the array.
	 */
	function makeList() {
		global $wgTitle, $wgContLang, $wgCanonicalNamespaceNames;
		global $resources_enableDirectFileLinks;
		$catPage = new CategoryViewer( $wgTitle );
		$skin = $catPage->getSkin();
		ksort( $this->resourceList );
		
		// this emulates CategoryViewer::getHTML(): 
		$catPage->clearCategoryState();
		// populate:
		foreach ( $this->resourceList as $sortkey=>$value) {
			if ( $value[0] == 'http' ) {
				$catPage->articles[] = $skin->makeExternalLink(
					$value[1], $value[2][0]
				) . ' (' . $skin->makeKnownLink( $value[2][1]->getPrefixedText(),
					wfMsg('redirect_link_view'), 'redirect=no') . ')';
			} elseif ( $value[0] == NS_IMAGE and $resources_enableDirectFileLinks) {
				$fileTitle = Title::makeTitle( $value[0], $value[1] );
				$fileArticle = new Image( $fileTitle ); /* create article obj */
				$catPage->articles[] = '<span class="plainlinks">' . 
					$skin->makeExternalLink( $fileArticle->getURL(),
					$value[2]) . '</span>';
			} else {
				$title = Title::makeTitle( $value[0], $value[1] );
				$catPage->articles[] = $skin->makeSizeLinkObj(
					$pageLength, $title, $wgContLang->convert( $value[2] )
				);
			}
			$catPage->articles_start_char[] = $wgContLang->convert( $wgContLang->firstChar( $sortkey ) );
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

	public function getResourceListCount( $title ) {
		global $resources_showPages, $resources_showSubpages, $resources_showLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$resourceList = array();

		/* add the list of pages linking here, if desired */
		if ( $resources_showPages or $resources_showPages == NULL ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $resources_showSubpages or $resources_showSubpages == NULL )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links (requires ExternalRedirects extension) */
		if ( $wgEnableExternalRedirects and
			( $resources_showLinks or $resources_showLinks == NULL ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );
		return count($resourceList);
	}

}

?>
