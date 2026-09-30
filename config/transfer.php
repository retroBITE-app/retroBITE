<?php

use App\Transfers\BatoceraTarget;
use App\Transfers\DaijishouTarget;
use App\Transfers\EsDeTarget;
use App\Transfers\OplTarget;
use App\Transfers\RecalboxTarget;
use App\Transfers\RetroPieTarget;

return [

    /*
     * The systems a game can be sent to, by key. Each lays out a drive its own
     * way; see App\Transfers\TransferTarget and docs/adr/0005. Only those
     * that play a console's games are offered for it; the first of them is
     * offered first, unless its library is laid out for another.
     */
    'targets' => [
        'batocera' => BatoceraTarget::class,
        'recalbox' => RecalboxTarget::class,
        'retropie' => RetroPieTarget::class,
        'es-de' => EsDeTarget::class,
        'daijishou' => DaijishouTarget::class,
        'opl' => OplTarget::class,
    ],

    /*
     * Where this app answers on the machine it runs on. A transfer writes to a
     * drive from the browser, which Chrome allows only on https or localhost,
     * so a page opened any other way links here instead. Compose sets it to
     * the published port; unset, the page only explains what is needed.
     */
    'localhost_url' => env('TRANSFER_LOCALHOST_URL') ?: null,

    'discovery' => [
        /*
         * Names asked for by DNS and NetBIOS when searching for shares, beside
         * whatever answers mDNS. Home routers register their DHCP clients by
         * hostname, so these often resolve even behind Docker's bridge, where
         * multicast does not reach. Batocera's default hostname first.
         */
        'names' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRANSFER_DISCOVERY_NAMES', 'batocera,recalbox,retropie'))))),

        /*
         * The LAN's /24 networks, scanned for the SMB port on every search —
         * the one search that crosses Docker's bridge. Unset, the
         * network HOST_IP is on is scanned, else the one the page was opened
         * on. Only private addresses; anything more than a /24 is scanned as
         * the /24 it starts in.
         */
        'subnets' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRANSFER_DISCOVERY_SUBNETS', ''))))),

        /* This machine's LAN address, as the share container is told it. */
        'host_ip' => env('HOST_IP') ?: null,
    ],

];
