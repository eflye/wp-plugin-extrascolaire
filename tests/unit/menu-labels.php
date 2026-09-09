<?php
if (!defined('ABSPATH')) define('ABSPATH', '/wp/');
require_once __DIR__ . '/../../includes/class-psc-menus.php';
$cases = json_decode(file_get_contents(__DIR__ . '/menu-label-cases.json'), true);
foreach ($cases as $case) {
    if (Psc_Menus::parse_menu_lines($case['raw']) !== $case['expected']) throw new RuntimeException('Menu : ' . $case['raw']);
}
echo count($cases) . " cas de parsing des menus validés.\n";
