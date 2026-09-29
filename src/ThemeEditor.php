<?php

namespace Dynart\ThemeEditor;

/**
 * The plugin's names, in one place
 */
class ThemeEditor {

    /**
     * Who may change a theme's files - **admin only** by default, like every permission a plugin
     * adds: saving a template is running PHP on the server, so it is given to nobody else unless
     * somebody means to on the Roles screen
     */
    const PERMISSION_EDIT = 'theme.edit';

    /** The admin navigation's key for the screen */
    const ADMIN_SECTION = 'theme-editor';

    /** The editor's form - see `ThemeEditorForms` */
    const FORM_FILE = 'theme_editor_file';
}
