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

$wgHooks['SkinTemplateContentActions'][] = 'efResourcesDisplayTab';
$wgHooks['BeforePageDisplay'][] = 'efResourcesTabCSS';

$wgExtensionCredits['specialpage'][] = array (
	'name' => 'Resources',
	'description' => 'Displays resources attached to an article (with the AddResource extension)',
	'version' => '1.4-1.13.1',
	'author' => 'Mathias Ertl',
	'url' => 'http://pluto.htu.tuwien.ac.at/devel_wiki/Resources',
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

function efResourcesTabCSS( &$outputPage )  {
	global $wgResourcesTabs, $wgScriptPath, $wgResourcesCSSpath;
	if ( ! $wgResourcesTabs )
		return true;

	if ( ! $wgResourcesCSSpath )
		$wgResourcesCSSpath = "$wgScriptPath/extensions/Resources";
	$outputPage->addScript( '<style type="text/css">/*<![CDATA[*/
		@import "' . $wgResourcesCSSpath . '/ResourcesTab.css";
		</style>' );
	return true;
}

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
			'text' => wfMsg('ResourcesTab'),
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
		
		$specialPage = SpecialPage::getTitleFor( 'Resources' );
		$mainTabs['view-resources'] = array( 'class' => false,
			'text' => wfMsg( 'resourcesTab' ),
			'href' => $specialPage->getLocalURL() . '/' .
				$title->getPrefixedDBkey() );
		
		/* get number of resources (and redden link if 0) */
		$resourcesPage = new Resources();
		$resourcesCount = $resourcesPage->getResourceListCount( $title );
		if ( ! $resourcesCount )
			$mainTabs['view-resources']['class'] = 'new';

		$tabs = array_merge( $mainTabs, $tabs );
	}

	return true;
}

?>
