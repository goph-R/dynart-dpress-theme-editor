<?php

namespace Dynart\ThemeEditor;

use Dynart\Dpress\Form\DpressForm;

/**
 * The editor's form: the file's text in the code editor, and the fingerprint of it as opened
 */
class ThemeEditorForms {

    /**
     * @param array $context `text`, `hash` (`RepositoryFiles::hash()` of it), `language` - the
     *                       editor's `data-code`: `html`, `css`, `markdown` or `text`
     */
    public function file(DpressForm $form, array $context): void {
        $language = (string)($context['language'] ?? 'text');
        $form->addFields([
            // the CSS box's shape, taller: `data-code` is what makes it the code editor, and the
            // line numbers are drawn by the colouring, so a language without one has none
            'text' => ['type' => 'textarea', 'label' => 'File', 'required' => false,
                       'attributes' => [
                           'rows'       => '30',
                           'spellcheck' => 'false',
                           'class'      => 'code theme-editor-code'.($language !== 'text' ? ' numbered' : ''),
                           'data-code'  => $language,
                       ]],
            // what the file was when this was drawn: a save onto a file that changed since - a
            // pull, a second tab - is refused rather than written over it
            'hash' => ['type' => 'hidden', 'required' => false],
        ]);
        $form->addValues([
            'text' => (string)($context['text'] ?? ''),
            'hash' => (string)($context['hash'] ?? ''),
        ]);
    }
}
