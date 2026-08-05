<?php

declare(strict_types=1);

/**
 * pages/partials/landing-illustration.php
 *
 * The hero illustration of the cover page: the glowing shelf of
 * books with the open-book, the search / author-profile / AI cards
 * and the recommendation network. Pure decorative SVG, reproduced 1:1
 * from the approved cover design; it is hidden from assistive
 * technology (the prose on the left already says everything).
 */

?>
<div class="landing-illus" aria-hidden="true">
    <div class="landing-illus-glow"></div>
    <svg viewBox="0 0 540 420" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient id="landingGlowGrad" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#7c5cbf"/>
                <stop offset="100%" stop-color="#0f0c29" stop-opacity="0"/>
            </radialGradient>
            <linearGradient id="landingCardGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#22d3ee" stop-opacity="0.6"/>
                <stop offset="100%" stop-color="#7c5cbf" stop-opacity="0.2"/>
            </linearGradient>
            <linearGradient id="landingCover1" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#4c1d95"/>
                <stop offset="100%" stop-color="#2e1065"/>
            </linearGradient>
            <linearGradient id="landingCover2" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#0e7490"/>
                <stop offset="100%" stop-color="#164e63"/>
            </linearGradient>
            <linearGradient id="landingCover3" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#5b3fa6"/>
                <stop offset="100%" stop-color="#3b1d80"/>
            </linearGradient>
            <filter id="landingCardShadow">
                <feDropShadow dx="0" dy="4" stdDeviation="8" flood-color="rgba(0,0,0,0.4)"/>
            </filter>
            <filter id="landingSoftGlow">
                <feGaussianBlur stdDeviation="3" result="blur"/>
                <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
        </defs>

        <ellipse cx="270" cy="250" rx="190" ry="100" fill="url(#landingGlowGrad)" opacity="0.2"/>

        <!-- Shelf -->
        <rect x="55" y="302" width="420" height="10" rx="5" fill="#1e1b4b"/>
        <rect x="75" y="312" width="380" height="4" rx="2" fill="#12104a" opacity="0.7"/>

        <!-- Book A -->
        <rect x="80" y="210" width="38" height="92" rx="4" fill="url(#landingCover1)"/>
        <rect x="80" y="210" width="8" height="92" rx="3" fill="rgba(0,0,0,0.25)"/>
        <rect x="93" y="240" width="19" height="3" rx="1.5" fill="#c4b5fd" opacity="0.7"/>
        <rect x="93" y="248" width="14" height="2" rx="1" fill="#c4b5fd" opacity="0.4"/>
        <rect x="93" y="255" width="16" height="2" rx="1" fill="#c4b5fd" opacity="0.3"/>

        <!-- Book B -->
        <rect x="124" y="222" width="22" height="80" rx="4" fill="url(#landingCover2)"/>
        <rect x="124" y="222" width="6" height="80" rx="3" fill="rgba(0,0,0,0.2)"/>
        <rect x="133" y="255" width="9" height="2" rx="1" fill="#67e8f9" opacity="0.7"/>

        <!-- Book C -->
        <rect x="152" y="228" width="48" height="74" rx="4" fill="url(#landingCover3)"/>
        <rect x="152" y="228" width="9" height="74" rx="3" fill="rgba(0,0,0,0.22)"/>
        <rect x="166" y="252" width="26" height="3" rx="1.5" fill="#ddd6fe" opacity="0.65"/>
        <rect x="166" y="260" width="20" height="2" rx="1" fill="#ddd6fe" opacity="0.4"/>
        <rect x="166" y="267" width="22" height="2" rx="1" fill="#ddd6fe" opacity="0.3"/>

        <!-- Book D -->
        <rect x="206" y="238" width="30" height="64" rx="4" fill="#1e3a8a"/>
        <rect x="206" y="238" width="7" height="64" rx="3" fill="rgba(0,0,0,0.2)"/>
        <rect x="217" y="260" width="14" height="2" rx="1" fill="#93c5fd" opacity="0.6"/>

        <!-- Book E -->
        <rect x="242" y="215" width="34" height="87" rx="4" fill="#7c1f50"/>
        <rect x="242" y="215" width="8" height="87" rx="3" fill="rgba(0,0,0,0.22)"/>
        <rect x="254" y="248" width="16" height="2.5" rx="1.5" fill="#fda4af" opacity="0.6"/>
        <rect x="254" y="256" width="12" height="2" rx="1" fill="#fda4af" opacity="0.4"/>

        <!-- Open Book -->
        <g transform="translate(300 195)" filter="url(#landingCardShadow)">
            <ellipse cx="0" cy="92" rx="58" ry="7" fill="rgba(0,0,0,0.35)"/>
            <path d="M0,0 C-1,-5 -4,-7 -10,-7 L-56,-4 C-62,-3 -64,0 -62,6 L-56,86 C-54,92 -49,94 -42,93 L0,88 Z" fill="#f8fafc"/>
            <path d="M0,0 L0,88 L-42,93 C-49,94 -54,92 -56,86 L-62,6 C-64,0 -62,-3 -56,-4 Z" fill="#eef2f7"/>
            <path d="M0,0 C1,-5 4,-7 10,-7 L56,-4 C62,-3 64,0 62,6 L56,86 C54,92 48,94 42,93 L0,88 Z" fill="#ffffff"/>
            <path d="M0,0 L0,88 L42,93 C48,94 54,92 56,86 L62,6 C64,0 62,-3 56,-4 Z" fill="#f1f5f9"/>
            <path d="M0,-7 C0.5,22 0.5,62 0,88" stroke="#cbd5e1" stroke-width="1.5"/>
            <line x1="-50" y1="16" x2="-10" y2="14" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="-50" y1="25" x2="-10" y2="23" stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="-50" y1="34" x2="-26" y2="33" stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="-50" y1="44" x2="-10" y2="43" stroke="#cbd5e1" stroke-width="1" stroke-linecap="round"/>
            <line x1="-50" y1="53" x2="-10" y2="52" stroke="#cbd5e1" stroke-width="1" stroke-linecap="round"/>
            <line x1="-50" y1="62" x2="-32" y2="61" stroke="#cbd5e1" stroke-width="1" stroke-linecap="round"/>
            <line x1="10" y1="16" x2="50" y2="14" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="10" y1="25" x2="50" y2="23" stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="10" y1="34" x2="36" y2="33" stroke="#94a3b8" stroke-width="1.2" stroke-linecap="round"/>
            <line x1="10" y1="44" x2="50" y2="43" stroke="#cbd5e1" stroke-width="1" stroke-linecap="round"/>
            <line x1="10" y1="53" x2="50" y2="52" stroke="#cbd5e1" stroke-width="1" stroke-linecap="round"/>
            <text x="10" y="74" font-size="9" fill="#fbbf24">★</text>
            <text x="20" y="74" font-size="9" fill="#fbbf24">★</text>
            <text x="30" y="74" font-size="9" fill="#fbbf24">★</text>
            <text x="40" y="74" font-size="9" fill="#fbbf24">★</text>
            <text x="50" y="74" font-size="9" fill="#cbd5e1">★</text>
        </g>

        <!-- Recommendation card -->
        <g transform="translate(418 138)" filter="url(#landingCardShadow)">
            <rect x="-52" y="-32" width="104" height="64" rx="12" fill="#1a1550"/>
            <rect x="-52" y="-32" width="104" height="64" rx="12" fill="url(#landingCardGrad1)"/>
            <rect x="-52" y="-32" width="104" height="64" rx="12" stroke="rgba(155,121,216,0.3)" stroke-width="1"/>
            <text x="-40" y="-12" font-size="7.5" fill="#a78bfa" font-family="Inter,sans-serif" font-weight="600" letter-spacing="0.8">RECOMMENDED FOR YOU</text>
            <rect x="-40" y="-6" width="62" height="2.5" rx="1.5" fill="rgba(255,255,255,0.12)"/>
            <rect x="-40" y="1" width="48" height="2" rx="1" fill="rgba(255,255,255,0.08)"/>
            <text x="-40" y="20" font-size="8.5" fill="#22d3ee" font-family="Inter,sans-serif" font-weight="500">✦ 98% match</text>
            <circle cx="34" cy="8" r="12" fill="rgba(34,211,238,0.15)" stroke="rgba(34,211,238,0.3)" stroke-width="1"/>
            <text x="30" y="12.5" font-size="13" fill="#67e8f9">🤖</text>
        </g>

        <!-- Search card -->
        <g transform="translate(98 168)" filter="url(#landingCardShadow)">
            <rect x="-46" y="-26" width="92" height="52" rx="12" fill="#1a1550" stroke="rgba(91,63,166,0.35)" stroke-width="1"/>
            <circle cx="-20" cy="0" r="10" stroke="#7c5cbf" stroke-width="1.5" fill="none"/>
            <line x1="-13" y1="7" x2="-7" y2="13" stroke="#7c5cbf" stroke-width="1.5" stroke-linecap="round"/>
            <text x="-3" y="-8" font-size="7.5" fill="#9b79d8" font-family="Inter,sans-serif" font-weight="600" letter-spacing="0.6">SEARCH</text>
            <rect x="-3" y="-3" width="36" height="2.5" rx="1.5" fill="rgba(255,255,255,0.1)"/>
            <rect x="-3" y="4" width="26" height="2" rx="1" fill="rgba(255,255,255,0.06)"/>
        </g>

        <!-- Author profile card -->
        <g transform="translate(90 115)" filter="url(#landingCardShadow)">
            <rect x="-44" y="-24" width="88" height="48" rx="10" fill="#12104a" stroke="rgba(91,63,166,0.3)" stroke-width="1"/>
            <circle cx="-26" cy="0" r="13" fill="#2e278a" stroke="rgba(155,121,216,0.4)" stroke-width="1.5"/>
            <text x="-30" y="5" font-size="14">👤</text>
            <text x="-8" y="-6" font-size="8" fill="#e2e8f0" font-family="Inter,sans-serif" font-weight="600">Sarah M.</text>
            <text x="-8" y="3" font-size="7" fill="#a78bfa" font-family="Inter,sans-serif">312 followers</text>
            <rect x="-8" y="8" width="40" height="8" rx="4" fill="rgba(91,63,166,0.35)"/>
            <text x="4" y="14.5" font-size="6.5" fill="#c4b5fd" font-family="Inter,sans-serif">+ Follow</text>
        </g>

        <!-- AI network -->
        <g opacity="0.75">
            <circle cx="440" cy="255" r="6" fill="#22d3ee" filter="url(#landingSoftGlow)"/>
            <circle cx="472" cy="225" r="4" fill="#9b79d8"/>
            <circle cx="492" cy="272" r="5" fill="#7c5cbf"/>
            <circle cx="458" cy="290" r="3.5" fill="#22d3ee"/>
            <circle cx="416" cy="278" r="4.5" fill="#a78bfa"/>
            <line x1="440" y1="240" x2="472" y2="225" stroke="rgba(155,121,216,0.5)" stroke-width="1" stroke-dasharray="3 2"/>
            <line x1="472" y1="225" x2="492" y2="272" stroke="rgba(34,211,238,0.4)" stroke-width="1" stroke-dasharray="3 2"/>
            <line x1="492" y1="272" x2="458" y2="290" stroke="rgba(155,121,216,0.45)" stroke-width="1" stroke-dasharray="3 2"/>
            <line x1="458" y1="290" x2="416" y2="278" stroke="rgba(34,211,238,0.35)" stroke-width="1" stroke-dasharray="3 2"/>
            <line x1="416" y1="278" x2="440" y2="240" stroke="rgba(155,121,216,0.4)" stroke-width="1" stroke-dasharray="3 2"/>
            <line x1="440" y1="240" x2="492" y2="272" stroke="rgba(34,211,238,0.25)" stroke-width="0.8" stroke-dasharray="3 3"/>
        </g>

        <!-- Stars + reviews -->
        <text x="82" y="318" font-size="11" fill="#fbbf24" text-anchor="middle">★</text>
        <text x="95" y="318" font-size="11" fill="#fbbf24" text-anchor="middle">★</text>
        <text x="108" y="318" font-size="11" fill="#fbbf24" text-anchor="middle">★</text>
        <text x="121" y="318" font-size="11" fill="#fbbf24" text-anchor="middle">★</text>
        <text x="134" y="318" font-size="11" fill="rgba(255,255,255,0.15)" text-anchor="middle">★</text>
        <text x="84" y="338" font-size="8" fill="rgba(148,163,184,0.7)" font-family="Inter,sans-serif">4.8 · 2,400 reviews</text>
    </svg>
</div>