<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
	echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "$IP/extensions/MyExtension/MyExtension.php" );
EOT;
	exit( 1 );
}

$wgAutoloadClasses['Resources'] = dirname(__FILE__) . '/SpecialResources.php';
$wgSpecialPages[ 'Resources' ] = 'Resources';
$wgHooks['LoadAllMessages'][] = 'Resources::loadMessages';
$wgHooks['LangugeGetSpecialPageAliases'][] = 'Resources_LocalizedPageName';

$wgExtensionCredits['specialpage'][] = array (
	'name' => 'Resources',
	'description' => 'Displays resources attached to an article (with the AddResource extension)',
	'version' => '0.9-1.11.0',
	'author' => 'Mathias Ertl',
	'url' => 'http://pluto.htu.tuwien.ac.at/devel_wiki/index.php/Resources',
);

function Resources_LocalizedPageName( &$specialPageArray, $code) {
	Resources::loadMessages();
	$text = wfMsg('resources');

	# Convert from title in text form to DBKey and put it into the alias array:
	$title = Title::newFromText( $text );
	$specialPageArray['Resources'][] = $title->getDBKey();

	return true;
}

?>
