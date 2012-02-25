<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
	echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "$IP/extensions/MyExtension/MyExtension.php" );
EOT;
	exit( 1 );
}

$dir = dirname(__FILE__);

$wgAutoloadClasses['Resources'] = $dir . '/SpecialResources.php';
$wgExtensionMessagesFiles['Resources'] = $dir . '/Resources.i18n.php';
$wgSpecialPages[ 'Resources' ] = 'Resources';
$wgHooks['LanguageGetSpecialPageAliases'][] = 'efResourcesLocalizedPageName';

$wgHooks['SkinTemplateContentActions'][] = 'efResourcesDisplayTab'; # for monobook
$wgHooks['SkinTemplateNavigation'][] = 'efResourcesNormalPages'; # for vector - just on normal pages

/**
 * this function is not currently used and is only here for future reference
 */
function efResourcesSpecialPage( $template, $links ) {
	global $wgTitle;

	/* we are on a special page */
	$curSpecialPage = $wgTitle->getPrefixedText();

	// return if we are not on the right special page
	if ( $curSpecialPage != SpecialPage::getTitleFor( 'Resources' ) )
		return true;

	// get parameter for special page:
	global $wgRequest, $wgUser, $wgAddResourceTab;
	$reqTitle = $wgRequest->getVal('title');
	$par = preg_replace('/' . $curSpecialPage . '\/?/', '', $reqTitle );
	if ( $par == '' ) // if no /par was given
		return true;
	$parTitle = Title::newFromText( $par )->getSubjectPage();
	$parTalkTitle = $parTitle->getTalkPage();

	$head = array (
		$parTitle->getNamespaceKey('') => array(
			'class' => $parTitle->exists() ? null : 'new',
			'text' => $parTitle->getNsText(),
			'href' => '/href', // todo
		)
	);
	$tail = array (
		$parTitle->getNamespaceKey('') . '_talk' => array(
			'class' => $parTalkTitle->exists() ? null : 'new',
			'text' => $parTalkTitle->getNsText(),
			'href' => '/href', // todo
		)
	);
	$links = array_merge( $head, $links, $tail );

	return true;
}

/**
 * Add Resource Tab on normal pages on SkinTemplates (like Vector). This does
 * not handle special pages, for which unfortunatly no hook is called (also see
 * the above function).
 */
function efResourcesNormalPages( $template, $links ) {
	global $wgResourcesNamespaces, $wgResourcesTabs, $wgTitle;
	if ( ! $wgResourcesTabs ) 
		return true;
	$ns = $wgTitle->getNamespace();
	
	if ( ! ( in_array( $ns, $wgResourcesNamespaces ) ||
			in_array( $ns - 1, $wgResourcesNamespaces ) ) )
		return true; /* admin doesn't want tab here */

	# get class for resources tab:
	$resourcePage = new Resources();
	$resourceCount = $resourcePage->getResourceListCount( $wgTitle );
	$class = $resourceCount > 0 ? null : 'new';

	# get link target:
	$resources = SpecialPage::getTitleFor( 'Resources' );
	$target = $resources->getLocalURL() .'/'. $wgTitle->getPrefixedDBkey();

	# resource tab text:
	if ( $resourceCount > 0 ) {
		$text = wfMsg( 'resourcesTabExists', $resourceCount );
	} else {
		$text = wfMsg( 'resourcesTab' );
	}
	
	$namespaces = $links['namespaces'];
	$namespace_key = array_keys( $namespaces );

	$resourcesTab = array( 
		$namespace_key[0] . '_resources' => array(
			'class' => $class,
			'text' => $text,
			'href' => $target,
			'context' => 'resources',
		)
	);
	
	# build array:
	$head = array_slice( $namespaces, 0, 1 );
	$tail = array_slice( $namespaces, 1 );
	$links['namespaces'] = array_merge( $head, $resourcesTab, $tail );

	return true;
}

$wgExtensionCredits['specialpage'][] = array (
	'name' => 'Resources',
	'description' => 'Displays resources attached to an article (with the AddResource extension)',
	'version' => '1.5.0-1.13.1',
	'author' => 'Mathias Ertl',
	'url' => 'http://fs.fsinf.at/wiki/Resources',
);

function efResourcesLocalizedPageName( &$specialPageArray, $code) {
	wfLoadExtensionMessages('Resources');
	$textMain = wfMsgForContent('resources');
	$textUser = wfMsg('resources');

	# Convert from title in text form to DBKey and put it into the alias array:
	$titleMain = Title::newFromText( $textMain );
	$titleUser = Title::newFromText( $textUser );
	$specialPageArray['Resources'][] = $titleMain->getDBKey();
	$specialPageArray['Resources'][] = $titleUser->getDBKey();

	return true;
}

/**
 * Add Tabs for Skins like monobook
 */
function efResourcesDisplayTab( $tabs ) {
	global $wgResourcesTabs, $wgTitle;
	if ( ! $wgResourcesTabs ) 
		return true;
	$ns = $wgTitle->getNamespace();
	
	if ( $ns == -1 ) {
		/* we are on a special page */
		$curSpecialPage = $wgTitle->getPrefixedText();

		// return if we are not on the right special page
		if ( $curSpecialPage != SpecialPage::getTitleFor( 'Resources' ) )
			return true;

		// get $par:
		global $wgRequest, $wgUser, $wgAddResourceTab;
		$reqTitle = $wgRequest->getVal('title');
		$par = preg_replace('/' . $curSpecialPage . '\/?/', '', $reqTitle );
		if ( $par == '' ) // if no /par was given
			return true;
		$parTitle = Title::newFromText( $par )->getSubjectPage();
		$parTalkTitle = $parTitle->getTalkPage();

		/* build tabs */
		$skin = $wgUser->getSkin();
		$nskey = $parTitle->getNamespaceKey();

		// subject page and talk page:
		$customTabs[$nskey] = $skin->tabAction(
			$parTitle, $nskey, false, '', true);
		$customTabs['talk'] = $skin->tabAction(
			$parTalkTitle, 'talk', false, '', true);

		// downloads-tab:
		$customTabs['view-resources'] = array ( 'class' => 'selected',
			'text' => wfMsg('resourcesTab'),
			$tabs['nstab-special']['href'] );
		
		/* get number of resources (and redden link if 0) */
		$resourcesPage = new Resources();
		$resourcesCount = $resourcesPage->getResourceListCount( $parTitle );
		if ( ! $resourcesCount )
			$customTabs['view-resources']['class'] .= ' new';

		// display add-resources tab, if requested
		if ( $wgAddResourceTab ) {
			$page = SpecialPage::getTitleFor( 'AddResource' );
			$customTabs['add-resources'] = array ( 
				'class' => false,
				'text' => wfMsg('addResourceTab'),
				'href' => $page->getLocalURL() . '/' .
					$parTitle->getPrefixedDBkey()
			);
		}

		$tabs = $customTabs;
	} else {
		/* subject/talk page */
		global $wgResourcesNamespaces;
		if ( ! ( in_array( $ns, $wgResourcesNamespaces ) ||
				in_array( $ns - 1, $wgResourcesNamespaces ) ) )
			return true; /* user doesn't want tab here */
		$mainTabs = array_slice ( $tabs, 0, 2, true );
		$secondaryTabs = array_splice( $tabs, 0, 2 );
		$title = $wgTitle->getSubjectPage();
		$titleBase = Title::newFromText( $title->getBaseText(),
			$title->getNamespace() );

		// this is in case we are on a sub-subpage, we want
		// the top-most page, i.e. 'article' from 'article/sub/sub':
		while ( $titleBase->isSubPage() ) {
			$titleBase = Title::newFromText( $titleBase->getBaseText(),
				$title->getNamespace() );

			if ( $titleBase->exists() )
				$title = $titleBase;
		}
		if ( $titleBase->exists() )
			$title = $titleBase;
		
		/* get number of resources (and redden link if 0) */
		$resourcePage = new Resources();
		$resourceCount = $resourcePage->getResourceListCount( $title );
		if ( $resourceCount > 0 ) {
			$tabText = wfMsg( 'resourcesTabExists', $resourceCount );
			$class = null;
		} else {
			$tabText = wfMsg( 'resourcesTab' );
			$class = 'new';
		}
		
		$specialPage = SpecialPage::getTitleFor( 'Resources' );
		$mainTabs['view-resources'] = array( 'class' => false,
			'text' => $tabText,
			'href' => $specialPage->getLocalURL() . '/' .
				$title->getPrefixedDBkey(),
			'class' => $class,
		);
		
		$tabs = array_merge( $mainTabs, $tabs );
	}

	return true;
}

?>
