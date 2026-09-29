# Theme editor

A [Dpress](https://github.com/goph-R/dynart-dpress) plugin: the active theme's templates and
stylesheets, edited in the admin, under **Theme files**.

- **The code editor** Dpress's markdown and CSS fields use - colours, line numbers, wrap, full size,
  Tab, Enter keeping the indent - with the HTML grammar for `.phtml`/`.php`/`.html` (PHP inside it
  coloured as PHP), CSS for `.css` and Markdown for `.md`. `.js`, `.json`, `.ini` and `.txt` open
  as plain text.
- **A template that does not parse is not saved.** PHP parses the text first - the parser
  `php -l` uses, in the same process - and a syntax error is a message on the field with its line,
  instead of a 500 on every page that uses the template.
- **A file changed underneath the editor is not written over** (a pull, another tab), and the file
  keeps its own line endings and final newline, so a one-word change is one word in `git diff`.
- **Git, when the theme is in a clone** - its own, or the site's around it: files not committed or
  not pushed are marked on the list and warned about in the editor. When it is in none, the screen
  says a save has no history.
- **A saved template runs on the next request**, even where OPcache does not check file times.

Needs **Dpress 0.93.0** or later (`Dynart\Dpress\Repository`, and the HTML grammar from 0.92.0).

## Why a plugin, and who may use it

Saving a `.phtml` is running PHP on the server, as the web server's user. So this is not in core:
a site that should never do that from a browser does not install it, and there is nothing to turn
back on. Installed, it is behind **`theme.edit`**, a permission only the admin role holds unless
somebody grants it on the Roles screen - and nobody should be given it who could not be given the
server.

**What can be opened**: the active theme's folder, and in it only the source files above - no hidden
file or folder (`.git`), nothing in `node_modules` or `vendor`, nothing over 512 KB, no link that
could lead out of the theme. `?file=` is looked up in that list, never followed as a path.

## Install

```sh
cd plugins
git clone https://github.com/goph-R/dynart-dpress-theme-editor.git theme-editor
dpress plugin:enable -name theme-editor
```

For the saves to reach the files, the theme folder has to belong to the web server's user
(`chown -R www-data:` on a Debian server), the same as the Docs plugin's source folder.

## Tests

```sh
composer install
vendor/bin/phpunit --stderr
```

`composer.json` is only for the tests; Dpress loads the plugin from `plugin.ini`.
