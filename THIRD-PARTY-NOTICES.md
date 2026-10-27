# Third-party notices

cemainou is licensed under the cemainou Source License (LICENSE.md). It ships or builds with the third-party works below, each under its own license.

## Fonts

- **Inter Tight** - Copyright 2022 The Inter Project Authors (https://github.com/rsms/inter-tight). SIL Open Font License 1.1.
  Files: `public/wsa/InterTight-Variable.ttf`, `resources/widget/fonts/*.woff2`, and the copy bundled into `public/build` from `@fontsource-variable/inter-tight`.
  License text: `public/wsa/OFL-InterTight.txt`, `resources/widget/fonts/OFL.txt`.

## Icons

- **Carbon icons** - Copyright IBM Corp. (https://carbondesignsystem.com). Apache License 2.0.
  Files: `resources/svg/heroicons-carbon/*.svg` (generated from the Carbon set). License text: `resources/svg/heroicons-carbon/LICENSE-carbon.txt`.
- **blade-carbon-icons** - codeat3. MIT License (`resources/svg/heroicons-carbon/LICENSE.md`); used at build time to copy the SVGs.
- **Heroicons** - Tailwind Labs. MIT License; a fallback for icon names not mapped to Carbon (`vendor/blade-ui-kit/blade-heroicons`).

## PHP and JavaScript dependencies

The packages installed by Composer (`vendor/`) and npm (`node_modules/`, build time only) keep their own licenses, listed in `composer.lock`
and `package-lock.json` and in each package's folder. They are mostly MIT and BSD; a few are Apache 2.0 (for example `phpoption/phpoption`,
`chillerlan/php-qrcode`, whose `NOTICE` file is kept in `vendor/chillerlan/php-qrcode/`). `nette/*` packages are used under their BSD-3-Clause option.
