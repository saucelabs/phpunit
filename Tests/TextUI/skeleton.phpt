--TEST--
phpunit Calculator ../_files/Calculator.php
--FILE--
<?php
$_SERVER['argv'][1] = '--no-configuration';
$_SERVER['argv'][2] = 'Calculator';
$_SERVER['argv'][3] = dirname(dirname(__FILE__)) . '/_files/Calculator.php';

require_once dirname(dirname(dirname(__FILE__))) . '/PHPUnit/Autoload.php';
PHPUnit_TextUI_Command::main();
?>
--EXPECTF--
%s
PHPUnit %s by Sebastian Bergmann.

....

Time: %i %s, Memory: %sMb

OK (4 tests, 4 assertions)
