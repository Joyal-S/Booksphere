<?php

declare(strict_types=1);

/**
 * auth/partials/_brand-panel.php
 *
 * The left brand panel shared by every authentication screen: logo,
 * illustration, tagline and platform stats. Included by
 * layouts/auth.php inside the <aside class="auth-brand">.
 *
 * The decorative layer is pure CSS (grid lines + glow) plus one
 * inline SVG book stack, so the panel stays light and crisp at every
 * resolution and honours prefers-reduced-motion.
 */

?>
<div class="auth-brand-grid" aria-hidden="true"></div>
<div class="auth-brand-glow" aria-hidden="true"></div>

<div class="auth-brand-top">
    <a class="auth-logo" href="/">
        <span class="auth-logo-mark" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                <path d="M4 19.5A2.5 2.5 0 016.5 17H20" stroke="white" stroke-width="2" stroke-linecap="round"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="9" y1="7" x2="15" y2="7" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                <line x1="9" y1="11" x2="13" y2="11" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </span>
        <span>
            <span class="auth-logo-name">BookSphere</span>
            <span class="auth-logo-tagline">Discover · Read · Recommend</span>
        </span>
    </a>
</div>

<div class="auth-brand-middle">
    <div class="auth-illustration" aria-hidden="true">
        <svg viewBox="0 0 320 300" fill="none" xmlns="http://www.w3.org/2000/svg">
            <ellipse cx="160" cy="272" rx="110" ry="16" fill="rgba(139,128,255,0.16)"/>
            <g class="auth-float-slow">
                <rect x="52" y="120" width="96" height="132" rx="5" fill="#3a2f8f" transform="rotate(-13 100 186)"/>
                <rect x="52" y="120" width="13" height="132" rx="3" fill="#2c246e" transform="rotate(-13 100 186)"/>
                <rect x="60" y="132" width="64" height="2.5" rx="1" fill="rgba(167,139,250,0.55)" transform="rotate(-13 100 186)"/>
                <rect x="60" y="141" width="48" height="2" rx="1" fill="rgba(255,255,255,0.14)" transform="rotate(-13 100 186)"/>
                <rect x="60" y="147" width="54" height="2" rx="1" fill="rgba(255,255,255,0.14)" transform="rotate(-13 100 186)"/>
            </g>
            <g class="auth-float">
                <rect x="128" y="100" width="88" height="126" rx="5" fill="#7c5cbf" transform="rotate(5 172 163)"/>
                <rect x="128" y="100" width="12" height="126" rx="3" fill="#5b3fa6" transform="rotate(5 172 163)"/>
                <rect x="139" y="115" width="58" height="2.5" rx="1" fill="rgba(255,255,255,0.6)" transform="rotate(5 172 163)"/>
                <rect x="139" y="124" width="42" height="2" rx="1" fill="rgba(255,255,255,0.3)" transform="rotate(5 172 163)"/>
                <rect x="139" y="130" width="50" height="2" rx="1" fill="rgba(255,255,255,0.3)" transform="rotate(5 172 163)"/>
                <rect x="139" y="136" width="36" height="2" rx="1" fill="rgba(255,255,255,0.3)" transform="rotate(5 172 163)"/>
            </g>
            <g class="auth-float-slow">
                <rect x="190" y="78" width="80" height="148" rx="5" fill="#b8adf5" transform="rotate(-3 230 152)"/>
                <rect x="190" y="78" width="11" height="148" rx="2" fill="#9b8ce8" transform="rotate(-3 230 152)"/>
                <rect x="204" y="94" width="52" height="2.5" rx="1" fill="#2d2b6b" opacity="0.4" transform="rotate(-3 230 152)"/>
                <rect x="204" y="103" width="40" height="2" rx="1" fill="#2d2b6b" opacity="0.22" transform="rotate(-3 230 152)"/>
                <rect x="204" y="109" width="46" height="2" rx="1" fill="#2d2b6b" opacity="0.22" transform="rotate(-3 230 152)"/>
                <rect x="204" y="115" width="32" height="2" rx="1" fill="#2d2b6b" opacity="0.22" transform="rotate(-3 230 152)"/>
                <rect x="234" y="78" width="7" height="32" rx="1.5" fill="#5b3fa6" transform="rotate(-3 230 152)"/>
                <polygon points="234,110 241,110 237.5,117" fill="#5b3fa6" transform="rotate(-3 230 152)"/>
            </g>
            <g transform="translate(64,228)">
                <path d="M0 20 Q48 8 96 20 L96 72 Q48 64 0 72 Z" fill="#d9d3f9"/>
                <path d="M96 20 Q144 8 192 20 L192 72 Q144 64 96 72 Z" fill="#efeafe"/>
                <rect x="94" y="18" width="4" height="58" rx="2" fill="rgba(26,26,46,0.1)"/>
                <line x1="16" y1="34" x2="80" y2="31" stroke="rgba(91,75,219,0.35)" stroke-width="1.5"/>
                <line x1="16" y1="41" x2="80" y2="38" stroke="rgba(91,75,219,0.25)" stroke-width="1.5"/>
                <line x1="16" y1="48" x2="62" y2="45" stroke="rgba(91,75,219,0.25)" stroke-width="1.5"/>
                <line x1="16" y1="55" x2="80" y2="52" stroke="rgba(91,75,219,0.2)" stroke-width="1.5"/>
                <line x1="104" y1="31" x2="176" y2="34" stroke="rgba(91,75,219,0.35)" stroke-width="1.5"/>
                <line x1="104" y1="38" x2="176" y2="41" stroke="rgba(91,75,219,0.25)" stroke-width="1.5"/>
                <line x1="104" y1="45" x2="158" y2="48" stroke="rgba(91,75,219,0.25)" stroke-width="1.5"/>
                <line x1="104" y1="52" x2="176" y2="55" stroke="rgba(91,75,219,0.2)" stroke-width="1.5"/>
            </g>
            <circle cx="48" cy="86" r="4" fill="rgba(167,139,250,0.5)" class="auth-float"/>
            <circle cx="282" cy="112" r="3" fill="rgba(167,139,250,0.35)" class="auth-float-slow"/>
            <circle cx="296" cy="240" r="5" fill="rgba(167,139,250,0.3)" class="auth-float"/>
        </svg>
    </div>
</div>

<div class="auth-brand-bottom">
    <p class="auth-brand-headline">Your next favourite book<br><em>is waiting to be found.</em></p>
    <p class="auth-brand-sub">Intelligent recommendations curated for the way you read.</p>
    <div class="auth-brand-stats">
        <div class="auth-brand-stat"><span class="auth-brand-stat-value">2.4M+</span><span class="auth-brand-stat-label">Books</span></div>
        <div class="auth-brand-stat"><span class="auth-brand-stat-value">180K+</span><span class="auth-brand-stat-label">Readers</span></div>
        <div class="auth-brand-stat"><span class="auth-brand-stat-value">4.9★</span><span class="auth-brand-stat-label">Rating</span></div>
    </div>
</div>