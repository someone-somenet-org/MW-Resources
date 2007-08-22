<?php

/**
 * Entry point
 */
function wfSpecialResources ($par) {
	global $wgOut;
	$page = new Resources();
	$page->execute($par);
}

class Resources extends SpecialPage
{
	function Resources() {
		global $wgOut;
		self::loadMessages();

		// the output of similarnamedarticles_title will be what
		// the link points to. the lowercase version will be used
		// as displayed link-text
		SpecialPage::SpecialPage( wfMsg('resources_title') ); // this is where the link points to

	}
	
	function execute ( $par ) {
		global $wgOut, $wgRequest;
		global $resources_showPages, $resources_showSubpages, $resources_showLinks;
		/* some variables from foreign extensions: */
		global $wgEnableExternalRedirects;
		$resourceList = array();
		$this->setHeaders();
		
		/* make backlink variable */
		$script = "<script type=\"text/javascript\">/*<![CDATA[*/\nvar downloadPage = \"$par\";\n/*]]>*/</script>\n";
		$wgOut->addScript( $script );

		/* make a Title object from $par */
		if ( $par )
			$title = Title::newFromText( $par );
		else {
			$wgOut->addWikiText( wfMsg('no_page_specified') );
			return;
		}

		/* add the list of pages linking here, if desired */
		if ( $resources_showPages or $resources_showPages == NULL ) 
			$resourceList = array_merge( $resourceList, $this->getFiles( $title ) );
		/* add the list of subpages, if desired */
		if ( $resources_showSubpages or $resources_showSubpages == NULL )
			$resourceList = array_merge( $resourceList, $this->getSubpages( $title ) );
		/* add a list of foreign links */
		if ( $wgEnableExternalRedirects and
			( $resources_showLinks or $resources_showLinks == NULL ) )
			$resourceList = array_merge( $resourceList, $this->getLinks( $title ) );

		$wgOut->addWikiText( $this->printHeader( $title, count($resourceList) ) );
		$wgOut->addHTML( $this->makeList( $resourceList ) );
	}

	/**
	 * get a list of pages linking to $target
	 * @param Title $target - function will find pages linking to this title
	 * @return array with the structure:
	 *		[0] => array( $sortkey => ($type, $link, $linktext) )
	 */
	function getFiles( $target ) {
		$dbr =& wfGetDB( DB_READ );
		$result = array ();

		// Make the query
		$plConds = array(
				'page_id=pl_from',
				'pl_namespace' => $target->getNamespace(),
				'pl_title' => $target->getDBkey(),
				);

		$tlConds = array(
				'page_id=tl_from',
				'tl_namespace' => $target->getNamespace(),
				'tl_title' => $target->getDBkey(),
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

			$sortkey = $row->page_title . ":" . $row->page_namespace;

			$result[$sortkey] = array ($row->page_namespace, $row->page_title, $row->page_title);

#			$nt = Title::makeTitle( $row->page_namespace, $row->page_title );
#				
#			if ( $row->page_is_redirect ) {
#				$extra = 'redirect=no';
#			} else {
#				$extra = '';
#			}
#
#			// Display properties (redirect or template)
#			$props = array();
#			if ( $row->page_is_redirect ) {
#				$props[] = $isredir;
#			}
#	
#			if ( $row->is_template ) {
#				$props[] = $istemplate;
#			}
		}
		return $result;
	}

	/* get subpages of $title */
	function getSubpages( $title ) {
		global $resources_SubpagesIncludeRedirects;
		$result = array ();
		$prefix = $title->getPrefixedURL() . "/";
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

	/* get foreign links for $title */
	function getLinks( $title ) {
		global $IP;
		require_once("$IP/extensions/ExternalRedirects/ExternalRedirects.php");

		$prefix = $title->getPrefixedURL() . "/";
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
			   cannot to a $article->fetchContent, because this triggers some
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

	/* print the header */
	function printHeader( $title, $count ) {
		global $wgUser;
		$skin = $wgUser->getSkin();
		$titleText = $title->getFullText();
		$r = "<div id=\"mw-pages\">\n";
		$r .= wfMsg( 'header', $titleText ) . "\n";
		if ( $count > 0 ) {
			$r .= wfMsg( 'header_text', $count, $titleText );
		} elseif ( $count == 1 ) {
			$r .= wfMsg( 'header_text_one', $count, $titleText );
		} else {
			$r .= wfMsg( 'header_text_none', $titleText );
		}
		$r .= "</div>";
		return $r;	
	}

	/* create a category list of the three former functions */
	function makeList( $list ) {
		global $wgTitle, $wgContLang;
		$catPage = new CategoryViewer( $wgTitle );
		$skin = $catPage->getSkin();
		ksort( $list );
		
		// this emulates CategoryViewer::getHTML(): 
		$catPage->clearCategoryState();
		// populate:
		foreach ( $list as $sortkey=>$value) {
			if ( $value[0] == 'http' ) {
				$catPage->articles[] = $skin->makeExternalLink(
					$value[1], $value[2][0]
				) . ' (' . $skin->makeKnownLink( $value[2][1]->getPrefixedText(),
					wfMsg('redirect_link_view'), 'redirect=no') . ')';
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

	/* internationalization stuff */
	function loadMessages() {
		static $messagesLoaded = false;
		global $wgMessageCache;
		if ( $messagesLoaded ) return;
		$messagesLoaded = true;

		require( dirname( __FILE__ ) . '/Resources.i18n.php' );
		foreach ( $allMessages as $lang => $langMessages ) {
			$wgMessageCache->addMessages( $langMessages, $lang );
		}
	}
}

?>
