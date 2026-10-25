{{--
    The brand in the topbar, the sidebar and the login card: the ear tag every
    animal in a herdbook carries, and the name. The name takes the colour of its
    surroundings (.fi-logo); sizes and the typeface are in resources/css/herdbook.css.
    The same tag is public/favicon.svg.
--}}
<span class="hb-brand">
    <svg class="hb-brand-mark" viewBox="0 0 64 64" aria-hidden="true">
        <rect width="64" height="64" rx="14" fill="#17181b" />
        <path
            fill="#fdc700"
            fill-rule="evenodd"
            transform="translate(32 33) rotate(-10) scale(.78) translate(-32 -32)"
            d="M23 14A9 9 0 0 1 41 14V18Q41 22 46 23L50 24Q54 25 54 30V50Q54 58 46 58H18Q10 58 10 50V30Q10 25 14 24L18 23Q23 22 23 18ZM36.2 14A4.2 4.2 0 1 0 27.8 14A4.2 4.2 0 1 0 36.2 14Z"
        />
    </svg>
    <span class="hb-brand-name">{{ filament()->getBrandName() }}</span>
</span>
