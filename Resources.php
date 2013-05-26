<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
	echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "$IP/extensions/MyExtension/MyExtension.php" );
EOT;
	exit( 1 );
}

$wgAutoloadClasses['Resources'] = __DIR__ . '/SpecialResources.php';
$wgExtensionMessagesFiles['Resources'] = __DIR__ . '/Resources.i18n.php';
$wgSpecialPages[ 'Resources' ] = 'Resources';
$wgHooks['LanguageGetSpecialPageAliases'][] = 'efResourcesLocalizedPageName';

$wgHooks['SkinTemplateNavigation'][] = 'efResourcesNormalPages';
$wgHooks['SkinTemplateNavigation::SpecialPage'][] = 'efResourcesSpecialPage';

function getResourceCount($title) {
	$resourcePage = new Resources();
	return $resourcePage->getResourceListCount($title);
}

function getResourceTabText($resourceCount) {
	if ($resourceCount > 0) {
		return wfMsg('resourcesTabExists', $resourceCount);
	} else {
		return wfMsg('resourcesTab');
	}
}

function getAddResourceUrl($title) {
	$addResource = SpecialPage::getTitleFor('AddResource');
	return $addResource->getLocalURL() .'/'. $title->getPrefixedDBkey();
}

/**
 * this function is not currently used and is only here for future reference
 */
function efResourcesSpecialPage( $template, $links ) {
	global $wgTitle, $wgRequest, $wgUser, $wgAddResourceTab;

	// return if we are not on the right special page
    if (!$wgTitle->isSpecial('Resources')) {
        return true;
    }

    // parse subpage-part. We cannot use $wgTitle->getSubpage() because the
    // special namespaces doesn't have real subpages
    $prefixedText = $wgTitle->getPrefixedText();
    if (strpos($prefixedText, '/') === FALSE) {
        return true; // no page given
    }
    $parts = explode( '/', $prefixedText);
    $pageName = $parts[count( $parts ) - 1];

	$title = Title::newFromText($pageName)->getSubjectPage();
    $talkTitle = $title->getTalkPage();

    // Get AddResource URL:
    $addResourceUrl = getAddResourceUrl($title);

	$head = array (
		$title->getNamespaceKey('') => array(
			'class' => $title->exists() ? 'is_resources' : 'new is_resources',
			'text' => $title->getText(),
			'href' => $title->getLocalUrl(),
		)
	);
    $tail = array (
        'add_resources' => array(
            'class' => '',
            'text' => '+',
            'href' => $addResourceUrl,
        ),

		$title->getNamespaceKey('') . '_talk' => array(
			'class' => $talkTitle->exists() ? null : 'new',
			'text' => wfMsg('Talk'),
			'href' => $talkTitle->getLocalUrl(),
		)
    );
    $resourceCount = getResourceCount($title);

    $links['namespaces'] = array_merge($head, $links['namespaces'], $tail);
    $links['namespaces']['special']['text'] = getResourceTabText($resourceCount);
    if ($resourceCount == 0) {
        $links['namespaces']['special']['class'] = 'new is_resources';
    } else {
        $links['namespaces']['special']['class'] = 'is_resources';
    }

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
    $title = $wgTitle->getSubjectPage();
	$ns = $title->getNamespace();

	if (! in_array( $ns, $wgResourcesNamespaces )) {
        return true; /* admin doesn't want tab here */
    }

    # get class for resources tab:
    $resourceCount = getResourceCount($title);
	$class = $resourceCount > 0 ? 'is_resources' : 'new is_resources';

	# get link target:
	$resources = SpecialPage::getTitleFor( 'Resources' );
	$target = $resources->getLocalURL() .'/'. $title->getPrefixedDBkey();

    # resource tab text:
    $text = getResourceTabText($resourceCount);

	$namespaces = $links['namespaces'];
	$namespace_key = array_keys( $namespaces );

    // Get AddResources URL:
    $addResourceUrl = getAddResourceUrl($title);

	$resourcesTab = array(
		$namespace_key[0] . '_resources' => array(
			'class' => $class,
			'text' => $text,
			'href' => $target,
        ),
        $namespace_key[0] . '_addresources' => array(
			'class' => '',
			'text' => '+',
			'href' => $addResourceUrl,
        ),
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
	'version' => '1.5.1',
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

?>
