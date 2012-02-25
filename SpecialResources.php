<?php

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
		SpecialPage::SpecialPage( 'Resources' );
		wfLoadExtensionMessages('Resources');
	}
	
	/**
	 * main worker-function...
	 * @param par the part after the '/' from the HTTP-Request
	 */
	function execute( $par ) {
		global $wgOut, $wgRequest;
		global $wgResourcesShowPages, $wgResourcesShowSubpages, $wgResourcesShowLinks;
		// variables from foreign extensions:
		global $wgEnableExternalRedirects;
		$this->setHeaders();
		$backlinkTitle;

		/* make a Title object from $par */
		if ( $par ) {
			$this->title = Title::newFromText( $par );
			$wgOut->setPagetitle( wfMsg('resourcesPageTitle', $this->title->getPrefixedText() ) );
		} else {
			global $wgResourcesCategory;
			if ( $wgResourcesCategory ) {
				$wgOut->addWikiText( "<dpl>
				mode=category
				resultsheader=" . wfMsg('header_allResources') . 
				"\noneresultheader=" . wfMsg('header_allResourcesOne') .
				"\nnoresultsheader=" . wfMsg('header_allResourcesNone') . 
				"\nredirects=include
				ordermethod=titlewithoutnamespace
				shownamespace=false
				category=" . $wgResourcesCategory . 
				"</dpl>" );
				$wgOut->setPagetitle( wfMsg( 'title_allResources' ) );
			} else 
				$wgOut->addWikiText( wfMsg('no_page_specified') );
			return;
		}
		
		$this->resourceList = $this->getResourceList( $this->title );

		$wgOut->addWikiText( $this->printHeader() );
		$wgOut->addHTML( $this->makeList() );

		$wgOut->addHTML( $this->makeRedirects() );
	}

	function makeRedirects() {
		global $wgOut;
		$dbr = wfGetDB( DB_SLAVE );
		$title = $this->title;

		$plConds = array(
                        'page_id=pl_from',
                        'pl_namespace' => $title->getNamespace(),
                        'pl_title' => $title->getDBkey(),
			'page_is_redirect' => 1,
                );
		$fields = array( 'page_id', 'page_namespace', 'page_title' );
		$options[] = 'STRAIGHT_JOIN';
		$options['ORDER BY'] = 'page_title';
                
		$plRes = $dbr->select( array( 'pagelinks', 'page' ), $fields,
			$plConds, __METHOD__, $options );
		
		if ( $dbr->numRows( $plRes ) == 0 ) {
			return;
		}

		$wgOut->addWikiText( wfMsg( 'redirects_header' ) );
		$wgOut->addWikiText( wfMsg( 'redirects_explanation' ) );

		while ( $row = $dbr->fetchObject( $plRes ) ) {
                                $rows[$row->page_id] = $row;
                }
                $dbr->freeResult( $plRes );
		
		ksort( $rows );
                $rows = array_values( $rows );

		foreach ( $rows as $row ) {
                        $nt = Title::makeTitle( $row->page_namespace, $row->page_title );
			# implode this later and addWikiText as a whole because otherwise
			# each <li> would be its own <ul> :-(
			$namespace = $nt->getNsText();
			$title = $nt->getText();
			$resourceTitleText = SpecialPage::getTitleFor( 'Resources' );
			if ( $row->page_namespace == NS_MAIN ) { # this is only to not have a : for the main namespace
				$list[] = wfMsg( 'redirect_element_main', $title, $resourceTitleText );
			} else {
				$list[] = wfMsg( 'redirect_element', $namespace, $title, $resourceTitleText );
			}

                }
		$wgOut->addWikiText( implode( $list, "\n" ) );
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
		if ( $wgResourcesShowPages !== FALSE ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $wgResourcesShowSubpages !== FALSE )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links (requires ExternalRedirects extension) */
		if ( $wgEnableExternalRedirects and
			( $wgResourcesShowLinks !== FALSE ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );
		return $resourceList;
	}

	/**
	 * function used by SimilarNamedArticles to print the number of
	 * resources for the given title.
	 * @param title Article for that we want the number of resources
	 * @return int the number of resources for the article.
	 */
	function getResourceListCount( $title ) {
		global $wgResourcesShowPages, $wgResourcesShowSubpages, $wgResourcesShowLinks;
		$count = 0;
		
		if ( $wgResourcesShowPages !== FALSE )
			$count += $this->getFiles( $title, TRUE );
		if ( $wgResourcesShowSubpages !== FALSE )
			$count += $this->getSubpages( $title, TRUE );
		if ( $wgResourcesShowLinks !== FALSE ) 
			$count += $this->getLinks( $title, TRUE );
		
		return $count;
	}

	/**
	 * get a list of pages linking to $title. The algorithm used here
	 * is a modified version of the algorithm used in Special:Whatlinkshere
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($link, $second_line) )
	 *	or int if $count == True
	 */
	function getFiles( $title, $count = FALSE ) {
		global $wgSkin, $wgContLang, $wgUser, $wgLegalTitleChars,
			$wgResourcesNamespaces, $wgResourcesDirectFileLinks;
		$skin = $wgUser->getSkin();
		$dbr =& wfGetDB( DB_READ );
		
		/* copied from SpecialUpload::processUpload(): */
                $prefix = preg_replace ( "/[^" . $wgLegalTitleChars . "]|:|\//", '-', 
			$title->getPrefixedText() . ' - ' );
		$result = array ();

		// Make the query
		$plConds = array(
			'page_id=pl_from',
			'pl_namespace' => $title->getNamespace(),
			'pl_title' => $title->getDBkey(),
			'page_latest=rev_id',
			'page_namespace=' . NS_IMAGE,
		);
		if ( $count ) {
			$fields = array( 'count(*) as count' );
			$plRes = $dbr->select( array( 'pagelinks', 'page', 'revision' ), $fields,
				$plConds );
			$count = $dbr->fetchObject( $plRes )->count;
			$dbr->freeResult( $plRes );
			return $count;
		} else {
			$fields = array( 'page_id', 'page_title', 'page_len', 'rev_timestamp' );
			$plRes = $dbr->select( array( 'pagelinks', 'page', 'revision' ), $fields,
				$plConds );
			if ( !$dbr->numRows( $plRes ) ) {
				return array(); // found nothing
			}
		}

		// Read the rows into an array and remove duplicates
		while ( $row = $dbr->fetchObject( $plRes ) ) {
			$rows[$row->page_id] = $row;
		}
		$dbr->freeResult( $plRes );

		// change the keys to 0-based indices
		$rows = array_values( $rows );

		// process result:
		foreach ( $rows as $row ) {
			// the sortkey is suffixed with the NS in case we have articles with same name
			$targetTitle = Title::makeTitleSafe( NS_FILE, $row->page_title );
			$displayTitle = str_replace( $prefix, '', $targetTitle->getText() );
			$sortkey = $displayTitle . ":" . $row->page_id;
			$sortkey = $this->makeSortkeySafe( $sortkey );
	
			/* create link and comment text */
			#$fileArticle = new Image( $targetTitle );
			#$fileArticle = LocalFile::newFromTitle($targetTitle);
			$fileArticle = wfLocalFile($targetTitle);
			$size = $this->size_readable( $fileArticle->getSize(), 'GB', '%01.0f %s' );
			
			$link = Linker::makeMediaLinkFile($targetTitle, $fileArticle, $displayTitle);
			if ( $wgResourcesDirectFileLinks ) {
				$detailLink = $skin->link($targetTitle, wfMsg('details'));
				$comment = '<br />' . wfMsg ( 'fileCommentWithDetails', $size, $fileArticle->getMimeType(), $detailLink );
			} else {
				$comment = '<br />' . wfMsg( 'fileComment', $size, $fileArticle->getMimeType() );
			}
			$result[ucfirst($sortkey)] = array ( $link, $comment );
		}
		return $result;
	}

	/**
	 * get a list of Subpages for $title. This function is a modfied
	 * version of the algorithm of Special:Prefixindex.
	 * @param title The title of the page we want the subpages of
	 * @param count If we only want the number of results
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ( $link, $linkInfo ) )
	 *	or int if $count == True
	 */
	function getSubpages( $title, $count = FALSE ) {
		global $wgUser, $wgResourcesSubpagesIncludeRedirects;
		$skin = $wgUser->getSkin();
		$dbr = wfGetDB( DB_SLAVE );
		$result = array ();

		/* make a query */
		$namespace = $title->getNamespace();
		$prefixKey = $title->getDBkey() . '/';
		#$prefix = $title->getText();
		#$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		#list( $namespace, $prefixKey, $prefix ) = $prefixList;

		$regexpPrefix = str_replace( '(', '\\\\(', $prefixKey );
		$regexpPrefix = str_replace( ')', '\\\\)', $regexpPrefix );

		$db_conditions = array(
				'page_namespace' => $namespace,
				'page_title LIKE \'' . $dbr->escapeLike( $prefixKey ) .'%\'',
				'page_title REGEXP \'^' . $regexpPrefix . '[^/]+$\'',
				'page_title >= ' . $dbr->addQuotes( $prefixKey ),
				'page_latest=rev_id',
		);
		if ( $wgResourcesSubpagesIncludeRedirects == false )
			$db_conditions[] = 'page_is_redirect=0';

		if ( $count ) {
			$fields = array( 'count(*) as count' );
			$res = $dbr->select( array('page', 'revision'), $fields, 
				$db_conditions );
			$count = $dbr->fetchObject( $res )->count;
			$dbr->freeResult( $res );
			return $count;
		} else {
			$fields = array( 'page_id', 'page_namespace', 'page_title', 'page_len', 'rev_timestamp' );
			$res = $dbr->select( array('page', 'revision'), $fields, 
				$db_conditions );
		}


		/* use the results of the query */
		while ( $row = $dbr->fetchObject( $res ) ) {
			$targetTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );

			$link = $skin->link($targetTitle, $targetTitle->getSubpageText());
			$comment = $this->createPageComment( wfMsg('subpage'),
				$row->page_len, $row->rev_timestamp );
			$sortkey = $targetTitle->getSubpageText() . '/' .
				$targetTitle->getBaseText() . ':' . $row->page_id;
			$sortkey = $this->makeSortkeySafe( $sortkey );
			
			$result[ucfirst($sortkey)] = array( $link, $comment );
		}
		$dbr->freeResult( $res );
		return $result;
	}

	/**
	 * get a list of ExternalRedirects for $title. The algorithm we use here is
	 * a modified version of the algorithm used in Special:Prefixindex. We
	 * @param title the title we get the external redirects for
	 * @param count If we only want the number of results
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ( $link, $linkInfo )
	 *	or int if $count == True
	 */
	function getLinks( $title, $count = False ) {
		global $IP, $wgUser, $wgExternalRedirectProtocols;
		$skin = $wgUser->getSkin();
		$result = array();
		$dbr = wfGetDB( DB_SLAVE );

		/* make the query */
		#$prefix = $title->getPrefixedDBkey() . "/";
		#$namespace = 0; //magic key :-(
		#$prefixList = SpecialAllpages::getNamespaceKeyAndText($namespace, $prefix);
		#list( $namespace, $prefixKey, $prefix ) = $prefixList;
		$namespace = $title->getNamespace();
		$prefixKey = $title->getDBkey() . '/';

		$tables = array( 'page', 'revision', 'text' );
		$condis = array(
			'page_namespace' => $namespace,
			'page_title LIKE \'' . $dbr->escapeLike( $prefixKey ) . '%\'',
			'page_is_redirect=1',
			'page_latest=rev_id',
			'rev_text_id=old_id',
			'old_text REGEXP \'^#REDIRECT \\\\[\\\\[(' . implode( "|", $wgExternalRedirectProtocols )  . ')://\'', // NOTE: This is case insensitive (mysql regex...)
		);
		if ( $count ) {
			$fields = array( 'count(*) as count' );
			$res = $dbr->select( $tables, $fields, $condis );
			$count = $dbr->fetchObject( $res )->count;
			$dbr->freeResult( $res );
			return $count;
		} else {
			$fields = array( 'page_id', 'page_namespace', 'page_title', 'old_text' );
			$res = $dbr->select( $tables, $fields, $condis );
		}

		/* use the results */
		while ( $row = $dbr->fetchObject( $res ) ) {

			list($num, $target, $targetInfo) = getTargetInfo( $row->old_text );
			if ($num == 0) {
				print "WARNING: This was not an External Redirect. Please notify Admins!";
				continue; // not an external redirect (should not happen because of neat regexp in sql-query)
			}
			
			$targetTitle = Title::makeTitleSafe( $row->page_namespace, $row->page_title );
			$targetInfo = Sanitizer::removeHTMLtags( $targetInfo ); //remove dangerous HTML tags
			
			$link = $skin->makeExternalLink(
					$target, $targetTitle->getSubpageText() );
			$linkInfo = '';
			if ( $targetInfo ) {
				$linkInfo .= '<br />' . $targetInfo;
			}
			$linkInfo .= ' (' . $skin->link(
				$targetTitle,
				wfMsg('redirect_link_view'),
				array(), 
				array('redirect' => 'no')
			) . ')';

			$sortkey = ucfirst( $targetTitle->getSubpageText() ) . ':' .
				$row->page_id;
			$sortkey = $this->makeSortkeySafe( $sortkey ); # fixes üöä...
			
			$result[$sortkey] = array( $link, $linkInfo );
		}
		$dbr->freeResult( $res );
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
		$skin = $wgUser->getSkin();
		$count = count( $this->resourceList );
		$titleText = $this->title->getFullText();
		$r = "<div id=\"mw-pages\">\n";
		$addResourceText = SpecialPage::getTitleFor( 'AddResource' );
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
		ksort( $this->resourceList );
		
		// this emulates CategoryViewer::getHTML(): 
		$catPage->clearCategoryState();
		// populate:
		foreach ( $this->resourceList as $sortkey=>$value) {
			$catPage->articles_start_char[] = $wgContLang->convert( $wgContLang->firstChar( $sortkey ) );
			if ( $wgResourcesAddInfos ) {
				$catPage->articles[] = $value[0] . $value[1];
			} else {
				$catPage->articles[] = $value[0];
			}
		}
		
		if( count( $catPage->articles ) > 0 )
			$result = $catPage->formatList( $catPage->articles, $catPage->articles_start_char );

		return $result;
	}

	/**
	 * replaces äöüÄÖÜ with ae/oe/etc.
	 */
	function makeSortkeySafe( $string ) {
		$string = str_replace( 'ä', 'ae', $string );
		$string = str_replace( 'ö', 'oe', $string );
		$string = str_replace( 'ü', 'ue', $string );
		$string = str_replace( 'Ä', 'Ae', $string );
		$string = str_replace( 'Ö', 'Oe', $string );
		$string = str_replace( 'Ü', 'Ue', $string );
		return $string;
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
		$timestamp = mktime( $time['tm_hour'], 
			$time['tm_min'],
			$time['tm_sec'],
			1,
			$time['tm_yday'] + 1, 
			$time['tm_year'] + 1900 );
		$lastChange = date( 'Y-m-d H:i', $timestamp );

		return '<br />' . wfMsg( 'pageComment', $info, $length, $lastChange) ;
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
