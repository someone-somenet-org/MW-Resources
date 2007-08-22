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
$wgHooks['LoadAllMessages'][] = 'Resources::loadMessages';

switch ( $wgLanguageCode ) {
        case 'en':
                $wgSpecialPages[ 'Resources' ] = 'Resources';
                break;
        case 'de':
                $wgSpecialPages[ 'Materialien' ] = 'Resources';
                break;
        default:
                $wgSpecialPages[ 'Resources' ] = 'Resources';
                break;
}

# do we need a hook here?
# require_once(dirname(__FILE__) . '/Hook.php');

?>
