<?php

namespace Dynart\ThemeEditor;

use Dynart\Dpress\Plugin\AbstractPlugin;

/**
 * The active theme's templates and stylesheets, edited in the admin
 *
 * **A plugin, not a core feature, on purpose.** Saving a `.phtml` is running PHP on the server, so
 * a site that should never do that from a browser simply does not have this installed - which is
 * a stronger off switch than a setting, because there is nothing to turn back on. Where it is
 * installed, it is behind a permission of its own (`theme.edit`) that only the admin role holds.
 *
 * It is made of Dpress's own parts: the code editor (`data-code="html"` since 0.92.0, `css`,
 * `markdown`), and the files of a git clone (`Dynart\Dpress\Repository`, 0.93.0) - so the lines a
 * file had, a file changed underneath the editor, and a change not yet committed are handled the
 * way the Docs plugin handles them. See README.md.
 */
class ThemeEditorPlugin extends AbstractPlugin {

    public function controllers(): array {
        return [ThemeEditorController::class];
    }

    public function permissions(): array {
        return [ThemeEditor::PERMISSION_EDIT => 'Theme editor'];
    }

    public function views(): array {
        return ['theme_editor' => dirname(__DIR__).'/views'];
    }

    public function assets(): array {
        return ['theme-editor.css'];
    }

    /** After Blocks: with the menus and the blocks, it is how the site looks */
    public function adminSections(): array {
        return [ThemeEditor::ADMIN_SECTION => [
            'label'      => 'Theme files',
            'route'      => '/admin/theme-editor',
            'permission' => ThemeEditor::PERMISSION_EDIT,
            'icon'       => 'icons/theme-editor.svg',
            'after'      => 'blocks',
        ]];
    }
}
