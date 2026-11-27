# Contributing to MDS Release Manager

Thank you for contributing to **MDS Release Manager**, maintained by **Musty Dungeon Studios**, a wholly owned subsidiary of **Walton International Business Group LLC** (https://mustydungeon.com).

## Contributor License Agreement (CLA)

By submitting a pull request, patch, or other contribution to this repository, you certify that:

1. The contribution is your original work and you have the legal right to submit it.
2. You grant **Musty Dungeon Studios / Walton International Business Group LLC** a perpetual, worldwide, non-exclusive, royalty-free, irrevocable copyright and patent license to use, modify, distribute, and **dual-license** your contribution under both strong copyleft (`GPL-3.0-or-later`) and commercial license terms.

## Code Standards

- PHP `8.0+`, WordPress `7.0+`, strict types (`declare(strict_types=1);`), and `LF` line endings.
- Run `composer run lint` (WPCS) and `npm run build` before opening a pull request.
- Respect Free Core vs. Paid Extension boundaries (Core stays 100% self-contained with zero nag banners or external API lock-in).
