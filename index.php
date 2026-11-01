<?php
// Link previews (WhatsApp, iMessage, Telegram, Slack...) need full addresses, so the page says
// where it's served from: behind Traefik, the original host and https.
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !empty($_SERVER['HTTPS'])) ? 'https' : 'http';
$origin = htmlspecialchars("$scheme://$host", ENT_QUOTES);
// Gzipped for browsers that accept it (ob_gzhandler checks Accept-Encoding): about a fifth of the bytes.
if (extension_loaded('zlib')) ob_start('ob_gzhandler');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- What a shared link shows: the toffee icon, name and a line about it. -->
    <meta name="description" content="Everyone's channel: public posts and private messages. No email or phone number needed.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="toffee">
    <meta property="og:title" content="toffee">
    <meta property="og:description" content="Everyone's channel: public posts and private messages. No email or phone number needed.">
    <meta property="og:url" content="<?= $origin ?>/">
    <meta property="og:image" content="<?= $origin ?>/og-image.png?v=3">
    <meta property="og:image:width" content="600">
    <meta property="og:image:height" content="600">
    <meta property="og:image:alt" content="The toffee logo">
    <meta name="twitter:card" content="summary">
    <!-- An app, not a page: no pinch or double-tap zoom. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <!-- iPhone: the name under the Home Screen icon, and a normal (dark on white) status bar. -->
    <meta name="apple-mobile-web-app-title" content="toffee">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>toffee</title>
    <link rel="manifest" href="manifest.json">
    <script>try { const t = localStorage.getItem('tf_theme'); if (t && t !== 'toffee') document.documentElement.dataset.theme = t; } catch (e) { /* default */ }
    // Who's signed in, asked now, while app.js is still on its way (one round trip less on a slow link).
    window.tfMe = fetch('api.php?a=me', { credentials: 'same-origin' }).then((r) => r.json());</script>
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=2">
    <link rel="icon" type="image/png" sizes="180x180" href="toffee.png?v=3">
    <link rel="apple-touch-icon" href="toffee.png?v=3">
    <link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
</head>
<body>
    <div class="social-overlay">
        <!-- Wide screens (PC, tablet in landscape): the feed stays in this column, and the
             channel you open shows beside it. Phones: hidden, the feed is the home screen. -->
        <aside class="feed-pane" hidden aria-label="Feed">
            <div class="social-head">
                <strong class="feed-title"><img class="brand-logo" src="toffee-logo-64.webp?v=2" alt=""><span class="brand">toffee</span></strong>
                <a class="powered-by" href="https://torii-edge-cloud.duckdns.org/" target="_blank" rel="noopener">
                    Powered by <span class="torii-logo" aria-hidden="true">⛩️</span> <b>Torii Edge Cloud</b>
                </a>
                <span class="head-bell feed-bell"></span>
            </div>
            <div class="social-account feed-account"></div>
            <div class="social-body feed-body"></div>
        </aside>
        <div class="social-dialog" role="main" aria-labelledby="socialTitle">
            <div class="social-head">
                <button type="button" class="social-back" hidden aria-label="Back">‹</button>
                <button type="button" class="social-home" hidden aria-label="Home" title="Home"><svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11 12 4l8 7v9h-5v-6H9v6H4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></button>
                <strong id="socialTitle">Toffee</strong>
                <a class="powered-by" href="https://torii-edge-cloud.duckdns.org/" target="_blank" rel="noopener">
                    Powered by <span class="torii-logo" aria-hidden="true">⛩️</span> <b>Torii Edge Cloud</b>
                </a>
                <span class="head-bell"></span>
            </div>
            <div class="social-account"></div>
            <div class="social-channel"></div>
            <div class="tab-row" hidden>
                <div class="seg social-tabs">
                    <button type="button" data-tab="public">Public</button>
                    <button type="button" data-tab="private"><svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" fill="#fff6cc" stroke="currentColor" stroke-width="1.8"/><path d="M8 11V8a4 4 0 0 1 8 0v3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg> Private</button>
                </div>
                <button type="button" class="sbtn tab-call-btn" data-video="0" hidden aria-label="Voice call" title="Voice call"><svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 3.5h2.8l1.4 4-2 1.3a11 11 0 0 0 6.4 6.4l1.3-2 4 1.4v2.8a2 2 0 0 1-2.2 2A17 17 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></button>
                <button type="button" class="sbtn tab-call-btn" data-video="1" hidden aria-label="Video call" title="Video call"><svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="12" height="10" rx="2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="M15 10.5 21 7v10l-6-3.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></button>
                <button type="button" class="sbtn tab-search-btn" aria-pressed="false" aria-label="Search here" title="Search here"><svg class="view-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/><path d="m16 16 4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></button>
            </div>
            <div class="tab-search" hidden></div>
            <div class="social-body"></div>
            <div class="social-foot"></div>
        </div>
    </div>

    <script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
