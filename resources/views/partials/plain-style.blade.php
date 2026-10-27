{{-- Stilul paginilor fără Filament (instalarea, actualizarea): alb, negru, linii fine. --}}
<style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: #0a0a0a; font: 16px/1.5 'Inter Tight', 'Helvetica Neue', Helvetica, Arial, sans-serif; }
        main { max-width: 44rem; margin: 0 auto; padding: 3rem 1rem 5rem; }
        .brand { font-size: 1.375rem; font-weight: 600; letter-spacing: -0.04em; }
        .lang { float: right; font-size: .875rem; color: #737373; }
        .lang a { color: inherit; }
        h1 { font-size: clamp(2.5rem, 7vw, 4rem); font-weight: 500; line-height: 1; letter-spacing: -0.045em; margin: 2.5rem 0 .75rem; }
        .lead { color: #737373; font-size: 1.0625rem; margin: 0 0 2.5rem; }
        section { border-top: 1px solid #0a0a0a; padding-top: 1rem; margin-top: 2.5rem; }
        h2 { font-size: 1.5rem; font-weight: 500; letter-spacing: -0.03em; margin: 0 0 1rem; }
        .req { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid #e5e5e5; font-size: .9375rem; }
        .req .no { color: #dc2626; }
        .req .opt { color: #737373; }
        label { display: block; font-size: .8125rem; color: #525252; margin: 1rem 0 .35rem; }
        input, select { width: 100%; height: 2.6rem; padding: 0 .75rem; border: 1px solid #d4d4d4; border-radius: 0; font: inherit; background: #fff; color: #0a0a0a; }
        select { appearance: none; -webkit-appearance: none; padding-right: 2.5rem; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230a0a0a' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m19.5 8.25-7.5 7.5-7.5-7.5'/%3E%3C/svg%3E") no-repeat right .75rem center / 1rem; }   /* săgeata cu linie, în câmp */
        input:focus, select:focus { outline: none; border-color: #0a0a0a; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
        .hint { font-size: .8125rem; color: #737373; margin: .35rem 0 0; }
        .error { border: 1px solid #dc2626; color: #0a0a0a; padding: .85rem 1rem; margin: 1.5rem 0; font-size: .9375rem; }
        .error p { margin: .15rem 0; }
        button { margin-top: 2rem; height: 2.9rem; padding: 0 1.5rem; border: 0; background: #0a0a0a; color: #fff; font: inherit; font-weight: 500; cursor: pointer; }
        button:disabled { opacity: .4; cursor: not-allowed; }
        @media (max-width: 560px) { .row { grid-template-columns: 1fr; } }
        @media (max-width: 639px) { button, a.button { width: 100%; min-height: 3rem; justify-content: center; } }   /* telefon: butoanele negre pe toată lățimea */
</style>
