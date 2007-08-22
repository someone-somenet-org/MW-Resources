<?php
# Not a valid entry point, skip unless MEDIAWIKI is defined
if (!defined('MEDIAWIKI')) {
        echo <<<EOT
To install my extension, put the following line in LocalSettings.php:
require_once( "$IP/extensions/MyExtension/MyExtension.php" );
EOT;
        exit( 1 );
}

$wgAutoloadClasses['Downloads'] = dirname(__FILE__) . '/SpecialDownloads.php';
$wgHooks['LoadAllMessages'][] = 'Downloads::loadMessages';

switch ( $wgLanguageCode ) {
        case 'en':
                $wgSpecialPages[ 'Downloads' ] = 'Downloads';
                break;
        case 'de':
                $wgSpecialPages[ 'Downloads' ] = 'Downloads';
                break;
        default:
                $wgSpecialPages[ 'Downloads' ] = 'Downloads';
                break;
}

# do we need a hook here?
# require_once(dirname(__FILE__) . '/Hook.php');

?>
