# WooCommerce AI Product Advisor

> Your AI co-pilot for WooCommerce product listings.

WooCommerce AI Product Advisor reviews your catalog, surfaces the products with the highest improvement potential, and proposes targeted edits to them — ready to apply with a single click.

## Why use it

- **Skip the blank page.** Get concrete, on-brand suggestions instead of staring at an empty description field.
- **Focus on what moves the needle.** Products are ranked by optimization potential, so you spend time where it actually pays off.
- **Stay in control.** Every suggestion is a diff you can edit, accept, or reject — nothing changes on your store until you say so.

## Features

- **AI-generated suggestions** for products, grounded in your store's brand tone.
- **Brand voice onboarding** so suggestions sound like you, not like a generic model.
- **One-click apply** with a side-by-side diff viewer and inline editing before you commit.
- **History & undo** — every applied change is logged and can be reverted.

## Requirements

- WordPress 6.8+
- WooCommerce 10.5+
- PHP 7.4+
- A WordPress.com account for the Jetpack connection

## Installation

1. Download the latest version from the releases page.
2. Drag the zip file into the **Plugins → Add New** screen and click **Install Now**.
3. Activate **WooCommerce AI Product Advisor** from **Plugins → Installed Plugins**.
4. Open **Product Advisor** (it appears right under **Products** in the admin menu) and complete the onboarding flow.

## Development

### Setup

```bash
pnpm install            # JS dependencies
composer install        # PHP dependencies
pnpm dev:server         # boot the wp-env WordPress sandbox
pnpm dev:client         # watch & rebuild client assets
```

`pnpm dev` runs both server and client in sequence.

### Useful scripts

| Script | What it does |
| --- | --- |
| `pnpm build` | Production build of the React client |
| `pnpm typecheck` | TypeScript type check (`tsc --noEmit`) |
| `pnpm lint:js` / `pnpm lint:css` | Lint client code and styles |
| `pnpm test:js` | Run the Jest test suite |
| `pnpm test:php` | Run the PHPUnit suite via Composer |
| `pnpm tube:setup` | Configure the Jurassic Tube HTTP tunnel |
| `pnpm tube:start` | Start the Jurassic Tube HTTP tunnel |
| `pnpm tube:stop` | Stop the Jurassic Tube HTTP tunnel |

## Project layout

```
client/      React/TypeScript admin UI (screens, components, queries)
src/         PHP plugin code (Admin, Connection, Onboarding, REST, …)
templates/   Admin page templates
build/       Compiled JS/CSS (generated)
tests/       PHPUnit and Jest tests
```

## Contributing

Pull requests are welcome. Please run `pnpm typecheck`, `pnpm lint:js`, and `pnpm test:js` before submitting. Pre-commit hooks via Husky and lint-staged enforce formatting on staged files.

## License

WooCommerce AI Product Advisor is licensed under [GNU General Public License v3 (or later)](./LICENSE.md).
