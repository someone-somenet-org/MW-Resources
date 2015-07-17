<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
    echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once("$IP/extensions/Resources/Resources.php");
EOT;
    exit(1);
}

$wgAutoloadClasses['SpecialResources'] = __DIR__ . '/SpecialResources.php';
$wgExtensionMessagesFiles['Resources'] = __DIR__ . '/Resources.i18n.php';
$wgExtensionMessagesFiles['ResourcesAlias'] = __DIR__ . '/Resources.alias.php';
$wgSpecialPages['Resources'] = 'SpecialResources';

$wgHooks['SkinTemplateNavigation'][] = 'efResourcesNormalPages';
$wgHooks['SkinTemplateNavigation::SpecialPage'][] = 'efResourcesSpecialPage';

function getResourceCount($title) {
    $resourcePage = SpecialPageFactory::getPage('Resources');
    return $resourcePage->getResourceListCount($title);
}

function getResourceTabText($resourceCount) {
    if ($resourceCount > 0) {
        return wfMessage('resourcesTabExists', $resourceCount)->text();
    } else {
        return wfMessage('resourcesTab')->text();
    }
}

function getAddResourceUrl($title) {
    $addResource = SpecialPage::getTitleFor('AddResource');
    return $addResource->getLocalURL() .'/'. $title->getPrefixedDBkey();
}

/**
 * Add Resource Tab on special pages on SkinTemplates (like Vector).
 */
function efResourcesSpecialPage(SkinTemplate &$sktemplate, array &$links) {
    global $wgTitle, $wgRequest, $wgUser, $wgAddResourceTab;

    # return if we are not on the right special page
    if (! ($wgTitle->isSpecial('Resources') || $wgTitle->isSpecial('AddResource'))) {
        return true;
    }

    # parse subpage-part. We cannot use $wgTitle->getSubpage() because the
    # special namespaces doesn't have real subpages
    $prefixedText = $wgTitle->getPrefixedText();
    $slashpos = strpos($prefixedText, '/');
    if ($slashpos === FALSE) {
        return true; // no page given
    }
    $pageName = substr($prefixedText, $slashpos + 1);

    $title = Title::newFromDBkey($pageName)->getSubjectPage();
    $talkTitle = $title->getTalkPage();

    # Get AddResource URL:
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
            'text' => wfMessage('Talk')->text(),
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
 * Add Resource Tab on normal pages on SkinTemplates (like Vector).
 */
function efResourcesNormalPages(SkinTemplate &$sktemplate, array &$links) {
    global $wgResourcesNamespaces, $wgResourcesTabs, $wgTitle;
    if (!$wgResourcesTabs) {
        return true;
    }
    $title = $wgTitle->getSubjectPage();
    $ns = $title->getNamespace();

    if (! in_array($ns, $wgResourcesNamespaces)) {
        return true; /* admin doesn't want tab here */
    }

    # get class for resources tab:
    $resourceCount = getResourceCount($title);
    $class = $resourceCount > 0 ? 'is_resources' : 'new is_resources';

    # get link target:
    $resources = SpecialPage::getTitleFor('Resources');
    $target = $resources->getLocalURL() .'/'. $title->getPrefixedDBkey();

    # resource tab text:
    $text = getResourceTabText($resourceCount);

    $namespaces = $links['namespaces'];
    $namespace_key = array_keys($namespaces);

    # Get AddResources URL:
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
    $head = array_slice($namespaces, 0, 1);
    $tail = array_slice($namespaces, 1);
    $links['namespaces'] = array_merge($head, $resourcesTab, $tail);

    return true;
}

$wgExtensionCredits['specialpage'][] = array (
    'path' => __file__,
    'name' => 'Resources',
    'description' => 'Displays resources attached to an article (with the AddResource extension)',
    'version' => '1.5.3-1.21.0',
    'author' => 'Mathias Ertl',
    'url' => 'http://fs.fsinf.at/wiki/Resources',
);

?>
