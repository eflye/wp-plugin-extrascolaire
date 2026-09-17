<?php
/** Sonde P1-14 : le verrou compare des timestamps Unix réels. */
if (!defined('ABSPATH')) exit;
$old = get_option('psc_lock_hours', 48); update_option('psc_lock_hours', 48);
$deadline = psc_lock_deadline_ts('2026-09-08');
add_filter('psc_now_ts', function () use ($deadline) { return $deadline - 1; });
if (psc_is_locked('2026-09-08')) { fwrite(STDERR, "FAIL: verrou trop tôt\n"); exit(1); }
remove_all_filters('psc_now_ts');
add_filter('psc_now_ts', function () use ($deadline) { return $deadline; });
if (!psc_is_locked('2026-09-08')) { fwrite(STDERR, "FAIL: échéance non verrouillée\n"); exit(1); }
remove_all_filters('psc_now_ts'); update_option('psc_lock_hours', $old);
echo "OK : horloge du verrou\n";
